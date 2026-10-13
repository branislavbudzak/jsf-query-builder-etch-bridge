# JSF AJAX path, indexer and range filters

Deep dive moved out of `CLAUDE.md`. Read before touching `class-jsf-bridge.php` / `class-jsf-provider.php` AJAX, loopback, indexer or range-filter code.

## Direct in-process render (v0.10.0+)

JSF AJAX (filter / pagination / sort clicks) does NOT do an HTTP loopback to the original page URL. Instead, the bridge caches the parsed `etch/element` wrapper block tree in a transient at page render time (`JSF_Bridge::on_render_block` → `set_transient( JSF_Bridge::block_cache_key( $url_path, $query_id ), [block, post_id, context], HOUR_IN_SECONDS )`, key format in "Pages whose main query depends on URL vars" below), and `JSF_Provider::ajax_get_content` retrieves it and renders it in-process via `render_block()`. Mirrors what JetEngine's listing-grid provider does (`get_render_instance('listing-grid', $attrs)->render()`).

Critical mechanism: all bridge hooks (`pre_render_block`, `pre_get_posts`, `render_block`, JE bridge equivalents) bail on `wp_doing_ajax() || is_admin()` BY DEFAULT. During in-process AJAX render they must fire normally (so the inner WP_Query gets re-tagged and JSF's provider hook can re-apply paged + filter args). The bypass is the static flag `JSF_Bridge::$in_ajax_render` - set true around the `render_block()` call, hooks check `! self::$in_ajax_render && ( wp_doing_ajax() || is_admin() )` to early-return. JE_Bridge reads the same flag (both classes are in the `JQBEB` namespace, so unqualified reference resolves).

## Main-query context restoration (v1.3.0+)

the AJAX fast path runs inside `admin-ajax.php` where the global `$wp_query` is the admin-ajax catch-all - no `is_tax()` / `is_post_type_archive()` flags, no `queried_object`, no archive-related `query_vars`. Pre-1.3.0 this meant any JE Query Builder query whose Dynamic Args read `get_queried_object()` / `is_*()` (e.g. a brand archive query bound to "current queried term") silently collapsed to "no filter" on AJAX and returned the full unfiltered universe - page 1 showed N brand-scoped listings, page 2 click returned page 2 of the full unfiltered set (much larger), so the user saw unrelated items in place of the next page. Fix: `JSF_Bridge::on_render_block` snapshots the main-query state into a `context` field (conditional-tag flags allowlist + `query_vars` allowlist + `queried_object` descriptor `{ type, id, taxonomy? }`), and `JSF_Provider::ajax_get_content` rebuilds a synthetic `WP_Query` via `JSF_Bridge::build_context_query()` and swaps it into BOTH `$GLOBALS['wp_query']` and `$wp_the_query` around the `render_block()` call. Globals are snapshotted before swap and restored in `finally`. The fake `WP_Query` is constructed via `new WP_Query()` (no args → skips `query()` → no SQL fires); `is_*` properties are set directly on the object, `query_vars` via `$query->set()`, `queried_object` resolved fresh via `get_post()` / `get_term()` / `get_user_by()` (so a stale cache after a term rename picks up new data). The 1.2.x transient shape without `context` is honoured via the legacy singular-only `post_id` / `setup_postdata` path. Covers archive, taxonomy, CPT-archive, AND singular pages where JE Dynamic Args read `get_queried_object()`. The fake-query swap does NOT make `is_main_query()` on the loop's inner WP_Query return true - that WP_Query is a fresh `new WP_Query` instance from Etch's loop handler, not the global, regardless of which object sits in `$GLOBALS['wp_query']`.

## Cache miss fallback

if the transient is gone (expired, never rendered for this URL+query_id, or extract failed), the provider falls through to the original HTTP loopback path (`wp_remote_get`). Same code as before - no regression for any working path. The loopback path naturally has correct `$wp_query` state because WP routes the request through `parse_request` → `query_posts` on the loopback URL.

## Loopback request slashing (v1.3.2)

`ajax_get_content` builds the loopback URL from `$_REQUEST`, which WP has already slash-escaped via `wp_magic_quotes()`. It MUST be run through `wp_unslash()` first, otherwise the loopback request slashes it a second time and every JSON-valued JSF query var (`_sort_*` is a JSON string, `_date_query` likewise) fails `json_decode( wp_unslash( … ) )` in JSF's parser, which then silently `continue`s past the clause. Symptom: filters work, sorting does not, HTTP 200, no log line anywhere. The values also need `urlencode_deep()` because `add_query_arg()` does NOT encode the args it is given (`build_query()` runs with `$urlencode = false`), so a bare `&` or `#` in a search term truncates or splits the query string.

## Cache key vs TranslatePress (v1.3.2)

`JSF_Bridge::current_path()` keys the transient on the path the BROWSER sees, because the read side looks it up by `wp_get_referer()`. TranslatePress SEO Pack's `Slug_Manager::translate_request_uri()` (`plugins_loaded` p3) overwrites `$_SERVER['REQUEST_URI']` with the default-language slug and stashes the real one in the global `$TRP_ORIGINAL_REQUEST_URI`, so `current_path()` prefers that global when set. Without it, every translated URL writes under the default-language key (clobbering it) and misses its own lookup forever, pinning that language to the slow loopback. Any other plugin that rewrites `REQUEST_URI` before block render will need the same treatment.

## Pages whose main query depends on URL vars use the loopback (v1.3.8)

The snapshot is keyed by path + query id and shared by every visitor of that path. Every search runs on `/` (`/?s=…&post_type=product`), so before 1.3.8 a filter click could rebuild the main query with the `s` of whoever last rendered a search (reproduced on Poctivá poživeň staging: "syr" became "med" results). Reconstructing such a request from the referrer was tried and dropped in review: WP derives more than the raw vars (`author__in`, `taxonomy`/`term`, the queried object, private-taxonomy and post type checks, CPT aliases like `?product=slug`, arrays), and a partial copy leaks or widens.

Rule now: `JSF_Bridge::main_query_url_args()` returns the WordPress public query vars (`$wp->public_query_vars`, minus `paged` / `page`) present in GET / POST. If the rendering request has any, no snapshot is written; if the AJAX referrer has any, the fast path is skipped. That AJAX render goes through the HTTP loopback, a real request for the visitor's own URL with their cookies, so WordPress and every plugin parse and prepare the main query exactly as on page load. JSF's own URL params (`jsf`, `tax`, `meta`, `sort`, `pagenum`…) are not public vars, so archives with filters in the URL keep the fast path. The loopback adds the referrer's public vars to its URL (an admin-ajax request does not carry them) and drops JSF 3.8.6's AJAX transport keys (`jsf_ajax`, `settings`, `query_context`, `indexing_filters`, `dynamic_range`, `referrer`): with `jsf_ajax=1` WordPress answered the loopback with an empty 302, so every cache miss on JSF 3.8.6 rendered nothing.

The same check runs on both sides (`JSF_Bridge::fast_path_allowed()`): at render on the page's GET / POST, at AJAX time on the referrer plus the AJAX request's own GET / POST. The filter `jqbeb_fast_path_enabled` ( `$allowed`, `$url_args` ) can turn the fast path off for more pages, and must for any page whose main query is personalized for anonymous visitors (cart, geo, A/B in `pre_get_posts`): the snapshot is shared, and the public-var check is not a general cross-visitor isolation.

Key since 1.3.8: `jqbeb_ctx2_` + md5( path | query id | u=<user id> ). The new prefix makes 1.3.7 entries (`jqbeb_block_*`, which may hold another visitor's search) unreadable right after the update. Logged-in visitors get their own entry, because a main-query hook may scope by the current user (author, private status) and the rebuilt query never re-runs it. Anonymous visitors share one entry per path and query id.

JSF 3.8.6 sends its AJAX request to the page URL (`referrer_url`), not to admin-ajax, so `wp_doing_ajax()` is false there. Code that must skip the AJAX render checks `JSF_Bridge::$in_ajax_render`.

## Contract for plugins that prepare the main query (v1.3.8)

A plugin that prepares a search or other URL-driven main query in `pre_get_posts` (Poctivá poživeň's search sets JetSearch's marker `_poziven_jetsearch` and the accepted `s`) needs nothing bridge-specific for results: the AJAX render of that page IS a normal front-end request (the loopback). What to know:

- The loopback carries `X-JQBEB-Loopback: 1` and `X-JQBEB-Loopback-Signature` (HMAC-signed timestamp with `wp_salt( 'auth' )`, valid 2 minutes; not the URI, which Cloudflare / the server normalize, `%2A` arrives as `*`). `JSF_Bridge::is_verified_loopback()` checks both. The header alone is spoofable; only the verified check may relax anything.
- It comes from the server's own IP. A per-IP rate limit should not count verified loopbacks as visitor searches (the visitor's AJAX request itself is the event to count).
- Rendered output is cached for 60 s per URL and user in `jqbeb_lb_*` (`jqbeb_loopback_cache_enabled` to disable).
- Indexer counts: `s` is a default query key since 1.3.8. A marker that switches on the plugin's own matching must be added via `jqbeb_jsf_default_query_keys`, or counts use plain WP search.

## Main-query loops on archives (v1.3.8)

Etch's `WpMainQueryLoopHandler` builds its WP_Query from `$wp_query->query_vars` plus the preset args. On a Woo archive those vars carry `jet_smart_filters = woocommerce-archive`, `post_type = ''` and the term only as `product_cat => slug`.

- `tag_query_for_jsf()` replaces any inherited `jet_smart_filters` that does not start with `etch-loop/`.
- `add_taxonomy_scope()`: when taxonomy query vars add clauses (WP's own `parse_tax_query()` on a probe), the vars themselves (`product_cat`, `taxonomy` + `term`, `cat`, `tag`…) are copied into the defaults, NOT translated into a tax_query. WP appends their clauses under the explicit tax_query's relation after JSF merged its filters into it; a pre-translated tax_query gave `(X OR A) AND B` for the counts while the loop ran `X AND B AND A` (review round 2, `tests/tax-scope-real-parser.php` checks both under the real WP parser). An empty `post_type` is resolved with the rule of `WP_Query::get_posts()`; category / tag only keeps WP's default post type.
- `capture_render_context()` keeps taxonomy query vars, so the fast-path render of a term archive path stays in the term.
- `Relation_Filters` treats `''` as "post type not set".

## Load more (v1.3.8)

JSF appends on load more by finding `providerSelectorData.list || selector` (`.jsf-etch-loop`) INSIDE the response and taking its children. The bridge answers with the wrapper's inner HTML, which only fits `insert`. A request with `props.pages` (JSF sets it only on load more and deletes it on a filter change or a normal page switch; JetEngine's listing grid reads the same signal) gets the inner HTML wrapped in `<div class="jsf-etch-loop">`. Keep JSF pagination blocks outside the wrapper: rendered inside, JSF 3.8.6 registers only the pagination rule during the AJAX render, returns a `query_context` with just `jet_paged`, and the next filter request fails with "Request data is incorrect."

## Page cap (v1.3.8)

`jet_paged` / `pagenum` / `paged` are capped by `JSF_Bridge::clamp_paged()` (`jqbeb_max_paged`, default 1000) in `ajax_get_content`, `merge_jsf_into_query` and `merge_filter_args`.

## Which path did a request take?

`wp transient get jqbeb_ctx2_$(php -r 'echo md5("/your-path/|default|u=0");')` (anonymous; `u=<id>` for a logged-in user). A page with public query vars in its URL (search) always takes the loopback. Present means fast path, absent means loopback. Loading the page yourself warms it, so a single manual page view flips the system to the fast path mid-test.

## Cache invalidation

1-hour TTL. Block edits in the editor are not auto-flushed; manual `wp transient delete --all` or wait 1h. Production-tier cache strategy if needed: hash `etch_loops` option into the cache key. Terms / posts referenced by `context.queried_object` are resolved at restore time (not snapshot time), so renames pick up automatically without cache flush.

## JSF provider default-query registration (v1.3.4+)

The Filter Indexer is **gated by `jet_smart_filters()->query->get_default_queries()`** - JSF iterates that array in `Indexer_Data::prepare_localized_data` and SKIPS providers without an entry. JS receives no `jetFiltersIndexedData` for skipped providers, and AJAX filter changes send empty `query_args` to the indexer endpoint (it counts against an empty post_type → wrong / zero counts). All built-in JSF providers register themselves at render time via `jet_smart_filters()->query->store_provider_default_query( $provider_id, $query_args, $query_id )`. Our bridge calls this from `JSF_Bridge::tag_query_for_jsf()` at `pre_get_posts` priority 50 - after JE bridge p40 has applied its base args, before JSF's filter merge at p60 - passing an allowlisted subset of `$query->query_vars` (post_type, post_status, posts_per_page, meta_query, tax_query, date_query, orderby, order, meta_key, post__in, post__not_in, paged), extendable via the `jqbeb_jsf_default_query_keys` filter (v1.3.4+). A site flag that scopes the loop in its own `pre_get_posts` MUST be added there, otherwise AJAX indexer counts run without it (nearcharger-core-logic adds `nc_light`). Storing the full query_vars would also work but bloats the localized JS payload with internal WP_Query defaults (error, m, p, attachment_id, etc.). The CMT split has not yet run at p50, so the stored meta_query is still in raw JE form; that's intentional - the indexer's `count_query` instantiates a fresh `WP_Query` which re-fires JE's pre_get_posts splitter at p10 inside that fresh query, so the CMT JOIN still gets emitted there.

## Range filters

- **Range filter live recalc on AJAX filter changes (v1.0.3+).** JSF 3.8.0+ pushes `dynamic_range[<bucket>][]=<query_var>` per pending range filter into every AJAX request and reads `response.dynamic_range[<query_var>] = { min, max }` from the provider's reply to call `updateRangeBounds()`. Bridge hooks `jet-smart-filters/render/ajax/data` (`add_dynamic_range_to_ajax_response`) and per-var:
  1. Builds the current filter context as a WP_Query - base from the trusted server defaults captured during render (`JSF_Bridge::trusted_defaults()`, since 1.3.5; before that `$_REQUEST['defaults']`), JSF clauses from `$_REQUEST['query']`, **excluding the var's own `_meta_query_<var>` / `_meta_query_<var>|<suffix>` clause** (otherwise the slider would collapse to the user's current selection and become impossible to widen).
  2. Runs the query for IDs only.
  3. Feeds them as `t.object_ID IN (…)` into `compute_cmt_range_min_max($targets, $ids)` (the v1.0.2 helper, public, with the new optional second arg).
  4. Injects `dynamic_range[<var>] = { min, max }` into the response.
  Var list is read from **`php://input`** because JSF's bucket-key shape (`dynamic_range[[object Object],apply_min_max_callback][]=…`) collapses under PHP's `parse_str` - the literal `[`/`]`/`,` inside the key truncates everything to a single `array[ '[object Object' => '<last var>' ]` and the other vars are silently lost. Walking the raw body recovers all of them. The "current context" args are sourced via JSF's own `get_query_from_request()` (with `$_REQUEST['query']` temporarily mutated to drop self-var keys, then restored) and merged onto the trusted defaults via `JSF_Bridge::merge_filter_args()` (AND of server and filter groups) - JSF's own `get_query_args()` does only a shallow `array_merge` that would drop the JE-base tax_query / meta_query.
