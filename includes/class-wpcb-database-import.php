<?php
/**
 * Imports a .sql dump (as produced by WPCB_Database::export, or any
 * standard mysqldump-style export) back into the WordPress database.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPCB_Database_Import {

	/** @var wpdb */
	private $db;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	/**
	 * Import a SQL file.
	 *
	 * @param string $sql_path           Absolute path to the .sql file
	 * @param bool   $remap_table_prefix If true, rewrites table names in the
	 *                                   dump from whatever prefix they used
	 *                                   to this site's current $wpdb->prefix
	 * @return array{success:bool, message:string, statements_run:int, errors:array}
	 */
	public function import( $sql_path, $remap_table_prefix = true ) {
		wpcb_raise_limits();

		if ( ! file_exists( $sql_path ) ) {
			return array( 'success' => false, 'message' => 'SQL file not found.', 'statements_run' => 0, 'errors' => array() );
		}

		$source_prefix = $remap_table_prefix ? $this->detect_source_prefix( $sql_path ) : null;
		$target_prefix = $this->db->prefix;

		$handle = fopen( $sql_path, 'r' );
		if ( ! $handle ) {
			return array( 'success' => false, 'message' => 'Could not open SQL file for reading.', 'statements_run' => 0, 'errors' => array() );
		}

		// Disable FK checks for the duration of import; some dumps assume this
		$this->db->query( 'SET FOREIGN_KEY_CHECKS=0' );
		$this->db->query( "SET NAMES 'utf8mb4'" );

		$buffer          = '';
		$statements_run  = 0;
		$errors          = array();
		$in_string       = false;
		$string_char     = '';

		while ( ! feof( $handle ) ) {
			$line = fgets( $handle, 1000000 ); // up to ~1MB per line, generous for wide INSERT rows

			if ( $line === false ) {
				break;
			}

			$trimmed = ltrim( $line );

			// Skip pure comment lines and blank lines when not mid-statement
			if ( $buffer === '' && ( $trimmed === '' || strpos( $trimmed, '--' ) === 0 || strpos( $trimmed, '#' ) === 0 ) ) {
				continue;
			}

			$buffer .= $line;

			// Determine if this line ends a statement: a trailing ';' that
			// is not inside an open quoted string. We track quote state
			// across the whole buffer for correctness with multi-line INSERTs.
			if ( $this->statement_is_complete( $buffer ) ) {
				$statement = trim( $buffer );
				$buffer = '';

				if ( $statement === '' || $statement === ';' ) {
					continue;
				}

				if ( $remap_table_prefix && $source_prefix && $source_prefix !== $target_prefix ) {
					$statement = $this->remap_prefix( $statement, $source_prefix, $target_prefix );
				}

				$result = $this->db->query( $statement );

				if ( $result === false && $this->db->last_error ) {
					$errors[] = array(
						'error'     => $this->db->last_error,
						'statement' => mb_substr( $statement, 0, 200 ),
					);
				} else {
					$statements_run++;
				}
			}
		}

		fclose( $handle );

		$this->db->query( 'SET FOREIGN_KEY_CHECKS=1' );

		$success = empty( $errors );

		return array(
			'success'         => $success,
			'message'         => $success
				? "Database imported successfully. {$statements_run} statement(s) executed."
				: "Import completed with " . count( $errors ) . " error(s) out of {$statements_run} successful statement(s). See details below.",
			'statements_run'  => $statements_run,
			'errors'          => $errors,
		);
	}

	/**
	 * Decide whether the buffered SQL so far forms one complete statement
	 * (ends in a semicolon that is not inside a quoted string or comment).
	 */
	private function statement_is_complete( $buffer ) {
		$len = strlen( $buffer );
		if ( $len === 0 ) {
			return false;
		}

		// Quick exit: must end with ; (possibly followed by whitespace/newline)
		$trimmed_end = rtrim( $buffer );
		if ( substr( $trimmed_end, -1 ) !== ';' ) {
			return false;
		}

		// Walk the buffer tracking quote state to make sure the final ';'
		// isn't inside a string literal (e.g. a value containing ");\n").
		$in_string   = false;
		$quote_char  = '';
		$escaped     = false;

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $buffer[ $i ];

			if ( $in_string ) {
				if ( $escaped ) {
					$escaped = false;
					continue;
				}
				if ( $char === '\\' ) {
					$escaped = true;
					continue;
				}
				if ( $char === $quote_char ) {
					$in_string = false;
				}
				continue;
			}

			if ( $char === "'" || $char === '"' ) {
				$in_string  = true;
				$quote_char = $char;
				continue;
			}
		}

		// If we're still "inside a string" at the end of the buffer, the
		// statement isn't actually complete yet (a literal newline inside
		// a value) — keep buffering.
		return ! $in_string;
	}

	/**
	 * Try to detect the table prefix used inside the dump file by reading
	 * the first chunk and matching CREATE TABLE / INSERT INTO statements,
	 * specifically looking for the wp_options or wp_posts style table.
	 */
	private function detect_source_prefix( $sql_path ) {
		$handle = fopen( $sql_path, 'r' );
		if ( ! $handle ) {
			return null;
		}

		$lines_checked = 0;
		$prefix = null;

		while ( ! feof( $handle ) && $lines_checked < 5000 ) {
			$line = fgets( $handle, 100000 );
			$lines_checked++;

			if ( $line === false ) {
				break;
			}

			if ( preg_match( '/(?:CREATE TABLE|INSERT INTO)\s+`?([a-zA-Z0-9_]*)(options|posts|users)`?/i', $line, $m ) ) {
				$prefix = $m[1];
				break;
			}
		}

		fclose( $handle );
		return $prefix;
	}

	/**
	 * Rewrite occurrences of the source table prefix with the target prefix
	 * in a single SQL statement (CREATE TABLE / DROP TABLE / INSERT INTO).
	 * Restricted to identifier positions (after backtick or whitespace before
	 * a table-name keyword) to avoid touching string literal data.
	 */
	private function remap_prefix( $statement, $source_prefix, $target_prefix ) {
		if ( $source_prefix === '' ) {
			return $statement;
		}

		// Match `prefix_tablename` (back-tick quoted identifiers) — this is
		// how our own exporter and standard mysqldump both quote identifiers,
		// so this is safe and won't touch string literal values.
		$pattern = '/`' . preg_quote( $source_prefix, '/' ) . '([a-zA-Z0-9_]+)`/';
		return preg_replace( $pattern, '`' . $target_prefix . '$1`', $statement );
	}
}
