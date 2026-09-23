<?php
/** Integration seam: real installed JSF parser -> real bridge provider, with WP stubs.
 * Run: php tests/ajax-query-security.php [path/to/jet-smart-filters/includes/query.php]
 */
define('ABSPATH', __DIR__);
define('WPINC', 'wp-includes');
$GLOBALS['hooks'] = [];
function add_filter($tag, $cb, $priority = 10, $argc = 1) { $GLOBALS['hooks'][$tag][$priority][] = [$cb, $argc]; }
function add_action(...$args) { add_filter(...$args); }
function apply_filters($tag, $value, ...$args) {
    $hooks = $GLOBALS['hooks'][$tag] ?? []; ksort($hooks);
    foreach ($hooks as $callbacks) { foreach ($callbacks as [$cb, $argc]) { $value = $cb(...array_slice([$value, ...$args], 0, $argc)); } }
    return $value;
}
function is_admin() { return false; }
function wp_doing_ajax() { return true; }
function absint($v) { return abs((int)$v); }
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : stripslashes($v); }
class WP_Query {
    public array $query_vars;
    public array $posts = [];
    public static array $last_args = [];
    public function __construct($vars = []) { $this->query_vars = $vars; self::$last_args = $vars; }
    public function get($k) { return $this->query_vars[$k] ?? ''; }
    public function set($k, $v) { $this->query_vars[$k] = $v; }
    public function is_main_query() { return false; }
}
class Jet_Smart_Filters_Provider_Base {}
require $argv[1] ?? dirname(__DIR__, 2) . '/jet-smart-filters/includes/query.php';
require dirname(__DIR__) . '/includes/class-state-stack.php';
require dirname(__DIR__) . '/includes/class-debug.php';
require dirname(__DIR__) . '/includes/class-jsf-bridge.php';
require dirname(__DIR__) . '/includes/class-jsf-provider.php';
function jet_smart_filters() { return $GLOBALS['jsf']; }
function get_option($k, $default = false) { return $default; }
$GLOBALS['jsf'] = (object)['data' => new class {
    public array $url_symbol = ['provider_id' => ':'];
    public function get_request() { return $_REQUEST; }
    public function get_request_var($k) { return $_REQUEST[$k] ?? null; }
}];
$bridge = new JQBEB\JSF_Bridge();
$failures = 0;
function check($condition, $label) {
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) { $failures++; }
}
$base = ['post_type'=>'ad-listing', 'post_status'=>'publish', 'post__in'=>[11,12], 'posts_per_page'=>12];
foreach (['defaults', 'sort', 'plain'] as $vector) {
    $_REQUEST = ['action'=>'jet_smart_filters', 'provider'=>'etch-loop/default', 'query'=>[], 'paged'=>2];
    $attack = ['post_type'=>'page', 'post_status'=>'draft', 'post__in'=>[99], 'suppress_filters'=>true];
    if ($vector === 'defaults') { $_REQUEST['defaults'] = $attack; }
    if ($vector === 'sort') { $_REQUEST['query']['_sort_standard'] = json_encode(['orderby'=>'date', 'order'=>'ASC'] + $attack); }
    if ($vector === 'plain') { foreach ($attack as $k=>$v) { $_REQUEST['query']['_plain_query_'.$k] = $v; } }
    $GLOBALS['jsf']->query = new Jet_Smart_Filters_Query_Manager();
    $GLOBALS['jsf']->query->get_query_from_request();
    $query = new WP_Query($base + ['jet_smart_filters'=>'etch-loop/default']);
    (new JQBEB\JSF_Provider())->apply_jsf_to_tagged_query($query);
    check($query->get('post_status') === 'publish', "$vector cannot expose drafts");
    check($query->get('post_type') === 'ad-listing', "$vector cannot change post type");
    check($query->get('post__in') === [11,12], "$vector cannot replace server ID scope");
    check($query->get('paged') === 2, "$vector keeps pagination");
    if ($vector === 'sort') { check($query->get('order') === 'ASC', 'legitimate sorting survives'); }
}
// Real p50 tagging captures the server baseline before p60 merges filters.
JQBEB\JSF_Bridge::$in_ajax_render = true;
$stack = (new ReflectionProperty($bridge, 'stack'))->getValue($bridge);
$stack->push('default');
$server = new WP_Query($base + ['nc_light'=>true, 'meta_query'=>['relation'=>'OR', ['key'=>'a','value'=>1], ['key'=>'b','value'=>2]]]);
add_filter('jqbeb_jsf_default_query_keys', static fn($keys) => [...$keys, 'nc_light']);
$bridge->tag_query_for_jsf($server);
check(JQBEB\JSF_Bridge::trusted_defaults('default')['nc_light'] === true, 'server site flag retained');
$_REQUEST['query'] = ['_sort_standard'=>json_encode(['orderby'=>'date','order'=>'ASC','post_status'=>'draft'])];
$_REQUEST['defaults'] = ['post_status'=>'draft','post_type'=>'page'];
jet_smart_filters()->query->get_query_from_request();
$args = jet_smart_filters()->query->get_query_args();
check($args['post_status'] === 'publish' && $args['post_type'] === 'ad-listing', 'reparse uses server defaults for indexer');
check($args['post__in'] === [11,12] && $args['nc_light'] === true, 'reparse retains ID and site scope');
$group = ['relation'=>'OR', ['key'=>'c','value'=>3], ['key'=>'d','value'=>4]];
$merged = JQBEB\JSF_Bridge::merge_filter_args($server->query_vars, ['meta_query'=>$group, 'post_status'=>'draft']);
check($merged['meta_query']['relation'] === 'AND' && $merged['meta_query'][0]['relation'] === 'OR' && $merged['meta_query'][1]['relation'] === 'OR', 'base and filter groups intersect without flattening OR');
check(JQBEB\JSF_Bridge::trusted_defaults('unknown')['post__in'] === [0], 'missing baseline fails closed');

