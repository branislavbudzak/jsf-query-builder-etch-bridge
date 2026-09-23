# Changelog

All notable changes to this project are documented here. The format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 1.3.5 - 2026-09-23

### Security
- Stop accepting browser-supplied JSF defaults for the `etch-loop` provider. Capture the server-generated baseline before user filters are applied and allow only supported filter, search, sort and pagination arguments from the parsed request. This closes post-status, post-type and ID-scope overrides through defaults, sort JSON and plain-query parameters.
- Use the same trusted baseline for indexer counts and dynamic range recalculation, including custom keys registered through `jqbeb_jsf_default_query_keys`. Requests without a rendered baseline fail closed for these auxiliary queries.
- Carry authenticated defaults from the server-side loopback response into indexer/range processing and the existing per-user response cache. Include the plugin version in response-cache keys so vulnerable cached responses are not reused after upgrade.
- Intersect server and client meta/tax/date groups with AND while preserving each group's internal relation. Other JSF providers remain unchanged.

### Verification
- Added `php tests/ajax-query-security.php` (requires an installed JetSmartFilters query manager, optionally supplied as the first argument). Covers the real JSF parser and bridge provider, scope overrides, server defaults, indexer/range context, loopback signatures and provider isolation.
- Reproduced draft disclosure through an anonymous HTTP request on a local WordPress site running 1.3.4, using a temporary draft fixture. The same request with 1.3.5 stays within the public catalog.
- Checked anonymous filtering, price sorting, pagination, empty search, indexer counts and dynamic ranges on direct rendering and cold loopback paths. Existing CMT and default-key regression suites pass.

### Compatibility
- Custom integrations that inject arbitrary query vars through plain-query or sorting payloads must move their baseline restrictions into server-side loop configuration. Controls-only requests with no server-rendered baseline return empty auxiliary results.
- A pre-existing date-sort issue with a retained CMT `meta_key` also reproduces on 1.3.4 and is not changed by this security patch.

## 1.3.4 - 2026-09-21

### Added
- Filter `jqbeb_jsf_default_query_keys` (`JSF_Bridge::default_query_keys()`) for the allowlist of query vars stored as the Etch loop's JSF default query. The Filter Indexer rebuilds its count queries from these defaults on AJAX requests (JS sends them back in `defaults`), so a site-specific flag that scopes the loop in its own `pre_get_posts` never reached the count query. Filter-option counts could then be computed over whatever `post__in` the browser sent, while the loop itself stayed correctly scoped. Default keys are unchanged; non-string or non-array filter results fall back to the defaults.

### Tests
- Added `php tests/default-query-keys.php`: defaults unchanged, filtered key stored, deduplication, fallback on a broken filter.
- Verified on staging with a site flag added through the filter: indexer queried IDs with 20 foreign + 5 own IDs in `post__in` return only the 5 own; an anonymous request returns none.

## 1.3.3 - 2026-09-21

### Fixed
- Route late JetSmartFilters sorting and meta filters through JetEngine Custom Meta Tables for native Etch loops tagged `etch-loop/<query-id>`, even without a JE Query Builder query ID. Previously the p70 redirect skipped these loops, so numeric sorts could query `wp_postmeta` instead of CMT and return no results.
- Preserve CMT base restrictions already extracted by JetEngine at p10 when applying the late p70 redirect. New CMT restrictions are intersected with the existing group, not substituted for it; an existing CMT order mapping is retained when no new CMT sort replaces it.
- Keep the redirect scoped to bridge queries: unrelated JSF providers, main queries, and ordinary admin queries remain excluded.

### Tests
- Added `php tests/cmt-late-scope.php`: 15 regression checks covering provider scope, preserved restrictions, order mappings, and ordinary metadata.
- Verified numeric sorting in both directions for four CMT fields on initial-page and bridge-AJAX contexts, plus combined filters, on a staging site with 116 listings.

## 1.3.2

