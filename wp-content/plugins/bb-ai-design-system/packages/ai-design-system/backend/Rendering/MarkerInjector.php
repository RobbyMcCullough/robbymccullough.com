<?php
/**
 * Inline-editing marker injector.
 *
 * Thin orchestrator around `MarkerEmissionPlanner` (HTML-aware token walk +
 * element-tree DCA) and the per-array `__bb_path` annotator. Mirrors the JS
 * `injectMarkers` and `annotatePaths` helpers in
 * `frontend/src/core/template-engine/template-engine.js` so the PHP renderer
 * emits the same `<!--bb:TYPE:path-->...<!--/bb:TYPE:path-->` markers the
 * client-side engine scans for.
 *
 * Both methods must produce output byte-identical to the JS reference.
 * Parity is asserted via the shared fixture in
 * `packages/ai-design-system/__test-fixtures__/marker-cases.json`.
 *
 * @package FL\DesignSystem\Rendering
 */

namespace FL\DesignSystem\Rendering;

use FL\DesignSystem\Services\Parser\FieldTypeResolver;

/**
 * Marker injector for inline editing.
 */
class MarkerInjector {

	/**
	 * Wrap settings value tokens in HTML comment markers for inline editing.
	 *
	 * Marks body-context tokens (text, svg, etc.) and wraps the smallest
	 * enclosing element of compound-image sub-tokens. Repeater-scoped bare
	 * keys embed `{{__bb_path}}` so Mustache resolves the absolute structural
	 * path at render time; paths are provided by `annotate_paths` before
	 * rendering.
	 *
	 * Field types are resolved via the optional `$resolver`. When null,
	 * body-context tokens emit as `bb:text:` (preserves pre-resolver
	 * behavior). Compound image sub-tokens emit nothing without a resolver
	 * because they can't be identified without form-schema context.
	 *
	 * Skips: section/inverted tags, dynamic-tag-name positions, and tokens
	 * inside HTML comments.
	 *
	 * @param string                $template Raw Mustache template.
	 * @param FieldTypeResolver|null $resolver Optional path -> field type
	 *                                         resolver.
	 * @return string Template with marker comments injected.
	 */
	public function inject_markers( string $template, ?FieldTypeResolver $resolver = null ): string {
		if ( '' === $template ) {
			return $template;
		}

		$planner = new MarkerEmissionPlanner();
		$plan    = $planner->plan_markers( $template, $resolver );
		if ( empty( $plan ) ) {
			return $template;
		}

		$ops = [];
		foreach ( $plan as $entry ) {
			$ops[] = [
				'at'   => $entry['start'],
				'kind' => 'open',
				'text' => '<!--bb:' . $entry['type'] . ':' . $entry['path'] . '-->',
			];
			$ops[] = [
				'at'   => $entry['end'],
				'kind' => 'close',
				'text' => '<!--/bb:' . $entry['type'] . ':' . $entry['path'] . '-->',
			];
		}

		// Stable sort: by `at`, then close-before-open at the same offset so a
		// marker that ends at position N closes before another that opens at N.
		// PHP's usort isn't stable, so include the original index in the
		// comparison for ties beyond kind.
		$indexed = [];
		foreach ( $ops as $idx => $op ) {
			$indexed[] = [ $op, $idx ];
		}
		usort(
			$indexed,
			static function ( $a, $b ) {
				if ( $a[0]['at'] !== $b[0]['at'] ) {
					return $a[0]['at'] - $b[0]['at'];
				}
				if ( $a[0]['kind'] !== $b[0]['kind'] ) {
					return 'close' === $a[0]['kind'] ? -1 : 1;
				}
				return $a[1] - $b[1];
			}
		);

		$out    = '';
		$cursor = 0;
		foreach ( $indexed as $pair ) {
			$op    = $pair[0];
			$out   .= substr( $template, $cursor, $op['at'] - $cursor ) . $op['text'];
			$cursor = $op['at'];
		}
		$out .= substr( $template, $cursor );
		return $out;
	}

	/**
	 * Return a copy of settings with `__bb_path` annotated on every plain-
	 * object array item at any depth. Each item's `__bb_path` is the full
	 * absolute path from the settings root (e.g. `groups.0.items.1`) so
	 * marker templates containing `{{__bb_path}}` resolve to a concrete
	 * structural path at render time.
	 *
	 * @param array $settings Settings tree.
	 * @return array Deep-copied settings with `__bb_path` on qualifying items.
	 */
	public function annotate_paths( array $settings ): array {
		return self::annotate_value( $settings, '' );
	}

	/**
	 * Recursive helper for `annotate_paths`.
	 *
	 * @param mixed  $value Value to annotate.
	 * @param string $path  Absolute dot-path to `$value`.
	 * @return mixed
	 */
	private static function annotate_value( $value, string $path ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( self::is_packed_list( $value ) ) {
			$out = [];
			foreach ( $value as $index => $item ) {
				if ( self::is_plain_object( $item ) ) {
					$item_path              = $path . '.' . $index;
					$annotated              = self::annotate_object( $item, $item_path );
					$annotated['__bb_path'] = $item_path;
					$out[]                  = $annotated;
				} else {
					$out[] = $item;
				}
			}
			return $out;
		}

		return self::annotate_object( $value, $path );
	}

	/**
	 * Recurse into an associative-array object, building child paths from
	 * `$path + "." + $key` (or `$key` at the root). Strips any pre-existing
	 * `__bb_path` so callers can't seed stale data.
	 *
	 * @param array  $obj  Associative array to recurse into.
	 * @param string $path Absolute dot-path to `$obj`.
	 * @return array Annotated copy of `$obj` (without its own `__bb_path`).
	 */
	private static function annotate_object( array $obj, string $path ): array {
		$out = [];
		foreach ( $obj as $key => $sub ) {
			if ( '__bb_path' === $key ) {
				continue;
			}
			$child_path  = '' === $path ? (string) $key : $path . '.' . $key;
			$out[ $key ] = self::annotate_value( $sub, $child_path );
		}
		return $out;
	}

	/**
	 * Whether a PHP array is a packed (numerically indexed 0..N-1) list.
	 *
	 * Empty arrays return false; they are treated as associative so the
	 * annotator recurses into them rather than handing them to the list path.
	 *
	 * @param array $arr Array to classify.
	 * @return bool
	 */
	private static function is_packed_list( array $arr ): bool {
		if ( empty( $arr ) ) {
			return false;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	/**
	 * Whether a value is a plain object in the JS sense: an associative
	 * array in PHP. Scalars, null, and packed-list arrays are rejected.
	 *
	 * @param mixed $value Value to classify.
	 * @return bool
	 */
	private static function is_plain_object( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( empty( $value ) ) {
			return true;
		}
		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}
}
