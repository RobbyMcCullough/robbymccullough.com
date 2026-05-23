<?php

namespace FL\DesignSystem\DesignKit;

use FL\DesignSystem\Adapters\WordPress\WordPressAuth;
use FL\DesignSystem\Contracts\AuthInterface;

/**
 * REST controller for Design Kit analyze and import endpoints.
 *
 * Accepts zip file uploads or server-bundled kit IDs, resolves them to a
 * kit directory, delegates to KitParser/KitImporter, and cleans up.
 */
class DesignKitController {

	private AuthInterface $auth;

	private ?BuiltInKitRegistry $registry;

	private ?DesignKitPageWriter $page_writer;

	/**
	 * @param AuthInterface            $auth        Auth adapter for permission checks.
	 * @param BuiltInKitRegistry|null  $registry    Registry of bundled kits, if available.
	 * @param DesignKitPageWriter|null $page_writer Writer for including pages in downloads.
	 */
	public function __construct(
		AuthInterface $auth,
		?BuiltInKitRegistry $registry = null,
		?DesignKitPageWriter $page_writer = null
	) {
		$this->auth        = $auth;
		$this->registry    = $registry;
		$this->page_writer = $page_writer;
	}

	/**
	 * Register REST routes on rest_api_init.
	 */
	public function boot(): void {
		\add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the analyze and import routes.
	 */
	public function register_routes(): void {
		$namespace = 'fl-design-system/v1';

		\register_rest_route(
			$namespace,
			'/design-kits/analyze',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'analyze' ],
				'permission_callback' => [ $this->auth, 'content_creator_permission_callback' ],
			]
		);

