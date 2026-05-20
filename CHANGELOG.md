# Changelog

All notable changes to this project are documented here. The format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed
- **AJAX pagination on a JE-Query-Builder-backed loop no longer drops the configured JE args (post_type / meta_query / tax_query / orderby / posts_per_page) when any JSF filter is active.** `get_args_with_pagination()` was calling `$je_query->set_filtered_prop( '_page', N )` BEFORE `get_query_args()` on a freshly-fetched JE query. JE's Posts_Query writes `_page` directly into `$this->final_query['paged']` ([jet-engine/.../queries/posts.php:320](../jet-engine/includes/components/query-builder/queries/posts.php)); on the first call of a request `final_query` is still `null`, so the assignment AUTOVIVIFIES `final_query` as a degenerate 2-key array (`paged` + `page`). The subsequent `get_query_args()` saw a non-null `final_query` and skipped `setup_query()` — so the configured post_type / meta_query / tax_query / orderby never entered `final_query`, and the returned args lost everything except the page we just set. Net effect: page 2 click on a JE-bridged loop ran against a degenerate query (`post_type='any'` Etch-preset default, no JSF tax/meta clauses scoped to the right CPT, JSF-applied geo/meta clauses matching across an over-broad universe) yielding 0 rows — most visibly when a JSF Location & Distance filter was active, where the page 1 result set was already small. Mirrors the pattern already used in `extract_ids_from_get_items()` (Merged / SQL / Data Store path) — calling `get_query_args()` first triggers `setup_query()` lazily, then `set_filtered_prop` mutates an already-populated `final_query`. Affected: all JE Query Builder Posts queries paginated via JSF on a Regular (non-jsf-stack) Posts loop.

### Added
- **`JQBEB_DEBUG_PAGINATION` diagnostic harness.** Off by default; enable per-site with `define( 'JQBEB_DEBUG_PAGINATION', true );` in `wp-config.php`. Routes a per-request buffer of `Debug::log()` checkpoints to the browser DevTools console as collapsed groups (`[jqbeb-debug] page-load (N entries)` / `[jqbeb-debug] ajax [provider/qid] paged=N (N entries)`) via two channels: inline `<script>` in `wp_footer` for the non-AJAX initial render, and the `_jqbeb_debug` key on the JSON response via the `jet-smart-filters/render/ajax/data` filter for JSF AJAX. Captures the `apply_regular_to_posts` / `merge_jsf_into_query` / `posts_request` / `the_posts` boundaries, plus `$_REQUEST` shape at admin-ajax entry. Used to diagnose the JE-args dropout above; kept in tree for future debugging.

## 1.1.0

