> **RESOLVED in v1.3.2 (2026-08-20). The diagnosis below is INVERTED, do not act on it.**
>
> The fast path was never broken. The **HTTP loopback** was: `ajax_get_content` forwarded
> the already-slashed `$_REQUEST` into the loopback URL, the loopback slashed it a second
> time, and JSF's `json_decode( wp_unslash( … ) )` on the JSON sort payload failed and
> dropped the clause. Separately, TranslatePress SEO Pack rewrites `$_SERVER['REQUEST_URI']`
> to the default-language slug, so `/koupit-ev/` never had a block-cache entry and .cz was
> pinned to the loopback permanently, which is the entire reason this looked domain-specific.
> See CHANGELOG 1.3.2 for the full write-up. Kept for the reproduction recipe in sections 4
> and 11, which are still accurate and useful.

---

# Handoff: JSF sorting sa stráca na AJAX fast path

**Dátum:** 2026-08-20
**Repo:** `jsf-query-builder-etch-bridge` (branislavbudzak/jsf-query-builder-etch-bridge)
**Verzia s bugom:** 1.3.1 (a pravdepodobne všetko od 0.10.0, keď fast path vznikol)
**Cieľová verzia opravy:** 1.3.2
**Postihnutý web:** nearcharger.sk + nearcharger.cz (produkcia)

---

## 1. TL;DR

**JSF sorting sa aplikuje len na HTTP loopback ceste. Na fast path (in-process render cached block tree) sa ticho stratí.**

Filtre a stránkovanie na fast path fungujú. Sorting nie. Výsledok sa vráti v default poradí loopu, HTTP 200, žiadna chyba, žiadny log.

Ktorú cestu request dostane, závisí výlučne na tom, či je nahriaty transient `jqbeb_block_<md5(cesta|query_id)>` (TTL 1 h). Preto sa bug javí ako náhodný a viazaný na doménu, hoci s doménou nesúvisí.

---

## 2. Ako sa to prejavilo (kontext, ktorý stál 2 dni)

Pôvodné hlásenie znelo „na nearcharger.cz nefunguje sorting podľa ceny, na nearcharger.sk funguje". Vyšetrovalo sa TranslatePress, LiteSpeed JS combine, JSF signature verification, poškodené meta kľúče, multi-domain URL konverzia. Všetko slepé stopy.

Realita: **rozbité je to na oboch doménach.** SK sa len častejšie trafí do loopbacku, lebo jej katalógová stránka je viac pokrytá LiteSpeed page cache → PHP tam beží zriedkavejšie → `on_render_block` transient nedopĺňa → po hodine expiruje → AJAX padne na loopback → sorting funguje. CZ stránka sa renderuje PHP-čkom častejšie, transient je stále čerstvý, fast path vždy vyhrá, sorting nikdy nefunguje.

(Posledná veta je interpretácia, nie dokázaná časť. Dokázaná časť je bod 3.)

---

## 3. Dôkaz (reprodukované, deterministické)

Rovnaký payload, rovnaká doména, jediná zmena medzi behmi bolo nahriatie transientu jedným GET requestom na katalógovú stránku:

```
                        SK  ASC              SK  DESC
transient studený:      RENAULT Zoe          Rolls-Royce Spectre     ← sortuje (loopback)
transient nahriaty:     Tesla Model Y        Tesla Model Y           ← nesortuje (fast path)
```

Overené aj z opačnej strany: loopback URL zavolaná priamo sortuje správne **na oboch doménach**:

```bash
S='{"orderby":"meta_value_num","order":"ASC","meta_key":"price_sale_gross"}'
curl -s -H 'X-JQBEB-Loopback: 1' \
  "https://nearcharger.cz/koupit-ev/?jsf=etch-loop%2Fdefault&_sort_standard=$(printf %s "$S" | jq -sRr @uri)" \
  | grep -o 'card__heading">[^<]*' | head -3
# → RENAULT Zoe / Peugeot iOn 2011 / Nissan LEAF 24 kWh   (správne ASC)
```

---

## 4. Reprodukčný recept

