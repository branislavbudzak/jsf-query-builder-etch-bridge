<?php
/** Fast-path context cache: search text never shared between visitors, page cap.
 * Run: php tests/ajax-context-cache.php
 */
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
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

$a = JSF_Bridge::url_query_vars_from_url('https://shop.test/?s=syr&post_type=product&jsf=etch-loop:produkty&tax=povod:23&utm_source=x&paged=3');
check($a === ['post_type' => 'product', 's' => 'syr'], 'only public main-query vars, JSF params / utm / paged ignored');
$b = JSF_Bridge::url_query_vars_from_url('https://shop.test/?post_type=product&s=med');
check(JSF_Bridge::block_cache_key('/', 'produkty', $a) === JSF_Bridge::block_cache_key('/', 'produkty', $b), 'two searches share the entry (key has names, not values)');
check(JSF_Bridge::block_cache_key('/', 'produkty', $a) !== JSF_Bridge::block_cache_key('/', 'produkty', []), 'search and front page on / get separate entries');
check(JSF_Bridge::block_cache_key('/kategoria/x/', 'produkty', []) === 'jqbeb_block_' . md5('/kategoria/x/|produkty'), 'plain archive key unchanged from 1.3.7');
$noisy = JSF_Bridge::url_query_vars_from_url('https://shop.test/kategoria/x/?author=1&orderby=title');
check(JSF_Bridge::block_cache_key('/kategoria/x/', 'produkty', $noisy) === JSF_Bridge::block_cache_key('/kategoria/x/', 'produkty', []), 'arbitrary public vars cannot multiply cache entries');
check(JSF_Bridge::url_query_vars(['s' => ['a', 'b']]) === [], 'array values ignored');

// Render: snapshot stored without the visitor's search text.
$GLOBALS['wp_query'] = new WP_Query(['s' => 'tajne-hladanie', 'post_type' => 'product', 'posts_per_page' => 24]);
$GLOBALS['wp_query']->is_search = true;
$_GET = ['s' => 'tajne-hladanie', 'post_type' => 'product'];
$_SERVER['REQUEST_URI'] = '/?s=tajne-hladanie&post_type=product';
$bridge = new JSF_Bridge();
$block  = ['blockName' => 'etch/element', 'attrs' => ['attributes' => ['class' => 'jsf-etch-loop jsf-etch-q-produkty']], 'innerBlocks' => []];
$bridge->on_render_block('<ul></ul>', $block);
$key = JSF_Bridge::block_cache_key('/', 'produkty', ['s' => '', 'post_type' => '']);
check(isset($GLOBALS['transients'][$key]), 'render writes the search variant entry');
check(!str_contains(serialize($GLOBALS['transients']), 'tajne-hladanie'), 'search text never stored');
check(($GLOBALS['transients'][$key]['context']['flags']['is_search'] ?? null) === true, 'search flags kept');
check(($GLOBALS['transients'][$key]['context']['query_vars']['posts_per_page'] ?? null) === 24, 'other context vars kept');

// Restore: values come from this visitor's referrer.
add_filter('jqbeb_render_context', static fn($c) => $c + ['extra' => 'marker']);
check((JSF_Bridge::capture_render_context()['extra'] ?? '') === 'marker', 'jqbeb_render_context filter can extend the snapshot');
$fake = JSF_Bridge::build_context_query($GLOBALS['transients'][$key]['context']);
JSF_Bridge::apply_url_query_vars($fake, JSF_Bridge::url_query_vars_from_url('https://shop.test/?s=med&post_type=product'));
check($fake->get('s') === 'med' && $fake->get('post_type') === 'product', 'rebuilt query uses the requesting visitor\'s search');
JSF_Bridge::apply_url_query_vars($fake, ['post_type' => 'shop_order']);
check(!isset($fake->query_vars['post_type']), 'non publicly queryable post_type dropped like WP::parse_request');

// Page cap.
check(JSF_Bridge::clamp_paged('999999') === 1000 && JSF_Bridge::clamp_paged('3') === 3, 'paged capped at 1000 by default');
add_filter('jqbeb_max_paged', static fn() => 100);
check(JSF_Bridge::clamp_paged(250) === 100, 'jqbeb_max_paged lowers the cap');
check(JSF_Bridge::merge_filter_args(['post_type' => 'product'], ['paged' => '5000'])['paged'] === 100, 'merged JSF paged is capped');
check(in_array('s', JSF_Bridge::default_query_keys(), true), 'indexer defaults keep the inherited search');

exit($failures ? 1 : 0);
