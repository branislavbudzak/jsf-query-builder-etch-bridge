<?php
/** Standalone regression for completing JSF geo queries on CMT map fields. Run: php tests/cmt-geo-query.php */
namespace {
    define('ABSPATH', __DIR__);
    function is_admin() { return false; }
    function wp_doing_ajax() { return false; }
    class WP_Query {
        public array $vars;
        public function __construct(array $vars = []) { $this->vars = $vars; }
        public function get($key) { return $this->vars[$key] ?? ''; }
        public function set($key, $value) { $this->vars[$key] = $value; }
        public function is_main_query() { return false; }
    }
}
namespace JQBEB {
    class JSF_Bridge { public static bool $in_ajax_render = false; }
}
namespace Jet_Engine\CPT\Custom_Tables {
    class Manager {
        public array $storages = [];
        public static array $fixtures = [];
        public static function instance() { $instance = new self(); $instance->storages = self::$fixtures; return $instance; }
        public function sanitize_field_name($name) { return str_replace('-', '_', $name); }
        public function get_db_instance($slug, $fields) { return new class { public function table() { return 'wp_ad_listing_meta'; } }; }
    }
}
namespace {
    require dirname(__DIR__) . '/includes/class-je-query-builder-bridge.php';
    $bridge = (new ReflectionClass(\JQBEB\JE_Query_Builder_Bridge::class))->newInstanceWithoutConstructor();
    $assert = static function($ok, $label) {
        if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
        echo "PASS: {$label}\n";
    };
    $storage = [
        'object_type' => 'post',
        'object_slug' => 'ad-listing',
        'fields'      => ['price', 'location', 'location_hash', 'location_lat', 'location_lng'],
        'raw_fields'  => [['name' => 'price', 'type' => 'number'], ['name' => 'location', 'type' => 'map']],
    ];
    \Jet_Engine\CPT\Custom_Tables\Manager::$fixtures = [$storage];
    $jsf = ['latitude' => '49.0547', 'longitude' => '20.2964', 'distance' => '50', 'units' => 'km'];
    $run = static function(array $vars) use ($bridge) {
        $query = new WP_Query($vars + ['post_type' => 'ad-listing', 'jet_smart_filters' => 'etch-loop/default']);
        $bridge->complete_geo_query_late($query);
        return $query->get('geo_query');
    };
    $complete = $jsf + ['raw_field' => 'location', 'lat_field' => 'location_lat', 'lng_field' => 'location_lng'];

    $assert($run(['geo_query' => $jsf]) === $complete, 'JSF location filter gets the CMT map field');
    $assert($run(['geo_query' => $jsf + ['raw_field' => '']]) === $complete, 'empty raw_field is completed');
    $assert($run(['geo_query' => $jsf + ['raw_field' => 'location']]) === $complete, 'raw_field without lat/lng fields is completed');
    $assert($run(['geo_query' => '']) === '', 'no geo query stays untouched');

    $explicit = $jsf + ['lat_field' => 'custom_lat', 'lng_field' => 'custom_lng'];
    $assert($run(['geo_query' => $explicit]) === $explicit, 'explicit lat/lng fields win');
    $other = $jsf + ['raw_field' => 'pickup'];
    $assert($run(['geo_query' => $other]) === $other, 'raw_field naming another field is left alone');

    // JSF indexer / count queries carry the geo filter without a provider tag.
    $query = new WP_Query(['post_type' => ['ad-listing'], 'geo_query' => $jsf, 'posts_per_page' => -1]);
    $bridge->complete_geo_query_late($query);
    $assert($query->get('geo_query') === $complete, 'untagged indexer query is completed');
    $query = new WP_Query(['post_type' => 'any', '_jqbeb_je_original_post_type' => 'ad-listing', 'geo_query' => $jsf]);
    $bridge->complete_geo_query_late($query);
    $assert($query->get('geo_query') === $complete, 'original post type of an ID-based JE loop is used');
    $query = new WP_Query(['post_type' => 'post', 'geo_query' => $jsf]);
    $bridge->complete_geo_query_late($query);
    $assert($query->get('geo_query') === $jsf, 'post type without CMT storage is left alone');
    $query = new WP_Query(['post_type' => 'ad-listing', 'geo_query' => 'latitude:49;longitude:20']);
    $bridge->complete_geo_query_late($query);
    $assert($query->get('geo_query') === 'latitude:49;longitude:20', 'unparsed string geo query is left alone');

    \Jet_Engine\CPT\Custom_Tables\Manager::$fixtures = [array_merge($storage, [
        'raw_fields' => [['name' => 'location', 'type' => 'map'], ['name' => 'pickup', 'type' => 'map']],
        'fields'     => array_merge($storage['fields'], ['pickup_lat', 'pickup_lng']),
    ])];
    $assert($run(['geo_query' => $jsf]) === $jsf, 'two map fields are ambiguous, left alone');

    \Jet_Engine\CPT\Custom_Tables\Manager::$fixtures = [array_merge($storage, ['fields' => ['price', 'location']])];
    $assert($run(['geo_query' => $jsf]) === $jsf, 'missing lat/lng columns, left alone');

    \Jet_Engine\CPT\Custom_Tables\Manager::$fixtures = [array_merge($storage, [
        'raw_fields' => [['name' => 'car-location', 'type' => 'map']],
        'fields'     => ['car_location_lat', 'car_location_lng'],
    ])];
    $assert($run(['geo_query' => $jsf]) === $jsf + ['raw_field' => 'car-location', 'lat_field' => 'car_location_lat', 'lng_field' => 'car_location_lng'], 'columns use the sanitized field name');
}
