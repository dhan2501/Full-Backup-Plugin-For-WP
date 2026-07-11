<?php
/**
 * Imports/restores files from a backup ZIP: either a full backup
 * (database.sql + wp-content/...) or a media-only backup (uploads/...).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPCB_Files_Import {

	/**
	 * Inspect an already-uploaded (or existing) backup zip to report what's
	 * inside it before the user commits to restoring.
	 */
	public function inspect( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return array( 'success' => false, 'message' => 'PHP ZipArchive extension is not available on this server.' );
		}

		$zip = new ZipArchive();
		if ( $zip->open( $zip_path ) !== true ) {
			return array( 'success' => false, 'message' => 'Could not open the uploaded ZIP file. It may be corrupt.' );
		}

		$has_database   = $zip->locateName( 'database.sql' ) !== false;
		$has_wp_content = false;
		$has_uploads    = false;
		$manifest       = null;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( strpos( $name, 'wp-content/' ) === 0 ) {
				$has_wp_content = true;
			}
			if ( strpos( $name, 'uploads/' ) === 0 ) {
				$has_uploads = true;
			}
		}

		$manifest_raw = $zip->getFromName( 'backup-info.json' );
		if ( $manifest_raw !== false ) {
			$decoded = json_decode( $manifest_raw, true );
			if ( is_array( $decoded ) ) {
				$manifest = $decoded;
			}
		}

		// If there's no manifest (e.g. older backup, or a .sql-derived zip)
		// but there IS a database dump, try to sniff the siteurl directly
		// out of the SQL so we can still detect a domain mismatch.
		if ( ! $manifest && $has_database ) {
			$sql_contents = $zip->getFromName( 'database.sql' );
			if ( $sql_contents !== false ) {
				$sniffed_url = $this->sniff_siteurl_from_sql( $sql_contents );
				if ( $sniffed_url ) {
					$manifest = array( 'site_url' => $sniffed_url );
				}
			}
		}

		$zip->close();

		return array(
			'success'        => true,
			'message'        => 'Backup inspected.',
			'has_database'   => $has_database,
			'has_wp_content' => $has_wp_content,
			'has_uploads'    => $has_uploads,
			'manifest'       => $manifest,
		);
	}

	/**
	 * Inspect a raw .sql dump (not inside a zip) for the same information,
	 * used when the user uploads a bare .sql file directly.
	 */
	public function inspect_sql_file( $sql_path ) {
		$handle = fopen( $sql_path, 'r' );
		if ( ! $handle ) {
			return array( 'success' => false, 'message' => 'Could not open SQL file for reading.' );
		}

		$buffer = '';
		$lines_read = 0;
		$sniffed_url = null;

		// siteurl is one of the very first rows wp_options typically holds,
		// so scanning the first ~2000 lines is enough without loading huge
		// dumps fully into memory.
		while ( ! feof( $handle ) && $lines_read < 2000 ) {
			$line = fgets( $handle, 1000000 );
			if ( $line === false ) {
				break;
			}
			$buffer .= $line;
			$lines_read++;

			if ( strpos( $buffer, 'siteurl' ) !== false ) {
				$sniffed_url = $this->sniff_siteurl_from_sql( $buffer );
				if ( $sniffed_url ) {
					break;
				}
			}
		}

		fclose( $handle );

		return array(
			'success'        => true,
			'message'        => 'SQL file inspected.',
			'has_database'   => true,
			'has_wp_content' => false,
			'has_uploads'    => false,
			'manifest'       => $sniffed_url ? array( 'site_url' => $sniffed_url ) : null,
		);
	}

	/**
	 * Try to extract the original site's URL by finding the 'siteurl' row
	 * inside a wp_options INSERT statement in raw SQL text. Looks for the
	 * pattern: ..., 'siteurl', 'http://example.com', ...
	 * (works for both our own exporter's format and standard mysqldump
	 * output, since both quote string values with single quotes).
	 */
	private function sniff_siteurl_from_sql( $sql_text ) {
		if ( preg_match( "/'siteurl'\s*,\s*'(https?:\\\\?\/\\\\?\/[^']+)'/i", $sql_text, $m ) ) {
			// Undo the SQL-escaping our exporter (and mysqldump) apply to
			// forward slashes / backslashes inside the string literal.
			$url = stripslashes( $m[1] );
			return rtrim( $url, '/' );
		}
		return null;
	}

	/**
	 * Extract database.sql from a full backup ZIP to a temp location and
	 * return its path (caller is responsible for running the import then
	 * deleting the temp file).
	 */
	public function extract_database_dump( $zip_path ) {
		$zip = new ZipArchive();
		if ( $zip->open( $zip_path ) !== true ) {
			return array( 'success' => false, 'message' => 'Could not open ZIP file.' );
		}

		if ( $zip->locateName( 'database.sql' ) === false ) {
			$zip->close();
			return array( 'success' => false, 'message' => 'No database.sql found inside this backup.' );
		}

		$tmp_path = WPCB_BACKUP_DIR . '/restore-db-' . gmdate( 'Y-m-d_H-i-s' ) . '.sql';
		$contents = $zip->getFromName( 'database.sql' );
		$zip->close();

		if ( $contents === false ) {
			return array( 'success' => false, 'message' => 'Failed to read database.sql from the ZIP.' );
		}

		file_put_contents( $tmp_path, $contents );

		return array( 'success' => true, 'message' => 'Database dump extracted.', 'path' => $tmp_path );
	}

	/**
	 * Extract the wp-content/ portion of a full backup ZIP (or the uploads/
	 * portion of a media-only backup) into the live filesystem.
	 *
	 * @param string $zip_path
	 * @param string $mode 'full' (wp-content/ -> WP_CONTENT_DIR) or 'media' (uploads/ -> wp_upload_dir basedir)
	 */
	public function restore_files( $zip_path, $mode = 'full' ) {
		wpcb_raise_limits();

		if ( ! class_exists( 'ZipArchive' ) ) {
			return array( 'success' => false, 'message' => 'PHP ZipArchive extension is not available on this server.' );
		}

		$zip = new ZipArchive();
		if ( $zip->open( $zip_path ) !== true ) {
			return array( 'success' => false, 'message' => 'Could not open ZIP file.' );
		}

		if ( $mode === 'media' ) {
			$prefix_in_zip = 'uploads/';
			$upload_dir    = wp_upload_dir();
			$destination   = $upload_dir['basedir'];
		} else {
			$prefix_in_zip = 'wp-content/';
			$destination   = WP_CONTENT_DIR;
		}

		$extracted = 0;
		$skipped_backup_dir = 0;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );

			if ( strpos( $name, $prefix_in_zip ) !== 0 ) {
				continue;
			}

			$relative = substr( $name, strlen( $prefix_in_zip ) );
			if ( $relative === '' ) {
				continue; // the directory entry itself
			}

			// Never let a restore overwrite the plugin's own backup storage,
			// and never write outside the destination (defensive path check).
			if ( strpos( $relative, 'wpcb-backups/' ) === 0 ) {
				$skipped_backup_dir++;
				continue;
			}
			if ( strpos( $relative, '..' ) !== false ) {
				continue;
			}

			$target_path = $destination . '/' . $relative;

			// Directory entry
			if ( substr( $name, -1 ) === '/' ) {
				if ( ! file_exists( $target_path ) ) {
					wp_mkdir_p( $target_path );
				}
				continue;
			}

			$target_dir = dirname( $target_path );
			if ( ! file_exists( $target_dir ) ) {
				wp_mkdir_p( $target_dir );
			}

			$contents = $zip->getFromIndex( $i );
			if ( $contents !== false ) {
				file_put_contents( $target_path, $contents );
				$extracted++;
			}
		}

		$zip->close();

		if ( $extracted === 0 ) {
			return array(
				'success' => false,
				'message' => "No matching files found inside the ZIP under '{$prefix_in_zip}'. Is this the right backup file?",
			);
		}

		return array(
			'success' => true,
			'message' => "Restored {$extracted} file(s) successfully." . ( $skipped_backup_dir ? " ({$skipped_backup_dir} backup-storage file(s) skipped for safety.)" : '' ),
			'extracted' => $extracted,
		);
	}

	/**
	 * Validate an uploaded file is actually a zip/sql before we touch it
	 */
	public function validate_uploaded_file( $tmp_path, $original_name ) {
		$ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'zip', 'sql' ), true ) ) {
			return array( 'success' => false, 'message' => 'Only .zip or .sql files are accepted.' );
		}

		if ( $ext === 'zip' && class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			$check = $zip->open( $tmp_path, ZipArchive::CHECKCONS );
			if ( $check !== true ) {
				return array( 'success' => false, 'message' => 'The uploaded file is not a valid ZIP archive.' );
			}
			$zip->close();
		}

		return array( 'success' => true, 'message' => 'File looks valid.', 'extension' => $ext );
	}
}
