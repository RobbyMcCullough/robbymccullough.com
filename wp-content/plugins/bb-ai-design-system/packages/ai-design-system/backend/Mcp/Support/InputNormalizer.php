<?php

namespace FL\DesignSystem\Mcp\Support;

/**
 * Normalizes MCP ability input before it reaches an ability's execute body.
 *
 * Two responsibilities:
 *   1. Resolve a small alias map (e.g. `label` -> `design_system_label`) so
 *      cross-tool confusion routes to the canonical key instead of being
 *      silently dropped or loudly rejected.
 *   2. Strip any remaining unknown keys, returning them so the registry can
 *      surface them on the response as `ignored_keys`.
 *
 * Aliases are intentionally scoped to the design-system pattern only. Add a
 * new entry only when a second confusion cluster shows up; do not generalize
 * preemptively.
 */
final class InputNormalizer {

	private const ALIASES = [
		'label'               => 'design_system_label',
		'design_system_label' => 'label',
		'uuid'                => 'design_system_uuid',
	];

	/**
	 * Normalize input against an ability's declared schema properties.
	 *
	 * @param array $input      Raw input from the MCP caller.
	 * @param array $properties The ability's `input_schema['properties']` map.
	 * @return array{input: array, ignored_keys: string[]}
	 */
	public static function normalize( array $input, array $properties ): array {
		$ignored_keys = [];

		foreach ( self::ALIASES as $alias => $canonical ) {
			if ( ! array_key_exists( $alias, $input ) ) {
				continue;
			}
			if ( ! array_key_exists( $canonical, $properties ) ) {
				continue;
			}
			if ( array_key_exists( $canonical, $input ) ) {
				// Both alias and canonical present — canonical wins, alias is ignored.
				$ignored_keys[] = $alias;
				unset( $input[ $alias ] );
				continue;
			}
			$input[ $canonical ] = $input[ $alias ];
			unset( $input[ $alias ] );
		}

		foreach ( array_keys( $input ) as $key ) {
			if ( ! array_key_exists( $key, $properties ) ) {
				$ignored_keys[] = $key;
				unset( $input[ $key ] );
			}
		}

		return [
			'input'        => $input,
			'ignored_keys' => $ignored_keys,
		];
	}

	/**
	 * Annotate a successful array result with the list of ignored keys.
	 *
	 * Uses array assignment, not the `+` union operator, so this value
	 * always wins if an ability ever returns an `ignored_keys` key of its own.
	 *
	 * @param mixed    $result       The ability's return value.
	 * @param string[] $ignored_keys Keys that were stripped during normalization.
	 * @return mixed The result, possibly with `ignored_keys` set.
	 */
	public static function annotate( $result, array $ignored_keys ) {
		if ( empty( $ignored_keys ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return $result;
		}
		$result['ignored_keys'] = $ignored_keys;
		return $result;
	}
}
