# CLAUDE.md

Guidance for AI coding agents (Claude Code, Codex) working in this repository. `AGENTS.md` is a symlink to this file; edit this file only. Deep dives live in `docs/` and are listed under "Read before editing" below; load them only when the task touches that area.

## What this plugin does

Three independent bridges that drive Etch's native Query Loop block from external query systems, plus a small per-block context sync:

1. **JSF bridge** - registers an `Etch Loop` content provider for JetSmartFilters. Filter / pagination / sort blocks can drive any Etch loop (initial-load + AJAX), with multi-loop support via `jsf-etch-q-{slug}` classes, an indexer for per-option counts, and a `[jsf_etch_count]` shortcode.
2. **JE Query Builder bridge** - lets a JetEngine Query Builder query become the data source for an Etch loop. Query types: `posts`, `users`, `terms`, `Merged_Query` (base types posts / users / terms), `SQL_Query` (target type from `cast_object_to` or `je-as-{type}` wrapper hint), `Data_Stores_Query` (target type from the store's post-vs-user setting).
3. **JE loop-context bridge** (v1.2.0+) - `pre_render_block` / `render_block` pair that makes JE blocks resolving their post via `jet_engine()->listings->data->get_current_object()` see the Etch loop card instead of the host page. Default scope: `jet-engine/data-store-button`.

Each bridge runs only if its target plugin is active. Etch is the only hard dependency for any of them to do anything useful.

## Read before editing

| Touching | Read first |
|---|---|
| JSF AJAX render, loopback, transient cache, main-query context restore, TranslatePress, indexer default queries, range filters | [`docs/jsf-ajax-path.md`](docs/jsf-ajax-path.md) |
| JE Query Builder dispatch (pagination order, Merged / SQL / Data Store, re-entrancy guard, CMT redirect, CMT indexer) or anything relying on Etch block / loop-handler internals | [`docs/etch-je-internals.md`](docs/etch-je-internals.md) |
| JE loop-context bridge, `jqbeb_loop_context_block_names` | [`docs/je-loop-context-bridge.md`](docs/je-loop-context-bridge.md) |
| Release, tag, ZIP | project skill `release` in `.claude/skills/release/` (Codex: `.agents/skills/release`) |

## Architecture essentials

### Bridge instantiation timing

`Plugin::boot()` runs at `plugins_loaded` (default priority). At that point all plugin main files are included, but JE component classes are NOT loaded yet (they appear at `init -1`).

- **JSF bridge** is instantiated immediately at `plugins_loaded`. JSF fires `jet-smart-filters/providers/register` at **`init` priority `-998`**; if `JSF_Bridge` were not ready before that, the `Etch Loop` provider would never register.
- **JE bridge** is deferred to `init` priority `0` via `Plugin::maybe_boot_je_bridge()`. JetEngine registers `\Jet_Engine\Query_Builder\Manager` at `init -1`, so `class_exists()` is false at `plugins_loaded` and booting there silently no-ops (the v0.6.0 bug). All its hooks fire well after `init`.
- **JE loop-context bridge** is instantiated at `plugins_loaded`, gated on `function_exists( 'jet_engine' )`. Its hooks fire on block render (post-`init`), when `jet_engine()->listings->data` is ready. It does not depend on JE Query Builder, so no `init` deferral.

### State_Stack pattern

Both query bridges use their own `State_Stack` instance (`includes/class-state-stack.php`) keyed by Etch wrapper class:

1. `pre_render_block` (p4 / p5) pushes the wrapper's `query_id`.
2. The matching pre-query hook (`pre_get_posts` / `pre_user_query` / `pre_get_terms`) reads the top, mutates the query, pops.
3. `render_block` p999 is a safety-net pop if no matching hook fired (wrapper without inner loop, or JE query type mismatched the Etch preset type).

Independent stacks mean no cross-contamination when both classes sit on the same wrapper.

### JSF AJAX in one paragraph

JSF AJAX does not loop back over HTTP by default: the wrapper block tree is cached in a transient at page render and re-rendered in-process via `render_block()` (`JSF_Bridge::$in_ajax_render` lets the hooks run during AJAX). The main-query state (`is_*` flags, `query_vars`, `queried_object`) is snapshotted at render and swapped back into `$wp_query` / `$wp_the_query` around the AJAX render (v1.3.0+). On cache miss it falls back to the HTTP loopback. Details and traps: `docs/jsf-ajax-path.md`.

### Hook priority ladder (when both bridges are active on the same wrapper)

```
pre_render_block  p4     JE bridge captures je-etch-loop wrapper
pre_render_block  p5     JSF bridge captures jsf-etch-loop wrapper;
                          JE loop-context bridge stashes + sets
                          jet_engine()->listings->data->current_object
                          when rendering a targeted block (default:
                          jet-engine/data-store-button) inside an
                          Etch loop iteration
pre_get_posts     p40    JE wholesale-replaces WP_Query args
pre_get_posts     p50    JSF tags the query (jet_smart_filters = etch-loop/{id})
                          and stores the provider default query for the indexer
pre_get_posts     p60    JSF merges filter args on top of JE base
pre_get_posts     p70    JE bridge CMT redirect (splits the merged meta_query
                          and orderby - sees JSF filter additions because it
                          fires after p60). Acts on queries marked with
                          _jqbeb_je_query_id or the etch-loop/ JSF provider.
pre_user_query    p10    JE bridge (Users base type)
pre_get_terms     p10    JE bridge (Terms base type)
render_block      p5     JE loop-context bridge restores the stashed
                          current_object (paired with the p5 stash above)
render_block      p999   safety-net pop for both bridges
wp_footer         p5     JSF bridge outputs window.JQBEBData (BEFORE wp_print_footer_scripts at p20)
```

### Rules that bite most often (full list in `docs/etch-je-internals.md`)

- `etch/element` stores classes only in `attrs.attributes.class`. No `attrs.className` fallback.
- Etch terms / users loop handlers need full `WP_Term` / `WP_User` objects; the bridge forces `fields = 'all'`.
- JE pagination: call `get_query_args()` once BEFORE `set_filtered_prop( '_page', N )`, otherwise `final_query` autovivifies and loses post_type / meta_query / orderby.
- Check `instanceof Merged_Query` FIRST; it reports its base type and looks like a Posts query.
- Never call `Data_Stores_Query::get_query_type()` for type detection (materialises the inner query).
- Keep the `in_extraction` re-entrancy guard in `try/finally` around any JE call that may instantiate `WP_*_Query`.
- CMT table names come from `Manager::get_db_instance(...)->table()`, never `Manager::get_table_name()`.
- Native Etch CMT late sorting (v1.3.3): the p70 redirect accepts `_jqbeb_je_query_id` or the exact JSF provider prefix `etch-loop/`. Preserve an existing same-table `custom_table_query.query`, intersect new restrictions with `AND`, keep the previous order mapping unless a new CMT sort replaces it. Never broaden the guard to every JSF provider. Regression: `php tests/cmt-late-scope.php`.
- Any site flag that scopes the loop in its own `pre_get_posts` must be added via `jqbeb_jsf_default_query_keys`, or AJAX indexer counts run without it (nearcharger-core-logic adds `nc_light`).

## Wrapper class conventions

| Class | Required for |
|---|---|
| `jsf-etch-loop` | JSF bridge - marks the immediate parent of loop cards (pagination/sort blocks must be OUTSIDE) |
| `jsf-etch-q-{slug}` | JSF bridge - disambiguates multi-loop pages; matches JSF block's "Query ID" setting |
| `je-etch-loop` | JE bridge - marks any Etch loop wrapper to use a JE query as data source |
| `je-q-{id}` | JE bridge - numeric JE query ID OR custom query_id slug |
| `je-as-{posts\|users\|terms}` | JE bridge - explicit target type override for SQL queries (also overrides a wrong `cast_object_to` inference for any JE query) |
| `je-jsf-stack` | JE bridge - opt-in JSF compatibility for Merged / SQL: fetches the FULL JE result set (overrides `max_items_per_page` / `limit_per_page` / `limit` / `_page` to 0/1) and does NOT force-disable WP_Query pagination flags. Required for JSF filters / pagination / `[jsf_etch_count]` on Merged / SQL Posts loops. Combine with `jsf-etch-loop`. |
| `jsf-etch-empty-state` | Etch element shown (`is-active`) when the paired loop is empty; pair via `data-for-query-id="<slug>"` on multi-loop pages, otherwise nearest `.jsf-etch-loop` ancestor |

Both bridges can coexist on the same wrapper. Class extraction reads only `attrs.attributes.class`.

## File map

```
jsf-query-builder-etch-bridge.php       Plugin header + constants + bootstrap
includes/
  class-plugin.php                      DI bootstrap, dependency probes, conditional bridge loading
  class-state-stack.php                 push/pop helper used by both bridges
  class-jsf-bridge.php                  JSF integration: wrapper capture, block cache, context snapshot, indexer, range filters
  class-jsf-provider.php                Jet_Smart_Filters_Provider_Base subclass, AJAX fast path + loopback fallback
  class-je-query-builder-bridge.php     JE type dispatch (Posts / Users / Terms / Merged / SQL / Data Stores) + CMT redirect
  class-je-loop-context-bridge.php      per-block current_object sync to the topmost Etch loop entry
  class-shortcode.php                   [jsf_etch_count] shortcode
  class-admin-page.php                  Settings → JSF Etch Bridge (English docs, conditional sections)
  class-debug.php                       pagination diagnostics to the browser console, off unless define( 'JQBEB_DEBUG_PAGINATION', true )
assets/js/count.js                      [jsf_etch_count] live updater (JSF event bus)
assets/js/range-fill.js                 page-load fill for JSF Range filter text inputs (JSF 3.8.0.1+ async dynamic range, legacy 3.7.x path kept)
assets/js/empty-state.js                toggles is-empty on .jsf-etch-loop and is-active on paired .jsf-etch-empty-state elements
assets/js/debug.js                      console consumer for class-debug.php buffers (page load + JSF AJAX)
bin/build-release-zip.sh                whitelisted release ZIP (plugin file, readme.txt, includes, assets only)
tests/                                  standalone PHP regressions, run with `php tests/<name>.php`
docs/                                   agent deep dives (not shipped in the ZIP)
```

## Filterable behaviour

- `jqbeb_loopback_sslverify` (default `false`) - set `true` on production for SSL verification on the JSF AJAX self-loopback.
- `jqbeb_loopback_cache_enabled` (`true`, `$cache_user_id`) - disable the 60-second per-user rendered-HTML loopback cache on sites with anonymous personalized content (cart, geo, A/B).
- `jqbeb_jsf_default_query_keys` (v1.3.4+) - allowlist of `query_vars` stored as the JSF provider default query for the indexer.
- `jqbeb_range_cmt_override_enabled` (`true`, `$args`, `$instance`) - opt out of the v1.0.2 CMT-aware Range filter min/max recompute.
- `jqbeb_loop_context_block_names` (`['jet-engine/data-store-button']`) - blocks whose render gets the JE `current_object` sync. Never add Etch's own dynamic blocks or JE Dynamic Field / Image / Link.
- `jqbeb_empty_results_payload` (`'<!--jqbeb:empty-results-->'`, `$inner`) - sentinel for zero-result AJAX renders; JSF treats `''` as "no update", so the sentinel forces the wrapper to clear.
- `jqbeb_empty_state_default_hide_enabled` (`true`) - disable the injected CSS that hides `.jsf-etch-empty-state:not(.is-active)` and ship your own.
- `jqbeb_count_late_substitution_enabled` (`true`) - disable the output buffer that fills in `[jsf_etch_count]` values late on the front end.

## Limitations to remember

- **JE Repeater / Comments / Current_WP_Query types are NOT supported.** Etch has no compatible loop handler; it would need an Etch core change (filter on `LoopHandlerManager::get_loop_preset_data()`). Don't reflect / monkey-patch.
- **JSF integration is Posts-only.** Users / Terms loops would need a `Jet_Smart_Filters_Provider_Base` subclass per type.
- **JSF + Merged / SQL / Data Store works ONLY in `je-jsf-stack` mode.** Cost: full JE fetch on every render.
- **SQL queries must return a recognisable ID column** (`ID` / `id` / `post_id` / `user_id` / `term_id`); other rows are silently skipped.
- **Only `loopId`-mode Etch loops are bridged.** `target` / expression mode bypasses `WP_Query`.
- **Indexer counts skip range filters**; only `tax_query` and `meta_query`. CMT is supported (v0.7.0+), CCT is not.
- **CMT redirect is Posts-only** (JE core registers Custom_Tables handlers only for `object_type='post'`).
- **Loop-context bridge** ignores non-default `object_context` on the Data Store Button and does not cover the shortcode form.

## Security stance

- **Browser-supplied JSF `defaults` are never trusted (1.3.5+).** For the `etch-loop` provider `discard_client_defaults()` empties them; the baseline is what the server captured while rendering the loop (`remember_defaults()` at `pre_get_posts` p50, or the HMAC-signed payload from the loopback), and only whitelisted filter args (`allowed_filter_args()`: meta/tax/date query, search, sort, paged, geo, alphabet) come from the request. Auxiliary queries (indexer counts, dynamic range) without a rendered baseline fail closed to `post__in => [0]`. Anything that can widen post status, post type or the ID scope must come from the server. Regression: `php tests/ajax-query-security.php`.
- Loopback AJAX forwards all cookies via `wp_remote_get()` so authenticated content resolves. SSL verification is off by default (local-dev compat) but filterable.
- `<!--JQBEB-PROPS:...-->` markers are stripped from the AJAX response before send; they only carry parent → loopback props.

## Versioning workflow

SemVer post-1.0: patch `fix:`, minor `feat:`, major documents migration in CHANGELOG and readme.txt. When bumping, update **all five** in the same commit:

1. Plugin header `Version:` in `jsf-query-builder-etch-bridge.php`
2. `JQBEB_VERSION` constant in the same file (also cache-busts `assets/js/*`)
3. `readme.txt` `Stable tag:` and `== Changelog ==` entry
4. `CHANGELOG.md` (Keep a Changelog format)
5. Tag `git tag v{x.y.z}` and push (`git push origin main v{x.y.z}`)

The full release procedure (tests, tag, whitelisted ZIP via `bin/build-release-zip.sh`, GitHub Release) is the project skill `release`. The ZIP is the only way the plugin reaches a site, so never zip the working copy by hand.

## Common commands

```bash
# Lint all PHP files (does not require WP)
for f in jsf-query-builder-etch-bridge.php includes/*.php; do php -l "$f"; done

# Standalone regressions
for t in tests/*.php; do php "$t"; done

# Which JSF AJAX path did a request take? Present = fast path, absent = loopback
wp transient get jqbeb_block_$(php -r 'echo md5("/your-path/|default");')
```
