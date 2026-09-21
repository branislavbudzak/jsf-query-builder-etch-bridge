<?php
/** Standalone regression for the filterable JSF default query keys. Run: php tests/default-query-keys.php */
namespace {
    define('ABSPATH', __DIR__);
    $GLOBALS['jqbeb_filter'] = null;
    function apply_filters($hook, $value) {
        if ('jqbeb_jsf_default_query_keys' !== $hook || !$GLOBALS['jqbeb_filter']) { return $value; }
        return ($GLOBALS['jqbeb_filter'])($value);
    }
    require dirname(__DIR__) . '/includes/class-jsf-bridge.php';

    $fail = static function (string $label): void { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); };
    $keys = \JQBEB\JSF_Bridge::default_query_keys();

    foreach (['post_type', 'post_status', 'meta_query', 'tax_query', 'post__in', 'paged'] as $core) {
        in_array($core, $keys, true) || $fail("default keeps {$core}");
    }
    echo "PASS: default keys unchanged\n";

    in_array('nc_light', $keys, true) && $fail('site flag absent without filter');
    echo "PASS: no site-specific key by default\n";

    $GLOBALS['jqbeb_filter'] = static fn ($k) => array_merge($k, ['nc_light', 'nc_light', 42]);
    $keys = \JQBEB\JSF_Bridge::default_query_keys();
    in_array('nc_light', $keys, true) || $fail('filter adds key');
    1 === count(array_keys($keys, 'nc_light', true)) || $fail('duplicates removed');
    in_array(42, $keys, true) && $fail('non-string dropped');
    echo "PASS: filter adds a key, deduplicated, non-strings dropped\n";

    $GLOBALS['jqbeb_filter'] = static fn ($k) => 'broken';
    $fallback = \JQBEB\JSF_Bridge::default_query_keys();
    (in_array('post_type', $fallback, true) && !in_array('nc_light', $fallback, true)) || $fail('non-array filter result falls back');
    echo "PASS: non-array filter result falls back to defaults\n";

    // The stored default args keep a filtered key from the loop's query vars.
    $vars = ['post_type' => 'ad-listing', 'nc_light' => true, 'suppress_filters' => false];
    $GLOBALS['jqbeb_filter'] = static fn ($k) => array_merge($k, ['nc_light']);
    $stored = array_intersect_key($vars, array_flip(\JQBEB\JSF_Bridge::default_query_keys()));
    ($stored === ['post_type' => 'ad-listing', 'nc_light' => true]) || $fail('stored defaults carry the flag');
    echo "PASS: stored defaults carry the filtered flag\n";
}
