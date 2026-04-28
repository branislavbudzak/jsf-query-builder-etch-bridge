# Changelog

All notable changes to this project are documented here. The format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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
