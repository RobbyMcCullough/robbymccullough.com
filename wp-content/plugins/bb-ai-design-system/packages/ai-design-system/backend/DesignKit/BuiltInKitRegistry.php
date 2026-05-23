<?php

namespace FL\DesignSystem\DesignKit;

/**
 * Registry for design kits bundled inside the plugin.
 *
 * Reads `_manifest.json` from a kit root directory and exposes the listed
 * kits as data + directory-path entries. Used by DesignKitController to
 * serve bundled kits through the same analyze/import pipeline as zip uploads.
 *
 * Manifest entry shape (in `_manifest.json`):
 *   {
 *     "id": "dune",
 *     "name": "Dune",
 *     "description": "Warm editorial kit."
 *   }
 */
class BuiltInKitRegistry {

	private string $root_dir;

	/**
	 * @param string $root_dir Absolute path to the directory containing `_manifest.json`
	 *                         and one subdirectory per bundled kit.
	 */
	public function __construct( string $root_dir ) {
		$this->root_dir = rtrim( $root_dir, '/\\' );
	}

	/**
	 * Return all bundled kits listed in the manifest.
	 *
	 * Each entry has: id, name, description, directory_path. Kits whose
	 * directory or `kit.json` is missing are skipped.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$manifest = $this->read_manifest();

		if ( empty( $manifest['kits'] ) || ! is_array( $manifest['kits'] ) ) {
			return [];
		}

		$entries = [];

		foreach ( $manifest['kits'] as $entry ) {
			$resolved = $this->resolve_entry( $entry );
			if ( null !== $resolved ) {
				$entries[] = $resolved;
			}
		}

		return $entries;
	}

	/**
	 * Return a single kit by id, or null if unknown.
	 *
	 * @param string $id Kit id from the manifest.
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		foreach ( $this->all() as $entry ) {
			if ( ( $entry['id'] ?? '' ) === $id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Read and decode `_manifest.json`.
	 *
	 * @return array<string, mixed>
	 */
	private function read_manifest(): array {
		$path = $this->root_dir . '/_manifest.json';

		if ( ! is_readable( $path ) ) {
			return [];
		}

		$json = file_get_contents( $path );
		if ( false === $json ) {
			return [];
		}

		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Turn a raw manifest entry into a resolved kit descriptor.
	 *
	 * Returns null when the entry is malformed or its directory is missing.
	 *
	 * @param mixed $entry Raw manifest entry.
	 * @return array<string, mixed>|null
	 */
	private function resolve_entry( $entry ): ?array {
		if ( ! is_array( $entry ) ) {
			return null;
		}

		$id = isset( $entry['id'] ) ? (string) $entry['id'] : '';
		if ( '' === $id || ! preg_match( '/^[a-z0-9][a-z0-9\-]*$/', $id ) ) {
			return null;
		}

		$directory = $this->root_dir . '/' . $id;
		if ( ! is_dir( $directory ) || ! file_exists( $directory . '/kit.json' ) ) {
			return null;
		}

		return [
			'id'             => $id,
			'name'           => isset( $entry['name'] ) ? (string) $entry['name'] : $id,
			'description'    => isset( $entry['description'] ) ? (string) $entry['description'] : '',
			'directory_path' => $directory,
		];
	}
}
