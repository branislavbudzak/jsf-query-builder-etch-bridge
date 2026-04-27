=== JSF Query Builder Etch Bridge ===
Contributors: branobudzak
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Drive Etch native Query Loops with JetSmartFilters and/or JetEngine Query Builder (Posts / Users / Terms / Merged Query). Each bridge works independently.

== Description ==

Two independent bridges for the Etch page builder's native Query Loop block:

1. **JetSmartFilters bridge** — registers an "Etch Loop" content provider so JSF filter, pagination, and sort blocks can drive any Etch Query Loop with AJAX.
2. **JetEngine Query Builder bridge** — lets a JE Query Builder query (Posts, Users, Terms, or Merged Query) become the data source for an Etch Query Loop, replacing the loop's built-in query.

Each bridge runs on its own. Use either, both, or none.

= Features =

* JSF as content provider for Etch loops (initial-load + AJAX filtering).
* Multi-loop support on a single page via `jsf-etch-q-{slug}` classes.
* `[jsf_etch_count]` shortcode showing live found_posts / max_num_pages / current page.
* Per-option counts on JSF filters via `pre-get-indexed-data` hook (taxonomy and postmeta).
* JetEngine Query Builder queries (Posts, Users, Terms, Merged Query) as Etch loop data sources, by ID or slug.
* Both bridges can layer on the same wrapper — JE provides the base query, JSF stacks filters on top.

= Requirements =

* WordPress 6.4+
* PHP 8.0+
* Etch (required for both bridges)
* JetSmartFilters (optional — only for JSF bridge)
* JetEngine (optional — only for JE Query Builder bridge)

= Limitations =

* JE bridge supports Posts, Users, Terms, and Merged Query types. SQL / Repeater / Comments are not supported (no compatible Etch loop preset).
* JE query type and Etch loop preset type must match (Posts↔wp-query, Users↔wp-users, Terms↔wp-terms). For Merged Query, match the merge's base type.
* JSF integration is Posts-only — JSF filters do not drive Users / Terms / Merged loops.
* Combining JSF with Merged Query is not supported (JSF expects a SQL-backed query, Merged predefines results via post__in).
* Only loopId-mode Etch loops are supported (target / expression mode bypasses WP_Query).
* JSF Filter Indexer counts skip range filters and CCT (custom meta tables).

== Installation ==

1. Upload `jsf-query-builder-etch-bridge` to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to **Settings → JSF Etch Bridge** for usage instructions.

== Changelog ==

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
