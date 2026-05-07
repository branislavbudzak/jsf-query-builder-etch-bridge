# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this plugin does

Two independent bridges that drive Etch's native Query Loop block from external query systems:

1. **JSF bridge** — registers an `Etch Loop` content provider for JetSmartFilters. Filter / pagination / sort blocks can drive any Etch loop (initial-load + AJAX), with multi-loop support via `jsf-etch-q-{slug}` classes, an indexer for per-option counts, and a `[jsf_etch_count]` shortcode.

2. **JE Query Builder bridge** — lets a JetEngine Query Builder query become the data source for an Etch loop. Supports query types: `posts`, `users`, `terms`, `Merged_Query` (with base types posts / users / terms), `SQL_Query` (target type inferred from `cast_object_to` or `je-as-{type}` wrapper hint), and `Data_Stores_Query` (target type inferred from the store's post-vs-user setting).

Each bridge runs only if its target plugin is active. Etch is the only hard dependency for either to do anything useful.

## Architecture (must read before editing)

### Bridge instantiation timing

`Plugin::boot()` runs at `plugins_loaded` (default priority).

- **JSF bridge** is instantiated immediately at `plugins_loaded`. Critical: JSF fires `jet-smart-filters/providers/register` at **`init` priority `-998`**, so if `JSF_Bridge` weren't ready before that hook, the `Etch Loop` provider would never register.
- **JE bridge** is deferred to `init` priority `0` via `Plugin::maybe_boot_je_bridge()`. **JetEngine registers `\Jet_Engine\Query_Builder\Manager` via its components-manager at `init` priority `-1`** (`includes/core/components-manager.php`), so at `plugins_loaded` the class doesn't yet exist and `class_exists('\Jet_Engine\Query_Builder\Manager')` returns false. Booting the JE bridge at `plugins_loaded` would silently no-op (this was the v0.6.0 bug). All of the JE bridge's hooks (`pre_render_block`, `pre_get_posts`, `pre_user_query`, `pre_get_terms`) fire well after `init`, so `init p0` registration is safe.

At `plugins_loaded`, all plugin **main files** have been included (so JSF function `jet_smart_filters` exists), but JE component classes are NOT yet loaded — they appear at `init -1`.

### State_Stack pattern

Both bridges use a per-bridge `State_Stack` instance (`includes/class-state-stack.php`) keyed by Etch wrapper class. The flow per bridge:

1. `pre_render_block` (priority 4 / 5) → push wrapper's `query_id` to the stack
2. The matching pre-query hook (`pre_get_posts` / `pre_user_query` / `pre_get_terms`) → read top of stack, mutate query, pop
3. `render_block` (priority 999) → safety-net pop if no matching hook fired (e.g. wrapper without inner loop, or JE query type mismatched the Etch preset type)

The two bridges have independent State_Stacks → no cross-contamination when both classes appear on the same wrapper.

### JSF AJAX path: direct in-process render (v0.10.0+)

JSF AJAX (filter / pagination / sort clicks) does NOT do an HTTP loopback to the original page URL. Instead, the bridge caches the parsed `etch/element` wrapper block tree in a transient at page render time (`JSF_Bridge::on_render_block` → `set_transient( JSF_Bridge::block_cache_key( $url_path, $query_id ), [block, post_id], HOUR_IN_SECONDS )`), and `JSF_Provider::ajax_get_content` retrieves it and renders it in-process via `render_block()`. Mirrors what JetEngine's listing-grid provider does (`get_render_instance('listing-grid', $attrs)->render()`).

Critical mechanism: all bridge hooks (`pre_render_block`, `pre_get_posts`, `render_block`, JE bridge equivalents) bail on `wp_doing_ajax() || is_admin()` BY DEFAULT. During in-process AJAX render they must fire normally (so the inner WP_Query gets re-tagged and JSF's provider hook can re-apply paged + filter args). The bypass is the static flag `JSF_Bridge::$in_ajax_render` — set true around the `render_block()` call, hooks check `! self::$in_ajax_render && ( wp_doing_ajax() || is_admin() )` to early-return. JE_Bridge reads the same flag (both classes are in the `JQBEB` namespace, so unqualified reference resolves).

**Cache miss fallback**: if the transient is gone (expired, never rendered for this URL+query_id, or extract failed), the provider falls through to the original HTTP loopback path (`wp_remote_get`). Same code as before — no regression for any working path.

**Cache invalidation**: 1-hour TTL. Block edits in the editor are not auto-flushed; manual `wp transient delete --all` or wait 1h. Production-tier cache strategy if needed: hash `etch_loops` option into the cache key.

### Hook priority ladder (when both bridges are active on the same wrapper)

```
pre_render_block  p4     JE bridge captures je-etch-loop wrapper
pre_render_block  p5     JSF bridge captures jsf-etch-loop wrapper
pre_get_posts     p40    JE wholesale-replaces WP_Query args
pre_get_posts     p50    JSF tags the query (jet_smart_filters = etch-loop/{id})
pre_get_posts     p60    JSF merges filter args on top of JE base
pre_get_posts     p70    JE bridge CMT redirect (splits the merged meta_query
                          and orderby — sees JSF filter additions because it
                          fires after p60). Only acts on queries marked with
                          _jqbeb_je_query_id.
pre_user_query    p10    JE bridge (Users base type)
pre_get_terms     p10    JE bridge (Terms base type)
render_block      p999   safety-net pop for both bridges
wp_footer         p5     JSF bridge outputs window.JQBEBData (BEFORE wp_print_footer_scripts at p20)
```

## Critical knowledge about Etch internals

These were the foot-guns discovered during initial development. All have to remain true for the bridge to keep working — verify if Etch is updated.

1. **`etch/element` block stores classes only in `attrs.attributes.class`.** The block declares both `'className' => false` AND `'customClassName' => false` ([etch/classes/Blocks/ElementBlock/ElementBlock.php:66-67](../etch/classes/Blocks/ElementBlock/ElementBlock.php)), so the standard Gutenberg `attrs.className` field is never populated. Don't add a fallback.

2. **Etch's loop handlers instantiate `new WP_Query / WP_User_Query / WP_Term_Query` directly** (`etch/classes/Blocks/Global/Utilities/LoopHandlers/`). WP core fires `pre_get_posts` / `pre_user_query` / `pre_get_terms` from inside those constructors / methods, so our hooks fire normally — no Etch-specific filter is needed.

3. **Etch's terms / users handlers expect full `WP_Term` / `WP_User` instances.** They check `$item instanceof WP_Term` etc. If the JE query returns IDs only, the loop renders empty cards. The bridge defensively forces `query_vars['fields'] = 'all'` after merging JE args for users / terms.

4. **`pre_user_query` fires inside `WP_User_Query::__construct()` (via `prepare_query()`)** — by the time `get_results()` runs the SQL has already executed. Our action listener must already be registered at construction time (it is, because `JE_Query_Builder_Bridge::__construct()` runs at `plugins_loaded`).

## Critical knowledge about JE Query Builder

1. **`Manager::instance()->get_query_by_id($id)` accepts BOTH numeric IDs and string slugs** — slugs are resolved via the `custom_query_ids_mapping`. The `je-q-{id}` wrapper class supports both forms.

2. **`Posts_Query::get_query_args()` runs the args through the `jet-engine/query-builder/types/posts-query/args` filter pipeline** — consumers don't need to apply it manually.

3. **Pagination is NOT automatic from `$_REQUEST`.** Consumers must call `$je_query->set_filtered_prop('_page', $page)` before calling `get_query_args()` / `get_items()`. The bridge does this from `$_REQUEST['jet_paged'|'paged'|'pagenum']`.

4. **`Merged_Query` reports its `$query_type` as its `base_query_type`** (e.g. `'posts'` for a Merged of Posts queries). This means a Merged query LOOKS like a regular Posts query to type dispatch — `instanceof Merged_Query` MUST be checked first, otherwise calling `get_query_args()` on it returns a useless `array_merge` of all sub-queries' args. Merged is handled via a different path: pre-fetch `get_items()`, extract IDs, feed via `post__in` / `include`.

5. **`SQL_Query` reports `$query_type === 'sql'`** which doesn't match any Etch loop preset directly. The bridge infers target type from (in priority order): wrapper class hint `je-as-{posts|users|terms}` → `cast_object_to` config (`WP_Post` / `WP_User` / `WP_Term`) → default `posts`. Same predefined-IDs path as Merged: `get_items()` → extract IDs → `post__in` / `include`. ID extraction handles WP_Post/WP_User/WP_Term instances AND raw stdClass rows from `$wpdb->get_results()` via heuristic column lookup (`ID` / `id` / `post_id` / `user_id` / `term_id`).

6. **`Data_Stores_Query` reports `$query_type === 'data-stores-query'`** and wraps a Posts or Users sub-query that filters by store contents. Target type detection reads `final_query['store_slug']` and calls `Module::stores->get_store($slug)->is_user_store()` — cheap, no inner query materialisation. **Avoid calling `Data_Stores_Query::get_query_type()` directly** — it triggers `get_current_query()` which materialises the inner WP_Query / WP_User_Query (expensive) just to determine the type.

7. **Re-entrancy guard.** Merged sub-queries, SQL `$wpdb->get_results()`, and Data Store inner queries fire `pre_get_posts` / `pre_user_query` / `pre_get_terms` from inside `extract_ids_from_get_items()`. Without a guard, our handlers would recurse on the same JE query (potentially infinite, definitely corrupting). The bridge sets `$this->in_extraction = true` around any JE method call that may instantiate WP_*_Query (`get_items()`, `get_query_args()`, `get_data_store_target_type()` setup), and dispatchers early-return when the flag is set. Wrap with `try/finally` so an exception cannot leave the flag stuck.

8. **Data Store cache reset for `je-jsf-stack` mode.** `Data_Stores_Query` caches its inner query in `$current_query`. If we set `final_query` overrides AFTER `get_current_query()` has already materialised, the overrides have no effect. The bridge calls `$je_query->reset_query()` after applying overrides so the next `get_items()` rebuilds with our values.

9. **CMT (Custom Meta Tables) timing trap.** JetEngine's `\Jet_Engine\CPT\Custom_Tables\Manager` registers a GLOBAL `posts_clauses` filter at priority 10 that emits a custom-table JOIN/WHERE/ORDER iff the WP_Query carries a `custom_table_query` query var. JE populates that var via its OWN `pre_get_posts` handler at priority 10 (one closure per CMT post type, in `Query::hook_query_handlers()` → `jet-engine/.../post-types/custom-tables/query.php:354`), which calls `exctract_meta_query_partials()` to split `meta_query` into custom-table vs `wp_postmeta` clauses. Our bridge applies JE args at priority 40 — strictly AFTER JE's splitter. The splitter therefore sees Etch's preset (no CMT meta_query), does nothing, and `custom_table_query` stays unset; the global `posts_clauses` filter then emits no CMT SQL and the resulting query searches `wp_postmeta` for fields that are not there. Result: 0 rows even though JE's own `get_items()` returns full data. The bridge's `apply_cmt_redirect()` mirrors JE's split logic inline AFTER our wholesale arg replacement; the actual hook attaches at `pre_get_posts` priority **70** (`apply_cmt_redirect_late`), strictly AFTER JSF's filter merge at priority 60, so the split sees the combined meta_query and routes both JE-base and JSF-filter CMT clauses into `custom_table_query`. The CMT table name MUST come from `Manager::get_db_instance($slug, $fields)->table()` (which prefixes with `$wpdb->prefix`), NOT `Manager::get_table_name($slug)` which returns the unprefixed slug-derived name and would emit a JOIN to a non-existent table. Tightly coupled to JE internals — public API surface we depend on is `Manager::instance()`, `Manager::$storages`, `Manager::get_db_instance()`, and the `custom_table_query` query var contract (`{table, query, order}`).

10. **CMT for JSF Indexer.** The bridge's `compute_indexed_counts` (in `JSF_Bridge`) generates per-option counts by querying `wp_postmeta` directly. For loops whose post type uses Custom Storage, the meta values live in the CMT table (column-per-field, `object_ID` FK), not `wp_postmeta`. The indexer detects CMT context via `JSF_Bridge::detect_cmt_for_args()` and routes meta_query keys accordingly: keys whose name appears in `Manager::$storages[*]['fields']` are queried as `SELECT \`{col}\` AS meta_value, COUNT(DISTINCT object_ID) FROM \`{cmt_table}\` ...`; keys outside the CMT field list still go to `wp_postmeta`. Multi-key filters (comma-separated keys representing OR'd meta_keys) split between the two paths and counts are merged into a single value→count bucket per filter. Column names are interpolated directly into SQL because `$wpdb->prepare()` cannot bind identifiers; trust source is membership in the registered fields list, sanitised again via `sanitize_cmt_column()` (defence-in-depth strip to `[A-Za-z0-9_]`).

11. **JSF provider default-query registration.** The Filter Indexer is **gated by `jet_smart_filters()->query->get_default_queries()`** — JSF iterates that array in `Indexer_Data::prepare_localized_data` and SKIPS providers without an entry. JS receives no `jetFiltersIndexedData` for skipped providers, and AJAX filter changes send empty `query_args` to the indexer endpoint (it counts against an empty post_type → wrong / zero counts). All built-in JSF providers register themselves at render time via `jet_smart_filters()->query->store_provider_default_query( $provider_id, $query_args, $query_id )`. Our bridge calls this from `JSF_Bridge::tag_query_for_jsf()` at `pre_get_posts` priority 50 — after JE bridge p40 has applied its base args, before JSF's filter merge at p60 — passing an allowlisted subset of `$query->query_vars` (post_type, post_status, posts_per_page, meta_query, tax_query, date_query, orderby, order, meta_key, post__in, post__not_in, paged). Storing the full query_vars would also work but bloats the localized JS payload with internal WP_Query defaults (error, m, p, attachment_id, etc.). The CMT split has not yet run at p50, so the stored meta_query is still in raw JE form; that's intentional — the indexer's `count_query` instantiates a fresh `WP_Query` which re-fires JE's pre_get_posts splitter at p10 inside that fresh query, so the CMT JOIN still gets emitted there.

## Wrapper class conventions

| Class | Required for |
|---|---|
| `jsf-etch-loop` | JSF bridge — marks the immediate parent of loop cards (pagination/sort blocks must be OUTSIDE) |
| `jsf-etch-q-{slug}` | JSF bridge — disambiguates multi-loop pages; matches JSF block's "Query ID" setting |
| `je-etch-loop` | JE bridge — marks any Etch loop wrapper to use a JE query as data source |
| `je-q-{id}` | JE bridge — numeric JE query ID OR custom query_id slug |
| `je-as-{posts\|users\|terms}` | JE bridge — explicit target type override for SQL queries (also works as override for any JE query if `cast_object_to` inference is wrong) |
| `je-jsf-stack` | JE bridge — opt-in JSF compatibility for Merged / SQL: bridge fetches the FULL JE result set (overrides `max_items_per_page` / `limit_per_page` / `limit` / `_page` to 0/1) and does NOT force-disable WP_Query pagination flags. Required to make JSF filters / pagination / `[jsf_etch_count]` work for Merged / SQL Posts loops. Must be combined with `jsf-etch-loop` to actually engage JSF. |

Both bridges can coexist on the same wrapper. Class extraction reads only `attrs.attributes.class`.

## File map

```
jsf-query-builder-etch-bridge.php       Plugin header + constants + bootstrap
includes/
  class-plugin.php                      DI bootstrap, dependency probes, conditional bridge loading
  class-state-stack.php                 push/pop helper used by both bridges
  class-jsf-bridge.php                  JSF integration (Snippet 1)
  class-jsf-provider.php                Jet_Smart_Filters_Provider_Base subclass + AJAX loopback
  class-je-query-builder-bridge.php     JE integration: type dispatch (Posts / Users / Terms / Merged / SQL / Data Stores) + CMT redirect helpers (apply_cmt_redirect / split_meta_query_for_cmt)
  class-shortcode.php                   [jsf_etch_count] shortcode
  class-admin-page.php                  Settings → JSF Etch Bridge (English docs, conditional sections)
assets/js/count.js                      [jsf_etch_count] live updater (subscribes to JSF event bus)
assets/js/range-fill.js                 Page-load fill for JSF Range filter editable text inputs. Bridges JSF 3.8.0.1+ async dynamic-range pattern (data-dynamic-range-pending + clearPendingDynamicRangeDisplay) by calling `updateRangeBounds()` directly on `jet-smart-filters/inited` for our `etch-loop` provider; legacy `input`-event dispatch path retained for JSF 3.7.x.
assets/js/empty-state.js                Toggles `is-empty` on each `.jsf-etch-loop` wrapper and `is-active` on every `.jsf-etch-empty-state` Etch element paired with the wrapper, so users can author a custom empty-state directly in Etch (text, card, image, anything). Default-hides empty-state elements via injected CSS until JS reveals them. Pairing: `data-for-query-id="<slug>"` on multi-loop pages, otherwise nearest `.jsf-etch-loop` ancestor walk.
```

## Filterable behaviour

- `apply_filters('jqbeb_loopback_sslverify', false)` — set to `true` (or `__return_true`) on production for proper SSL verification on the JSF AJAX self-loopback.
- `apply_filters('jqbeb_loopback_cache_enabled', true, $cache_user_id)` — disable the 60-second rendered-HTML loopback cache (return `false`) on sites with anonymous personalized content (cart, geo, A/B). Cache is per-user-ID; safe for typical role-/login-/membership-gated content.
- `apply_filters('jqbeb_range_cmt_override_enabled', true, $args, $instance)` — opt-out of the v1.0.2 JSF Range filter min/max recompute against JE CMT tables. Return `false` to fall back to JSF's default `wp_postmeta` query (which yields empty bounds for CMT fields).
- `apply_filters('jqbeb_empty_results_payload', '<!--jqbeb:empty-results-->', $inner)` — substitute the sentinel emitted (v1.0.4+) when the AJAX-rendered loop has zero results. JSF's frontend treats `content === ''` as "no update", leaving the previous result set in the DOM; the sentinel forces a replace so the wrapper visibly clears. Replace with a styled `<div class="...">No vehicles match.</div>` placeholder for a visible empty-state UI.

## Versioning workflow

Plugin is on SemVer post-1.0:

- **Patch (bug fix)**: `x.y.z` → `x.y.(z+1)` with `fix:` commit prefix.
- **Minor (feature, backwards-compatible)**: `x.y.z` → `x.(y+1).0` with `feat:` commit prefix.
- **Major (breaking change)**: `x.y.z` → `(x+1).0.0`. Document migration in CHANGELOG and readme.txt.

When bumping, update **all five** in the same commit:
1. Plugin header `Version:` in `jsf-query-builder-etch-bridge.php`
2. `JQBEB_VERSION` constant in the same file
3. `readme.txt` `Stable tag:` and `== Changelog ==` entry
4. `CHANGELOG.md` (Keep a Changelog format)
5. Tag with `git tag v{x.y.z}` and push (`git push origin main v{x.y.z}`)

Cache-bust note: `assets/js/count.js` is enqueued with `JQBEB_VERSION` as its version param. Bumping the constant invalidates the browser cache automatically.

## Common commands

```bash
# Lint all PHP files (does not require WP)
for f in jsf-query-builder-etch-bridge.php includes/*.php; do php -l "$f"; done

# Git workflow (no remote pre-push hooks here; main = production-ish)
git status
git log --oneline
git push origin main
```

## Limitations to remember

- **JE Repeater / Comments / Current_WP_Query types are NOT supported.** Etch has no compatible loop handler. Adding them would require an Etch core change (filter on `LoopHandlerManager::get_loop_preset_data()`) — see plan history. Don't try to add them via reflection / monkey-patch.
- **JSF integration is Posts-only.** JSF filters / pagination / sort do not drive Users / Terms loops. Adding it would require subclassing `Jet_Smart_Filters_Provider_Base` once per type with separate selectors.
- **JSF + Merged / SQL / Data Store works ONLY in `je-jsf-stack` mode.** Default behaviour fetches a JE-paginated slice (one page) and force-disables WP_Query pagination, which breaks JSF and the count shortcode. With `je-jsf-stack`, the bridge fetches the FULL JE filter set, leaves WP_Query pagination flags alone, and lets WP_Query / JSF natively paginate the `post__in` subset. Cost: full fetch on every render — fine for moderate sets, expensive for large ones.
- **SQL queries must return a recognisable ID column.** The heuristic looks for `ID` / `id` / `post_id` / `user_id` / `term_id`. Rows without one are silently skipped during ID extraction.
- **Only `loopId`-mode Etch loops are bridged.** `target` / expression mode bypasses `WP_Query` entirely.
- **Filter Indexer counts skip range filters** (per-option counts not generated for sliders). Only `tax_query` and `meta_query` are supported. JetEngine CMT (Custom Meta Tables) IS supported as of v0.7.0 — the indexer detects CMT context via `Manager::$storages` and queries the custom table directly when the filter's meta_key matches a registered CMT field. CCT (separate `wp_jet_cct_*` tables / no `wp_posts` link) is NOT directly supported because JSF cannot drive a non-WP-Query loop; bridge users would have to query the CCT as a JE SQL_Query and feed IDs.
- **Range filter live recalc on AJAX filter changes (v1.0.3+).** JSF 3.8.0+ pushes `dynamic_range[<bucket>][]=<query_var>` per pending range filter into every AJAX request and reads `response.dynamic_range[<query_var>] = { min, max }` from the provider's reply to call `updateRangeBounds()`. Bridge hooks `jet-smart-filters/render/ajax/data` (`add_dynamic_range_to_ajax_response`) and per-var:
  1. Builds the current filter context as a WP_Query — base from `$_REQUEST['defaults']`, JSF clauses from `$_REQUEST['query']`, **excluding the var's own `_meta_query_<var>` / `_meta_query_<var>|<suffix>` clause** (otherwise the slider would collapse to the user's current selection and become impossible to widen).
  2. Runs the query for IDs only.
  3. Feeds them as `t.object_ID IN (…)` into `compute_cmt_range_min_max($targets, $ids)` (the v1.0.2 helper, public, with the new optional second arg).
  4. Injects `dynamic_range[<var>] = { min, max }` into the response.
  Var list is read from **`php://input`** because JSF's bucket-key shape (`dynamic_range[[object Object],apply_min_max_callback][]=…`) collapses under PHP's `parse_str` — the literal `[`/`]`/`,` inside the key truncates everything to a single `array[ '[object Object' => '<last var>' ]` and the other vars are silently lost. Walking the raw body recovers all of them. The "current context" args are sourced via JSF's own `get_query_from_request()` (with `$_REQUEST['query']` temporarily mutated to drop self-var keys, then restored) and deep-merged with `$_REQUEST['defaults']` per multi-clause arg — JSF's own `get_query_args()` does only a shallow `array_merge` that would drop the JE-base tax_query / meta_query.
