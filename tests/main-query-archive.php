<?php
/** Etch main-query loop on a Woo term archive: inherited JSF tag, empty post_type, term scope, load more.
 * Run: php tests/main-query-archive.php [path/to/jet-smart-filters/includes/query.php]
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
function get_option($k, $default = false) { return $default; }

/** Registered taxonomies: name => [query_var, object types]. */
$GLOBALS['taxonomies'] = [
    'category'    => ['category_name', ['post']],
    'product_cat' => ['product_cat', ['product']],
    'povod'       => ['povod', ['product', 'dodavatel']],
];
function get_taxonomies($args = [], $output = 'names') {
    $out = [];
    foreach ($GLOBALS['taxonomies'] as $name => [$var, $types]) { $out[$name] = (object)['name' => $name, 'query_var' => $var, 'rewrite' => []]; }
    return $out;
}
function get_post_types($args = []) { return ['post' => 'post', 'page' => 'page', 'attachment' => 'attachment', 'product' => 'product', 'dodavatel' => 'dodavatel']; }
function get_object_taxonomies($type) {
    return array_keys(array_filter($GLOBALS['taxonomies'], static fn($t) => in_array($type, $t[1], true)));
}
function get_taxonomies_for_attachments() { return []; }
function get_queried_object() { return null; }

class WP_Tax_Query {
    public array $queried_terms = [];
    public array $queries;
    public function __construct(array $queries) {
        $this->queries = ['relation' => strtoupper($queries['relation'] ?? 'AND')] + array_filter($queries, 'is_array');
        foreach ($queries as $q) { if (is_array($q) && ($q['operator'] ?? 'IN') !== 'NOT IN') { $this->queried_terms[$q['taxonomy']] = ['terms' => $q['terms']]; } }
    }
}
class WP_Query {
    public array $query_vars;
    public $tax_query = null;
    public array $posts = [];
    public static array $last_args = [];
    public function __construct($vars = []) { $this->query_vars = $vars; self::$last_args = $vars; }
    public function get($k) { return $this->query_vars[$k] ?? ''; }
    public function set($k, $v) { $this->query_vars[$k] = $v; }
    public function is_main_query() { return false; }
    public function fill_query_vars($v) { return $v + ['cat' => '', 'tag' => '']; }
    /** Same shape as WP core for taxonomy query vars (WP_Query::parse_tax_query). */
    public function parse_tax_query(&$v) {
        $tax_query = !empty($v['tax_query']) && is_array($v['tax_query']) ? $v['tax_query'] : [];
        if (!empty($v['taxonomy']) && !empty($v['term'])) { $tax_query[] = ['taxonomy' => $v['taxonomy'], 'field' => 'slug', 'terms' => [$v['term']]]; }
        foreach (get_taxonomies() as $name => $t) {
            if (!empty($v[$t->query_var])) {
                $tax_query[] = ['taxonomy' => $name, 'field' => 'slug', 'terms' => preg_split('/[,]+/', $v[$t->query_var])];
            }
        }
        $this->tax_query = new WP_Tax_Query($tax_query);
    }
}
class Jet_Smart_Filters_Provider_Base {}

/** Relation 7: dodavatel (parent) -> product (child). */
class Fake_Relation {
    public function get_args($k) { return ['parent_object' => 'posts::dodavatel', 'child_object' => 'posts::product'][$k] ?? null; }
    public function get_children($parents, $fields) { return [101, 102]; }
}
function jet_engine() {
    return (object)['relations' => new class {
        public function get_active_relations($id = false) { return 7 === $id ? new Fake_Relation() : false; }
    }];
}

require $argv[1] ?? dirname(__DIR__, 2) . '/jet-smart-filters/includes/query.php';
require dirname(__DIR__) . '/includes/class-state-stack.php';
require dirname(__DIR__) . '/includes/class-debug.php';
require dirname(__DIR__) . '/includes/class-relation-filters.php';
require dirname(__DIR__) . '/includes/class-jsf-bridge.php';
require dirname(__DIR__) . '/includes/class-jsf-provider.php';
function jet_smart_filters() { return $GLOBALS['jsf']; }
$GLOBALS['jsf'] = (object)['data' => new class {
    public array $url_symbol = ['provider_id' => ':'];
    public function get_request() { return $_REQUEST; }
    public function get_request_var($k) { return $_REQUEST[$k] ?? null; }
}];
$GLOBALS['jsf']->query = new Jet_Smart_Filters_Query_Manager();