### Fixed
- **JSF sorting (and any filter value containing a quote, backslash, `&` or `#`) is no longer silently dropped on the AJAX HTTP-loopback path.** `JSF_Provider::ajax_get_content()` built the loopback URL from `$forwarded = $_REQUEST`. WP's `wp_magic_quotes()` slashes `$_REQUEST` on every request, and JSF ships its sort payload as a JSON **string** (`query[_sort_standard]={"orderby":"meta_value_num","order":"ASC","meta_key":"price_sale_gross"}`), so what we read back is already `{\"orderby\":…}`. Forwarding that verbatim meant the loopback request's own `wp_magic_quotes()` slashed it a second time, `{\\\"orderby\\\":…}`, while JSF's sort parser (`json_decode( wp_unslash( $value ) )`, jet-smart-filters/includes/query.php:681) strips exactly one level. `json_decode()` therefore received `{\"orderby\":…}`, returned `null`, and JSF's `if ( ! $data ) { continue; }` discarded the entire sort clause. No warning, no log, HTTP 200, the loop just rendered in its default order. Fixed by unslashing (`wp_unslash`) before the URL is assembled, and URL-encoding the values (`urlencode_deep`) because `add_query_arg()` does not encode the args it is handed and `build_query()` runs with `$urlencode = false`, an unencoded `&` inside a search term used to split the loopback query string and return the wrong result set too.
- **Non-default-language pages (TranslatePress multi-domain) now reach the AJAX fast path instead of permanently falling back to the HTTP loopback.** The block-tree transient is keyed on `JSF_Bridge::current_path()`, which read `$_SERVER['REQUEST_URI']`. TranslatePress SEO Pack's `Slug_Manager::translate_request_uri()` runs at `plugins_loaded` priority 3 and **overwrites** `$_SERVER['REQUEST_URI']` with the default-language slug, stashing the browser's real URI in the global `$TRP_ORIGINAL_REQUEST_URI`. So a page served at `nearcharger.cz/koupit-ev/` wrote its cache entry under `/kupit-ev/` (the Slovak slug), and clobbered the Slovak entry doing it, while the AJAX request from that page looked the cache up by its own referer, `/koupit-ev/`. Permanent miss on every request for every translated URL. `current_path()` now prefers `$TRP_ORIGINAL_REQUEST_URI` when TRP recorded one and falls back to `REQUEST_URI` everywhere else.

### Why this looked like a domain-specific bug (read this before re-investigating)
Reported as "sorting by price doesn't work on nearcharger.cz but does on nearcharger.sk". Two days went into TranslatePress AJAX JSON rewriting, LiteSpeed JS combine, JSF signature verification and corrupted meta keys. All dead ends, and the earlier handoff's conclusion (*"the fast path is broken, the loopback works"*) was exactly **inverted**.

What was actually happening: the fast path was always fine. The **loopback** was the broken one, and the only thing that decided which path a request took was whether the `jqbeb_block_<md5(path|query_id)>` transient existed for the referer's path. Because of the `current_path()` bug above, the Czech path `/koupit-ev/` **never** had an entry, so nearcharger.cz was pinned to the loopback, and therefore to the double-slashing bug, 100 % of the time, while nearcharger.sk sat on the fast path and sorted correctly. Nothing about the domain, TranslatePress's AJAX output filter, or LiteSpeed was involved; TRP mattered only because it rewrites `REQUEST_URI`.

Verification note for the future: `wp transient get jqbeb_block_$(php -r 'echo md5("/your-path/|default");')` tells you which path a given page will take. A single page view warms the entry, so **loading the page yourself flips the system to the fast path** and can mask or unmask the bug mid-test.

## 1.3.1

### Fixed
- **`jqbeb-range-fill` no longer produces a "Missing Dependencies: jet-smart-filters (missing)" report in Query Monitor.** `JSF_Bridge::enqueue_count_script()` enqueued `assets/js/range-fill.js` on `wp_enqueue_scripts` with a hard `[ 'jet-smart-filters' ]` dependency, but JSF registers that script handle both LATE and CONDITIONALLY: `Jet_Smart_Filters_Filter_Manager::filter_scripts()` (jet-smart-filters/includes/filters/manager.php:36) is hooked to `wp_footer` priority 15 and early-returns while `jet_smart_filters()->filters_not_used` is still true, i.e. on every page that renders no JSF filter block. On those pages the handle never exists, so `WP_Dependencies` cannot resolve our dependency, silently drops `jqbeb-range-fill` from the output, and Query Monitor reports it on each request. Bridge now enqueues the script from a dedicated `wp_footer` priority 16 callback (`JSF_Bridge::enqueue_range_fill_script()`) — after JSF's p15, before `wp_print_footer_scripts` at p20 — gated on `wp_script_is( 'jet-smart-filters', 'enqueued' | 'registered' )`. Filter-less pages skip the enqueue entirely (the script is a no-op there — it only walks `window.JetSmartFilters.filterGroups`, which does not exist), and pages that do render filters keep the explicit dependency so load order is still guaranteed. No user-visible behaviour change; range filters keep filling exactly as in 1.0.3+.