- **Range filter dynamic min/max IS CMT-aware (v1.0.2+).** Hooks `jet-smart-filters/filter-instance/args` priority 20. Accepts two `_source_callback` values:
  1. **`jet_smart_filters_meta_values`** — JSF's built-in "Get from Post Meta by query meta key". Queries `wp_postmeta` and returns NULL for CMT fields → JSF falls back to defaults. Bridge detects CMT field membership across all `Manager::$storages` (any storage matched).
  2. **`jet_engine_custom_storage_post_{slug}`** — JE-NATIVE per-CMT callback registered by `\Jet_Engine\CPT\Custom_Tables\Query::register_range_min_max_callback`. UI label is "{Post Type}: Get from custom storage by query meta key". JE's own callback (custom-tables/query.php:73) queries the right table BUT can return `[ 'min' => null, 'max' => null ]` when the SQL has no rows or all-NULL aggregates; JSF then drops to manual fallback because `isset($data['min'])` is FALSE on NULL. Bridge pins the lookup to the storage slug encoded in the callback name.
  Bridge SQL: `SELECT MIN(FLOOR(t.{col})), MAX(CEILING(t.{col})) FROM {cmt_table} t INNER JOIN wp_posts p ON p.ID = t.object_ID WHERE t.{col} IS NOT NULL AND t.{col} <> '' AND p.post_type = %s AND p.post_status IN (%s,...)`. Statuses come from `apply_filters('jet-smart-filters/dynamic-min-max/search-statuses', ['publish'])`. Step-rounding mirrors `Jet_Smart_Filters_Range_Filter::max_value_for_current_step`. Per-request memoised by filter ID (hit + miss). Provider-agnostic — gates on data-shape, not `content_provider`. Opt-out via `apply_filters( 'jqbeb_range_cmt_override_enabled', true, $args, $instance )`. Per-option indexer counts for sliders remain out of scope.
- **CMT redirect is Posts-only.** JE registers Custom_Tables Query handlers only for `object_type='post'` in core; user / term object types are gated behind a do_action that needs an add-on. The bridge therefore only mirrors the splitter for posts. Users / Terms loops with CMT would need extension via the same pattern.

## Security stance

- Loopback AJAX forwards all cookies via `wp_remote_get()` so authenticated content is correctly resolved. SSL verification is off by default (local-dev compat) but filterable.
- `<!--JQBEB-PROPS:...-->` markers are stripped from the AJAX response inner HTML before send, so they never reach the browser DOM. Only used internally for parent → loopback prop transport.

## When to reach for an agent

The bridge is a self-contained ~1,500 LOC plugin. Most edits are local. Reach for an Explore agent only when:

- Verifying Etch / JSF / JE internal behaviour after a major upstream version bump (the integration depends on internal classes / hooks that Etch / JSF / JE could break without notice).
- Tracing why a `jsf-etch-loop` wrapper isn't AJAX-updating — the loopback path goes through DOMDocument + XPath which is fragile against unbalanced HTML.
