<?php
/**
 * Admin documentation page — Settings → JSF Etch Bridge.
 *
 * Sections render conditionally based on which dependencies are active.
 *
 * @package JQBEB
 */

namespace JQBEB;

defined( 'ABSPATH' ) || exit;

class Admin_Page {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
	}

	public function register_menu(): void {
		add_options_page(
			__( 'JSF Etch Bridge', 'jsf-query-builder-etch-bridge' ),
			__( 'JSF Etch Bridge', 'jsf-query-builder-etch-bridge' ),
			'manage_options',
			'jqbeb',
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		$plugin   = Plugin::instance();
		$etch_ok  = $plugin->is_etch_active();
		$jsf_ok   = $plugin->is_jsf_active();
		$je_ok    = $plugin->is_je_query_builder_active();
		?>
		<div class="wrap jqbeb-admin">
			<h1><?php esc_html_e( 'JSF Query Builder Etch Bridge', 'jsf-query-builder-etch-bridge' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Version', 'jsf-query-builder-etch-bridge' ); ?>: <code><?php echo esc_html( JQBEB_VERSION ); ?></code>
			</p>

			<style>
				.jqbeb-admin .jqbeb-section { background:#fff; border:1px solid #c3c4c7; padding:18px 24px; margin:18px 0; }
				.jqbeb-admin h2 { margin-top:0; }
				.jqbeb-admin code { background:#f0f0f1; padding:2px 6px; border-radius:3px; font-size:13px; }
				.jqbeb-admin pre { background:#1d2327; color:#e1e1e1; padding:14px 18px; border-radius:4px; overflow:auto; }
				.jqbeb-admin pre code { background:transparent; color:inherit; padding:0; }
				.jqbeb-admin .jqbeb-status { display:inline-block; padding:2px 10px; border-radius:3px; font-weight:600; font-size:12px; }
				.jqbeb-admin .jqbeb-status.ok { background:#00a32a; color:#fff; }
				.jqbeb-admin .jqbeb-status.bad { background:#d63638; color:#fff; }
				.jqbeb-admin table.jqbeb-deps { border-collapse:collapse; margin:8px 0; }
				.jqbeb-admin table.jqbeb-deps td { padding:6px 14px 6px 0; vertical-align:middle; }
				.jqbeb-admin .jqbeb-callout { border-left:4px solid #2271b1; background:#f6f7f7; padding:10px 14px; margin:12px 0; }
				.jqbeb-admin .jqbeb-callout.warn { border-left-color:#dba617; }
			</style>

			<!-- ============== OVERVIEW ============== -->
			<div class="jqbeb-section">
				<h2><?php esc_html_e( 'Overview', 'jsf-query-builder-etch-bridge' ); ?></h2>
				<p>
					<?php esc_html_e( 'This plugin provides two independent bridges for Etch\'s native Query Loop block:', 'jsf-query-builder-etch-bridge' ); ?>
				</p>
				<ol>
					<li><strong><?php esc_html_e( 'JetSmartFilters bridge', 'jsf-query-builder-etch-bridge' ); ?></strong> — <?php esc_html_e( 'lets JSF filter / pagination / sort blocks drive an Etch Query Loop, with AJAX filtering and per-option indexed counts.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><strong><?php esc_html_e( 'JetEngine Query Builder bridge', 'jsf-query-builder-etch-bridge' ); ?></strong> — <?php esc_html_e( 'lets a JE Query Builder query (Posts, Users, or Terms) become the data source for an Etch Query Loop, replacing the loop\'s built-in query.', 'jsf-query-builder-etch-bridge' ); ?></li>
				</ol>
				<p>
					<?php esc_html_e( 'Each bridge runs on its own. Use either, both, or none. Etch is required for the bridges to do anything.', 'jsf-query-builder-etch-bridge' ); ?>
				</p>
			</div>

			<!-- ============== REQUIREMENTS ============== -->
			<div class="jqbeb-section">
				<h2><?php esc_html_e( 'Requirements', 'jsf-query-builder-etch-bridge' ); ?></h2>
				<table class="jqbeb-deps">
					<tr>
						<td><strong>Etch</strong></td>
						<td><?php echo $this->status_pill( $etch_ok ); ?></td>
						<td class="description"><?php esc_html_e( 'Required for both bridges. Builds the Query Loop block this plugin hooks into.', 'jsf-query-builder-etch-bridge' ); ?></td>
					</tr>
					<tr>
						<td><strong>JetSmartFilters</strong></td>
						<td><?php echo $this->status_pill( $jsf_ok ); ?></td>
						<td class="description"><?php esc_html_e( 'Optional. Needed only if you want filter / pagination / sort blocks driving the Etch loop.', 'jsf-query-builder-etch-bridge' ); ?></td>
					</tr>
					<tr>
						<td><strong>JetEngine</strong></td>
						<td><?php echo $this->status_pill( $je_ok ); ?></td>
						<td class="description"><?php esc_html_e( 'Optional. Needed only if you want JE Query Builder queries as data sources.', 'jsf-query-builder-etch-bridge' ); ?></td>
					</tr>
				</table>
				<?php if ( ! $etch_ok ) : ?>
					<div class="jqbeb-callout warn">
						<?php esc_html_e( 'Etch is not active. Both bridges are disabled until Etch is enabled.', 'jsf-query-builder-etch-bridge' ); ?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $jsf_ok ) : ?>
			<!-- ============== JSF SETUP ============== -->
			<div class="jqbeb-section">
				<h2><?php esc_html_e( 'JetSmartFilters bridge — setup', 'jsf-query-builder-etch-bridge' ); ?></h2>

				<h3>1. <?php esc_html_e( 'Mark the Etch loop wrapper', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<p><?php esc_html_e( 'In the Etch builder, on the IMMEDIATE container that wraps ONLY the loop cards (not pagination), add this CSS class:', 'jsf-query-builder-etch-bridge' ); ?></p>
				<pre><code>jsf-etch-loop</code></pre>
				<p><?php esc_html_e( 'Pagination and sort blocks must live OUTSIDE this wrapper.', 'jsf-query-builder-etch-bridge' ); ?></p>

				<h3>2. <?php esc_html_e( 'Multiple loops on one page', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<p><?php esc_html_e( 'Add a unique query class to each wrapper, with the slug of your choice:', 'jsf-query-builder-etch-bridge' ); ?></p>
				<pre><code>jsf-etch-loop jsf-etch-q-partner-listings
jsf-etch-loop jsf-etch-q-cars</code></pre>

				<h3>3. <?php esc_html_e( 'Configure JSF blocks in Gutenberg', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<p><?php esc_html_e( 'In every JSF filter, pagination, or sort block, set:', 'jsf-query-builder-etch-bridge' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'Content Provider', 'jsf-query-builder-etch-bridge' ); ?>:</strong> <code>Etch Loop</code></li>
					<li><strong><?php esc_html_e( 'Query ID', 'jsf-query-builder-etch-bridge' ); ?>:</strong> <?php esc_html_e( 'leave blank for the default loop, or use the slug after', 'jsf-query-builder-etch-bridge' ); ?> <code>jsf-etch-q-</code> <?php esc_html_e( '(e.g.', 'jsf-query-builder-etch-bridge' ); ?> <code>partner-listings</code>)</li>
				</ul>
			</div>

			<!-- ============== SHORTCODE ============== -->
			<div class="jqbeb-section">
				<h2><?php esc_html_e( 'Shortcode — [jsf_etch_count]', 'jsf-query-builder-etch-bridge' ); ?></h2>
				<p><?php esc_html_e( 'Renders a span that shows query result counts. Updates live after AJAX filter changes.', 'jsf-query-builder-etch-bridge' ); ?></p>

				<h3><?php esc_html_e( 'Basic usage', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<pre><code>[jsf_etch_count]                                       <?php esc_html_e( '— total found posts (default)', 'jsf-query-builder-etch-bridge' ); ?>
[jsf_etch_count attr="max_num_pages"]                  <?php esc_html_e( '— total pages', 'jsf-query-builder-etch-bridge' ); ?>
[jsf_etch_count attr="page"]                           <?php esc_html_e( '— current page', 'jsf-query-builder-etch-bridge' ); ?>
[jsf_etch_count provider="etch-loop" query_id="cars"]  <?php esc_html_e( '— specific multi-loop query', 'jsf-query-builder-etch-bridge' ); ?></code></pre>

				<h3><?php esc_html_e( 'Attributes', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<ul>
					<li><code>provider</code> — <?php esc_html_e( 'default', 'jsf-query-builder-etch-bridge' ); ?>: <code>etch-loop</code></li>
					<li><code>query_id</code> — <?php esc_html_e( 'default', 'jsf-query-builder-etch-bridge' ); ?>: <code>default</code></li>
					<li><code>attr</code> — <?php esc_html_e( 'default', 'jsf-query-builder-etch-bridge' ); ?>: <code>found_posts</code> — <?php esc_html_e( 'one of', 'jsf-query-builder-etch-bridge' ); ?> <code>found_posts</code>, <code>max_num_pages</code>, <code>page</code></li>
					<li><code>placeholder</code> — <?php esc_html_e( 'default', 'jsf-query-builder-etch-bridge' ); ?>: <code>0</code> — <?php esc_html_e( 'shown until JS fills the live value', 'jsf-query-builder-etch-bridge' ); ?></li>
				</ul>
			</div>

			<!-- ============== INDEXER ============== -->
			<div class="jqbeb-section">
				<h2><?php esc_html_e( 'Per-option counts (Filter Indexer)', 'jsf-query-builder-etch-bridge' ); ?></h2>
				<p><?php esc_html_e( 'In each JSF filter\'s admin settings, toggle "Show Items Count" → ON. Counts will appear next to each option, e.g. "Bratislava (12)".', 'jsf-query-builder-etch-bridge' ); ?></p>
				<p><strong><?php esc_html_e( 'Supported', 'jsf-query-builder-etch-bridge' ); ?>:</strong></p>
				<ul>
					<li>tax_query (<?php esc_html_e( 'taxonomy filters', 'jsf-query-builder-etch-bridge' ); ?>)</li>
					<li>meta_query (<?php esc_html_e( 'postmeta filters', 'jsf-query-builder-etch-bridge' ); ?>)</li>
				</ul>
				<p><strong><?php esc_html_e( 'Not supported', 'jsf-query-builder-etch-bridge' ); ?>:</strong></p>
				<ul>
					<li><?php esc_html_e( 'Range filters', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'JetEngine Custom Content Types stored in custom meta tables (CCT)', 'jsf-query-builder-etch-bridge' ); ?></li>
				</ul>
			</div>
			<?php endif; ?>

			<?php if ( $je_ok ) : ?>
			<!-- ============== JE QUERY BUILDER SETUP ============== -->
			<div class="jqbeb-section">
				<h2><?php esc_html_e( 'JetEngine Query Builder bridge — setup (standalone, no JSF required)', 'jsf-query-builder-etch-bridge' ); ?></h2>

				<h3>1. <?php esc_html_e( 'Create a JE Query Builder query', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<p><?php esc_html_e( 'Go to', 'jsf-query-builder-etch-bridge' ); ?> <strong>JetEngine → Query Builder</strong> <?php esc_html_e( 'and create a query. Three query types are supported: Posts, Users, Terms. Note the query\'s numeric ID, or set a custom Query ID slug like', 'jsf-query-builder-etch-bridge' ); ?> <code>partner-listings</code>.</p>

				<h3>2. <?php esc_html_e( 'Match the JE query type with the Etch loop preset type', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<p><?php esc_html_e( 'The JE query type and the Etch loop preset type must align — the bridge intercepts the underlying WordPress query class that Etch instantiates:', 'jsf-query-builder-etch-bridge' ); ?></p>
				<table class="jqbeb-deps">
					<tr>
						<td><strong><?php esc_html_e( 'JE query type', 'jsf-query-builder-etch-bridge' ); ?></strong></td>
						<td><strong><?php esc_html_e( 'Etch loop preset type', 'jsf-query-builder-etch-bridge' ); ?></strong></td>
						<td><strong><?php esc_html_e( 'Intercepted hook', 'jsf-query-builder-etch-bridge' ); ?></strong></td>
					</tr>
					<tr>
						<td><code>Posts</code></td>
						<td><code>wp-query</code> / <code>main-query</code></td>
						<td><code>pre_get_posts</code> (p40)</td>
					</tr>
					<tr>
						<td><code>Users</code></td>
						<td><code>wp-users</code></td>
						<td><code>pre_user_query</code> (p10)</td>
					</tr>
					<tr>
						<td><code>Terms</code></td>
						<td><code>wp-terms</code></td>
						<td><code>pre_get_terms</code> (p10)</td>
					</tr>
				</table>
				<p><?php esc_html_e( 'If the types do not match, the bridge silently no-ops and the Etch loop falls back to its preset query.', 'jsf-query-builder-etch-bridge' ); ?></p>

				<h3>3. <?php esc_html_e( 'Tag the Etch loop wrapper', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<p><?php esc_html_e( 'On the same wrapper as your Etch Query Loop, add these classes:', 'jsf-query-builder-etch-bridge' ); ?></p>
				<pre><code>je-etch-loop je-q-42                  <?php esc_html_e( '— numeric query ID', 'jsf-query-builder-etch-bridge' ); ?>
je-etch-loop je-q-partner-listings    <?php esc_html_e( '— Query ID slug', 'jsf-query-builder-etch-bridge' ); ?></code></pre>

				<h3>4. <?php esc_html_e( 'Etch loop preset is just a shell', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<p><?php esc_html_e( 'Pick any preset of the matching type. Its args are wholesale-replaced by the JE query — post type / role / taxonomy, ordering, meta queries, and pagination all come from JE.', 'jsf-query-builder-etch-bridge' ); ?></p>

				<div class="jqbeb-callout">
					<?php esc_html_e( 'This bridge runs without JetSmartFilters. Just adding', 'jsf-query-builder-etch-bridge' ); ?> <code>je-etch-loop je-q-{id}</code> <?php esc_html_e( 'is enough.', 'jsf-query-builder-etch-bridge' ); ?>
				</div>

				<div class="jqbeb-callout warn">
					<strong><?php esc_html_e( 'Defensive override:', 'jsf-query-builder-etch-bridge' ); ?></strong>
					<?php esc_html_e( 'For Users and Terms, the bridge forces', 'jsf-query-builder-etch-bridge' ); ?> <code>fields = "all"</code>
					<?php esc_html_e( 'after merging JE args, because Etch\'s loop handlers iterate over WP_User / WP_Term object instances.', 'jsf-query-builder-etch-bridge' ); ?>
				</div>
			</div>
			<?php endif; ?>

			<?php if ( $jsf_ok && $je_ok ) : ?>
			<!-- ============== COMBINED USAGE ============== -->
			<div class="jqbeb-section">
				<h2><?php esc_html_e( 'Combining both bridges', 'jsf-query-builder-etch-bridge' ); ?></h2>
				<p><?php esc_html_e( 'You can layer both bridges on the same wrapper. JetEngine provides the base query; JetSmartFilters stacks filter constraints on top.', 'jsf-query-builder-etch-bridge' ); ?></p>
				<pre><code>jsf-etch-loop jsf-etch-q-cars je-etch-loop je-q-cars</code></pre>
				<p><?php esc_html_e( 'Hook order on the WP_Query (Posts type):', 'jsf-query-builder-etch-bridge' ); ?></p>
				<ol>
					<li><code>pre_get_posts</code> p40 — <?php esc_html_e( 'JE wholesale-replaces query args.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><code>pre_get_posts</code> p50 — <?php esc_html_e( 'JSF tags the query for filter merging.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><code>pre_get_posts</code> p60 — <?php esc_html_e( 'JSF merges filter args on top of the JE base.', 'jsf-query-builder-etch-bridge' ); ?></li>
				</ol>
				<p><?php esc_html_e( 'For JE Users / Terms queries, JSF integration with the same loop is not yet wired — JSF is a Posts-only content provider in this plugin. Apply the JE bridge alone for Users and Terms loops.', 'jsf-query-builder-etch-bridge' ); ?></p>
			</div>
			<?php endif; ?>

			<!-- ============== LIMITATIONS ============== -->
			<div class="jqbeb-section">
				<h2><?php esc_html_e( 'Limitations', 'jsf-query-builder-etch-bridge' ); ?></h2>
				<ul>
					<li><?php esc_html_e( 'JE Query Builder bridge supports Posts, Users, and Terms query types. SQL, Repeater, Comments, Current_WP_Query, and Merged_Query are NOT supported (Etch has no compatible loop preset for them).', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'JE query type and Etch loop preset type must match (Posts↔wp-query, Users↔wp-users, Terms↔wp-terms). Mismatch causes silent no-op — Etch falls back to its preset query.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'JSF integration is Posts-only. JSF filters do not drive Users / Terms loops (no JSF content provider exists for those types in this plugin).', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'Only Etch loops in loopId mode (the regular dropdown selection) are supported. Target / expression mode does not use WP_Query.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'JSF Filter Indexer counts do not work for Custom Content Types stored in custom meta tables, or for range filters.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'JSF AJAX uses a self-loopback HTTP request to re-render the page. The result is cached for 60 seconds. On large pages this may be slower than a native JE Listing Grid AJAX update.', 'jsf-query-builder-etch-bridge' ); ?></li>
				</ul>
			</div>

			<!-- ============== TROUBLESHOOTING ============== -->
			<div class="jqbeb-section">
				<h2><?php esc_html_e( 'Troubleshooting', 'jsf-query-builder-etch-bridge' ); ?></h2>

				<h3><?php esc_html_e( 'Filter changes the URL but the loop doesn\'t update', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'The wrapper class is missing. Verify', 'jsf-query-builder-etch-bridge' ); ?> <code>jsf-etch-loop</code> <?php esc_html_e( 'is on the immediate parent of your loop cards.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'Pagination / sort blocks are inside the wrapper. Move them outside.', 'jsf-query-builder-etch-bridge' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'JE query is ignored — Etch shows the preset query instead', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Class is misspelled. Confirm', 'jsf-query-builder-etch-bridge' ); ?> <code>je-etch-loop</code> <?php esc_html_e( 'AND', 'jsf-query-builder-etch-bridge' ); ?> <code>je-q-{id}</code> <?php esc_html_e( 'are both present.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'Query ID does not match. Numeric ID = the JE query post ID; slug = the value of the "Query ID" field in JE Query Builder settings.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'Query type / loop preset type mismatch. Use a Posts JE query with a wp-query Etch preset, a Users JE query with a wp-users preset, or a Terms JE query with a wp-terms preset.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'JE query type is SQL / Repeater / Comments / Merged — not supported.', 'jsf-query-builder-etch-bridge' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Users / Terms loop renders empty cards or PHP warnings', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Etch needs full WP_User / WP_Term objects. The bridge forces fields="all" — but if you customised Etch\'s preset to return IDs only, that override is bypassed by the bridge. Use a default preset.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'JE query may filter all results out (e.g. role / taxonomy mismatch). Test the JE query in JetEngine → Query Builder preview first.', 'jsf-query-builder-etch-bridge' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Counts in [jsf_etch_count] always show 0', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Place the shortcode AFTER the loop in the DOM, or trust JS to fill it on page load.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'Verify the loop wrapper has the matching', 'jsf-query-builder-etch-bridge' ); ?> <code>jsf-etch-loop</code> <?php esc_html_e( 'class — counts only populate for tagged loops.', 'jsf-query-builder-etch-bridge' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Indexer counts not appearing on filter options', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<ul>
					<li><?php esc_html_e( '"Show Items Count" toggle is off in the JSF filter\'s admin settings.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'Filter is a range filter or targets CCT — not supported by this plugin\'s indexer.', 'jsf-query-builder-etch-bridge' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Loopback fails on production with SSL errors', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<p><?php esc_html_e( 'By default loopback uses', 'jsf-query-builder-etch-bridge' ); ?> <code>sslverify => false</code> <?php esc_html_e( 'for local-dev compatibility. To enable SSL verification on production, add to your theme functions or a small mu-plugin:', 'jsf-query-builder-etch-bridge' ); ?></p>
				<pre><code>add_filter( 'jqbeb_loopback_sslverify', '__return_true' );</code></pre>
			</div>
		</div>
		<?php
	}

	private function status_pill( bool $ok ): string {
		if ( $ok ) {
			return '<span class="jqbeb-status ok">' . esc_html__( '✓ Active', 'jsf-query-builder-etch-bridge' ) . '</span>';
		}
		return '<span class="jqbeb-status bad">' . esc_html__( '✗ Inactive', 'jsf-query-builder-etch-bridge' ) . '</span>';
	}
}