- **Range filter dynamic min/max IS CMT-aware (v1.0.2+).** Hooks `jet-smart-filters/filter-instance/args` priority 20. Accepts two `_source_callback` values:
  1. **`jet_smart_filters_meta_values`** - JSF's built-in "Get from Post Meta by query meta key". Queries `wp_postmeta` and returns NULL for CMT fields → JSF falls back to defaults. Bridge detects CMT field membership across all `Manager::$storages` (any storage matched).
  2. **`jet_engine_custom_storage_post_{slug}`** - JE-NATIVE per-CMT callback registered by `\Jet_Engine\CPT\Custom_Tables\Query::register_range_min_max_callback`. UI label is "{Post Type}: Get from custom storage by query meta key". JE's own callback (custom-tables/query.php:73) queries the right table BUT can return `[ 'min' => null, 'max' => null ]` when the SQL has no rows or all-NULL aggregates; JSF then drops to manual fallback because `isset($data['min'])` is FALSE on NULL. Bridge pins the lookup to the storage slug encoded in the callback name.
  Bridge SQL: `SELECT MIN(FLOOR(t.{col})), MAX(CEILING(t.{col})) FROM {cmt_table} t INNER JOIN wp_posts p ON p.ID = t.object_ID WHERE t.{col} IS NOT NULL AND t.{col} <> '' AND p.post_type = %s AND p.post_status IN (%s,...)`. Statuses come from `apply_filters('jet-smart-filters/dynamic-min-max/search-statuses', ['publish'])`. Step-rounding mirrors `Jet_Smart_Filters_Range_Filter::max_value_for_current_step`. Per-request memoised by filter ID (hit + miss). Provider-agnostic - gates on data-shape, not `content_provider`. Opt-out via `apply_filters( 'jqbeb_range_cmt_override_enabled', true, $args, $instance )`. Per-option indexer counts for sliders remain out of scope.

