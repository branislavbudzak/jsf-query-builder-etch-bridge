=== JSF Query Builder Etch Bridge ===
Contributors: branobudzak
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Drive Etch native Query Loops with JetSmartFilters and/or JetEngine Query Builder (Posts / Users / Terms / Merged Query / SQL / Data Stores Query). Each bridge works independently.

== Description ==

Two independent bridges for the Etch page builder's native Query Loop block:

1. **JetSmartFilters bridge** — registers an "Etch Loop" content provider so JSF filter, pagination, and sort blocks can drive any Etch Query Loop with AJAX.
2. **JetEngine Query Builder bridge** — lets a JE Query Builder query (Posts, Users, Terms, Merged Query, SQL, or Data Stores Query) become the data source for an Etch Query Loop, replacing the loop's built-in query.

Each bridge runs on its own. Use either, both, or none.

= Features =

* JSF as content provider for Etch loops (initial-load + AJAX filtering).
* Multi-loop support on a single page via `jsf-etch-q-{slug}` classes.
* `[jsf_etch_count]` shortcode showing live found_posts / max_num_pages / current page.
* Per-option counts on JSF filters via `pre-get-indexed-data` hook (taxonomy and postmeta).
* JetEngine Query Builder queries (Posts, Users, Terms, Merged Query, SQL, Data Stores Query) as Etch loop data sources, by ID or slug.
* SQL queries auto-extract IDs from result rows (WP_Post / WP_User / WP_Term instances OR raw stdClass with `ID` / `id` / `post_id` / `user_id` / `term_id` columns).
* Data Stores Query target type is auto-detected from the store's post-vs-user setting.
* Both bridges can layer on the same wrapper — JE provides the base query, JSF stacks filters on top.

= Requirements =

* WordPress 6.4+
* PHP 8.0+
* Etch (required for both bridges)
* JetSmartFilters (optional — only for JSF bridge)
* JetEngine (optional — only for JE Query Builder bridge)

= Limitations =

* JE bridge supports Posts, Users, Terms, Merged Query, SQL, and Data Stores Query types. Repeater / Comments are not supported (no compatible Etch loop preset).
* JE query type and Etch loop preset type must match (Posts↔wp-query, Users↔wp-users, Terms↔wp-terms). For Merged Query, match the merge's base type. For SQL, the target type is inferred from `cast_object_to` or `je-as-{type}` wrapper class hint.
* JSF integration with regular Posts queries works out of the box. For Merged / SQL Posts queries, opt in by adding `je-jsf-stack` wrapper class (full JE fetch + native WP_Query / JSF pagination + working `[jsf_etch_count]` shortcode).
* JSF integration with Users / Terms loops is NOT supported (no JSF content provider for those types in this version).
* SQL queries must return a recognisable ID column (`ID` / `id` / `post_id` / `user_id` / `term_id`). Rows without one are skipped.
* Only loopId-mode Etch loops are supported (target / expression mode bypasses WP_Query).
* JSF Filter Indexer counts skip range filters and CCT (custom meta tables).

== Installation ==

1. Upload `jsf-query-builder-etch-bridge` to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to **Settings → JSF Etch Bridge** for usage instructions.

== Changelog ==

= 1.0.0 =
* First public release. Folds the 0.x development line into a stable baseline. Both bridges (JSF + JE Query Builder) ship feature-complete with all advertised query types, JetEngine Custom Meta Tables support end-to-end (filter, sort, indexer counts, [jsf_etch_count] shortcode), and a fast in-process AJAX render path that mirrors JetEngine's listing-grid provider architecture.