		\register_rest_route(
			$namespace,
			'/design-kits/import',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'import' ],
				'permission_callback' => [ $this->auth, 'content_creator_permission_callback' ],
			]
		);

		\register_rest_route(
			$namespace,
			'/design-kits/download',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'download' ],
				'permission_callback' => [ $this->auth, 'content_creator_permission_callback' ],
				'args'                => [
					'designSystemUuid' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'pageIds'          => [
						'type'    => 'array',
						'items'   => [ 'type' => 'integer' ],
						'default' => [],
					],
				],
			]
		);

		if ( null === $this->registry ) {
			return;
		}

		\register_rest_route(
			$namespace,
			'/design-kits',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_built_in' ],
				'permission_callback' => [ $this, 'built_in_read_permission_callback' ],
			]
		);

		\register_rest_route(
			$namespace,
			'/design-kits/(?P<id>[a-z0-9][a-z0-9\-]*)/analyze',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'analyze_built_in' ],
				'permission_callback' => [ $this, 'built_in_read_permission_callback' ],
				'args'                => [
					'id' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		\register_rest_route(
			$namespace,
			'/design-kits/(?P<id>[a-z0-9][a-z0-9\-]*)/import',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'import_built_in' ],
				'permission_callback' => [ $this, 'built_in_read_permission_callback' ],
				'args'                => [
					'id' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		\register_rest_route(
			$namespace,
			'/design-kits/(?P<id>[a-z0-9][a-z0-9\-]*)/preview',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'preview_built_in' ],
				'permission_callback' => [ $this, 'built_in_read_permission_callback' ],
				'args'                => [
					'id' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Permission callback for bundled-kit endpoints.
	 *
	 * Uses `unfiltered_html` to align with the user-uploaded kit endpoints
	 * and the design-system CRUD surface, so any role that can create
	 * design systems can also browse and import bundled kits.
	 *
	 * @return bool
	 */
	public function built_in_read_permission_callback(): bool {
		return is_user_logged_in() && WordPressAuth::user_can_create_content();
	}

	/**
	 * Handle the analyze endpoint.
	 *
	 * Accepts a zip file upload, extracts it, runs KitParser::analyze(),
	 * and returns the analysis as JSON.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function analyze( \WP_REST_Request $request ) {
		$file = $this->get_uploaded_file( $request );

		if ( \is_wp_error( $file ) ) {
			return $file;
		}

		$temp_dir = $this->extract_zip( $file['tmp_name'] );

		if ( \is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		$kit_dir = $this->find_kit_root( $temp_dir );

		if ( \is_wp_error( $kit_dir ) ) {
			$this->cleanup_dir( $temp_dir );
			return $kit_dir;
		}

		try {
			$analysis = $this->analyze_directory( $kit_dir );
		} finally {
			$this->cleanup_dir( $temp_dir );
		}

		return new \WP_REST_Response( $analysis, 200 );
	}

	/**
	 * Handle the import endpoint.
	 *
	 * Accepts a zip file upload and a config JSON field. Extracts the zip,
	 * runs KitImporter::import(), and returns the results.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( \WP_REST_Request $request ) {
		if ( ! WordPressAuth::user_can_create_content() ) {
			return new \WP_Error(
				'insufficient_permissions',
				'Design Kit import requires the ability to save unfiltered HTML content.',
				[ 'status' => 403 ]
			);
		}

		$file = $this->get_uploaded_file( $request );

		if ( \is_wp_error( $file ) ) {
			return $file;
		}

		$config = $this->parse_import_config( $request );

		if ( \is_wp_error( $config ) ) {
			return $config;
		}

		$temp_dir = $this->extract_zip( $file['tmp_name'] );

		if ( \is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		$kit_dir = $this->find_kit_root( $temp_dir );

		if ( \is_wp_error( $kit_dir ) ) {
			$this->cleanup_dir( $temp_dir );
			return $kit_dir;
		}

		try {
			$results = $this->import_directory( $kit_dir, $config );
		} finally {
			$this->cleanup_dir( $temp_dir );
		}

		return new \WP_REST_Response( $results, 200 );
	}

	/**
	 * List bundled design kits served by the registry.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function list_built_in( \WP_REST_Request $request ) {
		$entries = $this->registry ? $this->registry->all() : [];

		$kits = array_map(
			static function ( array $entry ): array {
				// Don't leak server paths to the client.
				unset( $entry['directory_path'] );
				return $entry;
			},
			$entries
		);

		return new \WP_REST_Response( [ 'kits' => $kits ], 200 );
	}

	/**
	 * Analyze a bundled kit by id.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function analyze_built_in( \WP_REST_Request $request ) {
		$kit_dir = $this->resolve_built_in_directory( $request );

		if ( \is_wp_error( $kit_dir ) ) {
			return $kit_dir;
		}

		$analysis = $this->analyze_directory( $kit_dir );

		return new \WP_REST_Response( $analysis, 200 );
	}

	/**
	 * Import a bundled kit by id using the provided config.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_built_in( \WP_REST_Request $request ) {
		if ( ! WordPressAuth::user_can_create_content() ) {
			return new \WP_Error(
				'insufficient_permissions',
				'Design Kit import requires the ability to save unfiltered HTML content.',
				[ 'status' => 403 ]
			);
		}

		$config = $this->parse_import_config( $request );

		if ( \is_wp_error( $config ) ) {
			return $config;
		}

		$kit_dir = $this->resolve_built_in_directory( $request );

		if ( \is_wp_error( $kit_dir ) ) {
			return $kit_dir;
		}

		$results = $this->import_directory( $kit_dir, $config );

		return new \WP_REST_Response( $results, 200 );
	}

	/**
	 * Build a per-page preview response for a bundled kit.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview_built_in( \WP_REST_Request $request ) {
		$kit_dir = $this->resolve_built_in_directory( $request );

		if ( \is_wp_error( $kit_dir ) ) {
			return $kit_dir;
		}

		return new \WP_REST_Response( KitPreviewer::preview( $kit_dir ), 200 );
	}

	/**
	 * Run the analyze pipeline against a resolved kit directory.
	 *
	 * @param string $kit_dir Path to the directory containing kit.json.
	 * @return array
	 */
	private function analyze_directory( string $kit_dir ): array {
		return KitParser::analyze( $kit_dir );
	}

	/**
	 * Run the import pipeline against a resolved kit directory.
	 *
	 * @param string $kit_dir Path to the directory containing kit.json.
	 * @param array  $config  Import config.
	 * @return array
	 */
	private function import_directory( string $kit_dir, array $config ): array {
		return KitImporter::import( $kit_dir, $config );
	}

	/**
	 * Resolve a bundled kit id from the request to its directory on disk.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return string|\WP_Error Directory path, or error if the id is unknown.
	 */
	private function resolve_built_in_directory( \WP_REST_Request $request ) {
		if ( null === $this->registry ) {
			return new \WP_Error(
				'registry_unavailable',
				'Bundled design kits are not configured.',
				[ 'status' => 404 ]
			);
		}

		$id    = (string) $request->get_param( 'id' );
		$entry = $this->registry->get( $id );

		if ( null === $entry ) {
			return new \WP_Error(
				'unknown_kit',
				'Unknown design kit id.',
				[ 'status' => 404 ]
			);
		}

		return (string) $entry['directory_path'];
	}

	/**
	 * Parse and validate the `config` JSON parameter on an import request.
	 *
	 * Accepts either a JSON string (legacy zip-upload form-data) or an
	 * already-decoded array (JSON body).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return array|\WP_Error
	 */
	private function parse_import_config( \WP_REST_Request $request ) {
		$config_raw = $request->get_param( 'config' );

		if ( empty( $config_raw ) ) {
			return new \WP_Error(
				'missing_config',
				'The config parameter is required.',
				[ 'status' => 422 ]
			);
		}

		if ( is_array( $config_raw ) ) {
			return $config_raw;
		}

		$decoded = json_decode( (string) $config_raw, true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'invalid_config',
				'The config parameter must be valid JSON.',
				[ 'status' => 422 ]
			);
		}

		return $decoded;
	}

	/**
	 * Handle the download endpoint.
	 *
	 * Generates a Design Kit zip, optionally populated from an existing
	 * design system, and streams it to the client.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function download( \WP_REST_Request $request ) {
		$ds_uuid = $request->get_param( 'designSystemUuid' );
		$page_ids = $this->normalize_page_ids( $request->get_param( 'pageIds' ) );

		$result = DesignKitDownloadService::generate(
			$ds_uuid ?: null,
			$page_ids,
			$this->page_writer
		);

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		$zip_path = $result['path'];
		$filename = $result['filename'];
		$temp_dir = $result['temp_dir'];

		// Clean any existing output buffers to prevent corruption.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Stream the zip file.
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $zip_path ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );

		readfile( $zip_path );

		// Clean up temp directory.
		DesignKitDownloadService::cleanup_dir( $temp_dir );

		exit;
	}

	/**
	 * Normalize the pageIds query param into a clean array of post IDs.
	 *
	 * Accepts either a comma-separated string or an array; casts every entry
	 * to int and drops zeros and duplicates so the writer sees a clean list.
	 *
	 * @param mixed $raw The raw param value from the request.
	 * @return int[]
	 */
	private function normalize_page_ids( $raw ): array {
		if ( null === $raw || '' === $raw ) {
			return [];
		}

		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$ids = [];
		foreach ( $raw as $value ) {
			$id = (int) $value;
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Get the uploaded file from the request.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return array|\WP_Error File data array or error.
	 */
	private function get_uploaded_file( \WP_REST_Request $request ) {
		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new \WP_Error(
				'missing_file',
				'No file was uploaded. Send a zip file in the "file" field.',
				[ 'status' => 422 ]
			);
		}

		$file = $files['file'];

		// Validate it's a zip file.
		$ext = strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) );

		if ( 'zip' !== $ext ) {
			return new \WP_Error(
				'invalid_file_type',
				'Only .zip files are accepted.',
				[ 'status' => 422 ]
			);
		}

		return $file;
	}

	/**
	 * Extract a zip file to a temporary directory.
	 *
	 * @param string $file_path Path to the zip file.
	 * @return string|\WP_Error Path to the extracted directory or error.
	 */
	private function extract_zip( string $file_path ) {
		$zip = new \ZipArchive();

		if ( true !== $zip->open( $file_path ) ) {
			return new \WP_Error(
				'invalid_zip',
				'Could not open zip file.',
				[ 'status' => 422 ]
			);
		}

		$error = $this->validate_zip_entries( $zip );

		if ( \is_wp_error( $error ) ) {
			$zip->close();
			return $error;
		}

		$temp_dir = tempnam( sys_get_temp_dir(), 'design-kit-' ) . '_extracted';
		\wp_mkdir_p( $temp_dir );

		$zip->extractTo( $temp_dir );
		$zip->close();

		$this->remove_symlinks( $temp_dir );

		return $temp_dir;
	}

	/**
	 * Find the kit root within an extracted directory.
	 *
	 * Handles the common case where the zip contains a single wrapper
	 * directory (e.g., my-kit/kit.json instead of kit.json at root).
	 *
	 * @param string $dir Extracted directory path.
	 * @return string|\WP_Error Kit root directory path or error.
	 */
	private function find_kit_root( string $dir ) {
		// If kit.json exists at root, this is the kit root.
		if ( file_exists( \trailingslashit( $dir ) . 'kit.json' ) ) {
			return $dir;
		}

		// Check subdirectories for one that contains kit.json.
		$entries = array_diff( scandir( $dir ), [ '.', '..' ] );

		foreach ( $entries as $entry ) {
			$child = \trailingslashit( $dir ) . $entry;
			if ( is_dir( $child ) && file_exists( \trailingslashit( $child ) . 'kit.json' ) ) {
				return $child;
			}
		}

		return new \WP_Error(
			'missing_kit_json',
			'This doesn\'t appear to be a valid design kit. No kit.json was found.',
			[ 'status' => 422 ]
		);
	}

	/**
	 * Validate all entries in a zip archive before extraction.
	 *
	 * Checks for path traversal, disallowed extensions, dotfiles,
	 * excessive file count, and excessive cumulative size.
	 *
	 * @param \ZipArchive $zip Open zip archive.
	 * @return \WP_Error|null Error on violation, null if valid.
	 */
	private function validate_zip_entries( \ZipArchive $zip ) {
		$max_files = 500;
		$max_size  = 100 * 1024 * 1024; // 100 MB

		if ( $zip->numFiles > $max_files ) {
			return new \WP_Error(
				'zip_too_many_files',
				'The zip archive contains too many files (limit: ' . $max_files . ').',
				[ 'status' => 422 ]
			);
		}

		$allowed_extensions = [
			'html', 'css', 'json',
			'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico',
			'woff', 'woff2', 'ttf', 'otf', 'eot',
			'txt', 'md',
		];

		$cumulative_size = 0;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );

			// Reject path traversal.
			if ( false !== strpos( $name, '..' ) ) {
				return new \WP_Error(
					'zip_path_traversal',
					'The zip archive contains a path traversal entry: ' . $name,
					[ 'status' => 422 ]
				);
			}

			// Reject absolute paths or backslashes.
			if ( 0 === strpos( $name, '/' ) || 0 === strpos( $name, '\\' ) || false !== strpos( $name, '\\' ) ) {
				return new \WP_Error(
					'zip_invalid_path',
					'The zip archive contains an invalid path: ' . $name,
					[ 'status' => 422 ]
				);
			}

			// Directory entries are allowed.
			if ( '/' === substr( $name, -1 ) ) {
				continue;
			}

			// Skip OS metadata (macOS __MACOSX, resource forks, .DS_Store, Thumbs.db).
			if ( 0 === strpos( $name, '__MACOSX/' ) ) {
				continue;
			}

			$basename = basename( $name );

			if ( 0 === strpos( $basename, '._' ) || '.DS_Store' === $basename || 'Thumbs.db' === $basename ) {
				continue;
			}

			// Skip dotfiles and files with disallowed extensions.
			if ( 0 === strpos( $basename, '.' ) ) {
				continue;
			}

			$ext = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );

			if ( ! in_array( $ext, $allowed_extensions, true ) ) {
				continue;
			}

			// Track cumulative uncompressed size.
			$stat = $zip->statIndex( $i );

			if ( false !== $stat ) {
				$cumulative_size += $stat['size'];
			}

			if ( $cumulative_size > $max_size ) {
				return new \WP_Error(
					'zip_too_large',
					'The zip archive\'s uncompressed size exceeds the 100 MB limit.',
					[ 'status' => 422 ]
				);
			}
		}

		return null;
	}

	/**
	 * Remove any symlinks from a directory tree.
	 *
	 * @param string $dir Directory to walk.
	 */
	private function remove_symlinks( string $dir ): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isLink() ) {
				unlink( $item->getPathname() );
			}
		}
	}

	/**
	 * Recursively delete a temporary directory.
	 *
	 * @param string $dir Directory path to delete.
	 */
	private function cleanup_dir( string $dir ): void {
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