### Added
- **Author-controlled empty-results state via Etch.** Drop any Etch element (heading, card, CTA, "request a vehicle alert" form, dynamic-data block, …) on the page with class `jsf-etch-empty-state`, and the bridge auto-shows it whenever the paired loop wrapper has zero rendered children — initial render with no matching posts, AJAX filter pass that yielded 0, etc. The element is hidden by default via injected `display:none !important` (defends against Etch's own `display: flex` / `display: grid` inline styles) and revealed by toggling `is-active`. CSS-only fallback: site CSS can target `.jsf-etch-loop.is-empty::before { content: "…"; }` for a one-line no-results message without any author markup.
- Pairing rules — by default, an empty-state element pairs with the nearest `.jsf-etch-loop` ancestor walk; descendants of OTHER loop wrappers are explicitly excluded so multi-loop pages don't cross-show. Multi-loop pages with separate empty states use `data-for-query-id="<slug>"` to scope each empty-state to its `jsf-etch-q-<slug>` wrapper.
- A `MutationObserver` watches per-loop child mutations (AJAX-driven inner replace) AND the body for new loops added via popups / lazy-loaded sections, so the toggle stays correct on dynamic content.
- Settings → JSF Etch Bridge admin page picks up a new "Empty results state" section in the JSF tab with full setup instructions and CSS examples.

### Fixed
- **AJAX filter changes that yield zero results no longer leave the previous result set in the DOM.** When the merged WP_Query had 0 matching posts, Etch's loop block correctly rendered a wrapper with no children, `extract_wrapper_inner_html` correctly returned an empty string, and `JSF_Provider::ajax_get_content` correctly echoed nothing — so JSF received `{ "content": "", "pagination": { "found_posts": 0, … } }` and its frontend, defensively, interpreted the empty `content` as "no update — leave the wrapper alone". Result: users kept seeing the previous filter pass's cards (or, if filters were widened back from a narrow range, the initial all-results view) despite `found_posts === 0`. Bridge now post-processes the extracted inner via a new `ensure_non_empty_inner()` helper: when the inner is whitespace + comments only, it substitutes a sentinel `<!--jqbeb:empty-results-->` comment so JSF's replace path runs and the wrapper visibly clears.
- New filter `apply_filters( 'jqbeb_empty_results_payload', '<!--jqbeb:empty-results-->', $inner )` lets sites that prefer a server-rendered empty-state (e.g. localised text from the active locale, dynamic-data tied to filter values) substitute their own HTML instead of using the JS-toggled `.jsf-etch-empty-state` Etch element approach.

## 1.0.3

### Fixed
- **JSF Range filter live recalculation (added in JSF 3.8.0) now works on the bridge.** When other filters change, JSF posts a `dynamic_range[…][]=<query_var>` payload listing every range filter on the page that should have its bounds re-resolved against the current filter context, and reads `response.dynamic_range[<query_var>] = { min, max }` from each provider's AJAX response to call `updateRangeBounds()` on the slider. Crocoblock-native providers populate this server-side; our `etch-loop` provider previously did not, so range sliders kept their initial page-load bounds even after another filter narrowed the result set. Bridge now hooks `jet-smart-filters/render/ajax/data` and:
  - Reads the requested vars from the **raw POST body** (not `$_REQUEST`) — JSF's bucket-key shape `dynamic_range[[object Object],apply_min_max_callback][]=<var>` cannot be parsed by PHP's `parse_str` (the literal `[`/`]`/`,`/space inside the bracket-key collapses the expected `array<string, array<string>>` down to a single `array[ '[object Object' => '<last var>' ]`, silently overwriting every var except the last). The bridge walks `php://input` directly to recover all vars.
  - For each var, builds a WP_Query mirroring the current filter context but EXCLUDING the var's own `_meta_query_<var>` clause — otherwise the slider would collapse to the user's current selection and become impossible to widen.
  - Runs the query for IDs only and feeds them as `t.object_ID IN (...)` into the existing CMT MIN/MAX SQL helper from 1.0.2.
  - Injects the result map into the AJAX response.
- Provider-agnostic gate (data-shape, not `content_provider`); reuses the existing `jqbeb_range_cmt_override_enabled` opt-out filter.

### Notes
- Comma-separated multi-key range filters are aggregated across columns via the same path as the page-load override.
- Vars whose CMT column is all-NULL within the current filter context are silently omitted from the response — JSF's JS leaves the slider in its previous state, which is preferable to collapsing to 0/0.
- Re-uses the public `find_cmt_targets_for_meta_keys()` and `compute_cmt_range_min_max()` helpers exposed in 1.0.2 (latter gained a new `?int[] $restrict_to_object_ids` parameter for the `t.object_ID IN (…)` constraint).

## 1.0.2

### Fixed
- **JSF Range filter dynamic min/max for JE Custom Meta Tables.** Range filter sliders driven by JE post types using Custom Meta Tables (CMT / Custom Storage) showed empty bounds on page load — both with JSF's built-in `jet_smart_filters_meta_values` callback ("Get from Post Meta by query meta key", queries `wp_postmeta` which holds nothing for CMT fields) AND with JE's native `jet_engine_custom_storage_post_{slug}` callback ("{Post Type}: Get from custom storage by query meta key", queries the right table but returns `null` min/max when the column has only NULL values, which JSF then drops via `isset()` against NULL falling back to manual `_source_min` / `_source_max`). Bridge now hooks `jet-smart-filters/filter-instance/args` priority 20 and recomputes min/max from the CMT table when the filter's meta_key is a registered CMT field, scoped by the storage's `object_slug` post type and the `jet-smart-filters/dynamic-min-max/search-statuses` filter (default `['publish']`), with step-rounding mirroring JSF's `max_value_for_current_step`. Comma-separated multi-key filters are aggregated across columns. Per-request memoisation by filter ID prevents re-running the SQL for the multiple `Filter_Instance` constructions JSF performs per page (sitemap, hierarchy, dynamic tags). Provider-agnostic — gates on data-shape (CMT field membership), not `content_provider`. Opt-out via `apply_filters( 'jqbeb_range_cmt_override_enabled', true, $args, $instance )`. Manual min/max (`_source_callback = 'none'`), WooCommerce price, term meta, and user meta callbacks are untouched.
- **JSF 3.8.0.1+ async dynamic-range pattern integration.** JSF 3.8.0.1 introduced a new flow where the template emits empty `value=""` on editable text inputs (`.jet-range__inputs__min` / `.jet-range__inputs__max`) and `data-dynamic-range-pending="1"` on the wrapper. JSF's range constructor then calls `clearPendingDynamicRangeDisplay()` which explicitly empties the editable inputs and waits for `JetSmartFilterSettings.jetFiltersDynamicRange[providerKey][queryVar]` to populate them via `updateRangeBounds()` on the `jet-smart-filters/inited` event. Crocoblock-native providers self-register into that localized structure server-side, but our `etch-loop` provider does not, so every range filter on a JE-CMT-backed loop bridged through this plugin stayed empty until the user dragged a thumb. Bridge now ships `assets/js/range-fill.js` that listens for `jet-smart-filters/inited` (with an immediate try-on-load and a `MutationObserver` on `data-jet-inited` as belt-and-suspenders for race conditions and AJAX-injected filter blocks), walks every filter group, and for each range filter with `dynamicRangePending=true` calls `updateRangeBounds({min, max})` directly using the slider input's `min` / `max` attrs (which the PHP-side hook above populated correctly). Backwards-compatible with JSF 3.7.x — older filters get the legacy slider-input-event dispatch path that propagates value into editable inputs via JSF's `valuesUpdated('min'/'max')` chain. Per-filter `_jqbebRangeResolved` flag makes the resolver idempotent.

### Internal
- Frontend assets now use `filemtime( $file )` combined with `JQBEB_VERSION` as the `wp_enqueue_script` version arg, so any edit or fresh deploy that rewrites a JS file automatically invalidates browser AND CDN (Cloudflare / LiteSpeed / BunnyCDN) caches without needing a manual purge. The version arg stays stable between releases since file mtime only changes on actual content rewrite.

## 1.0.1

### Security
- Validate the loopback referer against `home_url()` / `site_url()` before issuing the AJAX self-loopback `wp_remote_get`. Prior versions used `wp_get_referer()` (attacker-controllable via `_wp_http_referer` / `HTTP_REFERER`) as the base URL with no host check, allowing an unauthenticated attacker hitting the JSF AJAX endpoint to make the server fetch arbitrary URLs (SSRF) and forward all of the visitor's `$_COOKIE` values to that target. A spoofed cross-origin referer now returns `<!-- jqbeb: cross-origin referrer rejected -->` and short-circuits before any outbound HTTP.
- `sslverify` for the loopback now defaults to `true`. Local-dev override via `add_filter( 'jqbeb_loopback_sslverify', '__return_false' );` (filter name unchanged).
- `redirection` for the loopback dropped from `3` to `0`. Loopback to ourselves never legitimately needs to follow redirects, and disallowing them blocks any open redirect (or 3rd-party redirect reachable from `home_url()`) from ferrying the request — and the forwarded cookies — off-site.
- Rendered-HTML loopback transient cache key now includes the current user ID (`md5( $url . '|u=' . $user_id )`). Previously the 60-second cache was keyed by URL only, so role-/login-/membership-gated loop content rendered for one user could be served to another for the TTL window. New filter `apply_filters( 'jqbeb_loopback_cache_enabled', true, $user_id )` lets sites with anonymous personalized content (cart, geo, A/B) disable the cache entirely.

### Notes
- The block-tree cache (1 h TTL, keyed by URL path + query_id) is unchanged — it stores a parsed AST and re-runs `render_block()` in the current user's context on retrieval, so it does not leak rendered output across users.
- Admin docs: SSL troubleshooting copy updated to reflect the new secure default.

## 1.0.0

First public release. Folds the 0.x development line into a stable baseline. Both bridges (JSF + JE Query Builder) ship feature-complete with all advertised query types, JetEngine Custom Meta Tables support end-to-end, and a fast in-process AJAX render path.

### Highlights
- **JSF Bridge.** Etch Loop registered as a JetSmartFilters content provider. Initial-load and AJAX filtering, pagination, and sort. Multi-loop support per page via `jsf-etch-q-{slug}` classes. `[jsf_etch_count]` shortcode for live `found_posts` / `max_num_pages` / current page.
- **JE Query Builder Bridge.** JetEngine Query Builder queries can drive any Etch Query Loop as the data source. Supported query types: Posts, Users, Terms, Merged Query, SQL, Data Stores Query. Wrapper class hints (`je-q-{id}`, `je-as-{type}`, `je-jsf-stack`) for routing.
- **JetEngine Custom Meta Tables (CMT) end-to-end.** JE base meta_query + orderby, JSF user filtering, JSF user sort, and Filter Indexer per-option counts all read/write the correct table.
- **Filter Indexer counts.** Per-option counts on JSF filters via the `jet-smart-filters/pre-get-indexed-data` hook. Supports `tax_query` and `meta_query` (postmeta and CMT).
- **Fast AJAX render path.** Direct in-process `render_block()` of the cached wrapper block tree. Mirrors JetEngine's listing-grid provider — no full-page HTTP loopback for filter / pagination / sort clicks.
- **Settings → JSF Etch Bridge admin page.** Tabbed layout with conditional sections (only what's relevant for the active dependencies), inline status pills, hook priority ladder, full CMT support matrix.

## 0.9.0

### Changed
- Admin page rebuilt with a tabbed layout (Overview / JSF Bridge / JE Bridge / Combined & CMT / Reference). Conditional tabs only appear if the matching dependency is active. Active tab persists in URL hash.
- Hot, repetitive content collapsed into `<details>` sub-sections (Merged / SQL / Data Stores / `je-jsf-stack` / shortcode / per-troubleshooting topic) for faster scanning. Body content wrapped in `.jqbeb-card-body` for consistent padding when open.
- `<details>` accordion arrow rebuilt as a CSS-drawn triangle inside a flexbox-aligned summary; rotates cleanly without layout jump on open. Hover state for affordance. Nested sub-details get smaller grey/blue triangles for visual hierarchy.
- Inline dependency status pills in the page header (always visible).
- New "Combined & CMT" tab consolidates: hook-priority ladder, full CMT support matrix, and a performance tip recommending MySQL indexes on hot CMT columns for range / sort queries.

### Removed
- Obsolete "not supported" notes for CMT in the Indexer and Limitations (CMT is fully supported as of 0.7.0–0.8.1).

## 0.8.1

### Fixed
- Filter Indexer counts (taxonomy and meta) were not being computed because the bridge never registered its loop's base query with JSF via `store_provider_default_query()`. JSF's `prepare_localized_data` iterates `get_default_queries()` to find providers eligible for indexing — providers without an entry are skipped, so no indexed_data was ever localized to JS, and AJAX filter changes sent empty `query_args` to the indexer endpoint (counting against the wrong post type). The bridge's `tag_query_for_jsf()` now stores a curated subset of the loop's `query_vars` at `pre_get_posts` priority 50 — after the JE bridge's arg injection at p40, before JSF's filter merge at p60. Affects all loops driven by the bridge: pure JSF (Etch Loop) and JSF + JE Query Builder.

## 0.8.0

### Added
- JSF active filtering and sorting on CMT-stored fields now works end-to-end. Previously the bridge's CMT redirect ran at `pre_get_posts` priority 40, BEFORE JSF's filter merge at priority 60, so any filter clauses JSF added for a CMT field landed in `meta_query` (going to `wp_postmeta`) instead of `custom_table_query` (going to the custom table).
- This completes CMT support for Posts queries: JE base meta_query + orderby, JSF user filters, JSF user sort, and Filter Indexer counts all read/write the correct table.

### Changed
- The redirect is now wired as a separate `apply_cmt_redirect_late` handler at priority **70**, strictly after JSF's merge, so the split sees the combined `meta_query` (JE base + JSF filters) and routes every CMT clause into `custom_table_query`. JSF sort filters on CMT fields work the same way — the orderby rewrite also happens at p70.

## 0.7.1

### Fixed
- CMT redirect emitted the unprefixed table name (`ad_listing_meta` instead of `wp_xxx_ad_listing_meta`), so the resulting `INNER JOIN` referenced a non-existent table and the query silently returned 0 rows. The bridge now obtains the prefixed name via `Manager::get_db_instance($slug, $fields)->table()` — the same path JE itself uses internally — instead of `Manager::get_table_name($slug)`. Both the JE bridge (`apply_cmt_redirect`) and the JSF Filter Indexer (`detect_cmt_for_args`) are corrected.

## 0.7.0

### Added
- JE Query Builder bridge: support for JetEngine **Custom Meta Tables** (post types with Custom Storage enabled).
- The bridge now replicates JE's `pre_get_posts` splitter inline (`apply_cmt_redirect()`): when the JE query's post type uses CMT, the bridge splits the applied `meta_query` into custom-table clauses vs `wp_postmeta` clauses, rewrites `orderby` for CMT-stored sort keys, and sets the `custom_table_query` query var. JE's global `posts_clauses` filter then emits the CMT JOIN/WHERE/ORDER.
- JSF Filter Indexer: `[jsf_etch_count]` and per-option counts on filter dropdowns now read from the CMT table when the loop's post type uses Custom Storage. Multi-key filters automatically split between CMT columns and `wp_postmeta` and merge counts into a unified bucket.

### Notes
- CCT (Custom Content Types — separate `wp_jet_cct_*` tables): no change needed — JE already exposes CCT queries as SQL_Query type, which the bridge handles via the existing pre-fetch + `post__in` path.
- This release fixes the long-standing bug where Posts queries with CMT `meta_query` returned 0 rows because our `pre_get_posts` priority 40 ran AFTER JE's CMT splitter at priority 10.

## 0.6.1

### Fixed
- JE Query Builder bridge was never instantiated. JetEngine registers `\Jet_Engine\Query_Builder\Manager` on `init` priority `-1` (via its components-manager), but the bridge bootstrap checked `class_exists()` at `plugins_loaded` p10 — too early. The check returned `false`, the bridge constructor never ran, no hooks were attached, and Etch loops kept rendering their built-in query unchanged. The bridge is now booted on `init` p0 (after JetEngine has registered Query_Builder, before any block render).

## 0.6.0

### Added
- JE Query Builder bridge: support for Data Stores Query (favourites, recently viewed, comparisons, etc.).
- Target type auto-detected from the store: post stores → `wp-query` Etch preset, user stores → `wp-users` Etch preset.
- `je-jsf-stack` mode also works for Data Stores Query: `final_query['max_items']` is set to `-1` (unlimited) and the inner query cache is reset via `reset_query()` so the override takes effect.

### Fixed
- Latent re-entrancy bug in Merged / SQL paths: JE's internal sub-queries / `$wpdb->get_results()` could re-fire `pre_get_posts` / `pre_user_query` / `pre_get_terms` while the bridge was still extracting IDs, causing recursion or arg corruption. Added an `in_extraction` guard around all JE method calls that may instantiate `WP_*_Query`.

### Changed
- Admin page documents Data Stores setup, target type detection rules, and dedicated troubleshooting.

## 0.5.0

### Added
- New opt-in mode `je-jsf-stack` for Merged / SQL queries: enables JSF filter / pagination / sort + the `[jsf_etch_count]` shortcode by fetching the full JE result set and letting WP_Query / JSF natively paginate the `post__in` subset.
- Wrapper class hint state is now encoded as pipe-separated tokens (`{id}|as={type}|stack=1`).

### Changed
- Bridge overrides JE pagination caps (`max_items_per_page` / `limit_per_page` / `limit` / `_page`) only when the wrapper carries `je-jsf-stack`. Default mode unchanged (JE owns pagination).
- Admin docs: dedicated section for `je-jsf-stack` with required wrapper combo, behaviour notes, and trade-offs.

## 0.4.0

### Added
- JE Query Builder bridge: support for SQL queries.
- Target type for SQL is inferred from (in priority order): `je-as-{posts|users|terms}` wrapper class hint → JE SQL query's `cast_object_to` setting (`WP_Post` / `WP_User` / `WP_Term`) → default `posts`.
- ID extraction handles `WP_Post` / `WP_User` / `WP_Term` instances and raw `stdClass` rows from `$wpdb->get_results()` via heuristic column lookup (`ID` / `id` / `post_id` / `user_id` / `term_id`).

### Changed
- Refactored Merged + SQL handling into shared `apply_ids_to_*` methods + a generalised `extract_ids_from_get_items()`.
- Admin page documents SQL setup, target type inference rules, ID extraction heuristics, and dedicated troubleshooting.

## 0.3.0

### Added
- JE Query Builder bridge: support for Merged Query (base types Posts / Users / Terms).
- Merged queries are pre-fetched via `get_items()`, IDs extracted, and fed to the Etch loop via `post__in` (Posts) or `include` (Users / Terms) with order preserved.

### Fixed
- Latent bug from v0.2.0 where Merged Posts queries were silently passing their nonsense `array_merge` of sub-query args to `WP_Query`.

### Changed
- Admin page documents Merged setup, caveats (no JSF combination), and dedicated troubleshooting.

## 0.2.0

### Added
- JE Query Builder bridge: support for Users and Terms query types in addition to Posts.
- New hook dispatchers: `pre_user_query` (p10) for Users, `pre_get_terms` (p10) for Terms.

### Changed
- Defensive `fields = "all"` override for Users and Terms to ensure `WP_User` / `WP_Term` objects reach Etch's loop iteration.
- Admin page updated with type-matching reference table and troubleshooting for Users / Terms loops.

## 0.1.0

### Added
- Initial release. Pre-1.0 testing version.