= 0.10.0 =
* Performance: JSF AJAX (filter / pagination / sort) now renders the loop directly in-process instead of doing a full-page HTTP loopback (`wp_remote_get` to the same URL → DOMDocument extract). Each `jsf-etch-loop` wrapper block tree is cached in a transient at page render time, keyed by URL path + query_id (1 hour TTL). On AJAX, `JSF_Provider::ajax_get_content` retrieves the cached tree and calls `render_block()` directly — no HTTPS handshake, no theme/header/footer render, no full-page parse. Mirrors the architecture JetEngine's listing-grid provider uses (`jet_engine()->listings->get_render_instance()->render()`). Expected speedup: 2–3 seconds → 50–200 ms.
* Bridge hooks (`pre_render_block`, `pre_get_posts`, `render_block`, JE bridge equivalents, plus `JSF_Provider::apply_jsf_to_tagged_query`) now bypass their `wp_doing_ajax()` / `is_admin()` early-returns when `JSF_Bridge::$in_ajax_render` is set, so the loop's inner WP_Query gets re-tagged and JSF's provider hook re-applies paged + filter args during the in-process render exactly as on initial page load.
* Fix: State_Stack push moved from the `jsf-etch-loop` wrapper's `pre_render_block` to the `etch/loop` child block's `pre_render_block`. Wrapper-level push made the FIRST sub-query that fired during wrapper render (e.g. a single-row partner CPT lookup for dynamic-data dereference) "eat" the qid via `tag_query_for_jsf`'s pop, leaving the actual loop query untagged → JSF provider's paged / filter args were never applied → page 2 click rendered the same posts as page 1. Bridge now tracks open wrappers in `wrapper_qids_open[]` and pushes the topmost qid only when the `etch/loop` child fires — its very next WP_Query IS the loop's own query, so the tag lands on the right one.
* Fix: Direct AJAX render now calls `$this->apply_filters_in_request()` before `render_block()` to register the `pre_get_posts` p60 hook (`apply_jsf_to_tagged_query`). On regular page loads and HTTP loopback, JSF registers this hook for us via `apply_filters_from_request` → `apply_filters_in_request`. But `ajax_apply_filters` (admin-ajax dispatch) jumps straight to `ajax_get_content` without going through that path, so the hook never gets registered, the merge-paged-into-query step never runs, and the loop's WP_Query stays at paged=0 (page 1) regardless of the request's `paged` value. Adding the call manually re-aligns admin-ajax with the regular request path.
* Fix: JE bridge State_Stack push moved from the `je-etch-loop` wrapper's `pre_render_block` to the `etch/loop` child block's `pre_render_block` — same root cause as the JSF bridge fix above. Wrapper-level push made the FIRST sub-query that fired during wrapper render (e.g. a partner-CPT lookup with `post_type='partner', posts_per_page=1`) consume the JE state via `on_pre_get_posts` and pop the stack before the actual loop's WP_Query ran. On AJAX page-N click for an `je-q-{id}` SQL/Merged/Data-Stores loop, the JE bridge silently dropped out, the loop fell back to Etch's preset query, and JE filtering was lost. Bridge now tracks open wrappers in `wrapper_data_open[]` (encoded `{qid}|as=...|stack=1` form) and pushes the topmost entry to State_Stack only when the `etch/loop` child fires.
* Fix: CMT sort / filter on SQL / Merged / Data Stores loops returned 0 results. `apply_ids_to_posts` overrides `post_type` to `'any'` (because `post__in` already determines the result set), but `apply_cmt_redirect_late` matches a CMT storage by comparing `$storage['object_slug']` (e.g. `'ad-listing'`) against `(array) $query->get('post_type')` — `'any'` matched no storage, so the redirect bailed, and a JSF orderby on a CMT-stored field (`orderby=meta_value_num`, `meta_key=price_sale_gross`) ended up JOINing `wp_postmeta` (which has none of the CMT data) and returning 0 rows. Bridge now stashes the pre-override post_type in `_jqbeb_je_original_post_type` query var and the CMT redirect prefers that for storage matching, falling back to the live `post_type` (which is still correct for `apply_regular_to_posts`). Affects both sort and filter for SQL / Merged / Data Stores loops on CMT-backed CPTs.
* Fallback: HTTP loopback path is preserved unchanged for cache miss (transient expired, never rendered, different referrer, or extract failure). No regression for any working scenario.

= 0.9.1 =
* Fix: JSF Pagination AJAX fataled with `array_merge(): Argument #1 must be of type array, string given` in `Indexer_Data::prepare_ajax_data` whenever the loop preset left `meta_query`, `tax_query`, or `post__not_in` as a non-array value (Etch presets default `meta_query` to `false`). The bridge stored those raw `query_vars` as the JSF default query at `pre_get_posts` p50; JSF localised them, JS POSTed them back as `defaults` (form-encoded → `false` becomes the string `"false"`), and JSF's `merge_query_args` then called `array_merge("false", ...)`. `tag_query_for_jsf` now drops any non-array value for those three keys before calling `store_provider_default_query`, so JSF only ever sees arrays for keys it array_merges.

