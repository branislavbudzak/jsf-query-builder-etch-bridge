# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this plugin does

Two independent bridges that drive Etch's native Query Loop block from external query systems:

1. **JSF bridge** — registers an `Etch Loop` content provider for JetSmartFilters. Filter / pagination / sort blocks can drive any Etch loop (initial-load + AJAX), with multi-loop support via `jsf-etch-q-{slug}` classes, an indexer for per-option counts, and a `[jsf_etch_count]` shortcode.

2. **JE Query Builder bridge** — lets a JetEngine Query Builder query become the data source for an Etch loop. Supports query types: `posts`, `users`, `terms`, `Merged_Query` (with base types posts / users / terms), and `SQL_Query` (target type inferred from `cast_object_to` or `je-as-{type}` wrapper hint).

Each bridge runs only if its target plugin is active. Etch is the only hard dependency for either to do anything useful.

## Architecture (must read before editing)

### Bridge instantiation timing

`Plugin::boot()` runs at `plugins_loaded` (default priority). It instantiates bridges immediately at that hook — NOT at `init`. This is critical because JSF fires its `jet-smart-filters/providers/register` action at **`init` priority `-998`**. If our `JSF_Bridge` weren't ready before that, the `Etch Loop` provider would never register.

At `plugins_loaded`, all plugin main files have been included, so JSF and JE class definitions exist regardless of plugin load order.

### State_Stack pattern

Both bridges use a per-bridge `State_Stack` instance (`includes/class-state-stack.php`) keyed by Etch wrapper class. The flow per bridge:

1. `pre_render_block` (priority 4 / 5) → push wrapper's `query_id` to the stack
2. The matching pre-query hook (`pre_get_posts` / `pre_user_query` / `pre_get_terms`) → read top of stack, mutate query, pop
3. `render_block` (priority 999) → safety-net pop if no matching hook fired (e.g. wrapper without inner loop, or JE query type mismatched the Etch preset type)

The two bridges have independent State_Stacks → no cross-contamination when both classes appear on the same wrapper.

### Hook priority ladder (when both bridges are active on the same wrapper)

```
pre_render_block  p4     JE bridge captures je-etch-loop wrapper
pre_render_block  p5     JSF bridge captures jsf-etch-loop wrapper
pre_get_posts     p40    JE wholesale-replaces WP_Query args
pre_get_posts     p50    JSF tags the query (jet_smart_filters = etch-loop/{id})
pre_get_posts     p60    JSF merges filter args on top of JE base
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
  class-je-query-builder-bridge.php     JE integration with type dispatch (Posts / Users / Terms / Merged)
  class-shortcode.php                   [jsf_etch_count] shortcode
  class-admin-page.php                  Settings → JSF Etch Bridge (English docs, conditional sections)
assets/js/count.js                      [jsf_etch_count] live updater (subscribes to JSF event bus)
```

## Filterable behaviour

- `apply_filters('jqbeb_loopback_sslverify', false)` — set to `true` (or `__return_true`) on production for proper SSL verification on the JSF AJAX self-loopback.

## Versioning workflow

This is a pre-1.0 plugin currently. Bump cadence:

- **Bug fix**: `0.x.y` → `0.x.(y+1)` with `fix:` commit prefix.
- **Feature**: `0.x.y` → `0.(x+1).0` with `feat:` commit prefix.
- **1.0.0**: only when user explicitly confirms staging behaviour is solid.

When bumping, update **all three** in the same commit:
1. Plugin header `Version:` in `jsf-query-builder-etch-bridge.php`
2. `JQBEB_VERSION` constant in the same file
3. `readme.txt` `Stable tag:` and `== Changelog ==` entry

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
- **JSF + Merged / SQL works ONLY in `je-jsf-stack` mode.** Default Merged / SQL behaviour fetches a JE-paginated slice (one page) and force-disables WP_Query pagination, which breaks JSF and the count shortcode. With `je-jsf-stack`, the bridge fetches the FULL JE filter set, leaves WP_Query pagination flags alone, and lets WP_Query / JSF natively paginate the `post__in` subset. Cost: full fetch on every render — fine for moderate sets, expensive for large ones.
- **SQL queries must return a recognisable ID column.** The heuristic looks for `ID` / `id` / `post_id` / `user_id` / `term_id`. Rows without one are silently skipped during ID extraction.
- **Only `loopId`-mode Etch loops are bridged.** `target` / expression mode bypasses `WP_Query` entirely.
- **Filter Indexer counts skip range filters and JetEngine CCT (custom meta tables).** Only `tax_query` and `meta_query` are supported.

## Security stance

- Loopback AJAX forwards all cookies via `wp_remote_get()` so authenticated content is correctly resolved. SSL verification is off by default (local-dev compat) but filterable.
- `<!--JQBEB-PROPS:...-->` markers are stripped from the AJAX response inner HTML before send, so they never reach the browser DOM. Only used internally for parent → loopback prop transport.

## When to reach for an agent

The bridge is a self-contained ~1,500 LOC plugin. Most edits are local. Reach for an Explore agent only when:

- Verifying Etch / JSF / JE internal behaviour after a major upstream version bump (the integration depends on internal classes / hooks that Etch / JSF / JE could break without notice).
- Tracing why a `jsf-etch-loop` wrapper isn't AJAX-updating — the loopback path goes through DOMDocument + XPath which is fragile against unbalanced HTML.