## 1.3.0

### Fixed
- **JSF pagination / sort on archive, taxonomy, and CPT-archive pages now preserves the main-query context for JE Query Builder Dynamic Args.** Until 1.2.x, the JSF AJAX fast-path (`JSF_Provider::ajax_get_content` → direct `render_block()` of the cached wrapper tree) ran inside `admin-ajax.php` where `$GLOBALS['wp_query']` is the admin-ajax catch-all: no `is_tax()` / `is_post_type_archive()` / `is_author()` flags, no `queried_object`, no archive-related `query_vars`. Any JE Query Builder query whose Dynamic Args read `get_queried_object()` / `get_queried_term_id` / `is_*()` (e.g. a brand archive query that filters listings by "current queried term") would silently collapse to "no filter" on AJAX and resolve against the entire universe of posts. Visible symptom: page 1 renders correctly (e.g. 56 brand-scoped Tesla listings, 5 pages); page 2 click via JSF returns rows from the full unfiltered set (e.g. found_posts jumps to 212 across 18 pages) and the user sees unrelated cars in place of the next page. The bug was identical in mechanism to the long-documented `WpMainQueryLoopHandler` issue but surfaced on JE-bridged loops because JE Dynamic Args is the more common Etch pattern for archive templates. Bridge now snapshots the main-query state at initial render and rebuilds it before the AJAX render — see Added below.
- Single CPT pages with JE queries whose Dynamic Args read `get_queried_object()` are similarly fixed. Previously the AJAX path set only `$GLOBALS['post']` (via `setup_postdata`), not `$wp_query->queried_object` — `get_queried_object()` reads from the latter, so JE macros bound to the queried post returned null on AJAX. The new context snapshot covers `is_singular` + post-typed `queried_object` so the singular case now works the same as the archive case.
- **`[jsf_etch_count]` shortcode no longer flashes "0" before the real value on initial page load when the shortcode renders above the loop block in source order.** Previously the shortcode could only resolve synchronously via `jet_smart_filters()->query->get_query_props()`, which is populated by JSF's `the_posts` priority 999 hook (jet-smart-filters/includes/query.php:31) — meaning a shortcode rendered BEFORE the matching loop wrapper (header counter, hero section, sticky topbar, sidebar, "Found N vehicles" subtitle above the grid, etc.) had nothing to read and fell back to the configurable `placeholder='0'` default. JS in `assets/js/count.js` then patched the span's textContent on DOMContentLoaded, producing a brief but visible "0 → N" flash on every load. Bridge now resolves in two passes: shortcode renders emit a sentinel (`__JQBEB_COUNT_PENDING__`) when synchronous resolution fails, and a page-wide output buffer engaged on `template_redirect` priority 1 substitutes the now-known value before the response leaves PHP. Cheap `strpos` early-out keeps pages without the shortcode at zero added cost; the JS-side reactive update for AJAX filter / pagination / sort changes is unchanged.
- **`.jsf-etch-empty-state` Etch elements no longer flash visible-then-hidden on loops that have results.** The default-hide CSS rule (`display:none !important` on `:not(.is-active)`) was injected at runtime by `assets/js/empty-state.js` on DOMContentLoaded, so authored empty-state elements (heading "No vehicles match", card with a "request a vehicle alert" CTA, etc.) painted with normal Etch styling for the ~50-200ms before JS ran. Bridge now emits the same CSS rule inline in `<head>` via `JSF_Bridge::print_empty_state_styles()` so it applies before the browser paints any body content. `<style>` ID matches the runtime injector's `STYLE_TAG_ID`, so the JS-side idempotency check picks it up and skips re-injection — no double rule, no specificity surprises. Empty-state activation timing for the "no results" case is unchanged (JS adds `is-active` once the loop's empty status is known).

