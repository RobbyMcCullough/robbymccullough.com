<?php

namespace FL\DesignSystem\DesignSystem;

/**
 * System token defaults and their associated CSS rules.
 *
 * System tokens use the `--ds-system-` prefix and are injected at
 * design system creation time. They behave like regular tokens once
 * stored but have special handling for AI updates and CSS output.
 */
class SystemTokens {

	/**
	 * Default values for system tokens.
	 *
	 * Merged into the token set when a new design system is created.
	 */
	const DEFAULTS = [
		'--ds-system-root-font-size' => '16px',
	];

	/**
	 * CSS rules keyed by the system token that triggers them.
	 *
	 * A rule is only output when its corresponding token exists
	 * in the design system's token set.
	 */
	const CSS_RULES = [
		'--ds-system-root-font-size' => 'html { font-size: var(--ds-system-root-font-size); }',
	];

	/**
	 * Build CSS rules for system tokens present in the given token map.
	 *
	 * Iterates CSS_RULES keys and appends each rule whose token exists
	 * in the provided token set.
	 *
	 * @param array $tokens Token map { name => value }.
	 * @return string Concatenated CSS rules, or empty string if none apply.
	 */
	public static function get_css_for_tokens( array $tokens ): string {
		$css = '';

		foreach ( self::CSS_RULES as $token_name => $rule ) {
			if ( array_key_exists( $token_name, $tokens ) ) {
				$css .= $rule . "\n";
			}
		}

		return $css;
	}
}
