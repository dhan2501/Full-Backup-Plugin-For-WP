<?php
/**
 * Handles full database export to a .sql file
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPCB_Database {

	/** @var wpdb */
	private $db;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	/**
	 * Export entire database to a .sql file
	 *
	 * @param string $destination_path Full path to write the .sql file to
	 * @return array{success:bool, message:string, path?:string}
	 */
	public function export( $destination_path ) {
		wpcb_raise_limits();

		$tables = $this->db->get_col( 'SHOW TABLES' );

		if ( empty( $tables ) ) {
			return array( 'success' => false, 'message' => 'No tables found in database.' );
		}

		$handle = fopen( $destination_path, 'w' );
		if ( ! $handle ) {
			return array( 'success' => false, 'message' => 'Could not open file for writing: ' . $destination_path );
		}

		// Header
		fwrite( $handle, "-- WP Complete Backup SQL Export\n" );
		fwrite( $handle, '-- Site: ' . get_bloginfo( 'url' ) . "\n" );
		fwrite( $handle, '-- Generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n" );
		fwrite( $handle, "-- WordPress DB table prefix: " . $this->db->prefix . "\n\n" );
		fwrite( $handle, "SET NAMES utf8mb4;\n" );
		fwrite( $handle, "SET FOREIGN_KEY_CHECKS=0;\n\n" );

		foreach ( $tables as $table ) {
			$this->export_table( $handle, $table );
		}

		fwrite( $handle, "SET FOREIGN_KEY_CHECKS=1;\n" );
		fclose( $handle );

		return array(
			'success' => true,
			'message' => 'Database exported successfully.',
			'path'    => $destination_path,
		);
	}

	/**
	 * Export a single table: structure + data
	 */
	private function export_table( $handle, $table ) {
		// Table structure
		fwrite( $handle, "-- --------------------------------------------------------\n" );
		fwrite( $handle, "-- Table: {$table}\n" );
		fwrite( $handle, "-- --------------------------------------------------------\n\n" );

		fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );

		$create = $this->db->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
		if ( isset( $create[1] ) ) {
			fwrite( $handle, $create[1] . ";\n\n" );
		}

		// Table data, chunked to avoid memory blowups on large tables
		$row_count = (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		if ( $row_count === 0 ) {
			return;
		}

		$columns = $this->db->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
		$column_list = '`' . implode( '`, `', $columns ) . '`';

		$chunk_size = 500;
		$chunks = ceil( $row_count / $chunk_size );

		for ( $i = 0; $i < $chunks; $i++ ) {
			$offset = $i * $chunk_size;
			$rows = $this->db->get_results( "SELECT * FROM `{$table}` LIMIT {$chunk_size} OFFSET {$offset}", ARRAY_A );

			if ( empty( $rows ) ) {
				continue;
			}

			$value_groups = array();

			foreach ( $rows as $row ) {
				$values = array();
				foreach ( $row as $value ) {
					if ( $value === null ) {
						$values[] = 'NULL';
					} else {
						$values[] = "'" . $this->escape( $value ) . "'";
					}
				}
				$value_groups[] = '(' . implode( ', ', $values ) . ')';
			}

			fwrite(
				$handle,
				"INSERT INTO `{$table}` ({$column_list}) VALUES\n" . implode( ",\n", $value_groups ) . ";\n\n"
			);
		}

		fwrite( $handle, "\n" );
	}

	/**
	 * Escape a value for safe inclusion in SQL string literal
	 */
	private function escape( $value ) {
		$value = (string) $value;
		// addslashes handles backslash, single quote, double quote, NUL
		$value = addslashes( $value );
		// Replace actual newlines/carriage returns with literal SQL escapes
		$value = str_replace( array( "\n", "\r" ), array( '\\n', '\\r' ), $value );
		return $value;
	}
}