### Added
- **Main-query context snapshot in the block-tree transient cache** ([includes/class-jsf-bridge.php](includes/class-jsf-bridge.php) — `JSF_Bridge::on_render_block`). Alongside the parsed block tree and (legacy) `post_id`, the transient now carries a `context` field with:
  - **Conditional-tag flags** snapshot of `WP_Query::$is_*` properties (`is_singular`, `is_archive`, `is_tax`, `is_post_type_archive`, `is_category`, `is_tag`, `is_author`, `is_date`, `is_search`, `is_home`, `is_front_page`, `is_paged`, …) — the same allowlist the global `is_*()` helpers branch on.
  - **`query_vars` allowlist** subset (`post_type`, `tax_query`, `meta_query`, `paged`, `posts_per_page`, `cat`, `tag`, `author`, `s`, `name`, `pagename`, `page_id`, `year`, `monthnum`, `day`, `post__in`, `post__not_in`, `post_status`, etc.) — scalar / array / null only; objects and closures are dropped defensively.
  - **`queried_object` descriptor** (`{ type, id, taxonomy? }`) — type is `'post'` / `'term'` / `'user'` / `'post_type'`. Resolved back to a live `WP_Post` / `WP_Term` / `WP_User` / `WP_Post_Type` at restore time so a stale cache after a term rename / post update picks up fresh data.
- **`JSF_Bridge::capture_render_context()`** — public helper that builds the snapshot from the current request. Returns an empty-but-shaped array on pages with no main query (e.g. REST hits) so the caller doesn't have to null-check.
- **`JSF_Bridge::build_context_query( array $context ): ?WP_Query`** — rebuilds a `WP_Query` instance from the snapshot. Constructed via `new WP_Query()` with no args (skips `query()`, so no SQL fires), then we set `is_*` properties directly on the object, copy `query_vars` via `$query->set()`, and resolve + assign `queried_object` / `queried_object_id`. `get_queried_object()` global short-circuits via `isset( $this->queried_object )`, so the resolved instance is returned without `WP_Query::get_queried_object()` re-resolving from query_vars. Returns `null` for empty snapshots (legacy 1.2.x cache entries during the 1h TTL upgrade window).
- **AJAX fast-path context restoration** ([includes/class-jsf-provider.php](includes/class-jsf-provider.php) — `JSF_Provider::ajax_get_content`). Before `render_block( $direct_cached['block'] )`, the provider:
  1. Snapshots `$GLOBALS['wp_query']`, `$wp_the_query`, `$post`.
  2. Builds the fake `WP_Query` from the cached context and swaps it into both `$wp_query` and `$wp_the_query` (so `is_main_query()` on the fake also returns true, matching initial-render semantics).
  3. For singular contexts, also runs `setup_postdata( get_post( $post_id ) )` to populate `$post` + author-data globals.
  4. Calls `apply_filters_in_request()` to register the `pre_get_posts` p60 hook (as before).
  5. Renders the block.
  6. In `finally`, calls `wp_reset_postdata()` (only when singular setup happened), then restores the three snapshotted globals in reverse order. Cleanup is exception-safe and idempotent.
- **Page-wide output buffer for late `[jsf_etch_count]` substitution** ([includes/class-shortcode.php](includes/class-shortcode.php) — `Shortcode::maybe_start_buffer` + `Shortcode::substitute_pending`). Engaged on `template_redirect` priority 1, skipped for admin / AJAX / REST / feed contexts, runs once at buffer flush (PHP shutdown by default). Scans for `<span class="jsf-etch-count" …>__JQBEB_COUNT_PENDING__</span>` markers and rewrites them to the now-known JSF query prop value. The `data-jsf-placeholder` attribute is preserved so the substitution falls back gracefully if a loop somehow never rendered. Filterable via `apply_filters( 'jqbeb_count_late_substitution_enabled', true )` for sites with custom output-streaming setups.
- **Inline `<head>` CSS for default-hidden empty-state elements** ([includes/class-jsf-bridge.php](includes/class-jsf-bridge.php) — `JSF_Bridge::print_empty_state_styles()`, hooked to `wp_head` priority 10). Emits the same `.jsf-etch-empty-state:not(.is-active){display:none !important}` rule that `assets/js/empty-state.js` injects at runtime, but BEFORE any body paint. Filterable via `apply_filters( 'jqbeb_empty_state_default_hide_enabled', true )` for sites that ship their own hiding mechanism (visibility, opacity, off-screen positioning, server-side Etch Conditions taking over).

