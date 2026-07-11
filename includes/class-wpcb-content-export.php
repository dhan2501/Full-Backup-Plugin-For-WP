<?php
/**
 * Export individual pages/posts including their content, metadata,
 * featured image, and any images referenced/attached, packaged as a ZIP.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPCB_Content_Export {

	/**
	 * Export a single post/page by ID into a ZIP containing:
	 * - content.html (rendered content)
	 * - meta.json (title, slug, dates, SEO meta, custom fields, taxonomies)
	 * - images/ (featured image + inline/attached images)
	 *
	 * @param int $post_id
	 * @return array{success:bool, message:string, file?:string, url?:string, size?:int}
	 */
	public function export_single( $post_id ) {
		wpcb_raise_limits();

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'success' => false, 'message' => "Post ID {$post_id} not found." );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return array( 'success' => false, 'message' => 'PHP ZipArchive extension is not available on this server.' );
		}

		if ( ! file_exists( WPCB_BACKUP_DIR ) ) {
			wp_mkdir_p( WPCB_BACKUP_DIR );
		}

		$timestamp = gmdate( 'Y-m-d_H-i-s' );
		$slug      = sanitize_title( $post->post_title ) ?: 'untitled';
		$zip_name  = "{$post->post_type}-{$slug}-{$post_id}-{$timestamp}.zip";
		$zip_path  = WPCB_BACKUP_DIR . '/' . $zip_name;

		$zip = new ZipArchive();
		if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
			return array( 'success' => false, 'message' => 'Could not create zip archive.' );
		}

		// 1. Content as HTML
		$content_html = $this->build_content_html( $post );
		$zip->addFromString( 'content.html', $content_html );

		// 2. Metadata as JSON
		$meta = $this->build_meta( $post );
		$zip->addFromString( 'meta.json', wp_json_encode( $meta, JSON_PRETTY_PRINT ) );

		// 3. Images: featured image + images attached to / referenced in the post
		$image_paths = $this->collect_image_paths( $post );
		foreach ( $image_paths as $i => $abs_path ) {
			if ( file_exists( $abs_path ) ) {
				$zip->addFile( $abs_path, 'images/' . basename( $abs_path ) );
			}
		}

		$zip->close();

		if ( ! file_exists( $zip_path ) ) {
			return array( 'success' => false, 'message' => 'Zip file was not created.' );
		}

		return array(
			'success' => true,
			'message' => 'Exported "' . $post->post_title . '" successfully.',
			'file'    => $zip_name,
			'url'     => WPCB_BACKUP_URL . '/' . $zip_name,
			'size'    => filesize( $zip_path ),
		);
	}

	/**
	 * Export multiple posts/pages at once into ONE zip, each in its own subfolder
	 *
	 * @param int[] $post_ids
	 */
	public function export_bulk( array $post_ids ) {
		wpcb_raise_limits();

		if ( ! class_exists( 'ZipArchive' ) ) {
			return array( 'success' => false, 'message' => 'PHP ZipArchive extension is not available on this server.' );
		}

		if ( ! file_exists( WPCB_BACKUP_DIR ) ) {
			wp_mkdir_p( WPCB_BACKUP_DIR );
		}

		$timestamp = gmdate( 'Y-m-d_H-i-s' );
		$zip_name  = "bulk-export-{$timestamp}.zip";
		$zip_path  = WPCB_BACKUP_DIR . '/' . $zip_name;

		$zip = new ZipArchive();
		if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
			return array( 'success' => false, 'message' => 'Could not create zip archive.' );
		}

		$count = 0;
		$index = array();

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$slug   = sanitize_title( $post->post_title ) ?: 'untitled';
			$folder = "{$post->post_type}-{$slug}-{$post_id}";

			$content_html = $this->build_content_html( $post );
			$zip->addFromString( "{$folder}/content.html", $content_html );

			$meta = $this->build_meta( $post );
			$zip->addFromString( "{$folder}/meta.json", wp_json_encode( $meta, JSON_PRETTY_PRINT ) );

			$image_paths = $this->collect_image_paths( $post );
			foreach ( $image_paths as $abs_path ) {
				if ( file_exists( $abs_path ) ) {
					$zip->addFile( $abs_path, "{$folder}/images/" . basename( $abs_path ) );
				}
			}

			$index[] = array(
				'id'    => $post_id,
				'title' => $post->post_title,
				'type'  => $post->post_type,
				'folder' => $folder,
			);

			$count++;
		}

		$zip->addFromString( 'index.json', wp_json_encode( $index, JSON_PRETTY_PRINT ) );
		$zip->close();

		if ( $count === 0 ) {
			@unlink( $zip_path );
			return array( 'success' => false, 'message' => 'No valid posts found to export.' );
		}

		return array(
			'success' => true,
			'message' => "Exported {$count} item(s) successfully.",
			'file'    => $zip_name,
			'url'     => WPCB_BACKUP_URL . '/' . $zip_name,
			'size'    => filesize( $zip_path ),
		);
	}

	/**
	 * Build a self-contained HTML document for the post content
	 */
	private function build_content_html( $post ) {
		$title       = esc_html( $post->post_title );
		$content     = apply_filters( 'the_content', $post->post_content );
		$featured_id = get_post_thumbnail_id( $post->ID );
		$featured_html = '';

		if ( $featured_id ) {
			$featured_html = '<p><img src="images/' . esc_attr( basename( get_attached_file( $featured_id ) ) ) . '" alt="Featured image" style="max-width:100%;"></p>';
		}

		return "<!DOCTYPE html>\n<html>\n<head><meta charset=\"utf-8\"><title>{$title}</title></head>\n<body>\n<h1>{$title}</h1>\n{$featured_html}\n{$content}\n</body>\n</html>";
	}

	/**
	 * Build metadata array: core fields, taxonomies, custom fields, SEO-ish meta
	 */
	private function build_meta( $post ) {
		$taxonomies = get_object_taxonomies( $post->post_type );
		$terms_data = array();

		foreach ( $taxonomies as $tax ) {
			$terms = get_the_terms( $post->ID, $tax );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$terms_data[ $tax ] = wp_list_pluck( $terms, 'name' );
			}
		}

		$custom_fields = get_post_meta( $post->ID );
		// flatten single-value meta arrays for readability
		foreach ( $custom_fields as $key => $values ) {
			if ( is_array( $values ) && count( $values ) === 1 ) {
				$custom_fields[ $key ] = maybe_unserialize( $values[0] );
			}
		}

		$featured_id  = get_post_thumbnail_id( $post->ID );
		$featured_url = $featured_id ? wp_get_attachment_url( $featured_id ) : null;

		return array(
			'id'              => $post->ID,
			'title'           => $post->post_title,
			'slug'            => $post->post_name,
			'type'            => $post->post_type,
			'status'          => $post->post_status,
			'author'          => get_the_author_meta( 'display_name', $post->post_author ),
			'date_created'    => $post->post_date,
			'date_modified'   => $post->post_modified,
			'excerpt'         => $post->post_excerpt,
			'permalink'       => get_permalink( $post->ID ),
			'parent_id'       => $post->post_parent,
			'menu_order'      => $post->menu_order,
			'comment_status'  => $post->comment_status,
			'featured_image'  => $featured_url,
			'taxonomies'      => $terms_data,
			'custom_fields'   => $custom_fields,
		);
	}

	/**
	 * Collect absolute file paths for the featured image and all images
	 * referenced in the post content (by matching uploads URLs) plus
	 * any media attached as children of the post.
	 */
	private function collect_image_paths( $post ) {
		$paths = array();

		// Featured image
		$featured_id = get_post_thumbnail_id( $post->ID );
		if ( $featured_id ) {
			$file = get_attached_file( $featured_id );
			if ( $file ) {
				$paths[] = $file;
			}
		}

		// Attached media (children) that are images
		$attachments = get_children( array(
			'post_parent' => $post->ID,
			'post_type'   => 'attachment',
			'post_mime_type' => 'image',
		) );

		foreach ( $attachments as $att ) {
			$file = get_attached_file( $att->ID );
			if ( $file ) {
				$paths[] = $file;
			}
		}

		// Inline images referenced in content via <img src="...">
		$upload_dir = wp_upload_dir();
		$base_url   = $upload_dir['baseurl'];
		$base_dir   = $upload_dir['basedir'];

		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $matches ) ) {
			foreach ( $matches[1] as $src ) {
				if ( strpos( $src, $base_url ) === 0 ) {
					$relative = str_replace( $base_url, '', $src );
					$abs_path = $base_dir . $relative;
					$paths[] = $abs_path;
				}
			}
		}

		return array_unique( $paths );
	}
}
