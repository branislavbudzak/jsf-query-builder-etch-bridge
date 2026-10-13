<?php
/** Fast-path context cache: search text never shared between visitors, page cap.
 * Run: php tests/ajax-context-cache.php
 */
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['hooks'] = [];
function add_filter($tag, $cb, $priority = 10, $argc = 1) { $GLOBALS['hooks'][$tag][$priority][] = [$cb, $argc]; }
function add_action(...$args) { add_filter(...$args); }
function apply_filters($tag, $value, ...$args) {
    $hooks = $GLOBALS['hooks'][$tag] ?? []; ksort($hooks);
    foreach ($hooks as $callbacks) { foreach ($callbacks as [$cb, $argc]) { $value = $cb(...array_slice([$value, ...$args], 0, $argc)); } }
    return $value;
}
function did_action($a) { return 1; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function absint($v) { return abs((int)$v); }
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : stripslashes($v); }
function wp_parse_url($url, $c = -1) { return parse_url($url, $c); }
function wp_parse_str($s, &$out) { parse_str($s, $out); }
function get_taxonomies($a = [], $o = 'names') { return ['product_cat' => (object)['query_var' => 'product_cat']]; }
function get_post_types($a = []) { return ['post' => 'post', 'page' => 'page', 'product' => 'product']; }
function get_queried_object() { return null; }
function get_queried_object_id() { return 0; }
$GLOBALS['transients'] = [];
function set_transient($k, $v, $t) { $GLOBALS['transients'][$k] = $v; }
function wp_salt($s) { return 'test-salt'; }
class WP { public array $public_query_vars = ['s', 'post_type', 'paged', 'author', 'orderby', 'product_cat']; }
$GLOBALS['wp'] = new WP();
class WP_Query {
    public array $query_vars = [];
    public bool $is_search = false;
    public function __construct($vars = []) { $this->query_vars = $vars; }
    public function get($k) { return $this->query_vars[$k] ?? ''; }
    public function set($k, $v) { $this->query_vars[$k] = $v; }
}
require dirname(__DIR__) . '/includes/class-state-stack.php';
require dirname(__DIR__) . '/includes/class-relation-filters.php';
require dirname(__DIR__) . '/includes/class-jsf-bridge.php';
use JQBEB\JSF_Bridge;

$failures = 0;
function check($condition, $label) {
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) { $failures++; }
}

$a = JSF_Bridge::main_query_url_args_from_url('https://shop.test/?s=syr&post_type[]=product&jsf=etch-loop:produkty&tax=povod:23&utm_source=x&paged=3');
check($a === ['s' => 'syr', 'post_type' => ['product']], 'public main-query vars kept raw (arrays too), JSF params / utm / paged ignored');
check(JSF_Bridge::main_query_url_args_from_url('https://shop.test/kategoria/x/?jsf=etch-loop:produkty&tax=povod:23&pagenum=2') === [], 'JSF filter URL of an archive keeps the fast path');
check(JSF_Bridge::block_cache_key('/kategoria/x/', 'produkty') === 'jqbeb_block_' . md5('/kategoria/x/|produkty'), 'archive key unchanged from 1.3.7');

$bridge = new JSF_Bridge();
$block  = ['blockName' => 'etch/element', 'attrs' => ['attributes' => ['class' => 'jsf-etch-loop jsf-etch-q-produkty']], 'innerBlocks' => []];
$key    = JSF_Bridge::block_cache_key('/', 'produkty');

// Search page (GET): no shared snapshot at all.
$GLOBALS['wp_query'] = new WP_Query(['s' => 'tajne-hladanie', 'post_type' => 'product', 'author__in' => [5]]);
$_GET = ['s' => 'tajne-hladanie', 'post_type' => 'product'];
$_POST = [];
$_SERVER['REQUEST_URI'] = '/?s=tajne-hladanie&post_type=product';
$bridge->on_render_block('<ul></ul>', $block);
check(!isset($GLOBALS['transients'][$key]), 'search page writes no shared snapshot');
check(!str_contains(serialize($GLOBALS['transients']), 'tajne-hladanie'), 'search text never stored');

// POST search and author archive by query string: same.
$_GET = []; $_POST = ['s' => 'post-hladanie'];
$bridge->on_render_block('<ul></ul>', $block);
$_POST = []; $_GET = ['author' => '5'];
$bridge->on_render_block('<ul></ul>', $block);
check($GLOBALS['transients'] === [], 'POST search and ?author= write no snapshot');

// Plain page: snapshot as before.
$_GET = ['utm_source' => 'x'];
$GLOBALS['wp_query'] = new WP_Query(['posts_per_page' => 24]);
$bridge->on_render_block('<ul></ul>', $block);
check(isset($GLOBALS['transients'][$key]), 'plain page still cached for the fast path');

// Loopback signature: only the bridge can produce it.
$_SERVER['HTTP_X_JQBEB_LOOPBACK'] = '1';
check(!JSF_Bridge::is_verified_loopback(), 'header alone is not a verified loopback');
$_SERVER['HTTP_X_JQBEB_LOOPBACK_SIGNATURE'] = time() . '.' . str_repeat('0', 64);
check(!JSF_Bridge::is_verified_loopback(), 'forged signature rejected');
$_SERVER['HTTP_X_JQBEB_LOOPBACK_SIGNATURE'] = JSF_Bridge::loopback_signature(time() - 600);
check(!JSF_Bridge::is_verified_loopback(), 'expired signature rejected');
$_SERVER['HTTP_X_JQBEB_LOOPBACK_SIGNATURE'] = JSF_Bridge::loopback_signature(time());
check(JSF_Bridge::is_verified_loopback(), 'fresh signed loopback verified');
unset($_SERVER['HTTP_X_JQBEB_LOOPBACK']);
check(!JSF_Bridge::is_verified_loopback(), 'signature without the loopback header rejected');

// Page cap.
check(JSF_Bridge::clamp_paged('999999') === 1000 && JSF_Bridge::clamp_paged('3') === 3, 'paged capped at 1000 by default');
add_filter('jqbeb_max_paged', static fn() => 100);
check(JSF_Bridge::clamp_paged(250) === 100, 'jqbeb_max_paged lowers the cap');
check(JSF_Bridge::merge_filter_args(['post_type' => 'product'], ['paged' => '5000'])['paged'] === 100, 'merged JSF paged is capped');
check(in_array('s', JSF_Bridge::default_query_keys(), true), 'indexer defaults keep the inherited search');

exit($failures ? 1 : 0);
