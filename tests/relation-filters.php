<?php
/** JetEngine relation filters on the etch-loop provider: real JSF parser, stubbed WP and JE relations.
 * Run: php tests/relation-filters.php [path/to/jet-smart-filters/includes/query.php]
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

/** Fake post table: ID => [post_type, post_status]. */
$GLOBALS['posts'] = [
    101 => ['product', 'publish'], 102 => ['product', 'publish'], 103 => ['product', 'publish'],
    104 => ['product', 'draft'],   105 => ['product', 'publish'], 106 => ['page', 'publish'],
];
class WP_Query {
    public array $query_vars;
    public array $posts = [];
    public static array $last_args = [];
    public function __construct($vars = []) {
        $this->query_vars = $vars; self::$last_args = $vars;
        if (!$vars) { return; }
        // Like WP_Query: post__in wins and post__not_in is then ignored; orderby post__in keeps its order.
        $order = !empty($vars['post__in']) && ($vars['orderby'] ?? '') === 'post__in' ? $vars['post__in'] : array_keys($GLOBALS['posts']);
        foreach ($order as $id) {
            if (!isset($GLOBALS['posts'][$id])) { continue; }
            [$type, $status] = $GLOBALS['posts'][$id];
            if (!empty($vars['post_type']) && !in_array($type, (array)$vars['post_type'], true)) { continue; }
            if ($status !== ($vars['post_status'] ?? 'publish')) { continue; }
            if (!empty($vars['post__in'])) {
                if (!in_array($id, $vars['post__in'], true)) { continue; }
            } elseif (!empty($vars['post__not_in']) && in_array($id, $vars['post__not_in'], true)) { continue; }
            $this->posts[] = $id;
        }
    }
    public function get($k) { return $this->query_vars[$k] ?? ''; }
    public function set($k, $v) { $this->query_vars[$k] = $v; }
    public function is_main_query() { return false; }
}
class Jet_Smart_Filters_Provider_Base {}

/** Relation rows: [rel_id, parent, child]. Relation 7: dodavatel (parent) -> product (child). */
$GLOBALS['rel_rows'] = [[7, 501, 101], [7, 501, 102], [7, 502, 102], [7, 502, 103], [7, 502, 104], [7, 503, 106], [9, 601, 101]];
class Fake_Relation_DB {
    public function __construct(public int $rel_id) {}
    public function raw_query($sql) {
        preg_match('/SELECT (\w+) AS id, COUNT\(DISTINCT (\w+)\).*IN \(([\d,]+)\)/s', $sql, $m);
        [$option_col, $item_col, $ids] = [$m[1], $m[2], array_map('intval', explode(',', $m[3]))];
        $col = ['parent_object_id' => 1, 'child_object_id' => 2];
        $out = [];
        foreach ($GLOBALS['rel_rows'] as $row) {
            if ($row[0] !== $this->rel_id || !in_array($row[$col[$item_col]], $ids, true)) { continue; }
            $out[$row[$col[$option_col]]][$row[$col[$item_col]]] = true;
        }
        return array_map(static fn($id, $items) => (object)['id' => $id, 'cnt' => count($items)], array_keys($out), $out);
    }
}
class Fake_Relation {
    public Fake_Relation_DB $db;
    public function __construct(public int $id, public array $args) { $this->db = new Fake_Relation_DB($id); }
    public function get_id() { return $this->id; }
    public function get_args($k) { return $this->args[$k] ?? null; }
    /** Mirrors JetEngine: an empty / 0 parent drops the condition and returns everything. */
    public function get_children($parents, $fields) { return $this->side((array)$parents, 1, 2); }
    public function get_parents($children, $fields) { return $this->side((array)$children, 2, 1); }
    private function side(array $match, int $from, int $to) {
        $match = array_filter($match);
        $out = [];
        foreach ($GLOBALS['rel_rows'] as $row) {
            if ($row[0] === $this->id && (!$match || in_array($row[$from], $match))) { $out[] = $row[$to]; }
        }
        return $out;
    }
}
$GLOBALS['relations'] = [
    7 => new Fake_Relation(7, ['parent_object' => 'posts::dodavatel', 'child_object' => 'posts::product']),
    9 => new Fake_Relation(9, ['parent_object' => 'posts::product', 'child_object' => 'posts::page']),
];
$GLOBALS['je_relations_on'] = true;
function jet_engine() {
    if (!$GLOBALS['je_relations_on']) { return (object)['relations' => null]; }
    return (object)['relations' => new class {
        public function get_active_relations($id = false) { return $GLOBALS['relations'][$id] ?? false; }
    }];
}

