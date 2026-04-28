<?php
/**
 * Admin documentation page — Settings → JSF Etch Bridge.
 *
 * Tab-based layout. Tabs render conditionally based on which dependencies
 * are active. Active tab persists in URL hash for refresh-friendliness.
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
		$plugin  = Plugin::instance();
		$etch_ok = $plugin->is_etch_active();
		$jsf_ok  = $plugin->is_jsf_active();
		$je_ok   = $plugin->is_je_query_builder_active();

		$tabs = [];
		$tabs['overview'] = __( 'Overview', 'jsf-query-builder-etch-bridge' );
		if ( $jsf_ok ) {
			$tabs['jsf'] = __( 'JSF Bridge', 'jsf-query-builder-etch-bridge' );
		}
		if ( $je_ok ) {
			$tabs['je'] = __( 'JE Bridge', 'jsf-query-builder-etch-bridge' );
		}
		if ( $jsf_ok && $je_ok ) {
			$tabs['combined'] = __( 'Combined & CMT', 'jsf-query-builder-etch-bridge' );
		}
		$tabs['reference'] = __( 'Reference', 'jsf-query-builder-etch-bridge' );
		?>
		<div class="wrap jqbeb-admin">
			<h1><?php esc_html_e( 'JSF Query Builder Etch Bridge', 'jsf-query-builder-etch-bridge' ); ?></h1>

			<div class="jqbeb-meta">
				<span class="jqbeb-version"><?php esc_html_e( 'Version', 'jsf-query-builder-etch-bridge' ); ?> <code><?php echo esc_html( JQBEB_VERSION ); ?></code></span>
				<span class="jqbeb-deps-inline">
					<?php echo $this->status_pill_inline( 'Etch', $etch_ok ); ?>
					<?php echo $this->status_pill_inline( 'JSF', $jsf_ok ); ?>
					<?php echo $this->status_pill_inline( 'JetEngine', $je_ok ); ?>
				</span>
			</div>

			<?php if ( ! $etch_ok ) : ?>
				<div class="notice notice-warning inline">
					<p><strong><?php esc_html_e( 'Etch is not active.', 'jsf-query-builder-etch-bridge' ); ?></strong> <?php esc_html_e( 'Both bridges are disabled until Etch is enabled.', 'jsf-query-builder-etch-bridge' ); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->print_styles(); ?>

			<nav class="nav-tab-wrapper jqbeb-tabs" role="tablist">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="#tab-<?php echo esc_attr( $slug ); ?>"
					   class="nav-tab <?php echo $slug === 'overview' ? 'nav-tab-active' : ''; ?>"
					   data-tab="<?php echo esc_attr( $slug ); ?>"
					   role="tab"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php $this->render_tab_overview( $etch_ok, $jsf_ok, $je_ok ); ?>
			<?php if ( $jsf_ok ) : ?>
				<?php $this->render_tab_jsf(); ?>
			<?php endif; ?>
			<?php if ( $je_ok ) : ?>
				<?php $this->render_tab_je(); ?>
			<?php endif; ?>
			<?php if ( $jsf_ok && $je_ok ) : ?>
				<?php $this->render_tab_combined(); ?>
			<?php endif; ?>
			<?php $this->render_tab_reference(); ?>

			<?php $this->print_scripts(); ?>
		</div>
		<?php
	}

	/* -------------------- TABS -------------------- */

	private function render_tab_overview( bool $etch_ok, bool $jsf_ok, bool $je_ok ): void {
		?>
		<div class="jqbeb-tab" id="tab-overview">
			<div class="jqbeb-card">
				<h2><?php esc_html_e( 'What this plugin does', 'jsf-query-builder-etch-bridge' ); ?></h2>
				<p><?php esc_html_e( 'Two independent bridges that drive Etch\'s native Query Loop block from external query systems:', 'jsf-query-builder-etch-bridge' ); ?></p>
				<ol>
					<li><strong><?php esc_html_e( 'JetSmartFilters bridge', 'jsf-query-builder-etch-bridge' ); ?></strong> — <?php esc_html_e( 'lets JSF filter / pagination / sort blocks drive an Etch Query Loop, with AJAX filtering and per-option indexed counts.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><strong><?php esc_html_e( 'JetEngine Query Builder bridge', 'jsf-query-builder-etch-bridge' ); ?></strong> — <?php esc_html_e( 'lets a JE Query Builder query (Posts, Users, Terms, Merged, SQL, Data Stores) become the data source for an Etch Query Loop.', 'jsf-query-builder-etch-bridge' ); ?></li>
				</ol>
				<p><?php esc_html_e( 'Each bridge runs on its own. Use either, both, or none. Etch is the only hard dependency.', 'jsf-query-builder-etch-bridge' ); ?></p>
			</div>

			<div class="jqbeb-card">
				<h2><?php esc_html_e( 'Dependencies', 'jsf-query-builder-etch-bridge' ); ?></h2>
				<table class="jqbeb-deps">
					<tr>
						<td><strong>Etch</strong></td>
						<td><?php echo $this->status_pill( $etch_ok ); ?></td>
						<td class="description"><?php esc_html_e( 'Required for both bridges. Builds the Query Loop block this plugin hooks into.', 'jsf-query-builder-etch-bridge' ); ?></td>
					</tr>
					<tr>
						<td><strong>JetSmartFilters</strong></td>
						<td><?php echo $this->status_pill( $jsf_ok ); ?></td>
						<td class="description"><?php esc_html_e( 'Optional. Needed only for filter / pagination / sort blocks driving the Etch loop.', 'jsf-query-builder-etch-bridge' ); ?></td>
					</tr>
					<tr>
						<td><strong>JetEngine</strong></td>
						<td><?php echo $this->status_pill( $je_ok ); ?></td>
						<td class="description"><?php esc_html_e( 'Optional. Needed only for JE Query Builder queries as data sources.', 'jsf-query-builder-etch-bridge' ); ?></td>
					</tr>
				</table>
			</div>

			<div class="jqbeb-card">
				<h2><?php esc_html_e( 'Quick start — wrapper class cheatsheet', 'jsf-query-builder-etch-bridge' ); ?></h2>
				<table class="jqbeb-classes">
					<thead><tr><th><?php esc_html_e( 'Class', 'jsf-query-builder-etch-bridge' ); ?></th><th><?php esc_html_e( 'Purpose', 'jsf-query-builder-etch-bridge' ); ?></th></tr></thead>
					<tbody>
						<tr><td><code>jsf-etch-loop</code></td><td><?php esc_html_e( 'Marks the immediate parent of loop cards for the JSF bridge.', 'jsf-query-builder-etch-bridge' ); ?></td></tr>
						<tr><td><code>jsf-etch-q-{slug}</code></td><td><?php esc_html_e( 'Disambiguates multi-loop pages; matches JSF block\'s "Query ID" setting.', 'jsf-query-builder-etch-bridge' ); ?></td></tr>
						<tr><td><code>je-etch-loop</code></td><td><?php esc_html_e( 'Marks an Etch loop wrapper to use a JE query as data source.', 'jsf-query-builder-etch-bridge' ); ?></td></tr>
						<tr><td><code>je-q-{id}</code></td><td><?php esc_html_e( 'Numeric JE query ID OR custom Query ID slug.', 'jsf-query-builder-etch-bridge' ); ?></td></tr>
						<tr><td><code>je-as-{posts|users|terms}</code></td><td><?php esc_html_e( 'Explicit target type override (mainly for SQL queries).', 'jsf-query-builder-etch-bridge' ); ?></td></tr>
						<tr><td><code>je-jsf-stack</code></td><td><?php esc_html_e( 'Opt-in JSF compatibility for Merged / SQL / Data Store queries.', 'jsf-query-builder-etch-bridge' ); ?></td></tr>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Both bridges can coexist on the same wrapper.', 'jsf-query-builder-etch-bridge' ); ?></p>
			</div>
		</div>
		<?php
	}

	private function render_tab_jsf(): void {
		?>
		<div class="jqbeb-tab" id="tab-jsf">
			<div class="jqbeb-card">
				<h2><?php esc_html_e( 'Setup — 3 steps', 'jsf-query-builder-etch-bridge' ); ?></h2>

				<h3>1. <?php esc_html_e( 'Mark the loop wrapper', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<p><?php esc_html_e( 'In the Etch builder, on the IMMEDIATE container that wraps ONLY the loop cards (not pagination), add:', 'jsf-query-builder-etch-bridge' ); ?></p>
				<pre><code>jsf-etch-loop</code></pre>
				<p class="description"><?php esc_html_e( 'Pagination and sort blocks must live OUTSIDE this wrapper.', 'jsf-query-builder-etch-bridge' ); ?></p>

				<h3>2. <?php esc_html_e( 'Multi-loop on one page (optional)', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<p><?php esc_html_e( 'Add a unique query class to each wrapper:', 'jsf-query-builder-etch-bridge' ); ?></p>
				<pre><code>jsf-etch-loop jsf-etch-q-partner-listings
jsf-etch-loop jsf-etch-q-cars</code></pre>

				<h3>3. <?php esc_html_e( 'Configure JSF blocks', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<ul>
					<li><strong><?php esc_html_e( 'Content Provider', 'jsf-query-builder-etch-bridge' ); ?>:</strong> <code>Etch Loop</code></li>
					<li><strong><?php esc_html_e( 'Query ID', 'jsf-query-builder-etch-bridge' ); ?>:</strong> <?php esc_html_e( 'leave blank for default; otherwise the slug after', 'jsf-query-builder-etch-bridge' ); ?> <code>jsf-etch-q-</code></li>
				</ul>
			</div>

			<details class="jqbeb-card">
				<summary><h2><?php esc_html_e( '[jsf_etch_count] shortcode', 'jsf-query-builder-etch-bridge' ); ?></h2></summary>
				<div class="jqbeb-card-body">
					<p><?php esc_html_e( 'Renders a span with query result counts. Updates live after AJAX filter changes.', 'jsf-query-builder-etch-bridge' ); ?></p>
					<pre><code>[jsf_etch_count]                                       <?php esc_html_e( '— total found posts (default)', 'jsf-query-builder-etch-bridge' ); ?>
[jsf_etch_count attr="max_num_pages"]                  <?php esc_html_e( '— total pages', 'jsf-query-builder-etch-bridge' ); ?>
[jsf_etch_count attr="page"]                           <?php esc_html_e( '— current page', 'jsf-query-builder-etch-bridge' ); ?>
[jsf_etch_count provider="etch-loop" query_id="cars"]  <?php esc_html_e( '— specific multi-loop query', 'jsf-query-builder-etch-bridge' ); ?></code></pre>
					<table class="jqbeb-attrs">
						<tr><th><?php esc_html_e( 'Attribute', 'jsf-query-builder-etch-bridge' ); ?></th><th><?php esc_html_e( 'Default', 'jsf-query-builder-etch-bridge' ); ?></th><th><?php esc_html_e( 'Notes', 'jsf-query-builder-etch-bridge' ); ?></th></tr>
						<tr><td><code>provider</code></td><td><code>etch-loop</code></td><td>—</td></tr>
						<tr><td><code>query_id</code></td><td><code>default</code></td><td>—</td></tr>
						<tr><td><code>attr</code></td><td><code>found_posts</code></td><td><code>found_posts</code> | <code>max_num_pages</code> | <code>page</code></td></tr>
						<tr><td><code>placeholder</code></td><td><code>0</code></td><td><?php esc_html_e( 'Shown until JS fills the live value.', 'jsf-query-builder-etch-bridge' ); ?></td></tr>
					</table>
				</div>
			</details>

			<details class="jqbeb-card" open>
				<summary><h2><?php esc_html_e( 'Per-option counts (Filter Indexer)', 'jsf-query-builder-etch-bridge' ); ?></h2></summary>
				<div class="jqbeb-card-body">
					<p><?php esc_html_e( 'In each JSF filter\'s admin settings, toggle "Show Items Count" → ON. Counts appear next to each option, e.g. "Bratislava (12)".', 'jsf-query-builder-etch-bridge' ); ?></p>
					<table class="jqbeb-support">
						<tr><td>tax_query <?php esc_html_e( '(taxonomy filters)', 'jsf-query-builder-etch-bridge' ); ?></td><td><span class="jqbeb-status ok">✓</span></td></tr>
						<tr><td>meta_query <?php esc_html_e( '(postmeta filters)', 'jsf-query-builder-etch-bridge' ); ?></td><td><span class="jqbeb-status ok">✓</span></td></tr>
						<tr><td>meta_query <?php esc_html_e( 'on JE Custom Meta Tables (CMT) fields', 'jsf-query-builder-etch-bridge' ); ?></td><td><span class="jqbeb-status ok">✓</span></td></tr>
						<tr><td><?php esc_html_e( 'Range filters (sliders)', 'jsf-query-builder-etch-bridge' ); ?></td><td><span class="jqbeb-status muted">—</span> <small><?php esc_html_e( 'sliders don\'t use per-option counts', 'jsf-query-builder-etch-bridge' ); ?></small></td></tr>
					</table>
					<p class="description"><?php esc_html_e( 'CMT-stored fields are auto-detected and queried directly against the custom table — see Combined & CMT tab for details.', 'jsf-query-builder-etch-bridge' ); ?></p>
				</div>
			</details>
		</div>
		<?php
	}

	private function render_tab_je(): void {
		?>
		<div class="jqbeb-tab" id="tab-je">
			<div class="jqbeb-card">
				<h2><?php esc_html_e( 'Setup — 4 steps', 'jsf-query-builder-etch-bridge' ); ?></h2>

				<h3>1. <?php esc_html_e( 'Create a JE Query Builder query', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<p><?php esc_html_e( 'Go to', 'jsf-query-builder-etch-bridge' ); ?> <strong>JetEngine → Query Builder</strong>. <?php esc_html_e( 'Note the numeric ID, or set a custom Query ID slug like', 'jsf-query-builder-etch-bridge' ); ?> <code>partner-listings</code>.</p>

				<h3>2. <?php esc_html_e( 'Match JE query type with Etch loop preset type', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<table class="jqbeb-types">
					<thead><tr><th><?php esc_html_e( 'JE query type', 'jsf-query-builder-etch-bridge' ); ?></th><th><?php esc_html_e( 'Etch preset', 'jsf-query-builder-etch-bridge' ); ?></th><th><?php esc_html_e( 'Hook', 'jsf-query-builder-etch-bridge' ); ?></th></tr></thead>
					<tbody>
						<tr><td><code>Posts</code></td><td><code>wp-query</code></td><td><code>pre_get_posts</code></td></tr>
						<tr><td><code>Users</code></td><td><code>wp-users</code></td><td><code>pre_user_query</code></td></tr>
						<tr><td><code>Terms</code></td><td><code>wp-terms</code></td><td><code>pre_get_terms</code></td></tr>
						<tr><td><code>Merged</code> <small>(base type)</small></td><td><?php esc_html_e( 'matches base', 'jsf-query-builder-etch-bridge' ); ?></td><td><?php esc_html_e( 'same as base', 'jsf-query-builder-etch-bridge' ); ?></td></tr>
						<tr><td><code>SQL</code> <small>(inferred)</small></td><td><?php esc_html_e( 'wp-query / wp-users / wp-terms', 'jsf-query-builder-etch-bridge' ); ?></td><td><?php esc_html_e( 'inferred', 'jsf-query-builder-etch-bridge' ); ?></td></tr>
						<tr><td><code>Data Stores</code></td><td><?php esc_html_e( 'wp-query (post stores) / wp-users (user stores)', 'jsf-query-builder-etch-bridge' ); ?></td><td><code>pre_get_posts</code> / <code>pre_user_query</code></td></tr>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Type mismatch → silent no-op; Etch falls back to its preset query.', 'jsf-query-builder-etch-bridge' ); ?></p>

				<h3>3. <?php esc_html_e( 'Tag the Etch loop wrapper', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<pre><code>je-etch-loop je-q-42                  <?php esc_html_e( '— numeric query ID', 'jsf-query-builder-etch-bridge' ); ?>
je-etch-loop je-q-partner-listings    <?php esc_html_e( '— Query ID slug', 'jsf-query-builder-etch-bridge' ); ?></code></pre>

				<h3>4. <?php esc_html_e( 'Etch preset is just a shell', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<p><?php esc_html_e( 'Pick any preset of the matching type. Its args are wholesale-replaced by the JE query — post type, ordering, meta queries, pagination all come from JE.', 'jsf-query-builder-etch-bridge' ); ?></p>
			</div>

			<details class="jqbeb-card">
				<summary><h2><?php esc_html_e( 'Merged Query', 'jsf-query-builder-etch-bridge' ); ?></h2></summary>
				<div class="jqbeb-card-body">
					<p><?php esc_html_e( 'JetEngine Merged Query combines results from several queries of the SAME base type. The bridge supports merged queries with base types Posts, Users, and Terms.', 'jsf-query-builder-etch-bridge' ); ?></p>
					<p><?php esc_html_e( 'Setup is identical to a regular JE query — just', 'jsf-query-builder-etch-bridge' ); ?> <code>je-etch-loop je-q-{merged-id}</code> <?php esc_html_e( 'on the wrapper. The bridge detects the merged class automatically.', 'jsf-query-builder-etch-bridge' ); ?></p>
					<p class="description"><strong><?php esc_html_e( 'How it works:', 'jsf-query-builder-etch-bridge' ); ?></strong> <?php esc_html_e( 'pre-fetch via', 'jsf-query-builder-etch-bridge' ); ?> <code>$merged-&gt;get_items()</code><?php esc_html_e( ', extract IDs, feed them to Etch\'s loop query as', 'jsf-query-builder-etch-bridge' ); ?> <code>post__in</code> / <code>include</code><?php esc_html_e( '. Order preserved with', 'jsf-query-builder-etch-bridge' ); ?> <code>orderby = "post__in"</code> / <code>"include"</code>.</p>
					<div class="jqbeb-callout warn">
						<strong><?php esc_html_e( 'Caveats:', 'jsf-query-builder-etch-bridge' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'Same-type only — set the merge\'s "Base Query Type" to match your Etch preset.', 'jsf-query-builder-etch-bridge' ); ?></li>
							<li><?php esc_html_e( 'JE owns pagination internally; Etch native pagination bypassed.', 'jsf-query-builder-etch-bridge' ); ?></li>
							<li><?php esc_html_e( 'JSF integration requires opt-in', 'jsf-query-builder-etch-bridge' ); ?> <code>je-jsf-stack</code> (<?php esc_html_e( 'see below', 'jsf-query-builder-etch-bridge' ); ?>).</li>
						</ul>
					</div>
				</div>
			</details>

			<details class="jqbeb-card">
				<summary><h2><?php esc_html_e( 'SQL Query', 'jsf-query-builder-etch-bridge' ); ?></h2></summary>
				<div class="jqbeb-card-body">
					<p><?php esc_html_e( 'JE SQL queries run raw', 'jsf-query-builder-etch-bridge' ); ?> <code>$wpdb->get_results()</code><?php esc_html_e( '. The bridge pre-fetches results, extracts an ID column, and feeds the IDs into the Etch loop\'s query.', 'jsf-query-builder-etch-bridge' ); ?></p>

					<h3><?php esc_html_e( 'Target type detection (priority order)', 'jsf-query-builder-etch-bridge' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Wrapper class hint:', 'jsf-query-builder-etch-bridge' ); ?> <code>je-as-posts</code> / <code>je-as-users</code> / <code>je-as-terms</code></li>
						<li>JE SQL <em>Cast Object To</em>: <code>WP_Post</code> → posts, <code>WP_User</code> → users, <code>WP_Term</code> → terms</li>
						<li><?php esc_html_e( 'Default:', 'jsf-query-builder-etch-bridge' ); ?> <code>posts</code></li>
					</ol>

					<h3><?php esc_html_e( 'ID column extraction', 'jsf-query-builder-etch-bridge' ); ?></h3>
					<p><?php esc_html_e( 'For each row: WP_Post / WP_User instance →', 'jsf-query-builder-etch-bridge' ); ?> <code>-&gt;ID</code><?php esc_html_e( '; WP_Term →', 'jsf-query-builder-etch-bridge' ); ?> <code>-&gt;term_id</code><?php esc_html_e( '; stdClass → first non-zero of', 'jsf-query-builder-etch-bridge' ); ?> <code>ID</code> | <code>id</code> | <code>post_id</code> | <code>user_id</code> | <code>term_id</code>.</p>

					<h3><?php esc_html_e( 'Example with explicit type hint', 'jsf-query-builder-etch-bridge' ); ?></h3>
					<pre><code>je-etch-loop je-q-42 je-as-users</code></pre>

					<div class="jqbeb-callout warn">
						<strong><?php esc_html_e( 'Caveats:', 'jsf-query-builder-etch-bridge' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'Rows without a recognised ID column are silently skipped.', 'jsf-query-builder-etch-bridge' ); ?></li>
							<li><?php esc_html_e( 'JE owns pagination via', 'jsf-query-builder-etch-bridge' ); ?> <code>limit_per_page</code> / <code>limit</code><?php esc_html_e( '.', 'jsf-query-builder-etch-bridge' ); ?></li>
							<li><?php esc_html_e( 'JSF integration requires', 'jsf-query-builder-etch-bridge' ); ?> <code>je-jsf-stack</code>.</li>
						</ul>
					</div>
				</div>
			</details>

			<details class="jqbeb-card">
				<summary><h2><?php esc_html_e( 'Data Stores Query', 'jsf-query-builder-etch-bridge' ); ?></h2></summary>
				<div class="jqbeb-card-body">
					<p><?php esc_html_e( 'JE Data Store queries wrap a Posts or Users sub-query and filter to items the user has saved (favourites, recently viewed, comparisons). The bridge uses the same pre-fetch + post__in path.', 'jsf-query-builder-etch-bridge' ); ?></p>

					<h3><?php esc_html_e( 'Target type detection', 'jsf-query-builder-etch-bridge' ); ?></h3>
					<ul>
						<li><?php esc_html_e( 'Auto-read from store config via', 'jsf-query-builder-etch-bridge' ); ?> <code>$store-&gt;is_user_store()</code><?php esc_html_e( ' — user store → wp-users; post store → wp-query.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><code>je-as-{type}</code> <?php esc_html_e( 'wrapper hint overrides if needed.', 'jsf-query-builder-etch-bridge' ); ?></li>
					</ul>

					<h3><?php esc_html_e( 'Example — favourites loop', 'jsf-query-builder-etch-bridge' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Create a Data Store in', 'jsf-query-builder-etch-bridge' ); ?> <strong>JetEngine → Data Stores</strong>.</li>
						<li><?php esc_html_e( 'Create a JE Query Builder query of type Data Stores Query.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'Wrapper:', 'jsf-query-builder-etch-bridge' ); ?> <code>je-etch-loop je-q-{ds-query-id}</code></li>
					</ol>
				</div>
			</details>

			<details class="jqbeb-card">
				<summary><h2><code>je-jsf-stack</code> — <?php esc_html_e( 'JSF compatibility for Merged / SQL / Data Store', 'jsf-query-builder-etch-bridge' ); ?></h2></summary>
				<div class="jqbeb-card-body">
					<p><?php esc_html_e( 'Add', 'jsf-query-builder-etch-bridge' ); ?> <code>je-jsf-stack</code> <?php esc_html_e( 'to the wrapper to enable JSF filter / pagination / sort + the [jsf_etch_count] shortcode for these query types.', 'jsf-query-builder-etch-bridge' ); ?></p>

					<h3><?php esc_html_e( 'How it changes behaviour', 'jsf-query-builder-etch-bridge' ); ?></h3>
					<ul>
						<li><?php esc_html_e( 'Bridge overrides JE per-page caps to unlimited and forces _page=1 → JE returns the FULL filter set on each render.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'WP_Query pagination flags are NOT force-disabled. Etch preset\'s', 'jsf-query-builder-etch-bridge' ); ?> <code>posts_per_page</code> + <?php esc_html_e( 'JSF\'s', 'jsf-query-builder-etch-bridge' ); ?> <code>paged</code> <?php esc_html_e( 'drive native pagination over the', 'jsf-query-builder-etch-bridge' ); ?> <code>post__in</code> <?php esc_html_e( 'subset.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'JSF', 'jsf-query-builder-etch-bridge' ); ?> <code>meta_query</code> / <code>tax_query</code> <?php esc_html_e( 'merge naturally — result is intersection of JE pre-fetched IDs and JSF filter constraints.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><code>found_posts</code> <?php esc_html_e( 'is correct →', 'jsf-query-builder-etch-bridge' ); ?> <code>[jsf_etch_count]</code> <?php esc_html_e( 'shows real counts.', 'jsf-query-builder-etch-bridge' ); ?></li>
					</ul>

					<h3><?php esc_html_e( 'Required wrapper classes', 'jsf-query-builder-etch-bridge' ); ?></h3>
					<pre><code>jsf-etch-loop jsf-etch-q-{slug} je-etch-loop je-q-{merged-or-sql-id} je-jsf-stack</code></pre>

					<div class="jqbeb-callout warn">
						<strong><?php esc_html_e( 'Trade-offs:', 'jsf-query-builder-etch-bridge' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'Cost: full JE result set fetched on every render. Fine for moderate sets (~hundreds). For 10k+ items prefer the default mode (without JSF).', 'jsf-query-builder-etch-bridge' ); ?></li>
							<li><?php esc_html_e( 'Posts loops only — JSF in this plugin is Posts-only.', 'jsf-query-builder-etch-bridge' ); ?></li>
						</ul>
					</div>
				</div>
			</details>
		</div>
		<?php
	}

	private function render_tab_combined(): void {
		?>
		<div class="jqbeb-tab" id="tab-combined">
			<div class="jqbeb-card">
				<h2><?php esc_html_e( 'Combining both bridges', 'jsf-query-builder-etch-bridge' ); ?></h2>
				<p><?php esc_html_e( 'Layer both bridges on the same wrapper. JetEngine provides the base query; JetSmartFilters stacks user filters on top.', 'jsf-query-builder-etch-bridge' ); ?></p>
				<pre><code>jsf-etch-loop jsf-etch-q-cars je-etch-loop je-q-cars</code></pre>

				<h3><?php esc_html_e( 'Hook order on Posts queries', 'jsf-query-builder-etch-bridge' ); ?></h3>
				<table class="jqbeb-hooks">
					<tr><td><code>pre_get_posts</code> p40</td><td><?php esc_html_e( 'JE bridge wholesale-replaces query args.', 'jsf-query-builder-etch-bridge' ); ?></td></tr>
					<tr><td><code>pre_get_posts</code> p50</td><td><?php esc_html_e( 'JSF bridge tags the query for filter merging + registers the default query.', 'jsf-query-builder-etch-bridge' ); ?></td></tr>
					<tr><td><code>pre_get_posts</code> p60</td><td><?php esc_html_e( 'JSF merges user filter args on top of JE base.', 'jsf-query-builder-etch-bridge' ); ?></td></tr>
					<tr><td><code>pre_get_posts</code> p70</td><td><?php esc_html_e( 'JE bridge CMT redirect (splits the merged meta_query if post type uses Custom Storage).', 'jsf-query-builder-etch-bridge' ); ?></td></tr>
				</table>
			</div>

			<div class="jqbeb-card">
				<h2><?php esc_html_e( 'Custom Meta Tables (CMT) support', 'jsf-query-builder-etch-bridge' ); ?></h2>
				<p><?php esc_html_e( 'When a post type has JetEngine\'s Custom Storage enabled, its meta values live in a dedicated table (e.g.', 'jsf-query-builder-etch-bridge' ); ?> <code>wp_{prefix}_ad_listing_meta</code><?php esc_html_e( ') instead of', 'jsf-query-builder-etch-bridge' ); ?> <code>wp_postmeta</code>. <?php esc_html_e( 'The bridge handles this transparently for both bridges:', 'jsf-query-builder-etch-bridge' ); ?></p>

				<table class="jqbeb-support">
					<tr><td><?php esc_html_e( 'JE base query: meta_query / orderby on CMT fields', 'jsf-query-builder-etch-bridge' ); ?></td><td><span class="jqbeb-status ok">✓</span></td></tr>
					<tr><td><?php esc_html_e( 'JSF user filter (checkbox / select / range) on CMT fields', 'jsf-query-builder-etch-bridge' ); ?></td><td><span class="jqbeb-status ok">✓</span></td></tr>
					<tr><td><?php esc_html_e( 'JSF sort filter on CMT fields', 'jsf-query-builder-etch-bridge' ); ?></td><td><span class="jqbeb-status ok">✓</span></td></tr>
					<tr><td><?php esc_html_e( 'JSF Filter Indexer per-option counts on CMT fields', 'jsf-query-builder-etch-bridge' ); ?></td><td><span class="jqbeb-status ok">✓</span></td></tr>
					<tr><td><?php esc_html_e( '[jsf_etch_count] for loops on CMT post types', 'jsf-query-builder-etch-bridge' ); ?></td><td><span class="jqbeb-status ok">✓</span></td></tr>
					<tr><td><?php esc_html_e( 'CMT for Users / Terms (vs Posts)', 'jsf-query-builder-etch-bridge' ); ?></td><td><span class="jqbeb-status muted">—</span> <small><?php esc_html_e( 'JE core supports CMT only for the post object_type.', 'jsf-query-builder-etch-bridge' ); ?></small></td></tr>
				</table>

				<details>
					<summary><strong><?php esc_html_e( 'How it works (technical)', 'jsf-query-builder-etch-bridge' ); ?></strong></summary>
					<p class="description"><?php esc_html_e( 'JE registers a global', 'jsf-query-builder-etch-bridge' ); ?> <code>posts_clauses</code> <?php esc_html_e( 'filter that emits a CMT JOIN/WHERE/ORDER iff the WP_Query carries a', 'jsf-query-builder-etch-bridge' ); ?> <code>custom_table_query</code> <?php esc_html_e( 'query var. JE\'s own splitter populates that var at', 'jsf-query-builder-etch-bridge' ); ?> <code>pre_get_posts</code> <?php esc_html_e( 'p10 — too early to see args our bridge injects later. Bridge replicates the splitter inline at p70 (after JSF\'s p60 merge), so the split sees the combined meta_query (JE base + JSF filters) and routes every CMT clause into', 'jsf-query-builder-etch-bridge' ); ?> <code>custom_table_query</code><?php esc_html_e( '.', 'jsf-query-builder-etch-bridge' ); ?></p>
					<p class="description"><?php esc_html_e( 'For the Filter Indexer, CMT fields are queried column-by-column directly against the custom table; non-CMT keys still go to', 'jsf-query-builder-etch-bridge' ); ?> <code>wp_postmeta</code><?php esc_html_e( '. Counts merge into a unified value→count bucket per filter.', 'jsf-query-builder-etch-bridge' ); ?></p>
				</details>
			</div>

			<div class="jqbeb-card">
				<h2><?php esc_html_e( 'Performance tip — index hot CMT columns', 'jsf-query-builder-etch-bridge' ); ?></h2>
				<p><?php esc_html_e( 'JE\'s CMT table only ships an', 'jsf-query-builder-etch-bridge' ); ?> <code>object_ID</code> <?php esc_html_e( 'index. Range filters and sort orderbys on individual columns trigger full table scans on larger datasets, which makes JSF AJAX feel slow.', 'jsf-query-builder-etch-bridge' ); ?></p>
				<p><?php esc_html_e( 'Add MySQL indexes on hot columns directly (Adminer / phpMyAdmin / WP-CLI):', 'jsf-query-builder-etch-bridge' ); ?></p>
				<pre><code>ALTER TABLE wp_{prefix}_ad_listing_meta ADD INDEX idx_price (price_sale_gross);
ALTER TABLE wp_{prefix}_ad_listing_meta ADD INDEX idx_mileage (mileage_km);
ALTER TABLE wp_{prefix}_ad_listing_meta ADD INDEX idx_priority (listing_priority);</code></pre>
				<p class="description"><?php esc_html_e( 'For range queries on numeric columns this is often a 10-100× speedup.', 'jsf-query-builder-etch-bridge' ); ?></p>
			</div>
		</div>
		<?php
	}

	private function render_tab_reference(): void {
		?>
		<div class="jqbeb-tab" id="tab-reference">
			<div class="jqbeb-card">
				<h2><?php esc_html_e( 'Limitations', 'jsf-query-builder-etch-bridge' ); ?></h2>
				<ul>
					<li><?php esc_html_e( 'JE Repeater / Comments / Current_WP_Query types are NOT supported (Etch has no compatible loop preset for them).', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'JE query type and Etch loop preset type must match. Mismatch → silent no-op; Etch falls back to its preset query.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'JSF integration is Posts-only. For Merged / SQL / Data Store Posts queries it requires the', 'jsf-query-builder-etch-bridge' ); ?> <code>je-jsf-stack</code> <?php esc_html_e( 'opt-in.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'Only Etch loops in', 'jsf-query-builder-etch-bridge' ); ?> <code>loopId</code> <?php esc_html_e( 'mode are bridged. Target / expression mode bypasses WP_Query entirely.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'Filter Indexer skips range filters (sliders don\'t need per-option counts).', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'CCT (separate', 'jsf-query-builder-etch-bridge' ); ?> <code>wp_jet_cct_*</code> <?php esc_html_e( 'tables, no link to', 'jsf-query-builder-etch-bridge' ); ?> <code>wp_posts</code><?php esc_html_e( ') is supported only via JE SQL_Query path with', 'jsf-query-builder-etch-bridge' ); ?> <code>post__in</code> <?php esc_html_e( 'feed. JSF cannot drive a non-WP-Query loop.', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'CMT redirect runs only for', 'jsf-query-builder-etch-bridge' ); ?> <code>object_type='post'</code> <?php esc_html_e( '(JE core does not support CMT for users/terms in core; that would need an add-on).', 'jsf-query-builder-etch-bridge' ); ?></li>
					<li><?php esc_html_e( 'JSF AJAX uses a self-loopback HTTP request to re-render the page (cached 60 s).', 'jsf-query-builder-etch-bridge' ); ?></li>
				</ul>
			</div>

			<div class="jqbeb-card">
				<h2><?php esc_html_e( 'Troubleshooting', 'jsf-query-builder-etch-bridge' ); ?></h2>

				<details>
					<summary><strong><?php esc_html_e( 'Filter changes URL but loop doesn\'t update', 'jsf-query-builder-etch-bridge' ); ?></strong></summary>
					<ul>
						<li><?php esc_html_e( 'Wrapper class missing — verify', 'jsf-query-builder-etch-bridge' ); ?> <code>jsf-etch-loop</code> <?php esc_html_e( 'is on the immediate parent of loop cards.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'Pagination / sort blocks are inside the wrapper. Move them outside.', 'jsf-query-builder-etch-bridge' ); ?></li>
					</ul>
				</details>

				<details>
					<summary><strong><?php esc_html_e( 'JE query is ignored — Etch shows the preset query instead', 'jsf-query-builder-etch-bridge' ); ?></strong></summary>
					<ul>
						<li><?php esc_html_e( 'Class misspelled — confirm both', 'jsf-query-builder-etch-bridge' ); ?> <code>je-etch-loop</code> <?php esc_html_e( 'AND', 'jsf-query-builder-etch-bridge' ); ?> <code>je-q-{id}</code>.</li>
						<li><?php esc_html_e( 'Query ID does not match. Numeric ID = the JE query post ID; slug = "Query ID" field in JE Query Builder settings.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'Query type / loop preset type mismatch.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'JE query type is Repeater / Comments — not supported.', 'jsf-query-builder-etch-bridge' ); ?></li>
					</ul>
				</details>

				<details>
					<summary><strong><?php esc_html_e( 'Loop renders 0 results but JE preview shows posts', 'jsf-query-builder-etch-bridge' ); ?></strong></summary>
					<ul>
						<li><?php esc_html_e( 'If post type uses Custom Storage and has', 'jsf-query-builder-etch-bridge' ); ?> <code>orderby = meta_value_num</code> <?php esc_html_e( 'on a CMT field, ensure the bridge is up-to-date (≥ 0.7.1) — earlier versions emitted unprefixed table names.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'Verify directly via SQL whether the WHERE clause matches any rows. JE\'s', 'jsf-query-builder-etch-bridge' ); ?> <code>get_items()</code> <?php esc_html_e( 'may return cached data from a previous state.', 'jsf-query-builder-etch-bridge' ); ?></li>
					</ul>
				</details>

				<details>
					<summary><strong><?php esc_html_e( 'SQL / Merged / Data Store renders empty / wrong items', 'jsf-query-builder-etch-bridge' ); ?></strong></summary>
					<ul>
						<li><?php esc_html_e( 'SQL: rows lack a recognised ID column. Add', 'jsf-query-builder-etch-bridge' ); ?> <code>SELECT ID</code> <?php esc_html_e( 'or alias an existing column.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'SQL: target type inference picked the wrong type — add', 'jsf-query-builder-etch-bridge' ); ?> <code>je-as-{type}</code> <?php esc_html_e( 'on the wrapper.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'Merged: base query type doesn\'t match Etch preset.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'Combined Merged/SQL/DS with JSF without', 'jsf-query-builder-etch-bridge' ); ?> <code>je-jsf-stack</code> — <?php esc_html_e( 'add the class or remove jsf-etch-loop.', 'jsf-query-builder-etch-bridge' ); ?></li>
					</ul>
				</details>

				<details>
					<summary><strong><?php esc_html_e( 'Users / Terms loop renders empty cards', 'jsf-query-builder-etch-bridge' ); ?></strong></summary>
					<ul>
						<li><?php esc_html_e( 'Etch needs full WP_User / WP_Term objects; the bridge forces fields="all". If you customised the preset to return IDs only, use a default preset.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'JE query may filter all results out — test in JE Query Builder preview first.', 'jsf-query-builder-etch-bridge' ); ?></li>
					</ul>
				</details>

				<details>
					<summary><strong><?php esc_html_e( '[jsf_etch_count] always shows 0', 'jsf-query-builder-etch-bridge' ); ?></strong></summary>
					<ul>
						<li><?php esc_html_e( 'Place the shortcode AFTER the loop in the DOM, or rely on JS to fill it on page load.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'Verify the loop wrapper has the matching', 'jsf-query-builder-etch-bridge' ); ?> <code>jsf-etch-loop</code> <?php esc_html_e( 'class — counts only populate for tagged loops.', 'jsf-query-builder-etch-bridge' ); ?></li>
					</ul>
				</details>

				<details>
					<summary><strong><?php esc_html_e( 'Indexer counts not appearing on filter options', 'jsf-query-builder-etch-bridge' ); ?></strong></summary>
					<ul>
						<li><?php esc_html_e( '"Show Items Count" toggle off in the JSF filter\'s admin settings.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'Filter is a range filter — those don\'t use per-option counts.', 'jsf-query-builder-etch-bridge' ); ?></li>
						<li><?php esc_html_e( 'Plugin too old (< 0.8.1) — earlier versions did not register the loop\'s default query with JSF, so the indexer skipped the provider entirely.', 'jsf-query-builder-etch-bridge' ); ?></li>
					</ul>
				</details>

				<details>
					<summary><strong><?php esc_html_e( 'Loopback fails on production with SSL errors', 'jsf-query-builder-etch-bridge' ); ?></strong></summary>
					<p><?php esc_html_e( 'Default loopback uses', 'jsf-query-builder-etch-bridge' ); ?> <code>sslverify => false</code> <?php esc_html_e( 'for local-dev compatibility. Enable verification on production via:', 'jsf-query-builder-etch-bridge' ); ?></p>
					<pre><code>add_filter( 'jqbeb_loopback_sslverify', '__return_true' );</code></pre>
				</details>
			</div>
		</div>
		<?php
	}

	/* -------------------- HELPERS -------------------- */

	private function status_pill( bool $ok ): string {
		if ( $ok ) {
			return '<span class="jqbeb-status ok">' . esc_html__( '✓ Active', 'jsf-query-builder-etch-bridge' ) . '</span>';
		}
		return '<span class="jqbeb-status bad">' . esc_html__( '✗ Inactive', 'jsf-query-builder-etch-bridge' ) . '</span>';
	}

	private function status_pill_inline( string $label, bool $ok ): string {
		$class = $ok ? 'ok' : 'bad';
		$mark  = $ok ? '✓' : '✗';
		return '<span class="jqbeb-status ' . $class . '" title="' . esc_attr( $label ) . '">' . $mark . ' ' . esc_html( $label ) . '</span>';
	}

	private function print_styles(): void {
		?>
		<style>
			.jqbeb-admin .jqbeb-meta { display:flex; align-items:center; gap:18px; flex-wrap:wrap; margin:6px 0 18px; color:#646970; font-size:13px; }
			.jqbeb-admin .jqbeb-meta code { background:#f0f0f1; padding:2px 6px; border-radius:3px; font-size:12px; }
			.jqbeb-admin .jqbeb-deps-inline { display:inline-flex; gap:6px; }
			.jqbeb-admin .jqbeb-status { display:inline-block; padding:2px 8px; border-radius:3px; font-weight:600; font-size:11px; line-height:1.4; }
			.jqbeb-admin .jqbeb-status.ok { background:#00a32a; color:#fff; }
			.jqbeb-admin .jqbeb-status.bad { background:#d63638; color:#fff; }
			.jqbeb-admin .jqbeb-status.muted { background:#dcdcde; color:#50575e; }

			.jqbeb-admin .jqbeb-tabs { margin-top:12px; }
			.jqbeb-admin .jqbeb-tab { display:none; }
			.jqbeb-admin .jqbeb-tab.active { display:block; }

			.jqbeb-admin .jqbeb-card { background:#fff; border:1px solid #c3c4c7; padding:16px 22px; margin:14px 0; border-radius:4px; }
			.jqbeb-admin details.jqbeb-card { padding:0; overflow:hidden; }
			.jqbeb-admin details.jqbeb-card > summary {
				display:flex; align-items:center; gap:10px;
				padding:14px 22px;
				cursor:pointer; list-style:none; user-select:none;
				transition:background-color 0.12s ease;
				border-radius:4px;
			}
			.jqbeb-admin details.jqbeb-card[open] > summary { border-radius:4px 4px 0 0; border-bottom:1px solid #f0f0f1; }
			.jqbeb-admin details.jqbeb-card > summary:hover { background-color:#f6f7f7; }
			.jqbeb-admin details.jqbeb-card > summary::-webkit-details-marker { display:none; }
			.jqbeb-admin details.jqbeb-card > summary::marker { content:''; }
			.jqbeb-admin details.jqbeb-card > summary::before {
				content:'';
				width:0; height:0;
				border-top:5px solid transparent;
				border-bottom:5px solid transparent;
				border-left:8px solid #2271b1;
				transition:transform 0.15s ease;
				flex-shrink:0;
			}
			.jqbeb-admin details.jqbeb-card[open] > summary::before { transform:rotate(90deg); }
			.jqbeb-admin details.jqbeb-card > summary > h2 {
				margin:0; flex:1;
				font-size:1.15em; font-weight:600; line-height:1.4;
			}
			.jqbeb-admin details.jqbeb-card > .jqbeb-card-body { padding:14px 22px 16px; }

			.jqbeb-admin .jqbeb-card h2 { margin-top:0; font-size:1.15em; }
			.jqbeb-admin .jqbeb-card h3 { margin:18px 0 8px; font-size:13px; text-transform:uppercase; letter-spacing:0.04em; color:#50575e; }
			.jqbeb-admin .jqbeb-card h4 { margin:14px 0 6px; }

			.jqbeb-admin code { background:#f0f0f1; padding:2px 6px; border-radius:3px; font-size:13px; }
			.jqbeb-admin pre { background:#1d2327; color:#e1e1e1; padding:12px 16px; border-radius:4px; overflow:auto; font-size:12px; line-height:1.5; }
			.jqbeb-admin pre code { background:transparent; color:inherit; padding:0; font-size:inherit; }

			.jqbeb-admin table { border-collapse:collapse; margin:8px 0; width:100%; }
			.jqbeb-admin table th, .jqbeb-admin table td { padding:6px 12px 6px 0; vertical-align:top; text-align:left; }
			.jqbeb-admin table th { font-weight:600; color:#50575e; border-bottom:1px solid #dcdcde; padding-bottom:8px; }
			.jqbeb-admin table.jqbeb-deps td { padding:8px 14px 8px 0; vertical-align:middle; }
			.jqbeb-admin table.jqbeb-classes td:first-child,
			.jqbeb-admin table.jqbeb-types td:first-child,
			.jqbeb-admin table.jqbeb-hooks td:first-child { white-space:nowrap; }
			.jqbeb-admin table.jqbeb-support td:last-child { width:1%; white-space:nowrap; text-align:right; }

			.jqbeb-admin .jqbeb-callout { border-left:4px solid #2271b1; background:#f6f7f7; padding:10px 14px; margin:14px 0; border-radius:0 4px 4px 0; }
			.jqbeb-admin .jqbeb-callout.warn { border-left-color:#dba617; background:#fcf9e8; }
			.jqbeb-admin .jqbeb-callout ul { margin:8px 0 0; }

			/* Nested / non-card details (sub-items in troubleshooting + Combined tab) */
			.jqbeb-admin details:not(.jqbeb-card) { margin:10px 0; }
			.jqbeb-admin details:not(.jqbeb-card) > summary {
				display:flex; align-items:center; gap:8px;
				padding:6px 0; cursor:pointer; list-style:none; user-select:none;
			}
			.jqbeb-admin details:not(.jqbeb-card) > summary::-webkit-details-marker { display:none; }
			.jqbeb-admin details:not(.jqbeb-card) > summary::marker { content:''; }
			.jqbeb-admin details:not(.jqbeb-card) > summary::before {
				content:'';
				width:0; height:0;
				border-top:4px solid transparent;
				border-bottom:4px solid transparent;
				border-left:6px solid #646970;
				transition:transform 0.15s ease;
				flex-shrink:0;
			}
			.jqbeb-admin details:not(.jqbeb-card)[open] > summary::before { transform:rotate(90deg); border-left-color:#2271b1; }
		</style>
		<?php
	}

	private function print_scripts(): void {
		?>
		<script>
		(function () {
			var tabs   = document.querySelectorAll('.jqbeb-admin .jqbeb-tabs .nav-tab');
			var panels = document.querySelectorAll('.jqbeb-admin .jqbeb-tab');

			function activate(slug) {
				if (!slug) return;
				var found = false;
				panels.forEach(function (p) {
					var match = p.id === 'tab-' + slug;
					p.classList.toggle('active', match);
					if (match) found = true;
				});
				tabs.forEach(function (t) {
					t.classList.toggle('nav-tab-active', t.dataset.tab === slug);
				});
				if (found && history.replaceState) {
					history.replaceState(null, '', '#tab-' + slug);
				}
				return found;
			}

			tabs.forEach(function (tab) {
				tab.addEventListener('click', function (e) {
					e.preventDefault();
					activate(tab.dataset.tab);
				});
			});

			// Initial: hash → first tab.
			var initial = (location.hash || '').replace(/^#tab-/, '');
			if (!initial || !activate(initial)) {
				activate('overview');
			}
		})();
		</script>
		<?php
	}
}