$bridge = new JQBEB\JSF_Bridge();
$failures = 0;
function check($condition, $label) {
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) { $failures++; }
}
JQBEB\JSF_Bridge::$in_ajax_render = true;
$stack = (new ReflectionProperty($bridge, 'stack'))->getValue($bridge);

// 1. Etch's main-query loop copies the archive's vars, including JSF's woocommerce-archive tag.
$visibility = [['taxonomy' => 'product_visibility', 'field' => 'term_taxonomy_id', 'terms' => [7], 'operator' => 'NOT IN']];
$archive    = ['post_type' => '', 'post_status' => 'publish', 'product_cat' => 'mliecne-a-syry', 'tax_query' => $visibility,
    'jet_smart_filters' => 'woocommerce-archive', 'posts_per_page' => 24];
$stack->push('produkty');
$loop = new WP_Query($archive);
$bridge->tag_query_for_jsf($loop);
check($loop->get('jet_smart_filters') === 'etch-loop/produkty', 'inherited woocommerce-archive tag is replaced by etch-loop/<qid>');
check($stack->is_empty(), 'stack popped after tagging');

$stack->push('produkty');
$own = new WP_Query(['post_type' => 'product', 'jet_smart_filters' => 'etch-loop/other']);
$bridge->tag_query_for_jsf($own);
check($own->get('jet_smart_filters') === 'etch-loop/other', 'an existing etch-loop tag is kept');
$stack->pop();

$stack->push('legacy');
$workaround = new WP_Query(['post_type' => 'product', 'jet_smart_filters' => '']);
$bridge->tag_query_for_jsf($workaround);
check($workaround->get('jet_smart_filters') === 'etch-loop/legacy', 'old workaround (jet_smart_filters empty) still tags');

$plain = new WP_Query(['post_type' => 'product', 'jet_smart_filters' => 'woocommerce-archive']);
$bridge->tag_query_for_jsf($plain);
check($plain->get('jet_smart_filters') === 'woocommerce-archive', 'queries outside an Etch loop wrapper keep their tag');

// 2. Defaults carry the term scope and the post type WP_Query would resolve.
$defaults = JQBEB\JSF_Bridge::trusted_defaults('produkty');
check($defaults['post_type'] === 'product', 'empty post_type resolves to the taxonomy object type');
$flat = json_encode($defaults['tax_query']);
check(str_contains($flat, '"product_cat"') && str_contains($flat, '"mliecne-a-syry"'), 'defaults carry the term scope');
check(str_contains($flat, '"product_visibility"'), 'existing tax_query (Woo visibility) kept');
check(($defaults['tax_query']['relation'] ?? '') === 'AND', 'term scope AND-ed with the existing tax_query');

$or = JQBEB\JSF_Bridge::add_taxonomy_scope(new WP_Query(['post_type' => 'product', 'product_cat' => 'syry', 'tax_query' => ['relation' => 'OR', ['taxonomy' => 'povod', 'field' => 'slug', 'terms' => ['at']]]]), ['post_type' => 'product']);
check($or['tax_query']['relation'] === 'OR' && count(array_filter($or['tax_query'], 'is_array')) === 2, 'var clause joins the explicit tax_query under its own relation, like WP_Query');
$dup = JQBEB\JSF_Bridge::add_taxonomy_scope(new WP_Query(['post_type' => '', 'product_cat' => 'syry', 'taxonomy' => 'product_cat', 'term' => 'syry']), []);
check(count(array_filter($dup['tax_query'], 'is_array')) === 1, 'taxonomy+term duplicate of the query var clause removed');
$shared = JQBEB\JSF_Bridge::add_taxonomy_scope(new WP_Query(['post_type' => '', 'povod' => 'rakusko']), []);
check($shared['post_type'] === ['dodavatel', 'product'], 'taxonomy on several types resolves to all of them');
$cat = JQBEB\JSF_Bridge::add_taxonomy_scope(new WP_Query(['post_type' => '', 'category_name' => 'zapisky']), []);
check(!isset($cat['post_type']) && isset($cat['tax_query']), 'category archive keeps WP default post type');
$typed = JQBEB\JSF_Bridge::add_taxonomy_scope(new WP_Query(['post_type' => 'product', 'product_cat' => 'a,b']), ['post_type' => 'product']);
check($typed['post_type'] === 'product' && $typed['tax_query'][0]['terms'] === ['a', 'b'], 'explicit post_type kept, multi-term var parsed');
$nc = ['post_type' => 'ad-listing', 'meta_query' => [['key' => 'a', 'value' => 1]]];
check(JQBEB\JSF_Bridge::add_taxonomy_scope(new WP_Query($nc), $nc) === $nc, 'loops without taxonomy query vars are unchanged');