= 0.9.0 =
* Admin page rebuilt with a tabbed layout (Overview / JSF Bridge / JE Bridge / Combined & CMT / Reference). Conditional tabs only appear if the matching dependency is active. Active tab persists in URL hash.
* Hot, repetitive content collapsed into `<details>` sub-sections (Merged / SQL / Data Stores / je-jsf-stack / shortcode / per-troubleshooting topic) for faster scanning. Body content wrapped in `.jqbeb-card-body` for consistent padding in open state.
* `<details>` accordion arrow rebuilt as CSS-drawn triangle inside a flexbox-aligned summary; rotates cleanly without layout jump on open. Hover state for affordance. Nested sub-details get smaller grey/blue triangles for visual hierarchy.
* Inline dependency status pills in the page header (always visible).
* New "Combined & CMT" tab consolidates: hook-priority ladder, full CMT support matrix (JE base, JSF user filter, sort, indexer counts, [jsf_etch_count]), and a performance tip recommending MySQL indexes on hot CMT columns for range / sort queries.
* Updated content to reflect CMT support added in 0.7.0–0.8.1; previous "not supported" notes for CMT in the indexer have been removed.

= 0.8.1 =
* Fix: Filter Indexer counts (taxonomy and meta) were not being computed because the bridge never registered its loop's base query with JSF via `store_provider_default_query()`. JSF's `prepare_localized_data` iterates `get_default_queries()` to find providers eligible for indexing — providers without an entry are skipped, so no indexed_data was ever localized to JS, and AJAX filter changes sent empty `query_args` to the indexer endpoint (counting against the wrong post type). The bridge's `tag_query_for_jsf()` now stores a curated subset of the loop's query_vars (post_type, post_status, posts_per_page, meta_query, tax_query, date_query, orderby, order, meta_key, post__in, post__not_in, paged) at `pre_get_posts` priority 50 — after the JE bridge's arg injection at p40, before JSF's filter merge at p60 — so JSF's Indexer flow has a correct baseline for both the localized-data path and the AJAX path. Affects all loops driven by the bridge: pure JSF (Etch Loop) and JSF + JE Query Builder.

= 0.8.0 =
* JSF active filtering and sorting on CMT-stored fields now works end-to-end. Previously the bridge's CMT redirect ran at `pre_get_posts` priority 40, BEFORE JSF's filter merge at priority 60, so any filter clauses JSF added for a CMT field landed in `meta_query` (going to `wp_postmeta`) instead of `custom_table_query` (going to the custom table). The redirect is now wired as a separate `apply_cmt_redirect_late` handler at priority **70**, strictly after JSF's merge, so the split sees the combined meta_query (JE base + JSF filters) and routes every CMT clause into `custom_table_query`. JSF sort filters on CMT fields work the same way — the orderby rewrite also happens at p70.
* This completes CMT support for Posts queries: JE base meta_query + orderby, JSF user filters, JSF user sort, and Filter Indexer counts all read/write the correct table.

= 0.7.1 =
* Fix: CMT redirect emitted the unprefixed table name (`ad_listing_meta` instead of `wp_xxx_ad_listing_meta`), so the resulting `INNER JOIN` referenced a non-existent table and the query silently returned 0 rows. The bridge now obtains the prefixed name via `Manager::get_db_instance($slug, $fields)->table()` — the same path JE itself uses internally — instead of `Manager::get_table_name($slug)`, which only returns the slug-derived raw name. Both the JE bridge (`apply_cmt_redirect`) and the JSF Filter Indexer (`detect_cmt_for_args`) are corrected.

= 0.7.0 =
* JE Query Builder bridge: support for JetEngine **Custom Meta Tables** (post types with Custom Storage enabled).
* Bridge now replicates JE's `pre_get_posts` splitter inline (`apply_cmt_redirect()`): when the JE query's post type uses CMT, the bridge splits the applied `meta_query` into custom-table clauses vs `wp_postmeta` clauses, rewrites `orderby` for CMT-stored sort keys, and sets the `custom_table_query` query var. JE's global `posts_clauses` filter (registered by `Custom_Tables\Manager`) then emits the CMT JOIN/WHERE/ORDER. This fixes the long-standing bug where Posts queries with CMT meta_query returned 0 rows because our `pre_get_posts` priority 40 ran AFTER JE's CMT splitter at priority 10.
* JSF Filter Indexer: `[jsf_etch_count]` and per-option counts on filter dropdowns now read from the CMT table when the loop's post type uses Custom Storage. Multi-key filters (comma-separated meta keys) automatically split between CMT columns and `wp_postmeta` and merge counts into a unified bucket.
* CCT (Custom Content Types — separate `wp_jet_cct_*` tables): no change needed — JE already exposes CCT queries as SQL_Query type, which the bridge handles via the existing pre-fetch + `post__in` path.