require $argv[1] ?? dirname(__DIR__, 2) . '/jet-smart-filters/includes/query.php';
require dirname(__DIR__) . '/includes/class-state-stack.php';
require dirname(__DIR__) . '/includes/class-debug.php';
require dirname(__DIR__) . '/includes/class-relation-filters.php';
require dirname(__DIR__) . '/includes/class-jsf-bridge.php';
require dirname(__DIR__) . '/includes/class-jsf-provider.php';
function jet_smart_filters() { return $GLOBALS['jsf']; }
$GLOBALS['jsf'] = (object)['utils' => new class {
    public function stripslashes_deep($v) { return is_array($v) ? array_map([$this, 'stripslashes_deep'], $v) : stripslashes($v); }
}, 'data' => new class {
    public array $url_symbol = ['provider_id' => ':'];
    public function get_request() { return $_REQUEST; }
    public function get_request_var($k) { return $_REQUEST[$k] ?? null; }
}];

// JetEngine's own translation at p-10: turns any relation clause it still sees into post__in.
$GLOBALS['je_translated'] = 0;
add_filter('jet-smart-filters/query/final-query', static function ($args) {
    foreach ((array)($args['meta_query'] ?? []) as $clause) {
        if (is_array($clause) && JQBEB\Relation_Filters::is_relation_key($clause['key'] ?? null)) {
            $GLOBALS['je_translated']++;
            $args['post__in'] = [101, 102, 103, 104, 105, 106];
        }
    }
    return $args;
}, -10);

$GLOBALS['wpdb'] = new class { public $postmeta = 'wp_postmeta'; public $term_relationships = 'tr'; public $term_taxonomy = 'tt'; public $terms = 't';
    public function prepare($sql, ...$a) { return $sql; } public function get_results($sql) { return []; } };

$bridge = new JQBEB\JSF_Bridge();
$failures = 0;
function check($condition, $label) {
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) { $failures++; }
}