// Indexer: count query built from those defaults is scoped and typed.
$_REQUEST = ['action' => 'jet_smart_filters', 'provider' => 'etch-loop/produkty', 'query' => []];
$GLOBALS['jsf']->query = new Jet_Smart_Filters_Query_Manager();
$GLOBALS['jsf']->query->get_query_from_request();
$indexer = (object)['indexing_data' => ['etch-loop/produkty' => ['tax_query' => ['povod' => [5]]]]];
$GLOBALS['wpdb'] = new class { public $term_relationships = 'tr'; public $term_taxonomy = 'tt'; public $terms = 't';
    public function prepare($sql, ...$a) { return $sql; } public function get_results($sql) { return []; } };
// Count query returns no IDs in this stub; the args it was built from are what matters.
$bridge->compute_indexed_counts(null, 'etch-loop/produkty', $GLOBALS['jsf']->query->get_query_args(), $indexer);
check(WP_Query::$last_args['post_type'] === 'product' && str_contains(json_encode(WP_Query::$last_args['tax_query']), 'mliecne-a-syry'), 'indexer count query has post type and term scope');

// Relation filter on a loop whose own post_type is still ''.
$plan = [['relation' => 'AND', 'clauses' => [['direction' => 'children', 'rel_id' => 7, 'ids' => [158]]]]];
check(JQBEB\Relation_Filters::resolve($plan, '') === [101, 102], 'relation resolves with empty post_type');
check(JQBEB\Relation_Filters::resolve($plan, ['']) === [101, 102], 'relation resolves with [\'\'] post_type');
check(JQBEB\Relation_Filters::resolve($plan, 'page') === null, 'a real post type mismatch still fails closed');
check(JQBEB\Relation_Filters::apply(['post_type' => ''], $plan)['post__in'] === [101, 102], 'apply on the inherited empty post_type narrows to the relation');

// Fast path context keeps taxonomy query vars (rewrite-derived on a term archive path), so the AJAX render stays in the term.
$GLOBALS['wp_query'] = new WP_Query($archive);
$context = JQBEB\JSF_Bridge::capture_render_context();
check(($context['query_vars']['product_cat'] ?? null) === 'mliecne-a-syry', 'render context captures the taxonomy query var');
check(!isset($context['query_vars']['jet_smart_filters']), 'render context does not carry the JSF tag');

// 3. Load more: JSF looks for the wrapper inside the response before appending.
$wrap = new ReflectionMethod(JQBEB\JSF_Provider::class, 'wrap_for_load_more');
$provider = new JQBEB\JSF_Provider();
$_REQUEST = ['props' => ['page' => '2', 'pages' => ['1', '2']]];
check($wrap->invoke($provider, '<li>a</li>') === '<div class="jsf-etch-loop"><li>a</li></div>', 'load-more response wrapped in the provider selector');
$_REQUEST = ['props' => ['page' => '2']];
check($wrap->invoke($provider, '<li>a</li>') === '<li>a</li>', 'normal pagination stays inner HTML');
$_REQUEST = ['props' => 'pages'];
check($wrap->invoke($provider, '<li>a</li>') === '<li>a</li>', 'malformed props ignored');

exit($failures ? 1 : 0);