### 4.1 Skript na AJAX request

Payload je odchytený z reálneho kliknutia na sorting v prehliadači. Ulož ako `jsf-probe.sh`:

```bash
#!/bin/bash
# usage: ./jsf-probe.sh <domain> <ASC|DESC> <referer-url>
d="$1"; ord="$2"; ref="$3"
curl -s -X POST "https://$d/wp-admin/admin-ajax.php" \
  -H "Referer: $ref" -H "X-Requested-With: XMLHttpRequest" \
  --data-urlencode "action=jet_smart_filters" \
  --data-urlencode "provider=etch-loop/default" \
  --data-urlencode "query[_sort_standard]={\"orderby\":\"meta_value_num\",\"order\":\"$ord\",\"meta_key\":\"price_sale_gross\"}" \
  --data-urlencode "defaults[post_type][]=ad-listing" \
  --data-urlencode "defaults[posts_per_page]=24" \
  --data-urlencode "defaults[post_status][]=publish" \
  --data-urlencode "defaults[tax_query][0][_id]=652052" \
  --data-urlencode "defaults[tax_query][0][collapsed]=false" \
  --data-urlencode "defaults[tax_query][0][taxonomy]=listing-status" \
  --data-urlencode "defaults[tax_query][0][field]=slug" \
  --data-urlencode "defaults[tax_query][0][terms][]=active" \
  --data-urlencode "defaults[meta_key]=listing_priority" \
  --data-urlencode "defaults[orderby][meta_value_num]=DESC" \
  --data-urlencode "defaults[orderby][date]=DESC" \
  --data-urlencode "defaults[paged]=0" \
  --data-urlencode "props[found_posts]=1016" \
  --data-urlencode "props[max_num_pages]=43" \
  --data-urlencode "props[page]=1"
```

Vyhodnotenie prvého inzerátu:

```bash
./jsf-probe.sh nearcharger.sk ASC https://nearcharger.sk/kupit-ev/ \
  | python3 -c "import sys,json,re;d=json.load(sys.stdin);print(re.findall(r'card__heading\">([^<]*)',d['content'])[0])"
```

### 4.2 Prepínanie ciest

```bash
# → FAST PATH (rozbité): nahrej transient jedným renderom stránky
curl -s -o /dev/null "https://nearcharger.sk/kupit-ev/?nocache=$RANDOM"

# → LOOPBACK (funguje): zahoď transient
wp transient delete --all          # alebo počkaj 1 h na expiráciu
```

Pozor: **akékoľvek načítanie katalógovej stránky (aj tvoje vlastné pri testovaní) prepne systém späť na fast path.** Toto ma pri diagnostike raz oklamalo — SK zrazu prestalo sortovať uprostred testovania, lebo som si stránku sám načítal.

### 4.3 Očakávané hodnoty (produkcia, 2026-08-20)

| sort | prvý inzerát |
|---|---|
| ASC správne | RENAULT Zoe Complete Zen Z.E.50 |
| DESC správne | Rolls-Royce Spectre 2024 |
| nesortované (bug) | Tesla Model Y RWD 2024 |

`found_posts` je 1016 vo všetkých prípadoch, na rozlíšenie ho nepoužívaj.

---

## 5. Mapa kódu

### Rozdvojenie ciest

`includes/class-jsf-provider.php`

| riadok | čo sa deje |
|---|---|
| 87 | `ajax_get_content()` — vstupný bod z JSF `render_content()` |
| 113–125 | referrer + same-origin guard |
| 127–137 | `$forwarded = $_REQUEST`, **`query[...]` sa sploští na top-level** (tu sa `query[_sort_standard]` mení na `_sort_standard`) |
| 178–179 | `$url = add_query_arg( $forwarded, $base_url )` — loopback URL, sorting v nej JE |
| 166–186 | **FAST PATH** — `get_transient( JSF_Bridge::block_cache_key( $referrer_path, $query_id ) )` |
| 254 | `$this->apply_filters_in_request()` — registruje `pre_get_posts` p60 |
| 256–258 | `$in_ajax_render = true; render_block( $direct_cached['block'] )` |
| 299+ | **LOOPBACK** — `wp_remote_get( $url )`, 60 s cache |

