<?php
/** JSF_Provider::ajax_get_content(): when the shared snapshot may be read, what the loopback gets.
 * Run: php tests/provider-fast-path.php
 */
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('JQBEB_VERSION', 'test');
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
function urlencode_deep($v) { return is_array($v) ? array_map('urlencode_deep', $v) : urlencode((string)$v); }
// Like WP: the values are already urlencode_deep()ed by the caller, add_query_arg does not encode again.
function add_query_arg($args, $url) { return $url . '?' . urldecode(http_build_query($args)); }
function home_url() { return 'https://shop.test'; }
function site_url() { return 'https://shop.test'; }
function wp_get_referer() { return $GLOBALS['referer']; }
function is_user_logged_in() { return false; }
function get_current_user_id() { return 0; }
function wp_salt($s) { return 'salt'; }
function esc_html($s) { return $s; }
function is_wp_error($e) { return $e instanceof WP_Error; }
class WP_Error { public function get_error_message() { return 'stop'; } }
class WP_Http_Cookie { public function __construct($a) {} }
$GLOBALS['reads'] = [];
function get_transient($k) { $GLOBALS['reads'][] = $k; return false; }
function set_transient($k, $v, $t) {}
function wp_remote_get($url, $args) { $GLOBALS['loopback'] = [$url, $args]; return new WP_Error(); }
class WP { public array $public_query_vars = ['jsf', 's', 'post_type', 'paged', 'author', 'product_cat']; }
$GLOBALS['wp'] = new WP();
class WP_Query { public array $query_vars = []; public function get($k) { return $this->query_vars[$k] ?? ''; } public function set($k, $v) { $this->query_vars[$k] = $v; } }
class Jet_Smart_Filters_Provider_Base {}
function jet_smart_filters() {
    return (object)['query' => new class { public $_query = []; public function get_current_provider() { return ['provider' => 'etch-loop', 'query_id' => 'produkty']; } }];
}
require dirname(__DIR__) . '/includes/class-state-stack.php';
require dirname(__DIR__) . '/includes/class-debug.php';
require dirname(__DIR__) . '/includes/class-relation-filters.php';
require dirname(__DIR__) . '/includes/class-jsf-bridge.php';
require dirname(__DIR__) . '/includes/class-jsf-provider.php';

$failures = 0;
function check($condition, $label) {
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) { $failures++; }
}
function request(string $referer, array $get = [], array $post = []) {
    $GLOBALS['referer'] = $referer; $GLOBALS['reads'] = []; $GLOBALS['loopback'] = null;
    $_GET = $get; $_POST = $post; $_REQUEST = $get + $post; $_COOKIE = [];
    ob_start(); (new JQBEB\JSF_Provider())->ajax_get_content(); ob_end_clean();
    return array_values(array_filter($GLOBALS['reads'], static fn($k) => str_starts_with($k, 'jqbeb_ctx2_')));
}
$ctx = JQBEB\JSF_Bridge::block_cache_key('/', 'produkty');

check(request('https://shop.test/kategoria/x/?jsf=etch-loop:produkty&tax=povod:23') !== [], 'plain archive reads the snapshot');
check(request('https://shop.test/?s=syr&post_type=product') === [], 'search referrer never reads the shared snapshot');
check(request('https://shop.test/kategoria/x/?jsf=woocommerce-archive&_s=med') === [], 'another JSF provider selected in the URL (may change the main query) skips the snapshot');
check(request('https://shop.test/kategoria/x/', [], ['provider' => 'woocommerce-archive/default']) === [], 'foreign provider in the AJAX request skips the snapshot');
check(request('https://shop.test/shop/jsf/woocommerce-archive/?_s=med') === [], 'JSF permalink path never reads a snapshot (review round 5)');
check(request('https://shop.test/shop/jsf/woocommerce-archive/?jsf=etch-loop:produkty') === [], 'JSF permalink path with an explicit Etch selector does not read either');
foreach (['/shop/%6asf/woocommerce-archive/', '/shop/j%73f/woocommerce-archive/', '/shop/jsf%2Fwoocommerce-archive/'] as $encoded) {
    check(request('https://shop.test' . $encoded . '?_s=med') === [], "encoded permalink segment $encoded never reads (review round 6)");
}
check(request('https://shop.test/about-jsf/') !== [], 'a slug merely containing jsf keeps the fast path');
check(request('https://shop.test/', [], ['s' => 'syr', 'query' => ['_meta_query_x' => '1']]) === [], 'POST s in the AJAX request itself never reads it either');
check(request('https://shop.test/', ['s' => 'syr', 'jsf_ajax' => '1']) === [], 'JSF 3.8.6 page-URL request with s never reads it');

