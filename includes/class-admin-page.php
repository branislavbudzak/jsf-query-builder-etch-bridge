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
					<li><strong><?php esc_html_e( 'JetEngine Query Builder bridge', 'jsf-query-builder-etch-bridge' ); ?></strong> — <?php esc_html_e( 'lets a JE Query Builder query (Posts, Users, Terms, Merged Query, or SQL) become the data source for an Etch Query Loop, replacing the loop\'s built-in query.', 'jsf-query-builder-etch-bridge' ); ?></li>
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
					<tr>
						<td><code>Merged Query</code><br><small><?php esc_html_e( 'base type Posts / Users / Terms', 'jsf-query-builder-etch-bridge' ); ?></small></td>
						<td><?php esc_html_e( 'matches the merge\'s base type', 'jsf-query-builder-etch-bridge' ); ?></td>
						<td><?php esc_html_e( 'same hook as base type', 'jsf-query-builder-etch-bridge' ); ?></td>
					</tr>
					<tr>
						<td><code>SQL</code><br><small><?php esc_html_e( 'inferred via cast_object_to or je-as-{type} hint', 'jsf-query-builder-etch-bridge' ); ?></small></td>
						<td><?php esc_html_e( 'wp-query / wp-users / wp-terms', 'jsf-query-builder-etch-bridge' ); ?></td>
						<td><?php esc_html_e( 'same hook as inferred type', 'jsf-query-builder-etch-bridge' ); ?></td>
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

				<h3><?php esc_html_e( 'Merged queries (combining results from multiple JE queries)', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<p><?php esc_html_e( 'JetEngine "Merged Query" combines results from several JE queries of the SAME base type — e.g. three Posts queries concatenated. This bridge supports merged queries with base types Posts, Users, and Terms.', 'jsf-query-builder-etch-bridge' ); ?></p>
				<p><?php esc_html_e( 'How it works:', 'jsf-query-builder-etch-bridge' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'The bridge pre-fetches the merged result via JE\'s', 'jsf-query-builder-etch-bridge' ); ?> <code>$merged-&gt;get_items()</code><?php esc_html_e( ', extracts the IDs, and feeds them to Etch\'s loop query as', 'jsf-query-builder-etch-bridge' ); ?> <code>post__in</code> <?php esc_html_e( '(Posts) or', 'jsf-query-builder-etch-bridge' ); ?> <code>include</code> <?php esc_html_e( '(Users / Terms).', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'Order is preserved with', 'jsf-query-builder-etch-bridge' ); ?> <code>orderby = "post__in"</code> <?php esc_html_e( '/', 'jsf-query-builder-etch-bridge' ); ?> <code>"include"</code><?php esc_html_e( '. The Etch preset\'s pagination is disabled — JE\'s merged query has already paginated internally.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'Setup is identical to a regular JE query: just add', 'jsf-query-builder-etch-bridge' ); ?> <code>je-etch-loop je-q-{merged-query-id}</code> <?php esc_html_e( 'to your wrapper. The bridge detects the merged class automatically.', 'jsf-query-builder-etch-bridge' ); ?></li>
				</ul>

				<div class="jqbeb-callout warn">
					<strong><?php esc_html_e( 'Merged query caveats:', 'jsf-query-builder-etch-bridge' ); ?></strong>
					<ul style="margin:8px 0 0 0;">
						<li><?php esc_html_e( 'Merged is same-type only — set the merge\'s "Base Query Type" to the type that matches your Etch preset.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'JSF + Merged is NOT supported. JSF expects a SQL-backed WP_Query and adds meta_query / tax_query constraints, but Merged predefines the result set via post__in. Use Merged with a plain Etch loop only.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'Pagination semantics are whatever JE\'s Merged_Query produces (per sub-query, capped by max_items_per_page). Etch\'s own pagination is bypassed.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'Empty merged result: the bridge feeds', 'jsf-query-builder-etch-bridge' ); ?> <code>post__in = [0]</code> <?php esc_html_e( '/', 'jsf-query-builder-etch-bridge' ); ?> <code>include = [0]</code> <?php esc_html_e( 'so the Etch loop renders nothing instead of falling back to all posts.', 'jsf-query-builder-etch-bridge' ); ?></li>
					</ul>
				</div>

				<h3><?php esc_html_e( 'SQL queries (raw $wpdb queries returning IDs)', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<p><?php esc_html_e( 'JE SQL queries don\'t use a WordPress query class — they run raw SQL via $wpdb->get_results(). The bridge supports them by pre-fetching results, extracting an ID column, and feeding the IDs into the Etch loop\'s WP_Query / WP_User_Query / WP_Term_Query as', 'jsf-query-builder-etch-bridge' ); ?> <code>post__in</code> / <code>include</code><?php esc_html_e( '. This works whenever your SQL returns rows that have a recognisable ID column.', 'jsf-query-builder-etch-bridge' ); ?></p>

				<p><strong><?php esc_html_e( 'How target type is determined (in priority order):', 'jsf-query-builder-etch-bridge' ); ?></strong></p>
				<ol>
					<li><?php esc_html_e( 'Wrapper class hint:', 'jsf-query-builder-etch-bridge' ); ?> <code>je-as-posts</code> / <code>je-as-users</code> / <code>je-as-terms</code> <?php esc_html_e( '(highest priority — explicit override).', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'JE SQL query\'s "Cast Object To" setting:', 'jsf-query-builder-etch-bridge' ); ?> <code>WP_Post</code> → posts, <code>WP_User</code> → users, <code>WP_Term</code> → terms.</li>
					<li><?php esc_html_e( 'Default:', 'jsf-query-builder-etch-bridge' ); ?> <code>posts</code> <?php esc_html_e( '(most common case).', 'jsf-query-builder-etch-bridge' ); ?></li>
				</ol>

				<p><strong><?php esc_html_e( 'How IDs are extracted from each row:', 'jsf-query-builder-etch-bridge' ); ?></strong></p>
				<ul>
					<li><?php esc_html_e( 'If the row is a WP_Post / WP_User instance →', 'jsf-query-builder-etch-bridge' ); ?> <code>-&gt;ID</code></li>
					<li><?php esc_html_e( 'If the row is a WP_Term instance →', 'jsf-query-builder-etch-bridge' ); ?> <code>-&gt;term_id</code></li>
					<li><?php esc_html_e( 'If the row is a stdClass (raw SQL result) → first non-zero column among', 'jsf-query-builder-etch-bridge' ); ?> <code>ID</code>, <code>id</code>, <code>post_id</code>, <code>user_id</code>, <code>term_id</code>.</li>
				</ul>

				<h4><?php esc_html_e( 'Example — posts loop driven by raw SQL', 'jsf-query-builder-etch-bridge' ); ?></h4>
				<ol>
					<li><?php esc_html_e( 'Create a JE SQL query that returns a column named', 'jsf-query-builder-etch-bridge' ); ?> <code>ID</code> <?php esc_html_e( '(e.g.', 'jsf-query-builder-etch-bridge' ); ?> <code>SELECT ID FROM wp_posts WHERE ...</code><?php esc_html_e( '). Optionally set "Cast Object To" =', 'jsf-query-builder-etch-bridge' ); ?> <code>WP_Post</code><?php esc_html_e( '.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'On your Etch wrapper add:', 'jsf-query-builder-etch-bridge' ); ?> <code>je-etch-loop je-q-{sql-query-id}</code></li>
					<li><?php esc_html_e( 'Pick a wp-query Etch loop preset. The bridge will replace its args with', 'jsf-query-builder-etch-bridge' ); ?> <code>post__in</code><?php esc_html_e( ' from your SQL result.', 'jsf-query-builder-etch-bridge' ); ?></li>
				</ol>

				<h4><?php esc_html_e( 'Example — explicit override with hint', 'jsf-query-builder-etch-bridge' ); ?></h4>
				<p><?php esc_html_e( 'If your SQL query has no Cast Object To set but you want to drive a users loop:', 'jsf-query-builder-etch-bridge' ); ?></p>
				<pre><code>je-etch-loop je-q-42 je-as-users</code></pre>

				<div class="jqbeb-callout warn">
					<strong><?php esc_html_e( 'SQL caveats:', 'jsf-query-builder-etch-bridge' ); ?></strong>
					<ul style="margin:8px 0 0 0;">
						<li><?php esc_html_e( 'Your SQL must return at least one of the recognised ID columns. Rows without one are silently skipped.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'JSF + SQL is NOT supported (same reason as Merged — predefined post__in cannot accept JSF\'s meta_query / tax_query overlays).', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'Pagination is delegated to JE SQL query (LIMIT/OFFSET driven by max_items_per_page or limit). Etch\'s own posts_per_page is bypassed.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'Empty SQL result → bridge feeds', 'jsf-query-builder-etch-bridge' ); ?> <code>[0]</code> <?php esc_html_e( 'so the Etch loop renders nothing rather than falling back to all posts.', 'jsf-query-builder-etch-bridge' ); ?></li>
					</ul>
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
				<p><?php esc_html_e( 'For JE Users / Terms / Merged / SQL queries, JSF integration with the same loop is not wired — JSF is a Posts-only content provider in this plugin. For Merged and SQL queries specifically, JSF cannot stack on top because the bridge predefines the result via post__in. Apply the JE bridge alone for those cases.', 'jsf-query-builder-etch-bridge' ); ?></p>
			</div>
			<?php endif; ?>

			<!-- ============== LIMITATIONS ============== -->
			<div class="jqbeb-section">
				<h2><?php esc_html_e( 'Limitations', 'jsf-query-builder-etch-bridge' ); ?></h2>
				<ul>
					<li><?php esc_html_e( 'JE Query Builder bridge supports Posts, Users, Terms, Merged Query, and SQL types. Repeater, Comments, Current_WP_Query are NOT supported (Etch has no compatible loop preset for them).', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'JE query type and Etch loop preset type must match (Posts↔wp-query, Users↔wp-users, Terms↔wp-terms). For Merged Query, match the merge\'s base type. For SQL, the target type is inferred from the wrapper hint or cast_object_to. Mismatch causes silent no-op — Etch falls back to its preset query.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'JSF integration is Posts-only and only for regular Posts queries. JSF filters do not drive Users / Terms / Merged / SQL loops.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'Merged and SQL queries bypass Etch\'s pagination (JE handles it internally). Combining them with JSF is NOT supported.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'SQL queries must return rows with a recognisable ID column (ID / id / post_id / user_id / term_id) for the bridge to extract IDs. Rows without one are skipped.', 'jsf-query-builder-etch-bridge' ); ?></li>
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
					<li><?php esc_html_e( 'JE query type is Repeater / Comments — not supported.', 'jsf-query-builder-etch-bridge' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'SQL query renders empty / wrong items', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Your SQL doesn\'t return a recognised ID column. Add', 'jsf-query-builder-etch-bridge' ); ?> <code>SELECT ID</code> <?php esc_html_e( '(or alias an existing column to', 'jsf-query-builder-etch-bridge' ); ?> <code>ID</code><?php esc_html_e( ').', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'Target type inference picked the wrong type. Add an explicit hint:', 'jsf-query-builder-etch-bridge' ); ?> <code>je-as-posts</code> <?php esc_html_e( '/', 'jsf-query-builder-etch-bridge' ); ?> <code>je-as-users</code> <?php esc_html_e( '/', 'jsf-query-builder-etch-bridge' ); ?> <code>je-as-terms</code> <?php esc_html_e( 'on the wrapper.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'You combined SQL with a JSF filter — not supported. Remove jsf-etch-loop classes from the wrapper.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'IDs returned by your SQL don\'t exist (e.g. queried a custom table that doesn\'t map to wp_posts). Test in JE Query Builder preview.', 'jsf-query-builder-etch-bridge' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Merged Query renders empty / wrong items', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Merge\'s "Base Query Type" doesn\'t match the Etch preset. A Merged Query of Users base type only works with a wp-users Etch preset.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'You combined Merged with a JSF filter — not supported. Remove jsf-etch-loop classes from the wrapper.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'Sub-queries in the merge return zero items. Test each sub-query in JE Query Builder preview first.', 'jsf-query-builder-etch-bridge' ); ?></li>
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