### Aplikácia filtrov a sortu

| miesto | čo robí |
|---|---|
| `class-jsf-provider.php:50` | `apply_filters_in_request()` → `add_action( 'pre_get_posts', 'apply_jsf_to_tagged_query', 60 )` |
| `class-jsf-provider.php:54` | `apply_jsf_to_tagged_query()` — guardy: `$in_ajax_render`/`is_admin`, `jet_smart_filters` flag, `strpos($flag,'etch-loop')`, `$this->applied[$flag]` |
| `class-jsf-provider.php:514` | `merge_jsf_into_query()` — `jet_smart_filters()->query->get_query_args()` a `$query->set()` pre každý kľúč |
| `class-jsf-bridge.php:230` | `tag_query_for_jsf()` p50 — nastaví `jet_smart_filters = 'etch-loop/{id}'` |
| `class-je-query-builder-bridge.php:432` | `apply_cmt_redirect_late()` p70 — **prepis `orderby` pre Custom Meta Table polia** |
| `class-je-query-builder-bridge.php:547–598` | vlastný prepis `meta_value_num` → `RAND(t)` placeholder, ktorý `posts_clauses` nahradí za `{cmt_table}.{column}+0` |

### Hook ladder

```
pre_get_posts p40  JE bridge → base args z JE Query Buildera
                              (odtiaľ meta_key=listing_priority a orderby={meta_value_num:DESC,date:DESC})
pre_get_posts p50  tag_query_for_jsf → nastaví jet_smart_filters flag
pre_get_posts p60  apply_jsf_to_tagged_query → merge_jsf_into_query (tu má prísť sorting)
pre_get_posts p70  apply_cmt_redirect_late → CMT prepis orderby
pre_get_posts p70  (nearcharger-core-logic) Nearcharger_Relation_Meta_Filter — cv__ klauzuly
```

---

## 6. Kľúčový kontext o dátach

`price_sale_gross` **nie je vo `wp_postmeta`.** Je v JetEngine Custom Meta Storage tabuľke `{prefix}ad_listing_meta` (na produkcii `wp_tjvo_ad_listing_meta`). To znamená, že `orderby=meta_value_num&meta_key=price_sale_gross` **nefunguje bez CMT prepisu na p70**. Ak sa sorting stratí kdekoľvek pred p70, alebo ak p70 nedostane správne vstupy, výsledok je ORDER BY nad prázdnym `wp_postmeta` JOINom → tichý fallback na default poradie loopu. Presne to vidíme.

Toto je dôvod, prečo **taxonomické filtre na fast path fungujú a sorting nie** — brand/body-type sú taxonómie, tie CMT nepotrebujú.

**Neoverené, over to ako prvé:** fungujú na fast path **meta filtre** nad CMT poľami (cenový range slider, `battery_soh`, `year_manufactured`)? Ak nie, bug je širší než sorting a fix bude iný. Ak áno, chyba je izolovaná na `orderby`/`meta_key` vetve.

---

## 7. Podozriví, zoradení

Na papieri by reťazec p50 → p60 → p70 mal fungovať aj na fast path — všetky tri hooky majú `$in_ajax_render` bypass. Takže niečo z toho v praxi nesedí. Kandidáti:

**A. `merge_jsf_into_query()` orderby vôbec nedostane.**
`get_query_args()` v JSF (`jet-smart-filters/includes/query.php:157`) robí:
```php
if ( $this->is_ajax_filter() && ! empty( $this->_default_query ) ) {
    return array_merge( $this->_default_query, $this->_query );
}
```
`array_merge` — `_query` (sort) má prebiť `_default_query` (defaults z requestu). Over, či `_query` na fast path naozaj obsahuje `orderby`/`order`/`meta_key`, alebo je prázdne. `_query` sa plní v `get_query_from_request()` (query.php:603), ktoré JSF volá v `ajax_apply_filters()` (render.php:315) — teda **pred** tým, než sa vôbec dostaneme do `ajax_get_content()`. Ak sa `_query` medzitým resetuje, je to tu.