= 0.6.1 =
* Fix: JE Query Builder bridge was never instantiated. JetEngine registers `\Jet_Engine\Query_Builder\Manager` on `init` priority `-1` (via its components-manager), but the bridge bootstrap checked `class_exists()` at `plugins_loaded` p10 — too early. The check returned `false`, the bridge constructor never ran, no hooks were attached, and Etch loops kept rendering their built-in query unchanged. The bridge is now booted on `init` p0 (after JetEngine has registered Query_Builder, before any block render).

= 0.6.0 =
* JE Query Builder bridge: added support for Data Stores Query (favourites, recently viewed, comparisons, etc.).
* Target type auto-detected from the store: post stores → wp-query Etch preset, user stores → wp-users Etch preset.
* `je-jsf-stack` mode also works for Data Stores Query: `final_query['max_items']` is set to `-1` (unlimited) and the inner query cache is reset via `reset_query()` so the override takes effect.
* Fixed a latent re-entrancy bug in Merged / SQL paths: JE's internal sub-queries / `$wpdb->get_results()` could re-fire `pre_get_posts` / `pre_user_query` / `pre_get_terms` while the bridge was still extracting IDs, causing recursion or arg corruption. Added an `in_extraction` guard around all JE method calls that may instantiate WP_*_Query.
* Admin page documents Data Stores setup, target type detection rules, and dedicated troubleshooting.

= 0.5.0 =
* New opt-in mode `je-jsf-stack` for Merged / SQL queries: enables JSF filter / pagination / sort + the `[jsf_etch_count]` shortcode by fetching the full JE result set and letting WP_Query / JSF natively paginate the `post__in` subset.
* Bridge overrides JE pagination caps (`max_items_per_page` / `limit_per_page` / `limit` / `_page`) only when the wrapper carries `je-jsf-stack`. Default mode unchanged (JE owns pagination).
* Wrapper class hint state is now encoded as pipe-separated tokens (`{id}|as={type}|stack=1`).
* Admin docs: dedicated section for `je-jsf-stack` with required wrapper combo, behaviour notes, and trade-offs.

= 0.4.0 =
* JE Query Builder bridge: added support for SQL queries.
* Target type for SQL is inferred from (in priority order): `je-as-{posts|users|terms}` wrapper class hint → JE SQL query's `cast_object_to` setting (`WP_Post` / `WP_User` / `WP_Term`) → default `posts`.
* ID extraction handles WP_Post / WP_User / WP_Term instances AND raw stdClass rows from `$wpdb->get_results()` via heuristic column lookup (`ID` / `id` / `post_id` / `user_id` / `term_id`).
* Refactored Merged + SQL handling into shared `apply_ids_to_*` methods + a generalised `extract_ids_from_get_items()`.
* Admin page documents SQL setup, target type inference rules, ID extraction heuristics, and dedicated troubleshooting.

= 0.3.0 =
* JE Query Builder bridge: added support for Merged Query (base types Posts / Users / Terms).
* Merged queries are pre-fetched via `get_items()`, IDs extracted, and fed to the Etch loop via `post__in` (Posts) or `include` (Users / Terms) with order preserved.
* Fixes a latent bug from v0.2.0 where Merged Posts queries were silently passing their nonsense `array_merge` of sub-query args to WP_Query.
* Admin page documents Merged setup, caveats (no JSF combination), and dedicated troubleshooting.

= 0.2.0 =
* JE Query Builder bridge: added support for Users and Terms query types in addition to Posts.
* New hook dispatchers: `pre_user_query` (p10) for Users, `pre_get_terms` (p10) for Terms.
* Defensive `fields = "all"` override for Users and Terms to ensure WP_User / WP_Term objects reach Etch's loop iteration.
* Admin page updated with type-matching reference table and troubleshooting for Users / Terms loops.

= 0.1.0 =
* Initial release. Pre-1.0 testing version.
