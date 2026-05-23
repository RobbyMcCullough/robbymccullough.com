<?php

namespace FL\DesignSystem\Services\Parser\AutoAnnotateGaps;

use FL\DesignSystem\Services\Parser\AutoAnnotateGaps;

/**
 * Key derivation + existing-key collection for the auto-annotate pre-pass.
 */
class Keys {

	/**
	 * First occurrence is bare (`heading`); subsequent collisions append `_N`
	 * (`heading_2`, `heading_3`, …). The disambiguate scope is the used-keys
	 * set for the surrounding annotation scope (top-level or per-repeater-item).
	 *
	 * @param string $base       The base fallback key.
	 * @param array  $used_keys  Map of `key => true` (mutated to register the result).
	 * @return string The disambiguated key.
	 */
	public static function disambiguate( string $base, array &$used_keys ): string {
		if ( ! isset( $used_keys[ $base ] ) ) {
			$used_keys[ $base ] = true;
			return $base;
		}
		$n = 2;
		while ( isset( $used_keys[ $base . '_' . $n ] ) ) {
			$n++;
		}
		$key               = $base . '_' . $n;
		$used_keys[ $key ] = true;
		return $key;
	}

	/**
	 * Collect existing `data-field` / `data-field-href` keys from a subtree.
	 * By default, walks past nothing — but stops at `data-repeater-item`
	 * boundaries when `$include_repeater_items` is false (their keys are
	 * in a separate per-item scope).
	 *
	 * @param \DOMNode $root                    The subtree root.
	 * @param bool     $include_repeater_items  Whether to descend into items.
	 * @return array<string, bool> Map of `key => true`.
	 */
	public static function collectExistingKeys( \DOMNode $root, bool $include_repeater_items ): array {
		$used = [];
		self::collectKeysRecursive( $root, $used, $include_repeater_items, true );
		return $used;
	}

	private static function collectKeysRecursive(
		\DOMNode $node,
		array &$used,
		bool $include_repeater_items,
		bool $is_root
	): void {
		if ( $node instanceof \DOMElement && ! $is_root ) {
			$tag = strtolower( $node->tagName );
			if ( in_array( $tag, AutoAnnotateGaps::SKIP_ANCESTOR_TAGS, true ) ) {
				return;
			}
			if ( ! $include_repeater_items && $node->hasAttribute( 'data-repeater-item' ) ) {
				return;
			}
			if ( $node->hasAttribute( 'data-field' ) ) {
				$used[ $node->getAttribute( 'data-field' ) ] = true;
			}
			if ( $node->hasAttribute( 'data-field-href' ) ) {
				$used[ $node->getAttribute( 'data-field-href' ) ] = true;
			}
		}

		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType ) {
				self::collectKeysRecursive( $child, $used, $include_repeater_items, false );
			}
		}
	}
}