**B. `merge_jsf_into_query()` orderby nastaví, ale p70 ho zahodí.**
`apply_cmt_redirect_late()` číta `$query->get('orderby')`, `$query->get('order')`, `$query->get('meta_key')`. Pri `orderby='meta_value_num'` (string) sa normalizuje na `[ 'meta_value_num' => $order_in ]`. Ak `$order_in` je prázdne (JSF ho posiela ako `order`, ale merge ho mohol nastaviť inak), padne to na `'DESC'` default a `meta_key` sa nastaví na `null` (riadok 562) bez toho, aby CMT placeholder korektne vznikol. Skontroluj, či sa `$unset_orders` nastaví na `true` a či `custom_table_query` vznikne.

**C. `_jqbeb_je_query_id` nie je nastavené na fast path.**
`apply_cmt_redirect_late()` na riadku 442 bezpodmienečne bailuje, ak query nemá `_jqbeb_je_query_id`. Toto query var nastavuje JE bridge na p40. Ak p40 na fast path z nejakého dôvodu nenabehne (State_Stack prázdny, `pre_render_block` p4 nezachytil wrapper z cached block tree), CMT prepis sa nikdy nespustí a sorting nad CMT poľom je automaticky mŕtvy — pri zachovaní funkčných taxonomických filtrov, lebo tie idú cez JSF p60 a CMT nepotrebujú. **Toto je môj najsilnejší tip.**

**D. Poradie registrácie p60.**
`apply_filters_in_request()` sa volá na riadku 254, tesne pred `render_block()`. Na loopbacku ho volá JSF cez `apply_filters_from_request()` už pri `parse_request`. Ak Etch loop stihne inštanciovať WP_Query skôr, než sa hook zaregistruje, p60 nikdy nenabehne. Vzhľadom na to, že stránkovanie a filtre fungujú, toto je málo pravdepodobné, ale over to.

---

## 8. Inštrumentácia (plugin ju už má)

Netreba nič dopisovať. V `wp-config.php`:

```php
define( 'JQBEB_DEBUG_PAGINATION', true );
```

Debug buffer sa na AJAX ceste pripojí do odpovede ako `_jqbeb_debug` cez filter `jet-smart-filters/render/ajax/data`, takže sa dá čítať priamo z curlu:

```bash
./jsf-probe.sh nearcharger.sk ASC https://nearcharger.sk/kupit-ev/ \
  | python3 -m json.tool | grep -A 40 _jqbeb_debug
```

Relevantné existujúce log pointy:
- `ajax_get_content ENTRY` → `request_query_keys` (vidí PHP `_sort_standard`?)
- `merge_jsf_into_query JSF_ARGS` → `jsf_keys` (je `orderby` medzi nimi?)
- `merge_jsf_into_query AFTER` → `query_orderby` (dostalo sa to do WP_Query?)

**Čo chýba a treba dopísať** (aspoň dočasne):
- log v `apply_cmt_redirect_late()` — či prešlo cez guardy, hodnota `_jqbeb_je_query_id`
- log v `apply_cmt_redirect()` — `$orderby_in`, `$meta_key_in`, `$unset_orders`, výsledné `custom_table_query`
- log vo `fast path` vs `loopback` vetve, aby bolo v odpovedi vidno, ktorá cesta bežala

Poznámka z docblocku `class-debug.php`: loopback cesta sa do bufferu nedostane (beží v inom PHP procese). Na porovnanie ciest potrebuješ log priamo do `error_log`, nie do bufferu.

---

## 9. Akceptačné kritériá

Fix je hotový, keď platí celá matica **s nahriatym transientom** (teda na fast path):

| doména | sort | očakávaný prvý inzerát |
|---|---|---|
| nearcharger.sk | ASC | RENAULT Zoe Complete Zen Z.E.50 |
| nearcharger.sk | DESC | Rolls-Royce Spectre 2024 |
| nearcharger.cz | ASC | RENAULT Zoe Complete Zen Z.E.50 |
| nearcharger.cz | DESC | Rolls-Royce Spectre 2024 |

