<?php
/**
 * Admin menu, dashboard page, and asset enqueueing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPCB_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu() {
		add_menu_page(
			'WP Complete Backup',
			'Complete Backup',
			'manage_options',
			'wpcb-backup',
			array( $this, 'render_page' ),
			'dashicons-database-export',
			80
		);
	}

	public function enqueue_assets( $hook ) {
		if ( $hook !== 'toplevel_page_wpcb-backup' ) {
			return;
		}

		wp_enqueue_style( 'wpcb-admin', WPCB_PLUGIN_URL . 'assets/admin.css', array(), WPCB_VERSION );
		wp_enqueue_script( 'wpcb-admin', WPCB_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), WPCB_VERSION, true );

		wp_localize_script( 'wpcb-admin', 'wpcbData', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'wpcb_nonce' ),
		) );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$files   = new WPCB_Files();
		$backups = $files->list_backups();
		$current_url = get_site_url();
		?>
		<div class="wrap wpcb-wrap">
			<h1>WP Complete Backup</h1>
			<p class="wpcb-sub">Backup your full site, restore a backup (yours or one from another site), and update the domain after moving sites.</p>

			<div id="wpcb-notice" class="wpcb-notice" style="display:none;"></div>

			<h2 class="nav-tab-wrapper wpcb-tabs">
				<a href="#wpcb-tab-backup" class="nav-tab nav-tab-active" data-tab="wpcb-tab-backup">Backup</a>
				<a href="#wpcb-tab-restore" class="nav-tab" data-tab="wpcb-tab-restore">Restore / Import</a>
				<a href="#wpcb-tab-migrate" class="nav-tab" data-tab="wpcb-tab-migrate">Migrate Domain</a>
			</h2>

			<!-- ========================= BACKUP TAB ========================= -->
			<div class="wpcb-tab-panel" id="wpcb-tab-backup">

				<div class="wpcb-grid">

					<div class="wpcb-card">
						<h2><span class="dashicons dashicons-database"></span> Full Site Backup</h2>
						<p>Database + all files in <code>wp-content</code> (themes, plugins, uploads) in a single ZIP.</p>
						<button class="button button-primary wpcb-btn" data-action="wpcb_full_backup">Create Full Backup</button>
					</div>

					<div class="wpcb-card">
						<h2><span class="dashicons dashicons-media-spreadsheet"></span> Database Only</h2>
						<p>Exports all database tables (posts, pages, settings, users, comments, etc.) to SQL.</p>
						<button class="button button-secondary wpcb-btn" data-action="wpcb_database_backup">Backup Database</button>
					</div>

					<div class="wpcb-card">
						<h2><span class="dashicons dashicons-format-image"></span> Media Library Only</h2>
						<p>Exports the entire <code>uploads</code> folder (all images and media files).</p>
						<button class="button button-secondary wpcb-btn" data-action="wpcb_media_backup">Backup Media</button>
					</div>

				</div>

				<hr class="wpcb-divider">

				<h2>Export Individual Pages / Posts</h2>
				<p>Pick one or more pages/posts to export. Each export includes the content, all metadata (SEO fields, custom fields, taxonomies), and any images used (featured + inline + attached).</p>

				<div class="wpcb-card wpcb-export-card">
					<div class="wpcb-export-controls">
						<input type="text" id="wpcb-post-search" placeholder="Search pages/posts by title…">
						<select id="wpcb-post-type-filter">
							<option value="any">All (Posts &amp; Pages)</option>
							<option value="post">Posts only</option>
							<option value="page">Pages only</option>
						</select>
						<button class="button" id="wpcb-search-btn">Search</button>
					</div>

					<div id="wpcb-post-results" class="wpcb-post-results">
						<p class="wpcb-hint">Search above to list your pages/posts.</p>
					</div>

					<div class="wpcb-export-actions">
						<button class="button button-primary" id="wpcb-export-selected" disabled>Export Selected (<span id="wpcb-selected-count">0</span>)</button>
					</div>
				</div>

				<hr class="wpcb-divider">

				<h2>Existing Backups</h2>
				<div id="wpcb-backups-list">
					<?php if ( empty( $backups ) ) : ?>
						<p>No backups created yet.</p>
					<?php else : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th>File</th>
									<th>Size</th>
									<th>Created</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $backups as $backup ) : ?>
									<tr data-filename="<?php echo esc_attr( $backup['name'] ); ?>">
										<td><?php echo esc_html( $backup['name'] ); ?></td>
										<td><?php echo esc_html( size_format( $backup['size'] ) ); ?></td>
										<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', $backup['modified'] ) ); ?> UTC</td>
										<td>
											<a href="<?php echo esc_url( $backup['url'] ); ?>" class="button button-small" download>Download</a>
											<button class="button button-small wpcb-use-for-restore" data-filename="<?php echo esc_attr( $backup['name'] ); ?>">Restore this</button>
											<button class="button button-small wpcb-delete-backup" data-filename="<?php echo esc_attr( $backup['name'] ); ?>">Delete</button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<p class="wpcb-note"><strong>Note:</strong> Backup files are stored in <code>wp-content/wpcb-backups/</code> and protected from direct web access. For best results on large sites, also keep an off-site copy (download the ZIP and store it elsewhere).</p>
			</div>

			<!-- ========================= RESTORE TAB ========================= -->
			<div class="wpcb-tab-panel" id="wpcb-tab-restore" style="display:none;">

				<div class="wpcb-card">
					<h2><span class="dashicons dashicons-upload"></span> Step 1 — Choose a backup</h2>
					<p>Upload a backup ZIP/SQL (from this site or another WordPress site), or pick one already listed above.</p>

					<div class="wpcb-upload-row">
						<input type="file" id="wpcb-restore-file-input" accept=".zip,.sql">
						<button class="button button-primary" id="wpcb-upload-restore-btn">Upload</button>
					</div>

					<p class="wpcb-hint" id="wpcb-restore-selected-file">No file selected yet.</p>
				</div>

				<div class="wpcb-card" id="wpcb-restore-step2" style="display:none;">
					<h2><span class="dashicons dashicons-search"></span> Step 2 — What's inside</h2>
					<div id="wpcb-restore-inspect-results"><p class="wpcb-hint">Inspecting…</p></div>
				</div>

				<div class="wpcb-card" id="wpcb-restore-step3" style="display:none;">
					<h2><span class="dashicons dashicons-controls-repeat"></span> Step 3 — Choose what to restore</h2>

					<label class="wpcb-checkbox-row">
						<input type="checkbox" id="wpcb-restore-db-checkbox">
						Restore database (overwrites current posts, pages, settings, users, comments)
					</label>

					<label class="wpcb-checkbox-row">
						<input type="checkbox" id="wpcb-restore-files-checkbox">
						Restore files
						<select id="wpcb-restore-files-mode">
							<option value="full">Full wp-content (themes, plugins, uploads)</option>
							<option value="media">Media/uploads only</option>
						</select>
					</label>

					<label class="wpcb-checkbox-row">
						<input type="checkbox" id="wpcb-restore-safety-checkbox" checked>
						Create a safety backup of the CURRENT site before restoring <span class="wpcb-recommended">(recommended)</span>
					</label>

					<div class="wpcb-warning-box">
						⚠️ Restoring the database will overwrite your current content with what's in the backup. This cannot be undone unless you made a safety backup. If this backup came from a different domain, go to the <strong>Migrate Domain</strong> tab afterward to fix URLs.
					</div>

					<button class="button button-primary button-hero" id="wpcb-run-restore-btn">Restore Now</button>
				</div>

				<div class="wpcb-card" id="wpcb-restore-results" style="display:none;">
					<h2><span class="dashicons dashicons-yes-alt"></span> Restore Log</h2>
					<div id="wpcb-restore-log"></div>
				</div>

			</div>

			<!-- ========================= MIGRATE DOMAIN TAB ========================= -->
			<div class="wpcb-tab-panel" id="wpcb-tab-migrate" style="display:none;">

				<div class="wpcb-card">
					<h2><span class="dashicons dashicons-admin-site-alt3"></span> Update Domain / URL After Restoring</h2>
					<p>
						If you restored a backup that was made on a different domain (e.g. moving from
						<code>oldsite.com</code> to <code>newsite.com</code>, or from a staging URL to your
						live domain), use this to safely update every database reference — including
						inside serialized data (widgets, theme settings, etc.) without corrupting it.
					</p>

					<p>Current site URL: <code><?php echo esc_html( $current_url ); ?></code></p>

					<div class="wpcb-migrate-row">
						<label>Old domain / URL (as it appears in the backup)</label>
						<input type="text" id="wpcb-old-url" placeholder="https://old-domain.com">
					</div>

					<div class="wpcb-migrate-row">
						<label>New domain / URL (this site's current address)</label>
						<input type="text" id="wpcb-new-url" placeholder="<?php echo esc_attr( $current_url ); ?>" value="<?php echo esc_attr( $current_url ); ?>">
					</div>

					<div class="wpcb-migrate-actions">
						<button class="button" id="wpcb-preview-replace-btn">Preview Changes (Dry Run)</button>
						<button class="button button-primary" id="wpcb-run-replace-btn" disabled>Run Replacement</button>
					</div>

					<div id="wpcb-replace-results"></div>

					<p class="wpcb-note">Tip: run the Preview first. It shows exactly how many occurrences would change in each table without modifying anything, so you can confirm the old URL is correct before committing.</p>
				</div>

			</div>

		</div>
		<?php
	}
}
