<?php
/**
 * Serialization-safe search & replace, used to update the site URL/domain
 * after importing a backup taken from a different site.
 *
 * WordPress stores a lot of data as PHP serialized strings inside the
 * database (widget settings, theme options, some plugin settings, etc).
 * A naive SQL "REPLACE INTO ... REPLACE(field, 'old', 'new')" corrupts any
 * serialized value where the replacement changes the byte-length of a
 * string, because serialized strings are length-prefixed
 * (e.g. s:18:"http://old.com/wp";). If the length prefix no longer matches
 * the actual string length, PHP's unserialize() fails and that row's data
 * is silently lost/broken.
 *
 * This class walks every text/blob column of every table, and for any value
 * that *is* a serialized PHP structure, recursively replaces inside it and
 * re-serializes with correct lengths. Plain strings get a straight replace.
 *
 * This also covers internal links inside page/post content: wp_posts.
 * post_content (and post_excerpt) are ordinary text columns, so <a href="">,
 * <img src="">, and block-editor (Gutenberg) HTML comments referencing the
 * old domain are all rewritten by the same pass — no special-casing needed,
 * since they're stored as plain HTML text, not serialized data.
 *
 * One column is deliberately EXCLUDED: wp_posts.guid. WordPress treats the
 * GUID as a permanent identifier that should never change after a post is
 * published — rewriting it can break RSS feed readers and caching/CDN
 * layers that key off the original GUID. This matches the convention used
 * by WP-CLI's own `wp search-replace` command, which skips guid by default.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPCB_Search_Replace {

	/** @var wpdb */
	private $db;

	/** @var array Tables to always skip (e.g. nothing by default, but reserved for future use) */
	private $skip_tables = array();

	/**
	 * Columns to always skip, keyed by table name WITHOUT the prefix
	 * (matched against the table name after stripping $wpdb->prefix).
	 * guid must never be rewritten — see class docblock.
	 */
	private $skip_columns = array(
		'posts' => array( 'guid' ),
	);

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	/**
	 * Run a site-wide search & replace across all tables with the current
	 * DB prefix.
	 *
	 * Automatically also checks the opposite-protocol variant of the search
	 * URL (http <-> https) and a protocol-relative "//domain.com" variant,
	 * since this is the single most common reason internal links don't get
	 * updated: the old backup has "http://old.com" links scattered through
	 * post_content, but the user only typed "https://old.com" (or vice
	 * versa) into the Old URL field. Each variant found is replaced with the
	 * matching protocol on the new URL.
	 *
	 * @param string $search    The old URL/domain to find
	 * @param string $replace   The new URL/domain to substitute
	 * @param bool   $dry_run   If true, counts matches without writing changes
	 * @return array{success:bool, message:string, tables:array, total_changes:int, variants_checked:array}
	 */
	public function run( $search, $replace, $dry_run = false ) {
		wpcb_raise_limits();

		if ( $search === '' ) {
			return array( 'success' => false, 'message' => 'Search value cannot be empty.', 'tables' => array(), 'total_changes' => 0, 'variants_checked' => array() );
		}

		$pairs = $this->build_search_replace_pairs( $search, $replace );

		$tables = $this->db->get_col( 'SHOW TABLES' );
		$report = array();
		$total_changes = 0;
		$variants_with_matches = array();

		foreach ( $tables as $table ) {
			// Only touch tables belonging to this WP installation's prefix
			if ( strpos( $table, $this->db->prefix ) !== 0 ) {
				continue;
			}
			if ( in_array( $table, $this->skip_tables, true ) ) {
				continue;
			}

			$result = $this->process_table( $table, $pairs, $dry_run );
			if ( $result['changes'] > 0 ) {
				$report[] = array(
					'table'   => $table,
					'rows'    => $result['rows'],
					'changes' => $result['changes'],
				);
				$total_changes += $result['changes'];
			}
			foreach ( $result['matched_variants'] as $v ) {
				$variants_with_matches[ $v ] = true;
			}
		}

		return array(
			'success'          => true,
			'message'          => $dry_run
				? "Dry run complete: {$total_changes} occurrence(s) found across " . count( $report ) . ' table(s).'
				: "Replacement complete: {$total_changes} occurrence(s) updated across " . count( $report ) . ' table(s).',
			'tables'           => $report,
			'total_changes'    => $total_changes,
			'variants_checked' => array_map( function ( $p ) { return $p['search']; }, $pairs ),
			'variants_matched' => array_keys( $variants_with_matches ),
		);
	}

	/**
	 * Build the list of search/replace pairs to check in a single pass,
	 * covering the most common protocol mismatches automatically.
	 *
	 * Examples, given search="https://old.com" replace="https://new.com":
	 *   - https://old.com  -> https://new.com   (exact, as typed)
	 *   - http://old.com   -> http://new.com    (http variant)
	 *   - //old.com        -> //new.com         (protocol-relative variant)
	 * If the user typed a bare domain with no protocol, only that exact
	 * string is used (no protocol variants are guessed).
	 */
	private function build_search_replace_pairs( $search, $replace ) {
		$pairs = array( array( 'search' => $search, 'replace' => $replace ) );

		$bare_search  = preg_replace( '#^https?://#i', '', $search );
		$bare_replace = preg_replace( '#^https?://#i', '', $replace );

		if ( preg_match( '#^https://#i', $search ) ) {
			$pairs[] = array(
				'search'  => 'http://' . $bare_search,
				'replace' => ( preg_match( '#^https://#i', $replace ) ? 'http://' . $bare_replace : $replace ),
			);
		} elseif ( preg_match( '#^http://#i', $search ) ) {
			$pairs[] = array(
				'search'  => 'https://' . $bare_search,
				'replace' => ( preg_match( '#^http://#i', $replace ) ? 'https://' . $bare_replace : $replace ),
			);
		}

		if ( preg_match( '#^https?://#i', $search ) ) {
			$pairs[] = array(
				'search'  => '//' . $bare_search,
				'replace' => '//' . $bare_replace,
			);
		}

		// De-duplicate (search+replace identical pairs, or a pair that's a
		// no-op because search === replace for that variant)
		$seen = array();
		$unique = array();
		foreach ( $pairs as $pair ) {
			if ( $pair['search'] === $pair['replace'] ) {
				continue;
			}
			$key = $pair['search'] . '|' . $pair['replace'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unique[] = $pair;
		}

		return $unique;
	}

	/**
	 * Process a single table: find its primary key, scan text columns,
	 * replace as needed. Tries every search/replace pair against each
	 * column value (so http/https/protocol-relative variants are all
	 * covered in one DB pass per row).
	 */
	private function process_table( $table, array $pairs, $dry_run ) {
		$primary_key = $this->get_primary_key( $table );
		$columns     = $this->get_text_columns( $table );

		$rows_affected    = 0;
		$changes_count    = 0;
		$matched_variants = array();

		if ( empty( $columns ) ) {
			return array( 'rows' => 0, 'changes' => 0, 'matched_variants' => array() );
		}

		// Page through the table to avoid loading huge tables into memory at once
		$chunk_size = 500;
		$offset     = 0;

		while ( true ) {
			$col_list = $primary_key ? "`{$primary_key}`, " : '';
			$col_list .= '`' . implode( '`, `', $columns ) . '`';

			$rows = $this->db->get_results(
				"SELECT {$col_list} FROM `{$table}` LIMIT {$chunk_size} OFFSET {$offset}",
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$updates = array();

				foreach ( $columns as $col ) {
					$value = $row[ $col ];

					if ( $value === null || $value === '' ) {
						continue;
					}

					$original = $value;
					$changed_this_column = false;

					foreach ( $pairs as $pair ) {
						if ( strpos( $value, $pair['search'] ) === false ) {
							continue;
						}

						$changes_count += $this->count_occurrences( $value, $pair['search'] );
						$matched_variants[ $pair['search'] ] = true;
						$value = $this->replace_value( $value, $pair['search'], $pair['replace'] );
						$changed_this_column = true;
					}

					if ( $changed_this_column && $value !== $original ) {
						$updates[ $col ] = $value;
					}
				}

				if ( ! empty( $updates ) ) {
					$rows_affected++;

					if ( ! $dry_run && $primary_key && isset( $row[ $primary_key ] ) ) {
						$this->db->update(
							$table,
							$updates,
							array( $primary_key => $row[ $primary_key ] )
						);
					}
				}
			}

			$offset += $chunk_size;

			if ( count( $rows ) < $chunk_size ) {
				break;
			}
		}

		return array(
			'rows'             => $rows_affected,
			'changes'          => $changes_count,
			'matched_variants' => array_keys( $matched_variants ),
		);
	}

	/**
	 * Replace inside a value, handling serialized PHP data correctly.
	 */
	private function replace_value( $value, $search, $replace ) {
		if ( $this->is_serialized( $value ) ) {
			$unserialized = @unserialize( $value );

			// unserialize() can return false both for actual false and for
			// failure; guard by checking the original string too.
			if ( $unserialized !== false || $value === 'b:0;' ) {
				$replaced = $this->recursive_replace( $unserialized, $search, $replace );
				return serialize( $replaced );
			}
		}

		// Not serialized (or failed to unserialize) — straight string replace
		return str_replace( $search, $replace, $value );
	}

	/**
	 * Recursively walk arrays/objects, replacing string values, then
	 * the caller re-serializes the whole structure (giving correct lengths).
	 */
	private function recursive_replace( $data, $search, $replace ) {
		if ( is_string( $data ) ) {
			return str_replace( $search, $replace, $data );
		}

		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $key => $value ) {
				$new_key = is_string( $key ) ? str_replace( $search, $replace, $key ) : $key;
				$out[ $new_key ] = $this->recursive_replace( $value, $search, $replace );
			}
			return $out;
		}

		if ( is_object( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data->$key = $this->recursive_replace( $value, $search, $replace );
			}
			return $data;
		}

		// int, float, bool, null — nothing to do
		return $data;
	}

	/**
	 * Check whether a string is PHP-serialized data
	 */
	private function is_serialized( $value ) {
		if ( ! is_string( $value ) || $value === '' ) {
			return false;
		}
		$value = trim( $value );

		if ( $value === 'N;' ) {
			return true;
		}
		if ( strlen( $value ) < 4 ) {
			return false;
		}
		if ( $value[1] !== ':' ) {
			return false;
		}

		// quick check against the common serialized prefixes: s, a, O, i, d, b
		if ( ! in_array( $value[0], array( 's', 'a', 'O', 'i', 'd', 'b' ), true ) ) {
			return false;
		}

		// Validate fully to avoid false positives corrupting plain strings
		// that happen to start with e.g. "s:" — actually run unserialize
		// with error suppression and confirm round-trip succeeds.
		set_error_handler( function () { /* silence notices during probe */ }, E_ALL );
		$result = @unserialize( $value );
		restore_error_handler();

		return $result !== false || $value === 'b:0;';
	}

	private function count_occurrences( $haystack, $needle ) {
		if ( $needle === '' ) {
			return 0;
		}
		return substr_count( $haystack, $needle );
	}

	/**
	 * Get the primary key column name for a table, if any
	 */
	private function get_primary_key( $table ) {
		$row = $this->db->get_row( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A );
		return $row ? $row['Column_name'] : null;
	}

	/**
	 * Get all text/varchar/blob-type columns for a table (the only ones
	 * worth scanning for string replacements), excluding any columns this
	 * table is configured to always skip (e.g. guid on the posts table).
	 */
	private function get_text_columns( $table ) {
		$cols = $this->db->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
		$text_types = array( 'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'blob', 'tinyblob', 'mediumblob', 'longblob' );

		$skip = $this->get_skip_columns_for_table( $table );

		$matched = array();
		foreach ( $cols as $col ) {
			$type = strtolower( preg_replace( '/\(.*$/', '', $col['Type'] ) );
			if ( in_array( $type, $text_types, true ) && ! in_array( $col['Field'], $skip, true ) ) {
				$matched[] = $col['Field'];
			}
		}
		return $matched;
	}

	/**
	 * Resolve which columns to skip for a given (prefixed) table name by
	 * stripping the current $wpdb->prefix and checking the skip_columns map.
	 */
	private function get_skip_columns_for_table( $table ) {
		if ( strpos( $table, $this->db->prefix ) === 0 ) {
			$bare_name = substr( $table, strlen( $this->db->prefix ) );
			if ( isset( $this->skip_columns[ $bare_name ] ) ) {
				return $this->skip_columns[ $bare_name ];
			}
		}
		return array();
	}
}