## JetEngine relation filters (v1.3.7+)

A JSF filter with query var `related_children*<rel_id>` (filter children by parent IDs) or `related_parents*<rel_id>` reaches the parser as a meta clause. JetEngine's `Relations\Filters::add_relation_query()` (final-query p-10) replaces it with `post__in`, but the bridge's `secure_filter_args()` (p `PHP_INT_MAX`) drops every `post__in` because the browser can send one through `_plain_query_post__in`. Before 1.3.7 the restriction was therefore silently lost, and the indexer counted the option from `wp_postmeta`, where relation data does not live.

Flow now:

1. `JSF_Bridge::extract_relation_filters()` at final-query **p-20**, `etch-loop` provider only, calls `Relation_Filters::extract()`. It removes the relation clauses from `meta_query` (top level and one nested group, the shapes JSF builds), discards any `jqbeb_relation_filters` the request tried to set, and stores a plan `[ [ 'relation' => 'AND'|'OR', 'clauses' => [ [ direction, rel_id, ids ] ] ] ]`. JetEngine at p-10 sees nothing to translate.
2. The plan survives the whitelist (`Relation_Filters::ARG` is allowed) and reaches `merge_filter_args()`, the single merge used by results (`JSF_Provider::merge_jsf_into_query`), indexer counts (`compute_indexed_counts`) and dynamic range (`build_recalc_context_args_excluding_var`). There `Relation_Filters::apply()` resolves it through `jet_engine()->relations->get_active_relations( $id )->get_children() / get_parents()` and intersects with the baseline `post__in`. Empty → `[0]`.
3. Group algebra: top-level groups AND; inside a group OR = union, AND = intersection. One checkbox filter with several values is a single `IN` clause = union.
4. Fail closed: values must match `^[1-9]\d*$` (JetEngine treats `0` / `''` as "no parent condition" and would return every child), JetEngine's relations component must be loaded and the relation active, the side returned by the direction must be `posts::<loop post type>` (any `posts::*` when the baseline has no post type or `any`; WP_Query still enforces the post type), compare must be `=` / `IN`, and a nested OR group mixing relation and plain meta clauses, a multi-key query var such as `related_children*7,color`, or a relation clause nested two levels deep cannot be expressed as an ID restriction. Any of these resolve to `post__in = [0]`.
5. `apply()` intersects in baseline order (so `orderby => post__in` from the JE bridge's Merged / SQL / Data Store mode keeps its order) and subtracts the baseline `post__not_in` itself, because WP_Query ignores `post__not_in` as soon as `post__in` is set.
6. Counts: a relation key in the indexer's `meta_query` data is counted with one `GROUP BY` on the relation table (`$relation->db->raw_query()`, `%table%` placeholder), limited to the IDs of the filtered count query, and only when the counted side of the relation is the loop's post type. Options without rows get 0. A multi-key query var that includes a relation key gets no counts.

Why the plan is safe to accept from the parsed request: it only ever narrows the server baseline (intersection), and the client cannot inject it directly because step 1 unsets it before building. A filter "of another grid" can only narrow the current one, the same as any meta filter the client can already send.

Known limit, older than this feature: on the first load / loopback `apply_jsf_to_tagged_query()` applies the parsed filters to every `etch-loop/*` query on the page, not only the matching query id. A relation plan from grid A therefore also narrows grid B (or empties it when B has another post type), exactly like a meta filter always did.

Not done (separate, opt-in): self-excluding counts for OR groups (an option's count with its own group removed). Until then hide counts on such filters.
