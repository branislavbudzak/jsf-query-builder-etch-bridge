<?php
/** Another JSF provider filtering the main query never writes the shared snapshot; provider ids decoded like JSF.
 * Real JSF query manager. Run: php tests/foreign-provider.php [path/to/jet-smart-filters/includes/query.php]
 */
define('ABSPATH', __DIR__);
define('WPINC', 'wp-includes');
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
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : stripslashes((string)$v); }
function wp_parse_url($url, $c = -1) { return parse_url($url, $c); }
function wp_parse_str($s, &$out) { parse_str($s, $out); }
function get_option($k, $d = false) { return $d; }
function get_taxonomies($a = [], $o = 'names') { return []; }
function get_queried_object() { return null; }
function get_queried_object_id() { return 0; }
function get_current_user_id() { return 0; }
$GLOBALS['transients'] = [];
function set_transient($k, $v, $t) { $GLOBALS['transients'][$k] = $v; }
class WP { public array $public_query_vars = ['jsf', 's', 'post_type', 'paged']; }
$GLOBALS['wp'] = new WP();
class WP_Query { public array $query_vars = []; public function __construct($v = []) { $this->query_vars = $v; } public function get($k) { return $this->query_vars[$k] ?? ''; } public function set($k, $v) { $this->query_vars[$k] = $v; } }
require $argv[1] ?? dirname(__DIR__, 2) . '/jet-smart-filters/includes/query.php';
require dirname(__DIR__) . '/includes/class-state-stack.php';
require dirname(__DIR__) . '/includes/class-relation-filters.php';
require dirname(__DIR__) . '/includes/class-jsf-bridge.php';
function jet_smart_filters() { return $GLOBALS['jsf']; }
$GLOBALS['jsf'] = (object)['data' => new class {
    public array $url_symbol = ['provider_id' => ':'];
    public function get_request() { return $_REQUEST; }
    public function get_request_var($k) { return $_REQUEST[$k] ?? null; }
}];
$GLOBALS['jsf']->query = new Jet_Smart_Filters_Query_Manager();

$failures = 0;
function check($condition, $label) {
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) { $failures++; }
}
/** Provider JSF selected for this request, as apply_filters_from_request / _from_permalink set it. */
function select_provider($raw) { (new ReflectionProperty($GLOBALS['jsf']->query, 'provider'))->setValue($GLOBALS['jsf']->query, $raw); }
function render(string $uri, array $get = []) {
    $GLOBALS['transients'] = []; $_GET = $get; $_POST = []; $_SERVER['REQUEST_URI'] = $uri;
    $GLOBALS['wp_query'] = new WP_Query(['s' => $get['_s'] ?? '']);
    (new JQBEB\JSF_Bridge())->on_render_block('<ul></ul>', ['blockName' => 'etch/element', 'attrs' => ['attributes' => ['class' => 'jsf-etch-loop jsf-etch-q-produkty']], 'innerBlocks' => []]);
    return $GLOBALS['transients'];
}

select_provider('woocommerce-archive/default');
check(render('/shop/jsf/woocommerce-archive/?_s=med', ['_s' => 'med']) === [], 'permalink-selected foreign provider (no jsf in GET) writes no snapshot');
check(JQBEB\JSF_Bridge::other_jsf_provider_active(), 'JSF-selected woocommerce-archive detected');
select_provider('etch-loop/produkty');
check(render('/kategoria/x/jsf/etch-loop/produkty/') !== [], 'etch-loop selected (permalink) keeps the fast path');
select_provider(null);
check(render('/kategoria/x/') !== [], 'no JSF provider selected keeps the fast path');

// Provider ids decoded with JSF's configurable delimiter.
$GLOBALS['jsf']->data->url_symbol['provider_id'] = '|';
check(JQBEB\JSF_Bridge::main_query_url_args(['jsf' => 'etch-loop|produkty']) === [], 'custom delimiter etch-loop|produkty is the Etch loop');
check(JQBEB\JSF_Bridge::main_query_url_args(['jsf' => 'woocommerce-archive|x']) === ['jsf' => 'woocommerce-archive|x'], 'custom delimiter foreign provider still counts');
check(JQBEB\JSF_Bridge::main_query_url_args(['jsf' => 'etch-loop:produkty', 'provider' => 'woocommerce-archive/default']) === ['provider' => 'woocommerce-archive/default'], 'foreign provider arg wins over an Etch jsf');

exit($failures ? 1 : 0);
