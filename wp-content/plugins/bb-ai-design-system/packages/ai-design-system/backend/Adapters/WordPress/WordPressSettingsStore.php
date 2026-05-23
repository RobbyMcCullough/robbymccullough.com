<?php

namespace FL\DesignSystem\Adapters\WordPress;

use FL\DesignSystem\Contracts\SettingsStoreInterface;

class WordPressSettingsStore implements SettingsStoreInterface {

	public const ENCRYPTED_KEYS = [ 'ai.api_key', 'media.unsplash_api_key', 'license.key' ];

	public const OPTION_MAP = [
		'ai.api_key'                => 'fl_design_system_ai_api_key',
		'ai.model'                  => 'fl_design_system_ai_model',
		'designSystem.overrides'    => 'fl_design_system_overrides',
		'media.unsplash_api_key'    => 'fl_design_system_media_unsplash_api_key',
		'license.key'               => 'fl_design_system_license_key',
	];

	public const DEFAULTS = [
		'ai.model' => 'claude-sonnet-4-6',
	];

	/**
	 * Get all settings with sensitive values masked.
	 *
	 * @return array
	 */
	public function all(): array {
		return [
			'ai'           => [
				'hasKey' => $this->has_encrypted_value( 'ai.api_key' ),
				'model'  => $this->get( 'ai.model', self::DEFAULTS['ai.model'] ),
			],
			'media'        => [
				'unsplash' => [
					'hasKey' => $this->has_encrypted_value( 'media.unsplash_api_key' ),
				],
			],
			'license'      => [
				'hasKey' => $this->has_encrypted_value( 'license.key' ),
			],
			'designSystem' => [
				'overrides' => $this->get_overrides(),
			],
		];
	}

	/**
	 * Get a single setting by dot-notation key.
	 *
	 * @param  string $key     Dot-notation key (e.g., 'ai.api_key').
	 * @param  mixed  $default Default value if the setting doesn't exist.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$option_key = $this->resolve_option_key( $key );
		if ( ! $option_key ) {
			return $default;
		}

		$value = get_option( $option_key, '' );
		if ( '' === $value ) {
			return $default;
		}

		if ( $this->is_encrypted( $key ) ) {
			return $this->decrypt( $value );
		}

		return $value;
	}

	/**
	 * Set one or more settings.
	 *
	 * @param array $values Associative array of dot-notation keys to values.
	 */
	public function set( array $values ): void {
		foreach ( $values as $key => $value ) {
			$option_key = $this->resolve_option_key( $key );
			if ( ! $option_key ) {
				continue;
			}

			if ( empty( $value ) && $this->is_encrypted( $key ) ) {
				delete_option( $option_key );
				continue;
			}

			$stored_value = $this->is_encrypted( $key ) ? $this->encrypt( $value ) : $value;
			update_option( $option_key, $stored_value );
		}
	}

	/**
	 * Delete a setting by dot-notation key.
	 *
	 * @param string $key Dot-notation key.
	 */
	public function delete( string $key ): void {
		$option_key = $this->resolve_option_key( $key );
		if ( $option_key ) {
			delete_option( $option_key );
		}
	}

	/**
	 * Get design system overrides as an object.
	 *
	 * @return object
	 */
	private function get_overrides(): object {
		$json = $this->get( 'designSystem.overrides' );
		if ( $json ) {
			$decoded = json_decode( $json, true );
			if ( is_array( $decoded ) ) {
				return (object) $decoded;
			}
		}

		return (object) [];
	}

	/**
	 * Check whether an encrypted value exists for the given key.
	 *
	 * @param  string $key Dot-notation key.
	 * @return bool
	 */
	private function has_encrypted_value( string $key ): bool {
		$option_key = $this->resolve_option_key( $key );
		return $option_key && (bool) get_option( $option_key, '' );
	}

	/**
	 * Resolve a dot-notation key to a wp_options key.
	 *
	 * @param  string      $key Dot-notation key.
	 * @return string|null
	 */
	private function resolve_option_key( string $key ): ?string {
		return self::OPTION_MAP[ $key ] ?? null;
	}

	/**
	 * Check whether a key should be encrypted.
	 *
	 * @param  string $key Dot-notation key.
	 * @return bool
	 */
	private function is_encrypted( string $key ): bool {
		return in_array( $key, self::ENCRYPTED_KEYS, true );
	}

	/**
	 * Encrypt a value using AES-256-CBC with a random IV.
	 *
	 * The random IV is prepended to the ciphertext before base64-encoding,
	 * so each encryption produces unique output even for identical plaintext.
	 *
	 * @param  string $value Plain text value.
	 * @return string Base64-encoded IV + encrypted value.
	 */
	private function encrypt( string $value ): string {
		$key = wp_salt( 'auth' );

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv_length = openssl_cipher_iv_length( 'AES-256-CBC' );
			$iv        = openssl_random_pseudo_bytes( $iv_length );
			$encrypted = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			return base64_encode( $iv . $encrypted );
		}

		// Fallback: XOR obfuscation when openssl is unavailable
		$key_hash = hash( 'sha256', $key, true );
		$result   = '';
		for ( $i = 0; $i < strlen( $value ); $i++ ) {
			$result .= $value[ $i ] ^ $key_hash[ $i % strlen( $key_hash ) ];
		}
		return base64_encode( $result );
	}

	/**
	 * Decrypt a value using AES-256-CBC.
	 *
	 * Supports two formats:
	 * - New: random IV prepended to raw ciphertext, then base64-encoded.
	 * - Legacy: base64-encoded openssl_encrypt output using a static IV
	 *   derived from wp_salt('secure_auth').
	 *
	 * @param  string $encrypted Base64-encoded encrypted value.
	 * @return string Decrypted value, or empty string on failure.
	 */
	private function decrypt( string $encrypted ): string {
		$key = wp_salt( 'auth' );

		if ( function_exists( 'openssl_encrypt' ) ) {
			$decoded   = base64_decode( $encrypted );
			$iv_length = openssl_cipher_iv_length( 'AES-256-CBC' );

			// New format: raw IV prepended to raw ciphertext.
			if ( strlen( $decoded ) > $iv_length ) {
				$iv        = substr( $decoded, 0, $iv_length );
				$cipher    = substr( $decoded, $iv_length );
				$decrypted = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

				if ( false !== $decrypted ) {
					return $decrypted;
				}
			}

			// Legacy format: base64-encoded openssl_encrypt output with static IV.
			return $this->decrypt_legacy( $decoded, $key );
		}

		// Fallback: XOR obfuscation when openssl is unavailable
		$key_hash = hash( 'sha256', $key, true );
		$decoded  = base64_decode( $encrypted );
		$result   = '';
		for ( $i = 0; $i < strlen( $decoded ); $i++ ) {
			$result .= $decoded[ $i ] ^ $key_hash[ $i % strlen( $key_hash ) ];
		}
		return $result;
	}

	/**
	 * Decrypt a value encrypted with the legacy static IV format.
	 *
	 * The old encrypt() base64-encoded the openssl_encrypt output (which is
	 * itself base64), so the stored value is double-encoded. The outer layer
	 * has already been decoded by the caller.
	 *
	 * @param  string $outer_decoded The result of base64_decode on the stored value.
	 * @param  string $key           Encryption key.
	 * @return string Decrypted value, or empty string on failure.
	 */
	private function decrypt_legacy( string $outer_decoded, string $key ): string {
		$static_iv = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );
		$decrypted = openssl_decrypt( $outer_decoded, 'AES-256-CBC', $key, 0, $static_iv );
		return false !== $decrypted ? $decrypted : '';
	}
}
