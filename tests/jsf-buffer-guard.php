<?php
/** A JSF block that leaves an output buffer open must not swallow the page; two guards on one site must not fight.
 * Run: php tests/jsf-buffer-guard.php
 */
define('ABSPATH', __DIR__);
$GLOBALS['hooks'] = [];
function add_filter($tag, $cb, $priority = 10, $argc = 1) { $GLOBALS['hooks'][$tag][$priority][] = [$cb, $argc]; }
function apply_filters($tag, $value, ...$args) {
    $hooks = $GLOBALS['hooks'][$tag] ?? []; ksort($hooks);
    foreach ($hooks as $callbacks) { foreach ($callbacks as [$cb, $argc]) { $value = $cb(...array_slice([$value, ...$args], 0, $argc)); } }
    return $value;
}
require dirname(__DIR__) . '/includes/class-jsf-buffer-guard.php';

/** JSF 3.8.x block render(): outer buffer + wrapper div, returns '' without closing it when the options are empty. */
function jsf_block_render(bool $has_options): string {
    ob_start();
    echo '<div class="jet-smart-filters-checkboxes jet-filter" data-indexer-rule="hide">';
    ob_start();
    if ($has_options) { echo '<ul><li>option</li></ul>'; }
    $markup = ob_get_clean();
    if ('' === trim($markup)) { return ''; }
    echo $markup . '</div>';
    return ob_get_clean();
}
/** WP render_block() / WP_Block::render(): pre_render_block, callback, render_block. */
function render_test_block(array $block, callable $callback): string {
    $pre = apply_filters('pre_render_block', null, $block, null);
    if (null !== $pre) { return (string) $pre; }
    return apply_filters('render_block', $callback(), $block, null);
}
/** A block-theme page: the template is rendered first, then the document is printed. */
function render_page(callable $template): string {
    ob_start();
    $base = ob_get_level();
    $html = $template();
    echo "<!DOCTYPE html>\n<html><body>" . $html . '</body></html>';
    $out = '';
    while (ob_get_level() >= $base) { $out = ob_get_clean() . $out; }
    return $out;
}

$failures = 0;
function check($condition, $label) {
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) { $failures++; }
}
$empty = ['blockName' => 'jet-smart-filters/checkboxes', 'attrs' => ['filter_id' => 1]];
$full  = ['blockName' => 'jet-smart-filters/checkboxes', 'attrs' => ['filter_id' => 2]];
$page  = fn() => render_test_block($empty, fn() => jsf_block_render(false)) . '<main>Obsah</main>';

// Without a guard the page starts with the orphaned filter div (the bug).
$GLOBALS['hooks'] = [];
$out = render_page($page);
check(str_starts_with($out, '<div class="jet-smart-filters-checkboxes'), 'no guard: page starts with the unclosed filter div');

// With the guard the page starts with the doctype and keeps its content.
$GLOBALS['hooks'] = [];
$guard = new JQBEB\JSF_Buffer_Guard();
$level = ob_get_level();
$out = render_page($page);
check(str_starts_with($out, '<!DOCTYPE html>'), 'guard: page starts with <!DOCTYPE html>');
check(str_contains($out, '<main>Obsah</main>') && !str_contains($out, 'jet-smart-filters-checkboxes'), 'guard: content kept, empty filter dropped');
check(ob_get_level() === $level, 'guard: buffer level restored');

// A filter with options is untouched.
$out = render_page(fn() => render_test_block($full, fn() => jsf_block_render(true)));
check(str_contains($out, '<ul><li>option</li></ul></div>') && str_starts_with($out, '<!DOCTYPE html>'), 'guard: filter with options rendered unchanged');

// Nested: an empty JSF filter inside another JSF block closes only its own leak.
$outer = ['blockName' => 'jet-smart-filters/active-filters'];
$out = render_page(fn() => render_test_block($outer, function () use ($empty) {
    ob_start();
    echo '<section>' . render_test_block($empty, fn() => jsf_block_render(false)) . '</section>';
    return ob_get_clean();
}));
check($out === "<!DOCTYPE html>\n<html><body><section></section></body></html>", 'guard: nested JSF blocks keep their own levels');

// Non-JSF blocks and short-circuited JSF blocks never touch buffers.
$other = ['blockName' => 'etch/element'];
$before = ob_get_level();
render_test_block($other, function () { ob_start(); echo 'x'; return ''; });
$left_open = ob_get_level() === $before + 1;
ob_end_clean();
check($left_open, 'guard: a non-JSF block leak is left alone');
add_filter('pre_render_block', fn($pre, $block) => $block === $full ? 'cached' : $pre, 10, 2);
render_test_block($full, fn() => '');
check((new ReflectionProperty(JQBEB\JSF_Buffer_Guard::class, 'levels'))->getValue($guard) === [], 'guard: short-circuited block pushes no level');

// A buffer PHP cannot remove stays (no notice loop).
$GLOBALS['hooks'] = [];
new JQBEB\JSF_Buffer_Guard();
$before = ob_get_level();
render_test_block($empty, function () {
    ob_start(null, 0, PHP_OUTPUT_HANDLER_STDFLAGS & ~PHP_OUTPUT_HANDLER_REMOVABLE);
    return '';
});
check(ob_get_level() === $before + 1, 'guard: non-removable buffer is not closed');
// PHP cannot remove that buffer; it stays open and is flushed at exit, the checks below count levels relative to it.

// Two guards (bridge + poziven-core-logic filter-guard): the second finds nothing to close.
$GLOBALS['hooks'] = [];
$closed = -1;
new JQBEB\JSF_Buffer_Guard();
add_filter('render_block', function ($c) use (&$closed) { $closed = ob_get_level(); return $c; }, PHP_INT_MIN, 2);
new JQBEB\JSF_Buffer_Guard();
$base = ob_get_level() + 1; // render_page() opens the page buffer.
$out = render_page($page);
check(str_starts_with($out, '<!DOCTYPE html>') && str_contains($out, '<main>Obsah</main>'), 'two guards: page intact');
check($closed === $base, 'two guards: after the first guard the level is back at the page buffer, the second closes nothing');

exit($failures ? 1 : 0);
