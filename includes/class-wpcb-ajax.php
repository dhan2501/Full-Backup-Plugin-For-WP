<?php
/**
 * AJAX request handlers for backup actions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPCB_Ajax {

	public function __construct() {
		add_action( 'wp_ajax_wpcb_full_backup', array( $this, 'handle_full_backup' ) );
		add_action( 'wp_ajax_wpcb_database_backup', array( $this, 'handle_database_backup' ) );
		add_action( 'wp_ajax_wpcb_media_backup', array( $this, 'handle_media_backup' ) );
		add_action( 'wp_ajax_wpcb_export_single', array( $this, 'handle_export_single' ) );
		add_action( 'wp_ajax_wpcb_export_bulk', array( $this, 'handle_export_bulk' ) );
		add_action( 'wp_ajax_wpcb_delete_backup', array( $this, 'handle_delete_backup' ) );
		add_action( 'wp_ajax_wpcb_search_posts', array( $this, 'handle_search_posts' ) );

		// Restore / import
		add_action( 'wp_ajax_wpcb_upload_backup', array( $this, 'handle_upload_backup' ) );
		add_action( 'wp_ajax_wpcb_inspect_backup', array( $this, 'handle_inspect_backup' ) );
		add_action( 'wp_ajax_wpcb_restore_backup', array( $this, 'handle_restore_backup' ) );
		add_action( 'wp_ajax_wpcb_preview_search_replace', array( $this, 'handle_preview_search_replace' ) );
		add_action( 'wp_ajax_wpcb_run_search_replace', array( $this, 'handle_run_search_replace' ) );
	}

	private function verify_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
		check_ajax_referer( 'wpcb_nonce', 'nonce' );
	}

	public function handle_full_backup() {
		$this->verify_request();
		$files = new WPCB_Files();
		$result = $files->create_full_backup();
		$result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	public function handle_database_backup() {
		$this->verify_request();
		$files = new WPCB_Files();
		$result = $files->create_database_only_backup();
		$result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	public function handle_media_backup() {
		$this->verify_request();
		$files = new WPCB_Files();
		$result = $files->create_media_only_backup();
		$result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	public function handle_export_single() {
		$this->verify_request();

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Invalid post ID.' ) );
		}

		$exporter = new WPCB_Content_Export();
		$result = $exporter->export_single( $post_id );
		$result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	public function handle_export_bulk() {
		$this->verify_request();

		$ids_raw = isset( $_POST['post_ids'] ) ? (array) $_POST['post_ids'] : array();
		$post_ids = array_map( 'absint', $ids_raw );
		$post_ids = array_filter( $post_ids );

		if ( empty( $post_ids ) ) {
			wp_send_json_error( array( 'message' => 'No posts selected.' ) );
		}

		$exporter = new WPCB_Content_Export();
		$result = $exporter->export_bulk( $post_ids );
		$result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	public function handle_delete_backup() {
		$this->verify_request();

		$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
		if ( ! $filename ) {
			wp_send_json_error( array( 'message' => 'Invalid filename.' ) );
		}

		$files = new WPCB_Files();
		$deleted = $files->delete_backup( $filename );

		$deleted
			? wp_send_json_success( array( 'message' => 'Backup deleted.' ) )
			: wp_send_json_error( array( 'message' => 'Could not delete backup (file not found).' ) );
	}

	/**
	 * Search posts/pages for the export picker UI
	 */
	public function handle_search_posts() {
		$this->verify_request();

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$type   = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'any';

		$args = array(
			'post_type'      => $type === 'any' ? array( 'post', 'page' ) : $type,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => 50,
			's'              => $search,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		$query = new WP_Query( $args );
		$results = array();

		foreach ( $query->posts as $p ) {
			$results[] = array(
				'id'     => $p->ID,
				'title'  => $p->post_title ?: '(no title)',
				'type'   => $p->post_type,
				'status' => $p->post_status,
				'date'   => get_the_date( 'Y-m-d', $p ),
			);
		}

		wp_send_json_success( array( 'posts' => $results ) );
	}

	/**
	 * Handle the backup file upload (zip or sql) from the Restore tab.
	 * Moves it into the plugin's backup directory and returns its filename
	 * so subsequent AJAX calls (inspect/restore) can refer to it.
	 */
	public function handle_upload_backup() {
		$this->verify_request();

		if ( empty( $_FILES['backup_file'] ) || ! isset( $_FILES['backup_file']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => 'No file was uploaded.' ) );
		}

		$file = $_FILES['backup_file'];

		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			wp_send_json_error( array( 'message' => 'Upload failed (error code ' . $file['error'] . '). The file may exceed your server\'s upload_max_filesize/post_max_size.' ) );
		}

		$importer = new WPCB_Files_Import();
		$validation = $importer->validate_uploaded_file( $file['tmp_name'], $file['name'] );

		if ( ! $validation['success'] ) {
			wp_send_json_error( $validation );
		}

		if ( ! file_exists( WPCB_BACKUP_DIR ) ) {
			wp_mkdir_p( WPCB_BACKUP_DIR );
		}

		$safe_name = 'uploaded-' . gmdate( 'Y-m-d_H-i-s' ) . '-' . sanitize_file_name( $file['name'] );
		$dest_path = WPCB_BACKUP_DIR . '/' . $safe_name;

		if ( ! move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
			wp_send_json_error( array( 'message' => 'Could not move uploaded file into place. Check folder permissions on wp-content/wpcb-backups/.' ) );
		}

		wp_send_json_success( array(
			'message'  => 'File uploaded successfully.',
			'filename' => $safe_name,
			'extension' => $validation['extension'],
		) );
	}

	/**
	 * Inspect an already-uploaded (or existing) backup zip to report what's
	 * inside it before the user commits to restoring. Also compares the
	 * backup's original site URL (if detectable) against this site's
	 * current URL, so the UI can clearly flag a cross-domain restore.
	 */
	public function handle_inspect_backup() {
		$this->verify_request();

		$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
		if ( ! $filename ) {
			wp_send_json_error( array( 'message' => 'Invalid filename.' ) );
		}

		$path = WPCB_BACKUP_DIR . '/' . $filename;
		if ( ! file_exists( $path ) ) {
			wp_send_json_error( array( 'message' => 'File not found.' ) );
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$importer = new WPCB_Files_Import();

		if ( $ext === 'sql' ) {
			$result = $importer->inspect_sql_file( $path );
		} else {
			$result = $importer->inspect( $path );
		}

		if ( ! $result['success'] ) {
			wp_send_json_error( $result );
		}

		$result['type'] = $ext;
		$result = $this->add_domain_comparison( $result );

		wp_send_json_success( $result );
	}

	/**
	 * Compare a backup's detected origin site URL against this site's
	 * current URL and attach the comparison to the inspect result, so the
	 * Restore tab can show a clear "this backup is from a different domain"
	 * notice (or confirm it matches) before the user restores anything.
	 */
	private function add_domain_comparison( array $result ) {
		$current_url = get_site_url();
		$current_host = wp_parse_url( $current_url, PHP_URL_HOST );

		$backup_url = null;
		if ( ! empty( $result['manifest']['site_url'] ) ) {
			$backup_url = $result['manifest']['site_url'];
		}

		$result['current_site_url'] = $current_url;
		$result['backup_site_url']  = $backup_url;
		$result['domain_detected']  = (bool) $backup_url;
		$result['domain_mismatch']  = false;

		if ( $backup_url ) {
			$backup_host = wp_parse_url( $backup_url, PHP_URL_HOST );
			$result['domain_mismatch'] = $backup_host && $current_host && strcasecmp( $backup_host, $current_host ) !== 0;
		}

		return $result;
	}

	/**
	 * Perform the actual restore: optional pre-restore safety backup, then
	 * database import and/or file extraction depending on what the user
	 * chose and what the backup contains.
	 */
	public function handle_restore_backup() {
		$this->verify_request();
		wpcb_raise_limits();

		$filename        = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
		$restore_db      = isset( $_POST['restore_database'] ) && $_POST['restore_database'] === '1';
		$restore_files   = isset( $_POST['restore_files'] ) && $_POST['restore_files'] === '1';
		$files_mode      = isset( $_POST['files_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['files_mode'] ) ) : 'full';
		$safety_backup   = isset( $_POST['safety_backup'] ) && $_POST['safety_backup'] === '1';

		if ( ! $filename ) {
			wp_send_json_error( array( 'message' => 'Invalid filename.' ) );
		}

		$path = WPCB_BACKUP_DIR . '/' . $filename;
		if ( ! file_exists( $path ) ) {
			wp_send_json_error( array( 'message' => 'Backup file not found on server.' ) );
		}

		$log = array();

		// Safety net: back up current state before doing anything destructive
		if ( $safety_backup ) {
			$files = new WPCB_Files();
			$pre = $files->create_full_backup();
			$log[] = $pre['success']
				? 'Safety backup of current site created before restore: ' . $pre['file']
				: 'Warning: safety backup before restore failed (' . $pre['message'] . '). Continuing anyway.';
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$db_errors = array();
		$db_done = false;
		$files_done = false;

		// --- Database restore ---
		if ( $restore_db ) {
			$sql_path = $path;
			$cleanup_sql = false;

			if ( $ext === 'zip' ) {
				$importer = new WPCB_Files_Import();
				$extract_result = $importer->extract_database_dump( $path );

				if ( ! $extract_result['success'] ) {
					wp_send_json_error( array(
						'message' => $extract_result['message'],
						'log'     => $log,
					) );
				}

				$sql_path = $extract_result['path'];
				$cleanup_sql = true;
			}

			$db_importer = new WPCB_Database_Import();
			$db_result = $db_importer->import( $sql_path, true );

			if ( $cleanup_sql ) {
				@unlink( $sql_path );
			}

			$log[] = $db_result['message'];
			$db_errors = $db_result['errors'];
			$db_done = $db_result['success'];

			if ( ! $db_result['success'] ) {
				// Report partial failure with details rather than a generic error
				wp_send_json_error( array(
					'message' => 'Database restore finished with errors. See details below.',
					'log'     => $log,
					'db_errors' => array_slice( $db_errors, 0, 20 ),
					'db_errors_total' => count( $db_errors ),
				) );
			}
		}

		// --- Files restore ---
		if ( $restore_files && $ext === 'zip' ) {
			$importer = new WPCB_Files_Import();
			$file_result = $importer->restore_files( $path, $files_mode );

			$log[] = $file_result['message'];
			$files_done = $file_result['success'];

			if ( ! $file_result['success'] ) {
				wp_send_json_error( array(
					'message' => 'File restore failed: ' . $file_result['message'],
					'log'     => $log,
				) );
			}
		} elseif ( $restore_files && $ext === 'sql' ) {
			$log[] = 'Skipped file restore: uploaded file was a .sql dump (database only, no files included).';
		}

		// Flush rewrite rules and clear any object cache after a DB restore,
		// since permalinks/options just changed underneath WordPress
		if ( $db_done ) {
			flush_rewrite_rules( true );
			wp_cache_flush();
		}

		wp_send_json_success( array(
			'message'    => 'Restore completed.',
			'log'        => $log,
			'db_restored' => $db_done,
			'files_restored' => $files_done,
			'current_site_url' => get_site_url(),
			'current_home_url' => get_home_url(),
		) );
	}

	/**
	 * Dry-run the domain/URL search & replace and report how many
	 * occurrences would change, without writing anything.
	 */
	public function handle_preview_search_replace() {
		$this->verify_request();

		$search  = isset( $_POST['search'] ) ? trim( wp_unslash( $_POST['search'] ) ) : '';
		$replace = isset( $_POST['replace'] ) ? trim( wp_unslash( $_POST['replace'] ) ) : '';

		if ( $search === '' || $replace === '' ) {
			wp_send_json_error( array( 'message' => 'Both old and new URL fields are required.' ) );
		}

		$sr = new WPCB_Search_Replace();
		$result = $sr->run( $search, $replace, true );

		$result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	/**
	 * Execute the domain/URL search & replace for real.
	 */
	public function handle_run_search_replace() {
		$this->verify_request();

		$search  = isset( $_POST['search'] ) ? trim( wp_unslash( $_POST['search'] ) ) : '';
		$replace = isset( $_POST['replace'] ) ? trim( wp_unslash( $_POST['replace'] ) ) : '';

		if ( $search === '' || $replace === '' ) {
			wp_send_json_error( array( 'message' => 'Both old and new URL fields are required.' ) );
		}

		$sr = new WPCB_Search_Replace();
		$result = $sr->run( $search, $replace, false );

		if ( $result['success'] ) {
			// Also explicitly update siteurl/home in case they weren't
			// caught (e.g. if old URL had a trailing slash mismatch)
			$current_siteurl = get_option( 'siteurl' );
			$current_home    = get_option( 'home' );

			if ( strpos( $current_siteurl, $search ) !== false ) {
				update_option( 'siteurl', str_replace( $search, $replace, $current_siteurl ) );
			}
			if ( strpos( $current_home, $search ) !== false ) {
				update_option( 'home', str_replace( $search, $replace, $current_home ) );
			}

			flush_rewrite_rules( true );
			wp_cache_flush();

			$result['current_site_url'] = get_site_url();
			$result['current_home_url'] = get_home_url();
		}

		$result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}
}