### Notes
- The 1.2.x transient shape (`{ block, post_id }`) is still honoured for cache hits during the 1h TTL after upgrade — `context === null` triggers the legacy singular-only restoration path (set `$GLOBALS['post']` via `setup_postdata`, no `$wp_query` swap). New writes always include `context`.
- The fake `WP_Query` swap is scoped strictly to the `render_block()` call. Other admin-ajax hooks running in the same request (JSF's own dispatch, our own hooks via `JSF_Bridge::$in_ajax_render` flag, the loop's inner `WP_Query` firing `pre_get_posts`) all see the fake during render and the original after `finally` — so the `is_main_query()` check on the LOOP's inner WP_Query still correctly returns false (it's a fresh `new WP_Query` instance, not the global, regardless of which "global" sits in `$wp_query`).
- The `'post_id' => get_queried_object_id()` field in the transient is kept for backward compat but is now redundant for non-singular pages — `get_queried_object_id()` on a term archive returns the term ID, which the 1.2.x AJAX path then passed to `get_post()` (returning null or a coincidentally-numbered post) and called `setup_postdata` on. The new path uses the `context.queried_object` descriptor instead, which carries the correct `type` discriminator.
- No new filter hooks introduced. The snapshot allowlist of flags + query_var keys is intentionally hardcoded — Dynamic Args evaluation pulls from a stable set of WordPress conditional tags and query vars, and exposing the list as a filter would invite drift between what the snapshot captures and what `build_context_query` sets. If a future JE / third-party block needs additional state captured (e.g. a non-standard query_var), extend the allowlist in code with a CHANGELOG note rather than opening it via filter.

## 1.2.0

### Added
- **JetEngine Data Store Button now works inside Etch loops.** Both for plain Etch posts loops (Etch's own query) and for loops driven by the JE Query Builder bridge. Previously every Data Store Button rendered inside an Etch loop card bound its `data-post` / `data-args.post_id` to the **page** the loop sat on, not the card's post — so clicking any "add to favourites" button on any card stored the host page. Root cause: JE's Data Store Button resolves its post via `jet_engine()->listings->data->get_current_object_id()`, which reads JE's internal `current_object`. JE updates `current_object` via the `the_post` hook ([jet-engine/.../listings/data.php:96](../jet-engine/includes/components/listings/data.php) → `set_current_object($post)`). Etch's loop does NOT call `setup_postdata()` and does NOT fire `the_post` — it uses its own `DynamicContextProvider` stack ([etch/.../LoopBlock/LoopBlock.php:120-152](../etch/classes/Blocks/LoopBlock/LoopBlock.php)). So JE never sees the iteration and `current_object` stayed pinned to the outer page. In JE Listing Grid the same button worked because Listing Grid runs WP_Query the standard way and `the_post` fires per iteration.
- **New sub-bridge: `JE_Loop_Context_Bridge`** ([includes/class-je-loop-context-bridge.php](includes/class-je-loop-context-bridge.php)). Hooks `pre_render_block` priority 5 and `render_block` priority 5; for any block name in the configured list (default: `jet-engine/data-store-button`) it walks Etch's `DynamicContextProvider` stack for the topmost `'loop'` entry, reads the entry's source via `DynamicContentEntry::get_source()`, stashes JE's previous `current_object`, calls `set_current_object($loop_item)`, lets the block render, then restores the previous value. Stack-shaped stash array so nested supported blocks (e.g. a Data Store Button inside another supported block) restore in correct order.
- Walks `array_reverse($stack)` so innermost-loop wins on nested Etch loops — the block is "in" the deepest loop.
- Accepts `WP_Post` / `WP_User` / `WP_Term` instances directly; falls back to `get_post( (int) $source )` for numeric ID sources. Anything else is skipped (returns null), which is the safe no-op fallback to JE's existing behaviour.
- Works for both initial page load and JSF AJAX in-process render — `pre_render_block` / `render_block` filters fire on every `render_block()` call regardless of context, and the bridge does not rely on the `JSF_Bridge::$in_ajax_render` flag.
- **New filter:** `apply_filters( 'jqbeb_loop_context_block_names', [ 'jet-engine/data-store-button' ] )`. Extend the supported-block list to cover third-party JE add-on blocks that use the same `get_current_object()` resolution pattern. Default scope is intentionally narrow — Etch's native dynamic-data resolution already handles JE Dynamic Field / Dynamic Image / Dynamic Link blocks (and other field-resolution blocks) via its own `DynamicContextProvider` lookup; adding them here would be a double-resolution and risk colliding with Etch's expected semantics.

### Notes
- Bridge boots at `plugins_loaded` (alongside the JSF bridge), gated only on `function_exists( 'jet_engine' )`. The hooks themselves do their own defensive checks for the Etch `DynamicContextProvider` class before each call, so the bridge silently no-ops on pages without Etch loops.
- No dependency on JE Query Builder — runs even when only JetEngine + Etch are installed.
- The original Data Store Button render path is unchanged. We only mutate JE's transient `current_object` state for the duration of the target block's render call.

## 1.1.1

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
