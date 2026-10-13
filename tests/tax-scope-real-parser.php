<?php
/** Indexer defaults vs the loop under WordPress's real WP_Query / WP_Tax_Query parser.
 * Run: php tests/tax-scope-real-parser.php [path/to/wp-includes]
 */
namespace {
define('ABSPATH', __DIR__ . '/');
$wpinc = $argv[1] ?? dirname(__DIR__, 4) . '/wp-includes';
function apply_filters($tag, $value, ...$args) { return $value; }
function do_action(...$a) {}
function wp_basename($p, $s = '') { return basename($p, $s); }
function absint($v) { return abs((int) $v); }
function get_taxonomies($args = [], $output = 'names') {
    $t = [];
    foreach (['category' => 'category_name', 'post_tag' => 'tag', 'product_cat' => 'product_cat', 'povod' => 'povod'] as $name => $var) {
        $t[$name] = (object) ['name' => $name, 'query_var' => $var, 'rewrite' => ['hierarchical' => 'product_cat' === $name]];
    }
    return $t;
}
function get_post_types($a = []) { return ['post' => 'post', 'product' => 'product']; }
function get_object_taxonomies($type) { return 'product' === $type ? ['product_cat', 'povod'] : ['category', 'post_tag']; }
function get_taxonomies_for_attachments() { return []; }
function taxonomy_exists($t) { return true; }
function is_admin() { return false; }
function wp_using_themes() { return true; }
function get_option($k, $d = false) { return $d; }
function is_scalar_or_null($v) { return true; }
function get_query_var($v) { return ''; }
function wp_parse_args($a, $d = []) { return array_merge($d, (array) $a); }
function get_taxonomy($t) { return (object) ['name' => $t, 'query_var' => $t]; }
function get_post_type_object($t) { return null; }
function has_action(...$a) { return false; }
function do_action_ref_array(...$a) {}
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
function wp_is_serving_rest_request(...$a) { return false; }
require $wpinc . '/class-wp-tax-query.php';
require $wpinc . '/class-wp-query.php';
require dirname(__DIR__) . '/includes/class-state-stack.php';
require dirname(__DIR__) . '/includes/class-relation-filters.php';
require dirname(__DIR__) . '/includes/class-jsf-bridge.php';

$failures = 0;
function check($condition, $label) {
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) { $failures++; }
}
/** The tax query WP_Query would run for these args. */
function parsed(array $args): array {
    $q = new WP_Query();
    $v = $q->fill_query_vars($args);
    $q->parse_tax_query($v);
    return $q->tax_query->queries;
}
/** The loop query as it stands at pre_get_posts: WP's real parse_query() has set its flags. */
function loop_query(array $vars): WP_Query { $q = new WP_Query(); $q->parse_query($vars); return $q; }

$x = ['taxonomy' => 'povod', 'field' => 'slug', 'terms' => ['sk']];
$b = ['taxonomy' => 'povod', 'field' => 'slug', 'terms' => ['at']];
$loop_vars = ['post_type' => '', 'product_cat' => 'syry', 'tax_query' => ['relation' => 'OR', $x]];
$defaults  = JQBEB\JSF_Bridge::add_taxonomy_scope(loop_query($loop_vars), array_intersect_key($loop_vars, ['post_type' => 1, 'tax_query' => 1]));
check($defaults['post_type'] === 'product', 'empty post_type resolved like WP_Query::get_posts()');

// Unfiltered: count query parses exactly like the loop.
check(parsed(JQBEB\JSF_Bridge::merge_filter_args($defaults, [])) == parsed(JQBEB\JSF_Bridge::merge_filter_args($loop_vars, [])), 'unfiltered: indexer tax query == loop tax query');

// Filtered: JSF tax filter merged first, WP appends the var clause after (review round 2 case).
$filters = ['tax_query' => [$b]];
$loop    = parsed(JQBEB\JSF_Bridge::merge_filter_args($loop_vars, $filters));
$count   = parsed(JQBEB\JSF_Bridge::merge_filter_args($defaults, $filters));
check($count == $loop, 'filtered OR group: indexer tax query == loop tax query');
check(str_contains(json_encode($loop), '"syry"') && 'AND' === $loop['relation'], 'loop keeps the term scope ANDed after a filter');

// Woo-like archive: visibility NOT IN + term var.
$vis  = [['taxonomy' => 'product_visibility', 'field' => 'term_taxonomy_id', 'terms' => [8], 'operator' => 'NOT IN']];
$woo  = ['post_type' => '', 'product_cat' => 'mliecne-a-syry', 'taxonomy' => 'product_cat', 'term' => 'mliecne-a-syry', 'tax_query' => $vis];
$wdef = JQBEB\JSF_Bridge::add_taxonomy_scope(loop_query($woo), ['post_type' => '', 'tax_query' => $vis]);
check(parsed(JQBEB\JSF_Bridge::merge_filter_args($wdef, $filters)) == parsed(JQBEB\JSF_Bridge::merge_filter_args($woo, $filters)), 'Woo archive with a filter: indexer == loop');
check(JQBEB\JSF_Bridge::add_taxonomy_scope(loop_query(['post_type' => 'product']), ['post_type' => 'product']) === ['post_type' => 'product'], 'no taxonomy vars: defaults untouched');

// Review round 3: category archive + explicit OR group with a nested custom taxonomy.
$nested = ['post_type' => '', 'category_name' => 'news', 'tax_query' => ['relation' => 'OR', ['relation' => 'AND', ['taxonomy' => 'povod', 'field' => 'slug', 'terms' => ['sk']]]]];
$nq = loop_query($nested);
$nd = JQBEB\JSF_Bridge::add_taxonomy_scope($nq, ['post_type' => '', 'tax_query' => $nested['tax_query']]);
check(!$nq->is_tax && empty($nd['post_type']), 'WP is_tax false (category archive): post type left to WP default, like the loop');
$tq = loop_query(['post_type' => '', 'product_cat' => 'syry']);
check($tq->is_tax && JQBEB\JSF_Bridge::add_taxonomy_scope($tq, ['post_type' => ''])['post_type'] === 'product', 'WP is_tax true: post type resolved');

exit($failures ? 1 : 0);
}