request('https://shop.test/?s=syr&post_type=product', ['s' => 'syr', 'post_type' => 'product', 'jsf_ajax' => '1'], ['action' => 'jet_smart_filters', 'provider' => 'etch-loop/produkty', 'query' => ['_meta_query__stock_status' => 'instock'], 'settings' => ['x' => 1], 'query_context' => ['rules' => []], 'indexing_filters' => '[1]', 'dynamic_range' => ['a' => ['_price']], 'referrer' => ['uri' => '/']]);
[$url, $args] = $GLOBALS['loopback'];
parse_str((string) parse_url($url, PHP_URL_QUERY), $lb);
check(($lb['s'] ?? '') === 'syr' && ($lb['_meta_query__stock_status'] ?? '') === 'instock', 'loopback keeps the search and the filter');
check(!array_intersect_key($lb, array_flip(['jsf_ajax', 'settings', 'query_context', 'indexing_filters', 'dynamic_range', 'referrer', 'action'])), 'JSF transport keys not forwarded (jsf_ajax=1 made WP 302)');
$_SERVER['HTTP_X_JQBEB_LOOPBACK'] = '1';
$_SERVER['HTTP_X_JQBEB_LOOPBACK_SIGNATURE'] = $args['headers']['X-JQBEB-Loopback-Signature'];
check(JQBEB\JSF_Bridge::is_verified_loopback(), 'loopback carries a valid signature');
unset($_SERVER['HTTP_X_JQBEB_LOOPBACK'], $_SERVER['HTTP_X_JQBEB_LOOPBACK_SIGNATURE']);

// After 1.3.8 (Etch thread): JSF 3.8.6 posts to the page URL INCLUDING the query string the page was opened with.
function loopback_args(): array { parse_str((string) parse_url($GLOBALS['loopback'][0], PHP_URL_QUERY), $lb); return $lb; }
$opened = 'https://shop.test/?s=syr&post_type=product&jsf=etch-loop:produkty&tax=povod:23';
$get    = ['s' => 'syr', 'post_type' => 'product', 'jsf' => 'etch-loop:produkty', 'tax' => 'povod:23', 'jsf_ajax' => '1', '_tax_query_povod' => '23', 'jet_paged' => '3', 'pagenum' => '3'];
request($opened, $get, ['action' => 'jet_smart_filters', 'provider' => 'etch-loop/produkty']);
$lb = loopback_args();
check(($lb['s'] ?? '') === 'syr' && ($lb['post_type'] ?? '') === 'product', 'reset on a page opened with a filter: search kept');
check(!array_intersect_key($lb, array_flip(['tax', '_tax_query_povod', 'pagenum', 'jet_paged', 'pagination'])), 'reset: the filter and page the page was opened with are not replayed');
check(($lb['jsf'] ?? '') === 'etch-loop/produkty', 'loopback selects the provider JSF posted');
request($opened, $get, ['action' => 'jet_smart_filters', 'provider' => 'etch-loop/produkty', 'query' => ['_tax_query_povod' => '19']]);
$lb = loopback_args();
check(($lb['_tax_query_povod'] ?? '') === '19' && !isset($lb['tax']), 'new selection replaces the one in the page URL');
request('https://shop.test/dodavatelia/?kat=5&jsf=etch-loop:produkty&pagenum=2&utm_source=x', ['kat' => '5', 'jsf' => 'etch-loop:produkty', 'pagenum' => '2', 'jsf_ajax' => '1'], ['provider' => 'etch-loop/produkty', 'paged' => '3']);
$lb = loopback_args();
check(($lb['kat'] ?? '') === '5' && ($lb['jet_paged'] ?? '') === '3' && ($lb['pagenum'] ?? '') !== '2', 'site params kept, page only from JSF\'s posted paged');

// Fast path: the in-process render takes the posted page, not the page URL's pagenum / JSF's jet_paged.
$page = new ReflectionProperty(JQBEB\JSF_Provider::class, 'ajax_page');
$_REQUEST = ['pagenum' => '2', 'jet_paged' => '2'];
$page->setValue(null, 0);
$loop = new WP_Query(); $loop->query_vars = ['post_type' => 'product', 'jet_smart_filters' => 'etch-loop/produkty'];
(new JQBEB\JSF_Provider())->apply_jsf_to_tagged_query($loop);
check(empty($loop->query_vars['paged']), 'fast path: filter change without posted page starts on page 1');
$page->setValue(null, 3);
$loop = new WP_Query(); $loop->query_vars = ['post_type' => 'product', 'jet_smart_filters' => 'etch-loop/produkty'];
(new JQBEB\JSF_Provider())->apply_jsf_to_tagged_query($loop);
check(($loop->query_vars['paged'] ?? 0) === 3, 'fast path: posted page applied');
$page->setValue(null, null);
$loop = new WP_Query(); $loop->query_vars = ['post_type' => 'product', 'jet_smart_filters' => 'etch-loop/produkty'];
(new JQBEB\JSF_Provider())->apply_jsf_to_tagged_query($loop);
check(($loop->query_vars['paged'] ?? 0) === 2, 'server render (Etch pager ?pagenum=N) still reads the URL page');

exit($failures ? 1 : 0);
