<?php
/** Standalone regression for the late-CMT query-scope guard. Run: php tests/cmt-late-scope.php */
namespace {
    define('ABSPATH', __DIR__);
    $admin = false;
    $ajax = false;
    function is_admin() { return $GLOBALS['admin']; }
    function wp_doing_ajax() { return $GLOBALS['ajax']; }
    class WP_Query {
        public array $vars;
        public bool $main = false;
        public function __construct(array $vars = []) { $this->vars = $vars; }
        public function get($key) { return $this->vars[$key] ?? ''; }
        public function set($key, $value) { $this->vars[$key] = $value; }
        public function is_main_query() { return $this->main; }
    }
}
namespace JQBEB {
    class JSF_Bridge { public static bool $in_ajax_render = false; }
}
namespace Jet_Engine\CPT\Custom_Tables {
    class Manager {
        public array $storages = [];
        public static int $calls = 0;
        public static array $fixtures = [];
        public static function instance() { self::$calls++; $instance = new self(); $instance->storages = self::$fixtures; return $instance; }
        public function get_db_instance($slug, $fields) { return new class { public function table() { return 'wp_inventory_meta'; } }; }
    }
}
namespace {
    require dirname(__DIR__) . '/includes/class-je-query-builder-bridge.php';
    $reflection = new ReflectionClass(\JQBEB\JE_Query_Builder_Bridge::class);
    $bridge = $reflection->newInstanceWithoutConstructor();
    $cases = [
        ['native Etch JSF', ['jet_smart_filters'=>'etch-loop/moje-inzeraty'], false, false, false, true],
        ['native Etch default', ['jet_smart_filters'=>'etch-loop/default'], false, false, false, true],
        ['existing JE query', ['_jqbeb_je_query_id'=>42], false, false, false, true],
        ['unrelated query', [], false, false, false, false],
        ['other JSF provider', ['jet_smart_filters'=>'jet-engine/default'], false, false, false, false],
        ['near-match provider', ['jet_smart_filters'=>'etch-loop-other/default'], false, false, false, false],
        ['non-string provider', ['jet_smart_filters'=>['etch-loop/default']], false, false, false, false],
        ['main query', ['jet_smart_filters'=>'etch-loop/default'], true, false, false, false],
        ['unrelated admin request', ['jet_smart_filters'=>'etch-loop/default'], false, true, false, false],
        ['bridge AJAX render', ['jet_smart_filters'=>'etch-loop/default'], false, true, true, true],
    ];
    foreach ($cases as [$label,$vars,$main,$isAdmin,$render,$expected]) {
        $GLOBALS['admin'] = $isAdmin;
        $GLOBALS['ajax'] = $isAdmin;
        \JQBEB\JSF_Bridge::$in_ajax_render = $render;
        \Jet_Engine\CPT\Custom_Tables\Manager::$calls = 0;
        $query = new WP_Query($vars);
        $query->main = $main;
        $bridge->apply_cmt_redirect_late($query);
        $actual = \Jet_Engine\CPT\Custom_Tables\Manager::$calls === 1;
        if ($actual !== $expected) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
        echo "PASS: {$label}\n";
    }

    $GLOBALS['admin'] = $GLOBALS['ajax'] = false;
    \JQBEB\JSF_Bridge::$in_ajax_render = false;
    \Jet_Engine\CPT\Custom_Tables\Manager::$fixtures = [
        ['object_type'=>'post','object_slug'=>'inventory','fields'=>['price','views']],
    ];
    $baseClause = [['key'=>'price','value'=>100000,'compare'=>'>=','type'=>'NUMERIC']];
    $baseOrder = [['custom_key'=>'price+0','order'=>'ASC','replacement'=>'RAND(123)']];
    $base = ['post_type'=>'inventory','jet_smart_filters'=>'etch-loop/test',
        'custom_table_query'=>['table'=>'wp_inventory_meta','query'=>$baseClause,'order'=>$baseOrder]];
    $assert = static function($ok, $label) {
        if (!$ok) { fwrite(STDERR,"FAIL: {$label}\n"); exit(1); }
        echo "PASS: {$label}\n";
    };
    $query = new WP_Query($base + ['meta_key'=>'views','orderby'=>'meta_value_num','order'=>'DESC']);
    $bridge->apply_cmt_redirect_late($query);
    $bundle = $query->get('custom_table_query');
    $assert($bundle['query'] === $baseClause, 'late sort preserves base CMT restriction');
    $assert($query->vars['meta_key'] === null && $bundle['order'][0]['custom_key'] === 'views+0', 'late numeric sort redirects without postmeta JOIN');

    $newClause = [['key'=>'views','value'=>10,'compare'=>'>','type'=>'NUMERIC']];
    $query = new WP_Query($base + ['meta_query'=>$newClause,'orderby'=>['RAND(123)'=>'ASC']]);
    $bridge->apply_cmt_redirect_late($query);
    $bundle = $query->get('custom_table_query');
    $assert($bundle['query'] === ['relation'=>'AND',$baseClause,$newClause], 'late filter intersects base CMT restriction');
    $assert($bundle['order'] === $baseOrder, 'late filter preserves existing CMT sort');

    $plainClause = [['key'=>'external_field','value'=>'ok','compare'=>'=']];
    $query = new WP_Query($base + ['meta_query'=>array_merge($newClause,$plainClause),'orderby'=>'date','order'=>'DESC']);
    $bridge->apply_cmt_redirect_late($query);
    $assert(array_values($query->get('meta_query')) === $plainClause, 'non-CMT metadata stays in postmeta');
}
