# JSF Query Builder Etch Bridge

A WordPress plugin that connects [Etch](https://etchwp.com/)'s native Query Loop block to two external query systems: [JetSmartFilters](https://crocoblock.com/plugins/jetsmartfilters/) and [JetEngine Query Builder](https://crocoblock.com/plugins/jetengine/). Use either, both, or none — each bridge runs independently.

## What it does

The plugin ships two independent bridges:

- **JSF bridge** — registers an `Etch Loop` content provider for JetSmartFilters. Filter, pagination, and sort blocks drive the Etch loop with AJAX, including per-option indexed counts and a live `[jsf_etch_count]` shortcode.
- **JetEngine Query Builder bridge** — lets a JE Query Builder query become the data source for an Etch Query Loop. The Etch preset's args are wholesale-replaced by the JE query, so the loop gets JE's full filter pipeline (including JetEngine's Custom Meta Tables and dynamic args).

Both bridges can layer on the same wrapper. JE provides the base query, JSF stacks user filters on top.

## Features

- Etch Loop as a JSF content provider (initial load + AJAX, pagination, sort).
- Multi-loop support on a single page via `jsf-etch-q-{slug}` classes.
- `[jsf_etch_count]` shortcode showing live `found_posts` / `max_num_pages` / current page.
- Per-option counts on JSF filter dropdowns (`tax_query` and `meta_query`, including JetEngine **Custom Meta Tables**).
- JetEngine queries as Etch loop data sources by ID or slug — supported types: **Posts**, **Users**, **Terms**, **Merged Query**, **SQL**, **Data Stores**.
- SQL queries auto-extract IDs from result rows (`WP_Post` / `WP_User` / `WP_Term` instances or raw `stdClass` rows with `ID` / `id` / `post_id` / `user_id` / `term_id`).
- Data Stores Query target type auto-detected from the store's post-vs-user setting.
- Full **JetEngine Custom Meta Tables** support — base meta_query, orderby, JSF user filters, JSF sort, and Filter Indexer counts all read/write the correct custom table.
- Opt-in `je-jsf-stack` mode for combining Merged / SQL / Data Store queries with JSF filters and pagination.

## Requirements

| Plugin | Required for |
| --- | --- |
| **Etch** | Both bridges. Without Etch the plugin silently no-ops. |
| **JetSmartFilters** | JSF bridge only. |
| **JetEngine** | JE Query Builder bridge only. |

- WordPress **6.4+**
- PHP **8.0+**

## Installation

1. Upload the plugin folder to `wp-content/plugins/` (or install from a zip).
2. Activate via **Plugins** in the WordPress admin.
3. Open **Settings → JSF Etch Bridge** for the in-product setup guide.

## Quick start

Add wrapper classes to your Etch loop's immediate parent container. The most common combos:

```html
<!-- Pure JSF, drives the loop with filters/pagination/sort -->
<div class="jsf-etch-loop">…</div>

<!-- JetEngine Query Builder query as data source -->
<div class="je-etch-loop je-q-42">…</div>

<!-- Both: JE base query + JSF user filters on top -->
<div class="jsf-etch-loop je-etch-loop je-q-partner-listings">…</div>

<!-- Multi-loop: disambiguate per loop -->
<div class="jsf-etch-loop jsf-etch-q-cars je-etch-loop je-q-cars">…</div>
```

Reference for every wrapper class (`je-as-{type}`, `je-jsf-stack`, etc.) lives in **Settings → JSF Etch Bridge** under the Overview tab.

## Documentation

The admin page (**Settings → JSF Etch Bridge**) is the canonical reference. It has 5 conditional tabs:

- **Overview** — what the plugin does, dependency status, wrapper-class cheatsheet.
- **JSF Bridge** — setup steps, `[jsf_etch_count]` shortcode, Filter Indexer.
- **JE Bridge** — setup steps, query-type matching, Merged / SQL / Data Stores / `je-jsf-stack`.
- **Combined & CMT** — hook ladder, full Custom Meta Tables support matrix, performance tips.
- **Reference** — limitations and per-topic troubleshooting.

For implementation notes that go deeper than the admin page (Etch internals, JE timing traps, hook priority ladder), see [`CLAUDE.md`](./CLAUDE.md). Version history is in [`CHANGELOG.md`](./CHANGELOG.md).

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). See plugin header for details.

## Author

Branislav Budzák
