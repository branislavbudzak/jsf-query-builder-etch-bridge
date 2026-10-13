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
function loop_query(array $vars): WP_Query { $q = new WP_Query(); $q->query_vars = $q->fill_query_vars($vars); return $q; }

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

exit($failures ? 1 : 0);
}
