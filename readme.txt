=== JSF Query Builder Etch Bridge ===
Contributors: branobudzak
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.7.0
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