Plus regresné testy, ktoré sa nesmú pokaziť:

1. **Taxonomické filtre** (brand, body-type, ad-listing-type) na fast path
2. **Meta/range filtre nad CMT poľami** (cena, SoH, rok, dojazd) na fast path
3. **Stránkovanie** — strana 2 a 3, s aktívnym filtrom aj bez neho
4. **Kombinácia filter + sorting naraz**
5. **Loopback cesta** (po `wp transient delete --all`) — všetko vyššie musí fungovať aj tam
6. **Indexer counts** pri filtroch (JSF Filter Indexer) — `tag_query_for_jsf` registruje base query pre indexer, pri zásahu do p50 to skontroluj
7. **`cv__` relation filtre** z `nearcharger-core-logic` (p70) — nesmú kolidovať s CMT prepisom

---

## 10. Čo NErobiť

- **Nerieš mu-plugin obchádzku.** Zvažovalo sa zabíjanie `jqbeb_block_*` transientu pri sortovacom requeste, aby sa vynútil loopback. Zámerne zamietnuté, fix má byť v plugine.
- **Nevypínaj fast path celkovo.** Je to jeho hlavná value proposition (10–50× rýchlejšie než loopback), regresia na výkone by bola citeľná.
- **Neriešime TranslatePress.** Áno, na CZ doméne prepisuje AJAX JSON (pretty-print + odstránené HTML komentáre), je to jeho feature „preklad AJAX obsahu". So sortingom to nesúvisí, overené.
- **Neriešime LiteSpeed JS combine.** Request sa odosielal správne po celý čas, payload je na oboch doménach byte-identický.
- **Nemeň JSF sorting widget config.** `ORDER BY: Meta key numeric` + `META KEY: price_sale_gross` je správne. Platforma používa jeden kurz, takže poradie podľa EUR aj CZK je identické, `price_sale_gross_czk` netreba.
- **Nemeň `defaults[meta_key]=listing_priority`** v Etch loope. Bola to podozrivá stopa, ale nie je príčina — SK sortuje s presne rovnakými defaults.

---

## 11. Prostredie

**SSH:** `ssh nearcharger` (ssh.nearcharger.sk, port 222). Jeden server pre prod aj staging.

| prostredie | cesta |
|---|---|
| produkcia | `/var/www873/p61521/nearcharger.sk/web` |
| staging | `/var/www873/p61521/nearcharger.sk/sub/staging` (HTTP basic auth) |

**Katalógové stránky:** `https://nearcharger.sk/kupit-ev/` a `https://nearcharger.cz/koupit-ev/` (page ID 9038, TRP multi-domain, jeden a ten istý post).

- **DB prefix je `wp_tjvo_`** na prod aj lokále. Nikdy nehardkóduj `wp_`.
- Deploy lokál → staging: `bash ~/Local\ Sites/nearcharger-local/local-to-staging.sh bridge`
- LiteSpeed: `wp litespeed-purge all` funguje na prod, na stagingu vráti 401. Po purge chvíľu 404-ujú optimalizované assety.
- **NEMAZAŤ** `wp-content/litespeed/` (1,2 GB).
- `chrome-devtools` MCP nefunguje, Chrome nie je nainštalovaný. Diagnostika ide cez curl.
- Lokálny web: Local by Flywheel (`~/Local Sites/nearcharger-local`). Preferuj lokálnu reprodukciu — produkcia je živý web.

---

## 12. Release

Podľa konvencie repa (`CLAUDE.md`, `CHANGELOG.md`):

```
fix: v1.3.2 - JSF sorting sa stráca na AJAX fast path
```

Vetva `fix/sorting-lost-on-fastpath`, PR do `main`, nikdy commit priamo do `main`.

Do CHANGELOGu patrí vysvetlenie **prečo to vyzeralo ako doménový problém** — bez toho to o pol roka niekto (pravdepodobne ja) bude znova hľadať v TranslatePresse.