// Initial-page / loopback parser uses the same protection without AJAX defaults.
$_REQUEST = ['provider'=>'etch-loop/default', '_sort_standard'=>json_encode(['orderby'=>'date','order'=>'DESC','post_type'=>'page','post_status'=>'draft'])];
jet_smart_filters()->query = new Jet_Smart_Filters_Query_Manager();
(new ReflectionProperty(jet_smart_filters()->query, 'is_ajax_filter'))->setValue(jet_smart_filters()->query, false);
(new ReflectionProperty(jet_smart_filters()->query, 'provider'))->setValue(jet_smart_filters()->query, 'etch-loop/default');
jet_smart_filters()->query->get_query_from_request();
check(!isset(jet_smart_filters()->query->_query['post_status']) && !isset(jet_smart_filters()->query->_query['post_type']), 'initial-page / loopback sort cannot change scope');

$_REQUEST = ['action'=>'jet_smart_filters', 'provider'=>'jet-engine/default', 'defaults'=>['post_status'=>'draft'], 'query'=>[]];
jet_smart_filters()->query = new Jet_Smart_Filters_Query_Manager();
jet_smart_filters()->query->get_query_from_request();
check(jet_smart_filters()->query->get_query_args()['post_status'] === 'draft', 'other providers unaffected');
$_REQUEST = ['action'=>'jet_smart_filters', 'provider'=>'etch-loop/default', 'defaults'=>['post_status'=>'draft','post_type'=>'page'], 'query'=>[]];
jet_smart_filters()->query = new Jet_Smart_Filters_Query_Manager();
jet_smart_filters()->query->get_query_from_request();
$bridge->compute_indexed_counts(null, 'etch-loop/default', ['post_type'=>'page','post_status'=>'draft','post__in'=>[99]], (object)['indexing_data'=>['etch-loop/default'=>['tax_query'=>['body-type'=>[]]]]]);
check(WP_Query::$last_args['post_status'] === 'publish' && WP_Query::$last_args['post__in'] === [11,12], 'indexer ignores client scope');
$range = (new ReflectionMethod($bridge, 'build_recalc_context_args_excluding_var'))->invoke($bridge, 'price');
check($range['post_status'] === 'publish' && $range['post__in'] === [11,12] && $range['nc_light'] === true, 'range uses server scope and site flags');
function wp_json_encode($v) { return json_encode($v); }
function wp_salt($scheme) { return 'test-only-salt'; }
$extract = new ReflectionMethod(JQBEB\JSF_Provider::class, 'extract_loopback_props');
$payload = ['query_id'=>'transport', 'props'=>['found_posts'=>1], 'defaults'=>['post_type'=>'page','post_status'=>'draft']];
$marker = static fn($v) => '<!--JQBEB-PROPS:'.base64_encode(json_encode($v)).'-->';
$extract->invoke(new JQBEB\JSF_Provider(), $marker($payload), 'transport');
check(JQBEB\JSF_Bridge::trusted_defaults('transport')['post__in'] === [0], 'unsigned HTML marker cannot supply defaults');
$payload['defaults_signature'] = JQBEB\JSF_Bridge::defaults_signature('transport', $payload['defaults']);
$extract->invoke(new JQBEB\JSF_Provider(), $marker($payload), 'transport');
check(JQBEB\JSF_Bridge::trusted_defaults('transport')['post_status'] === 'draft', 'signed server loopback baseline restored');
$payload['defaults']['post_status'] = 'private';
$extract->invoke(new JQBEB\JSF_Provider(), $marker($payload), 'transport');
check(JQBEB\JSF_Bridge::trusted_defaults('transport')['post_status'] === 'draft', 'tampered signed marker rejected');
exit($failures ? 1 : 0);