// Server baseline captured at p50 for query id "shop".
JQBEB\JSF_Bridge::$in_ajax_render = true;
$stack = (new ReflectionProperty($bridge, 'stack'))->getValue($bridge);
$GLOBALS['jsf']->query = new Jet_Smart_Filters_Query_Manager();
$stack->push('shop');
$baseline = ['post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 12];
$bridge->tag_query_for_jsf(new WP_Query($baseline));

/** Parse a JSF AJAX request and run the loop query through the bridge, return post IDs. */
function run(array $query, ?array $base = null, string $qid = 'shop') {
    global $baseline;
    $_REQUEST = ['action' => 'jet_smart_filters', 'provider' => "etch-loop/$qid", 'query' => $query];
    $GLOBALS['jsf']->query = new Jet_Smart_Filters_Query_Manager();
    $GLOBALS['jsf']->query->get_query_from_request();
    $loop = new WP_Query();
    $loop->query_vars = ($base ?? $baseline) + ['jet_smart_filters' => "etch-loop/$qid"];
    (new JQBEB\JSF_Provider())->apply_jsf_to_tagged_query($loop);
    $result = new WP_Query(array_diff_key($loop->query_vars, ['jet_smart_filters' => 1]));
    return [$result->posts, $loop->query_vars];
}
$rel = '_meta_query_related_children*7';

[$ids, $vars] = run([$rel => ['501']]);
check($ids === [101, 102], 'one supplier restricts the listing');
check($GLOBALS['je_translated'] === 0, 'JetEngine never sees the relation clause');
check(empty($vars['meta_query']), 'relation clause removed from meta_query');

[$ids] = run([$rel => ['501', '502']]);
check($ids === [101, 102, 103], 'several suppliers are an OR union, drafts stay hidden');

[$ids] = run([$rel => ['599']]);
check($ids === [], 'supplier without products gives an empty listing');

foreach (['0' => ['0'], 'empty string' => [''], 'malformed' => ['501abc'], 'negative' => ['-501']] as $label => $value) {
    [$ids, $vars] = run([$rel => $value]);
    check($ids === [] && $vars['post__in'] === [0], "invalid value ($label) never widens the result");
}

[$ids] = run(['_meta_query_related_children*8' => ['501']]);
check($ids === [], 'deleted / unknown relation gives an empty listing');

[$ids] = run(['_meta_query_related_children*9' => ['601']]);
check($ids === [], 'relation whose child side is another post type gives an empty listing');

[$ids] = run(['_meta_query_related_parents*7' => ['102']]);
check($ids === [], 'parents direction on a product loop is a type mismatch (parents are dodavatel)');
[$ids] = run(['_meta_query_related_parents*7' => ['102']], ['post_type' => 'dodavatel', 'post_status' => 'publish']);
// No dodavatel posts exist in the fake table, but the plan must resolve, not fail closed.
check(JQBEB\Relation_Filters::resolve([['relation' => 'AND', 'clauses' => [['direction' => 'parents', 'rel_id' => 7, 'ids' => [102]]]]], 'dodavatel') === [501, 502], 'parents direction resolves on the parent post type');

[$ids, $vars] = run([$rel => ['502']], $baseline + ['post__in' => [101, 102]]);
check($ids === [102], 'relation intersects the server ID scope');
[$ids, $vars] = run([$rel => ['502']], $baseline + ['post__in' => [101]]);
check($ids === [] && $vars['post__in'] === [0], 'empty intersection with the server scope stays empty');

[$ids, $vars] = run([$rel => ['501'], '_plain_query_post__in' => ['103', '106']]);
check($ids === [101, 102], 'client post__in is ignored');
$forged = [['relation' => 'AND', 'clauses' => [['direction' => 'children', 'rel_id' => 7, 'ids' => [503]]]]];
[$ids, $vars] = run(['_plain_query_jqbeb_relation_filters' => $forged]);
check($ids === [101, 102, 103, 105] && !isset($vars['post__in']), 'a forged relation plan without a relation filter is discarded');

[$ids] = run([$rel => ['501', '502']], $baseline + ['post__not_in' => [102]]);
check($ids === [101, 103], 'server post__not_in survives the relation post__in');
[$ids] = run([$rel => ['501', '502']], $baseline + ['post__in' => [103, 102, 101], 'orderby' => 'post__in']);
check($ids === [103, 102, 101], 'baseline post__in order survives (orderby post__in)');

[$ids, $vars] = run([$rel => '502']);
check($ids === [102, 103], 'select / radio scalar value works');

[$ids] = run(['_meta_query_related_children*7,color' => ['501']]);
check($ids === [], 'multi-key query var mixing a relation fails closed');

// Non-AJAX parse (first load, URL restore with apply_type mixed, loopback): filters at the top level.
$_REQUEST = ['provider' => 'etch-loop/shop', $rel => ['501']];
$GLOBALS['jsf']->query = new Jet_Smart_Filters_Query_Manager();
(new ReflectionProperty($GLOBALS['jsf']->query, 'is_ajax_filter'))->setValue($GLOBALS['jsf']->query, false);
(new ReflectionProperty($GLOBALS['jsf']->query, 'provider'))->setValue($GLOBALS['jsf']->query, 'etch-loop/shop');
$GLOBALS['jsf']->query->get_query_from_request();
$merged = JQBEB\JSF_Bridge::merge_filter_args($baseline, $GLOBALS['jsf']->query->_query);
check($merged['post__in'] === [101, 102], 'non-AJAX / URL restore parse takes the same path');

[$ids, $vars] = run([$rel => ['501'], '_tax_query_povod' => ['5']]);
check($ids === [101, 102] && !empty($vars['tax_query']), 'relation combines with a taxonomy filter');

// Two relation groups at once: supplier 502 AND (through relation 7 used twice) supplier 501.
$plan = [
    ['relation' => 'AND', 'clauses' => [['direction' => 'children', 'rel_id' => 7, 'ids' => [501]]]],
    ['relation' => 'AND', 'clauses' => [['direction' => 'children', 'rel_id' => 7, 'ids' => [502]]]],
];
check(JQBEB\Relation_Filters::resolve($plan, 'product') === [102], 'two relation groups intersect');
$plan = [['relation' => 'OR', 'clauses' => [['direction' => 'children', 'rel_id' => 7, 'ids' => [501]], ['direction' => 'children', 'rel_id' => 7, 'ids' => [502]]]]];
check(JQBEB\Relation_Filters::resolve($plan, 'product') === [101, 102, 103, 104], 'OR group unions its clauses');
$plan[0]['relation'] = 'AND';
check(JQBEB\Relation_Filters::resolve($plan, 'product') === [102], 'AND group intersects its clauses');

$and = JQBEB\Relation_Filters::extract(['meta_query' => [['relation' => 'AND', ['key' => 'related_children*7', 'value' => '501'], ['key' => 'related_children*7', 'value' => '502']]]]);
check(JQBEB\Relation_Filters::resolve($and[JQBEB\Relation_Filters::ARG], 'product') === [102], 'custom checkbox AND operator group intersects');
$deep = JQBEB\Relation_Filters::extract(['meta_query' => [['relation' => 'OR', ['relation' => 'OR', ['key' => 'related_children*7', 'value' => '501']], ['key' => 'color', 'value' => 'red']]]]);
check(JQBEB\Relation_Filters::resolve($deep[JQBEB\Relation_Filters::ARG], 'product') === null, 'deeper nested relation clause fails closed');
$mixed = JQBEB\Relation_Filters::extract(['meta_query' => [['relation' => 'OR', ['key' => 'related_children*7', 'value' => '501'], ['key' => 'color', 'value' => 'red']]]]);
check(JQBEB\Relation_Filters::resolve($mixed[JQBEB\Relation_Filters::ARG], 'product') === null, 'mixed OR group fails closed');
$weird = JQBEB\Relation_Filters::extract(['meta_query' => [['key' => 'related_children*7x', 'value' => '501']]]);
check(empty($weird['meta_query']) && JQBEB\Relation_Filters::resolve($weird[JQBEB\Relation_Filters::ARG], 'product') === null, 'key JetEngine would translate but we cannot parse fails closed');

// Other providers keep JetEngine's native behaviour.
$_REQUEST = ['action' => 'jet_smart_filters', 'provider' => 'jet-engine/default', 'query' => [$rel => ['501']]];
$GLOBALS['jsf']->query = new Jet_Smart_Filters_Query_Manager();
$GLOBALS['je_translated'] = 0;
$GLOBALS['jsf']->query->get_query_from_request();
check($GLOBALS['je_translated'] === 1 && !isset($GLOBALS['jsf']->query->_query[JQBEB\Relation_Filters::ARG]), 'other providers untouched');

// Indexer counts: relation table, limited to the filtered set.
$_REQUEST = ['action' => 'jet_smart_filters', 'provider' => 'etch-loop/shop', 'query' => [$rel => ['501']]];
$GLOBALS['jsf']->query = new Jet_Smart_Filters_Query_Manager();
$GLOBALS['jsf']->query->get_query_from_request();
$indexer = (object)['indexing_data' => ['etch-loop/shop' => ['meta_query' => ['related_children*7' => [501, 502, 503]]]]];
$counts = $bridge->compute_indexed_counts(null, 'etch-loop/shop', $GLOBALS['jsf']->query->get_query_args(), $indexer);
check($counts['meta_query']['related_children*7'] === ['501' => 2, '502' => 1, '503' => 0], 'counts come from the relation table within the filtered set');
check(WP_Query::$last_args['post__in'] === [101, 102], 'count query carries the relation restriction');

$_REQUEST['query'] = [];
$GLOBALS['jsf']->query = new Jet_Smart_Filters_Query_Manager();
$GLOBALS['jsf']->query->get_query_from_request();
$counts = $bridge->compute_indexed_counts(null, 'etch-loop/shop', $GLOBALS['jsf']->query->get_query_args(), $indexer);
check($counts['meta_query']['related_children*7'] === ['501' => 2, '502' => 2, '503' => 0], 'unfiltered counts skip drafts and other post types');

$counts = $bridge->compute_indexed_counts(null, 'etch-loop/nobaseline', ['post_type' => 'product'], (object)['indexing_data' => ['etch-loop/nobaseline' => $indexer->indexing_data['etch-loop/shop']]]);
check($counts === [], 'indexer without a rendered baseline fails closed');

$GLOBALS['je_relations_on'] = false;
[$ids, $vars] = run([$rel => ['501']]);
check($ids === [] && $vars['post__in'] === [0], 'JetEngine relations component unavailable fails closed without a fatal');
check(JQBEB\Relation_Filters::counts('related_children*7', [101], [501], 'product') === null, 'counts without relations component return null');
$GLOBALS['je_relations_on'] = true;
check(JQBEB\Relation_Filters::counts('related_children*9', [101], [601], 'product') === null, 'counts on a relation whose counted side is another post type return null');

// Dynamic range context keeps the relation restriction.
$_REQUEST = ['action' => 'jet_smart_filters', 'provider' => 'etch-loop/shop', 'query' => [$rel => ['502'], '_meta_query_price' => ['10', '20']]];
$GLOBALS['jsf']->query = new Jet_Smart_Filters_Query_Manager();
$GLOBALS['jsf']->query->get_query_from_request();
$range = (new ReflectionMethod($bridge, 'build_recalc_context_args_excluding_var'))->invoke($bridge, 'price');
check($range['post__in'] === [102, 103, 104] && $range['post_status'] === 'publish', 'dynamic range context carries the relation restriction');

exit($failures ? 1 : 0);
