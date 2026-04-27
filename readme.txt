=== JSF Query Builder Etch Bridge ===
Contributors: branobudzak
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Drive Etch native Query Loops with JetSmartFilters and/or JetEngine Query Builder. Each bridge works independently.

== Description ==

Two independent bridges for the Etch page builder's native Query Loop block:

1. **JetSmartFilters bridge** — registers an "Etch Loop" content provider so JSF filter, pagination, and sort blocks can drive any Etch Query Loop with AJAX.
2. **JetEngine Query Builder bridge** — lets a JE Query Builder query become the data source for an Etch Query Loop, replacing the loop's built-in query.

Each bridge runs on its own. Use either, both, or none.

= Features =

* JSF as content provider for Etch loops (initial-load + AJAX filtering).
* Multi-loop support on a single page via `jsf-etch-q-{slug}` classes.
* `[jsf_etch_count]` shortcode showing live found_posts / max_num_pages / current page.
* Per-option counts on JSF filters via `pre-get-indexed-data` hook (taxonomy and postmeta).
* JetEngine Query Builder queries (Posts type) as Etch loop data sources, by ID or slug.
* Both bridges can layer on the same wrapper — JE provides the base query, JSF stacks filters on top.

= Requirements =

* WordPress 6.4+
* PHP 8.0+
* Etch (required for both bridges)
* JetSmartFilters (optional — only for JSF bridge)
* JetEngine (optional — only for JE Query Builder bridge)

= Limitations =

* JE bridge supports only "posts" query type.
* Only loopId-mode Etch loops are supported (target / expression mode bypasses WP_Query).
* JSF Filter Indexer counts skip range filters and CCT (custom meta tables).

== Installation ==

1. Upload `jsf-query-builder-etch-bridge` to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to **Settings → JSF Etch Bridge** for usage instructions.

== Changelog ==

= 0.1.0 =
* Initial release. Pre-1.0 testing version.
