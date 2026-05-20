# JSF Query Builder Etch Bridge

A WordPress plugin that connects [Etch](https://etchwp.com/)'s native Query Loop block to two external query systems: [JetSmartFilters](https://crocoblock.com/plugins/jetsmartfilters/) and [JetEngine Query Builder](https://crocoblock.com/plugins/jetengine/). Use either, both, or none — each bridge runs independently.

## What it does

The plugin ships independent bridges:

- **JSF bridge** — registers an `Etch Loop` content provider for JetSmartFilters. Filter, pagination, and sort blocks drive the Etch loop with AJAX, with full support for JetEngine Custom Meta Tables (filtering, sorting, indexer counts, dynamic range bounds, live recalculation), an author-controlled empty-results state, and a live `[jsf_etch_count]` shortcode.
- **JetEngine Query Builder bridge** — lets a JE Query Builder query become the data source for an Etch Query Loop. The Etch preset's args are wholesale-replaced by the JE query, so the loop gets JE's full filter pipeline (including JetEngine's Custom Meta Tables and dynamic args).
- **JE Data Store Button inside Etch loops (v1.2.0+)** — drop the JetEngine Data Store Button block (favourites, recently-viewed, comparison) into any Etch loop card and each button automatically targets the correct loop item. No wrapper class needed.

Both bridges can layer on the same wrapper. JE provides the base query, JSF stacks user filters on top.

## Features

### JSF bridge

- **Etch Loop** as a JSF content provider — initial load + AJAX, pagination, sort.
- **Multi-loop support** per page via `jsf-etch-q-{slug}` classes — each loop addressable by its own JSF Query ID.
- **`[jsf_etch_count]` shortcode** showing live `found_posts` / `max_num_pages` / current page (auto-updates on filter changes via the JSF event bus).
- **Per-option indexed counts** on JSF filter dropdowns — `tax_query` and `meta_query`, including JetEngine **Custom Meta Tables**.
- **Range filter dynamic min/max** for JE Custom Meta Tables — sliders auto-resolve their bounds from the right table whether the user picks JSF's *Get from Post Meta by query meta key* or JE's native *{Post Type}: Get from custom storage by query meta key* in the filter dropdown.
- **Range filter live recalculation** (JSF 3.8.0+) — slider bounds re-resolve against the current filter context as the user changes other filters, scoped so a slider's own clause is excluded from its own bounds (so it can always widen back from a narrow selection).
- **Author-controlled empty-results state** — drop any Etch element (heading, card, CTA, dynamic data block) on the page with class `jsf-etch-empty-state` and the bridge auto-shows it when an AJAX filter yields zero results, hides it otherwise. Plus an `is-empty` CSS hook on the loop wrapper for one-line CSS solutions.
- **Fast in-process AJAX render** — no full-page HTTP loopback; mirrors JetEngine's listing-grid speed.

### JetEngine Query Builder bridge

- Any JetEngine Query Builder query becomes the data source for an Etch Query Loop — by ID or slug.
- Supported types: **Posts**, **Users**, **Terms**, **Merged Query**, **SQL Query**, **Data Stores Query**.
- SQL queries auto-extract IDs from result rows (`WP_Post` / `WP_User` / `WP_Term` instances or raw `stdClass` rows with `ID` / `id` / `post_id` / `user_id` / `term_id`).
- Data Stores Query target type auto-detected from the store's post-vs-user setting.
- Wrapper class hints — `je-as-{posts|users|terms}` (target-type override for SQL), `je-jsf-stack` (full JE result-set fetch for native pagination).

### JetEngine Data Store Button inside Etch loops (v1.2.0+)

Drop the JE **Data Store Button** block (favourites, recently-viewed, comparison stores) into any Etch loop card and each button automatically binds to the correct loop item — frontend renders `data-post="{loop-item-id}"` per card with no wrapper class or extra configuration.

- Works for plain Etch posts loops, the JE Query Builder bridge above, and JSF-bridged loops.
- Works for post stores AND user stores (target follows the store's setting).
- Works for initial render and JSF AJAX re-renders.
- Other JE dynamic blocks (Dynamic Field / Image / Link) are unaffected — Etch resolves those via its own dynamic-data layer.
- Extend to third-party JE add-on blocks via the `jqbeb_loop_context_block_names` filter.

Background: the Data Store Button resolves its target via `jet_engine()->listings->data->get_current_object()`, which JE updates on the `the_post` hook. Etch's loop block does not fire `the_post` (it uses its own DynamicContextProvider stack instead of `setup_postdata()`), so without this bridge every button on every card bound to the host page. JE's own Listing Grid was unaffected because it runs `WP_Query` the standard way.

### JetEngine Custom Meta Tables (CMT) — end-to-end

When the loop's post type uses Custom Storage, the bridge routes ALL of these to the right table (none of it falls back to `wp_postmeta`):

- JE base `meta_query` and `orderby` on CMT fields.
- JSF user filtering and sorting on CMT fields.
- Filter Indexer per-option counts on CMT fields.
- `[jsf_etch_count]` over CMT-filtered results.
- Range filter dynamic min/max + live recalculation on CMT fields.

### Combined: JSF + JE on the same wrapper

- JE provides the base query (post type, filters, custom args).
- JSF stacks user filters / pagination / sort on top.
- For Merged / SQL / Data Stores, opt in via `je-jsf-stack` to enable JSF + native pagination over the JE result set.

## Requirements

| Plugin | Required for |
| --- | --- |
| **Etch** | Both bridges. Without Etch the plugin silently no-ops. |
| **JetSmartFilters** | JSF bridge only. |
| **JetEngine** | JE Query Builder bridge only (Custom Meta Tables and Query Builder). |

- WordPress **6.4+**
- PHP **8.0+**

## Installation

1. Download the latest release zip from [Releases](https://github.com/branislavbudzak/jsf-query-builder-etch-bridge/releases).
2. WordPress admin → **Plugins → Add New → Upload Plugin** → upload zip → Activate.
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

### Empty-state UX

When a filter pass yields zero results, the bridge clears the loop wrapper and exposes two CSS hooks:

- **`is-empty`** is added to the `.jsf-etch-loop` wrapper itself. CSS-only fallback for a quick text:
  ```css
  .jsf-etch-loop.is-empty::before {
      content: "No vehicles match these filters.";
      display: block; padding: 2rem; text-align: center;
  }
  ```
- **`is-active`** is added to any Etch element bearing the class `jsf-etch-empty-state` that's paired with the wrapper. Drop a Container / card / CTA / dynamic-data block in the Etch builder, give it that class, and it auto-shows when the loop is empty (hidden by default via injected `display:none !important`).

Multi-loop pages: pair empty-states explicitly with `data-for-query-id="<slug>"` matching the loop's `jsf-etch-q-<slug>` class.

## Documentation

The admin page (**Settings → JSF Etch Bridge**) is the canonical reference. It has 5 conditional tabs (only the relevant ones for your active dependencies are shown):

- **Overview** — what the plugin does, dependency status, wrapper-class cheatsheet.
- **JSF Bridge** — setup steps, `[jsf_etch_count]` shortcode, Filter Indexer, range filter dynamic min/max, empty-results state.
- **JE Bridge** — setup steps, query-type matching, Merged / SQL / Data Stores / `je-jsf-stack`.
- **Combined & CMT** — hook ladder, full Custom Meta Tables support matrix, performance tips.
- **Reference** — limitations and per-topic troubleshooting.

For implementation notes that go deeper than the admin page (Etch internals, JE timing traps, hook priority ladder, JSF version-specific behaviours), see [`CLAUDE.md`](./CLAUDE.md). Version history is in [`CHANGELOG.md`](./CHANGELOG.md).

## Filterable behaviour

- `apply_filters('jqbeb_loopback_sslverify', true)` — set to `__return_false` for local-dev environments with self-signed certs.
- `apply_filters('jqbeb_loopback_cache_enabled', true, $cache_user_id)` — disable the per-user 60-second loopback cache (return `false`) on sites with anonymous personalised content (cart, geo, A/B).
- `apply_filters('jqbeb_range_cmt_override_enabled', true, $args, $instance)` — opt out of the range filter dynamic min/max + live recalculation against JE CMT tables. Falls back to JSF's native behaviour (which yields empty bounds for CMT fields).
- `apply_filters('jqbeb_empty_results_payload', '<!--jqbeb:empty-results-->', $inner)` — substitute the sentinel comment that the bridge emits when the AJAX-rendered loop has zero results. Replace with a styled `<div class="...">No vehicles match.</div>` placeholder if you want a server-rendered empty-state UI instead of the JS-toggled `.jsf-etch-empty-state` Etch element approach.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). See plugin header for details.

## Author

Branislav Budzák
