# JE loop-context bridge (v1.2.0+)

Lives in [`includes/class-je-loop-context-bridge.php`](../includes/class-je-loop-context-bridge.php). Solves a narrow but invisible-by-default bug: **JE blocks that read `jet_engine()->listings->data->get_current_object()` to resolve their target post operate on the host page, not on the loop card, when rendered inside an Etch loop.**

## Loop-source array shape (NOT a WP_Post)

A subtle but critical detail: Etch's loop handlers ([etch/.../LoopHandlers/WpQueryLoopHandler.php](../../etch/classes/Blocks/Global/Utilities/LoopHandlers/WpQueryLoopHandler.php:49-61)) do NOT push raw `WP_Post` / `WP_User` / `WP_Term` instances onto the `DynamicContextProvider` stack - they push the output of `get_dynamic_data($post)`, which is an **associative array** of post properties merged with `wp_parse_args($data, get_object_vars($post))`. So the loop entry's `get_source()` returns an array like `[ 'id' => 1234, 'ID' => 1234, 'title' => '…', 'post_type' => 'ad-listing', 'post_status' => '…', … ]`, NOT a `WP_Post`. The bridge has to detect the array shape and resolve back to an object via `get_post( $id )` / `get_user_by( 'id', $id )` / `get_term( $id )`. Type discrimination is by shape markers: post → `post_type`/`post_status`/`post_author`, user → `user_login`/`user_email`, term → `taxonomy`/`term_taxonomy_id`. If a future Etch version starts pushing raw instances instead, the bridge's `instanceof` short-circuit at the top of `resolve_loop_source()` handles that too - no migration needed.

## Why JE Listing Grid works but Etch loops don't

JE's `listings->data` manager hooks `the_post` ([jet-engine/.../listings/data.php:96](../../jet-engine/includes/components/listings/data.php) → `maybe_set_current_object`) and calls `set_current_object($post)` for each loop iteration. JE Listing Grid runs a normal `WP_Query` + `$query->the_post()` loop, so the hook fires per card and JE's `current_object` tracks the loop. Etch loops do NOT call `setup_postdata()` and do NOT fire `the_post` - [`Etch\Blocks\LoopBlock\LoopBlock::render_block`](../../etch/classes/Blocks/LoopBlock/LoopBlock.php) (lines 120-152) pushes each item onto its own `DynamicContextProvider` stack and renders inner blocks via `render_block()`. JE's hook never fires, JE's `current_object` stays pinned to the page object resolved before the Etch loop began, and every nested JE Data Store Button (etc.) gets the page's ID.

The Etch + JE Dynamic Field / Dynamic Image / Dynamic Link blocks are unaffected because Etch's own dynamic-data resolution reads from the `DynamicContextProvider` stack, not from JE's `current_object`. Only blocks that bypass Etch's dynamic-data layer and call `jet_engine()->listings->data->get_current_object()` directly are broken - the Data Store Button is the canonical example.

## Mechanism

For any block whose name is in `target_block_names` (default `['jet-engine/data-store-button']`, filterable via `jqbeb_loop_context_block_names`):

1. `pre_render_block` priority 5 - walk `DynamicContextProvider::get_stack()->all()` from top, find the topmost `DynamicContentEntry` with `get_type() === 'loop'`, read its source via `get_source()`. If the source is a `WP_Post` / `WP_User` / `WP_Term` (or a numeric ID, treated as a post), stash `jet_engine()->listings->data->get_current_object()` on the bridge's `stash_stack` and call `set_current_object($loop_item)`.
2. `render_block` priority 5 - if `stash_stack` is non-empty, pop one entry and restore via `set_current_object($previous)`.

The stash is shaped as a stack (PHP array used as LIFO via `array_push` + `array_pop`) so nested supported blocks restore in correct order. `array_reverse` walk on the context stack picks the innermost loop on nested Etch loops.

## Why this isn't `pre_render_block` priority 10 or higher

The bridge is positioned ahead of any block-level filter that might use JE's `current_object` to render. Priority 5 mirrors the existing JSF bridge's wrapper-capture priority and keeps the relative ordering consistent. Filter hook execution order within the same priority is registration-order, so the JE Query Builder bridge's `on_pre_render_block` at priority 4 still runs first when both classes touch the same block - which is desired (Query Builder configures loop args before we sync card context).

## Extending to more blocks

Add the block name to the filter:

```php
add_filter( 'jqbeb_loop_context_block_names', function ( $names ) {
    $names[] = 'jet-engine/some-other-block';
    return $names;
} );
```

DO NOT add Etch's own dynamic blocks here - Etch already resolves them via `DynamicContextProvider`. DO NOT add JE Dynamic Field / Image / Link blocks - same reason; adding them would be a double-resolution and may surface stale `current_object` state to other JE side-effects.

## Limitations

- **`object_context` override on the Data Store Button** is bypassed. The button supports `object_context` other than `'default_object'` (e.g. `'current_user'`, `'current_post_author'`, `'queried_user'`), each branching into different `listings->data` accessors. Our fix only intercepts the default path. If users select a non-default context, behaviour falls through to JE's existing resolution (typically against the page-level state).
- **Shortcode form is NOT covered.** JE also exposes the Data Store Button as a shortcode (`[jet_engine_data_store_button …]`) that renders via `Jet_Engine_Render_Base::do_action()` without going through the block render path. Etch loops only render blocks, so the shortcode path is irrelevant here - but anyone porting this fix to a different builder where shortcodes might appear inside the loop should remember it.
