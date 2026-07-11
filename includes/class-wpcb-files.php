<?php
/**
 * Handles file-system backups: wp-content (uploads/themes/plugins) zipping,
 * and orchestration of a complete site backup (DB + files in one zip).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPCB_Files {

	/**
	 * Recursively add a directory to a ZipArchive
	 *
	 * @param ZipArchive $zip
	 * @param string     $source_dir   Absolute path of directory to add
	 * @param string     $zip_path     Path prefix inside the zip
	 * @param array      $exclude_dirs Absolute paths to skip (e.g. the backup dir itself)
	 */
	public static function add_dir_to_zip( ZipArchive $zip, $source_dir, $zip_path = '', array $exclude_dirs = array() ) {
		$source_dir = rtrim( $source_dir, '/' );

		if ( ! is_dir( $source_dir ) ) {
			return;
		}

		foreach ( $exclude_dirs as $excl ) {
			if ( rtrim( $excl, '/' ) === $source_dir ) {
				return;
			}
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$item_path = $item->getPathname();

			// Skip excluded directories (e.g. our own backups folder)
			$skip = false;
			foreach ( $exclude_dirs as $excl ) {
				$excl = rtrim( $excl, '/' );
				if ( strpos( $item_path, $excl ) === 0 ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}

			$relative_path = ltrim( str_replace( $source_dir, '', $item_path ), '/' );
			$target_in_zip = $zip_path !== '' ? $zip_path . '/' . $relative_path : $relative_path;

			if ( $item->isDir() ) {
				$zip->addEmptyDir( $target_in_zip );
			} else {
				$zip->addFile( $item_path, $target_in_zip );
			}
		}
	}

	/**
	 * Build a complete site backup: database SQL + wp-content files, all in one ZIP
	 *
	 * @return array{success:bool, message:string, file?:string, url?:string, size?:int}
	 */
	public function create_full_backup() {
		wpcb_raise_limits();

		if ( ! class_exists( 'ZipArchive' ) ) {
			return array( 'success' => false, 'message' => 'PHP ZipArchive extension is not available on this server.' );
		}

		if ( ! file_exists( WPCB_BACKUP_DIR ) ) {
			wp_mkdir_p( WPCB_BACKUP_DIR );
		}

		$timestamp  = gmdate( 'Y-m-d_H-i-s' );
		$site_slug  = sanitize_title( get_bloginfo( 'name' ) ) ?: 'site';
		$zip_name   = "full-backup-{$site_slug}-{$timestamp}.zip";
		$zip_path   = WPCB_BACKUP_DIR . '/' . $zip_name;
		$sql_temp   = WPCB_BACKUP_DIR . "/db-{$timestamp}.sql";

		// 1. Export database to a temp SQL file
		$db = new WPCB_Database();
		$db_result = $db->export( $sql_temp );

		if ( ! $db_result['success'] ) {
			return array( 'success' => false, 'message' => 'Database export failed: ' . $db_result['message'] );
		}

		// 2. Create zip and add SQL + wp-content
		$zip = new ZipArchive();
		if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
			@unlink( $sql_temp );
			return array( 'success' => false, 'message' => 'Could not create zip archive.' );
		}

		// add database dump at root of zip
		$zip->addFile( $sql_temp, 'database.sql' );

		// add a manifest with site info
		$manifest = $this->build_manifest();
		$zip->addFromString( 'backup-info.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

		// add wp-content (uploads, themes, plugins) but exclude our own backups dir
		self::add_dir_to_zip( $zip, WP_CONTENT_DIR, 'wp-content', array( WPCB_BACKUP_DIR ) );

		$zip->close();

		// cleanup temp sql
		@unlink( $sql_temp );

		if ( ! file_exists( $zip_path ) ) {
			return array( 'success' => false, 'message' => 'Zip file was not created.' );
		}

		return array(
			'success' => true,
			'message' => 'Full backup created successfully.',
			'file'    => $zip_name,
			'url'     => WPCB_BACKUP_URL . '/' . $zip_name,
			'size'    => filesize( $zip_path ),
		);
	}

	/**
	 * Database-only backup, zipped
	 */
	public function create_database_only_backup() {
		wpcb_raise_limits();

		if ( ! file_exists( WPCB_BACKUP_DIR ) ) {
			wp_mkdir_p( WPCB_BACKUP_DIR );
		}

		$timestamp = gmdate( 'Y-m-d_H-i-s' );
		$sql_path  = WPCB_BACKUP_DIR . "/database-{$timestamp}.sql";

		$db = new WPCB_Database();
		$result = $db->export( $sql_path );

		if ( ! $result['success'] ) {
			return $result;
		}

		// zip it for smaller download
		if ( class_exists( 'ZipArchive' ) ) {
			$zip_name = "database-{$timestamp}.zip";
			$zip_path = WPCB_BACKUP_DIR . '/' . $zip_name;
			$zip = new ZipArchive();
			if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) === true ) {
				$zip->addFile( $sql_path, 'database.sql' );
				$zip->close();
				@unlink( $sql_path );

				return array(
					'success' => true,
					'message' => 'Database backup created.',
					'file'    => $zip_name,
					'url'     => WPCB_BACKUP_URL . '/' . $zip_name,
					'size'    => filesize( $zip_path ),
				);
			}
		}

		// fallback: raw sql, no zip
		return array(
			'success' => true,
			'message' => 'Database backup created.',
			'file'    => basename( $sql_path ),
			'url'     => WPCB_BACKUP_URL . '/' . basename( $sql_path ),
			'size'    => filesize( $sql_path ),
		);
	}

	/**
	 * Media/uploads-only backup, zipped
	 */
	public function create_media_only_backup() {
		wpcb_raise_limits();

		if ( ! class_exists( 'ZipArchive' ) ) {
			return array( 'success' => false, 'message' => 'PHP ZipArchive extension is not available on this server.' );
		}

		if ( ! file_exists( WPCB_BACKUP_DIR ) ) {
			wp_mkdir_p( WPCB_BACKUP_DIR );
		}

		$upload_dir = wp_upload_dir();
		$source     = $upload_dir['basedir'];

		$timestamp = gmdate( 'Y-m-d_H-i-s' );
		$zip_name  = "media-{$timestamp}.zip";
		$zip_path  = WPCB_BACKUP_DIR . '/' . $zip_name;

		$zip = new ZipArchive();
		if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
			return array( 'success' => false, 'message' => 'Could not create zip archive.' );
		}

		self::add_dir_to_zip( $zip, $source, 'uploads', array( WPCB_BACKUP_DIR ) );
		$zip->close();

		return array(
			'success' => true,
			'message' => 'Media backup created.',
			'file'    => $zip_name,
			'url'     => WPCB_BACKUP_URL . '/' . $zip_name,
			'size'    => filesize( $zip_path ),
		);
	}

	/**
	 * Basic site manifest included in full backups for reference
	 */
	private function build_manifest() {
		global $wp_version;

		return array(
			'site_url'     => get_bloginfo( 'url' ),
			'site_name'    => get_bloginfo( 'name' ),
			'wp_version'   => $wp_version,
			'php_version'  => phpversion(),
			'table_prefix' => $GLOBALS['wpdb']->prefix,
			'generated_at' => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			'plugin'       => 'WP Complete Backup',
			'plugin_version' => WPCB_VERSION,
		);
	}

	/**
	 * List existing backups in the backup directory
	 */
	public function list_backups() {
		if ( ! file_exists( WPCB_BACKUP_DIR ) ) {
			return array();
		}

		$files = glob( WPCB_BACKUP_DIR . '/*.{zip,sql}', GLOB_BRACE );
		if ( ! $files ) {
			return array();
		}

		$backups = array();
		foreach ( $files as $file ) {
			$backups[] = array(
				'name'     => basename( $file ),
				'url'      => WPCB_BACKUP_URL . '/' . basename( $file ),
				'size'     => filesize( $file ),
				'modified' => filemtime( $file ),
			);
		}

		// newest first
		usort( $backups, function( $a, $b ) {
			return $b['modified'] - $a['modified'];
		} );

		return $backups;
	}

	/**
	 * Delete a backup file by name (sanitized, restricted to backup dir)
	 */
	public function delete_backup( $filename ) {
		$filename = basename( $filename ); // prevent path traversal
		$path = WPCB_BACKUP_DIR . '/' . $filename;

		if ( file_exists( $path ) ) {
			unlink( $path );
			return true;
		}
		return false;
	}
}
