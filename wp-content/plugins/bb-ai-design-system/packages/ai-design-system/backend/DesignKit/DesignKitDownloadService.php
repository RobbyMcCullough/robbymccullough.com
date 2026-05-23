<?php

namespace FL\DesignSystem\DesignKit;

use FL\DesignSystem\DesignSystem\DesignSystemPostType;

/**
 * Generates downloadable Design Kit zip files.
 *
 * Copies the template from data/design-kit/, optionally populates it
 * with an existing design system's data, and returns a zip file path.
 */
class DesignKitDownloadService {

	/**
	 * Path to the design kit template directory.
	 *
	 * @return string
	 */
	private static function template_dir(): string {
		return dirname( __DIR__, 2 ) . '/data/design-kit/';
	}

	/**
	 * Generate a design kit zip file.
	 *
	 * @param string|null               $ds_uuid     Optional design system UUID to populate the kit from.
	 * @param int[]                     $page_ids    Optional page post IDs to include under pages/.
	 * @param DesignKitPageWriter|null  $page_writer Required when $page_ids is non-empty.
	 * @return array{path: string, filename: string, temp_dir: string}|\WP_Error Zip file path and suggested filename, or error.
	 */
	public static function generate(
		?string $ds_uuid = null,
		array $page_ids = [],
		?DesignKitPageWriter $page_writer = null
	) {
		$temp_dir = self::create_temp_dir();

		if ( ! $temp_dir ) {
			return new \WP_Error(
				'temp_dir_failed',
				'Could not create temporary directory.',
				[ 'status' => 500 ]
			);
		}

		$kit_dir = \trailingslashit( $temp_dir ) . 'design-kit/';

		// Copy template files.
		self::copy_dir( self::template_dir(), $kit_dir );

		// Remove .gitkeep files -- they're only needed in the repo.
		self::remove_gitkeep_files( $kit_dir );

		$filename = 'design-kit';

		if ( $ds_uuid ) {
			$result = self::populate_from_design_system( $kit_dir, $ds_uuid );

			if ( \is_wp_error( $result ) ) {
				self::cleanup_dir( $temp_dir );
				return $result;
			}

			$filename = $result['filename'];

			// Rename the kit folder to match the filename slug.
			$renamed_dir = \trailingslashit( $temp_dir ) . $filename . '/';
			rename( rtrim( $kit_dir, '/' ), rtrim( $renamed_dir, '/' ) );
			$kit_dir = $renamed_dir;
		}

		// Add page HTML files if requested.
		if ( ! empty( $page_ids ) ) {
			if ( null === $page_writer ) {
				self::cleanup_dir( $temp_dir );
				return new \WP_Error(
					'missing_page_writer',
					'A DesignKitPageWriter is required when page IDs are provided.',
					[ 'status' => 500 ]
				);
			}

			$write_result = $page_writer->write( $kit_dir, $page_ids );
			if ( \is_wp_error( $write_result ) ) {
				self::cleanup_dir( $temp_dir );
				return $write_result;
			}
		}

		// Create the zip.
		$zip_path = \trailingslashit( $temp_dir ) . $filename . '.zip';
		$zip_result = self::create_zip( $kit_dir, $zip_path );

		if ( \is_wp_error( $zip_result ) ) {
			self::cleanup_dir( $temp_dir );
			return $zip_result;
		}

		return [
			'path'     => $zip_path,
			'filename' => $filename . '.zip',
			'temp_dir' => $temp_dir,
		];
	}

	/**
	 * Populate a kit directory from an existing design system.
	 *
	 * @param string $kit_dir Kit directory path (with trailing slash).
	 * @param string $ds_uuid Design system UUID.
	 * @return array{filename: string}|\WP_Error
	 */
	private static function populate_from_design_system( string $kit_dir, string $ds_uuid ): array {
		$post = DesignSystemPostType::get_by_uuid( $ds_uuid );

		if ( ! $post ) {
			return new \WP_Error(
				'ds_not_found',
				'Design system not found.',
				[ 'status' => 404 ]
			);
		}

		$ds = DesignSystemPostType::format_for_response( $post );

		// Write design-system/styles.css with @tokens, @reset, @base sections.
		$styles_css = self::build_styles_css( $ds );
		$ds_dir = $kit_dir . 'design-system/';
		\wp_mkdir_p( $ds_dir );
		file_put_contents( $ds_dir . 'styles.css', $styles_css );

		// Write design-system/art-direction.md with guidance and business context.
		$guidance = $ds['guidance'] ?? '';
		$brief    = $ds['brief'] ?? '';

		if ( '' !== $guidance || '' !== $brief ) {
			$art_direction = '';

			if ( '' !== $guidance ) {
				$art_direction .= $guidance;
			}

			if ( '' !== $brief ) {
				if ( '' !== $art_direction ) {
					$art_direction .= "\n\n";
				}
				$art_direction .= "## Business Context\n\n" . $brief;
			}

			file_put_contents( $ds_dir . 'art-direction.md', $art_direction . "\n" );
		}

		// Write design-system/script.js with base JavaScript.
		$base_js = $ds['js'] ?? '';

		if ( '' !== $base_js ) {
			file_put_contents( $ds_dir . 'script.js', $base_js . "\n" );
		}

		// Update kit.json with identity fields only.
		$kit_json_path = $kit_dir . 'kit.json';
		$kit_json = json_decode( file_get_contents( $kit_json_path ), true );

		$kit_json['uuid'] = $ds_uuid;
		$kit_json['name'] = $ds['label'];

		file_put_contents(
			$kit_json_path,
			wp_json_encode( $kit_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);

		// Build a slug for the filename.
		$slug = sanitize_title( $ds['label'] );
		$filename = $slug ? 'design-kit-' . $slug : 'design-kit';

		return [ 'filename' => $filename ];
	}

	/**
	 * Build styles.css content from design system data.
	 *
	 * Uses the @tokens, @reset, @base markers that the kit format spec requires.
	 *
	 * @param array $ds Design system data from format_for_response().
	 * @return string CSS content.
	 */
	private static function build_styles_css( array $ds ): string {
		$parts = [];

		// @tokens section.
		$tokens = $ds['tokens'] ?? [];
		$lines = [];
		foreach ( $tokens as $name => $value ) {
			$lines[] = '  ' . $name . ': ' . $value . ';';
		}
		$parts[] = "/* @tokens */\n:root {\n" . implode( "\n", $lines ) . "\n}";

		// @reset section.
		$reset = $ds['reset'] ?? '';
		if ( '' !== $reset ) {
			$parts[] = "/* @reset */\n" . $reset;
		} else {
			$parts[] = "/* @reset */";
		}

		// @base section.
		$base = $ds['base'] ?? '';
		if ( '' !== $base ) {
			$parts[] = "/* @base */\n" . $base;
		} else {
			$parts[] = "/* @base */";
		}

		return implode( "\n\n", $parts ) . "\n";
	}

	/**
	 * Create a zip file from a directory.
	 *
	 * @param string $source_dir Directory to zip.
	 * @param string $zip_path   Output zip file path.
	 * @return true|\WP_Error
	 */
	private static function create_zip( string $source_dir, string $zip_path ) {
		$zip = new \ZipArchive();

		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error(
				'zip_failed',
				'Could not create zip file.',
				[ 'status' => 500 ]
			);
		}

		$source_dir = realpath( $source_dir );
		$base_name  = basename( $source_dir );

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source_dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $files as $file ) {
			$real_path     = $file->getRealPath();
			$relative_path = $base_name . '/' . substr( $real_path, strlen( $source_dir ) + 1 );

			if ( $file->isDir() ) {
				$zip->addEmptyDir( $relative_path );
			} else {
				$zip->addFile( $real_path, $relative_path );
			}
		}

		$zip->close();

		return true;
	}

	/**
	 * Create a temporary directory.
	 *
	 * @return string|false Temp directory path or false on failure.
	 */
	private static function create_temp_dir() {
		$temp_file = tempnam( sys_get_temp_dir(), 'design-kit-dl-' );

		if ( ! $temp_file ) {
			return false;
		}

		// tempnam creates a file; remove it and create a directory instead.
		unlink( $temp_file );
		$temp_dir = $temp_file . '_dir';

		if ( ! \wp_mkdir_p( $temp_dir ) ) {
			return false;
		}

		return $temp_dir;
	}

	/**
	 * Recursively copy a directory.
	 *
	 * @param string $source Source directory.
	 * @param string $dest   Destination directory.
	 */
	private static function copy_dir( string $source, string $dest ): void {
		\wp_mkdir_p( $dest );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$target = $dest . substr( $item->getRealPath(), strlen( realpath( $source ) ) );

			if ( $item->isDir() ) {
				\wp_mkdir_p( $target );
			} else {
				copy( $item->getRealPath(), $target );
			}
		}
	}

	/**
	 * Remove .gitkeep files from a directory tree.
	 *
	 * @param string $dir Directory path.
	 */
	private static function remove_gitkeep_files( string $dir ): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( '.gitkeep' === $file->getFilename() ) {
				unlink( $file->getRealPath() );
			}
		}
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory to delete.
	 */
	public static function cleanup_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getRealPath() );
			} else {
				unlink( $file->getRealPath() );
			}
		}

		rmdir( $dir );
	}
}
