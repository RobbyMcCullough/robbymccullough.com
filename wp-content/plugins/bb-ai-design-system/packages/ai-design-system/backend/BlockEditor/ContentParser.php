<?php

namespace FL\DesignSystem\BlockEditor;

/**
 * Parses fl-ds/custom block inner content into definition parts.
 *
 * The block stores its definition (template, CSS, JS, form config) as
 * structured HTML in the block's innerHTML. This class extracts each
 * section from the raw string.
 */
class ContentParser {

	/**
	 * Parse raw innerHTML into definition parts.
	 *
	 * @param  string $content Raw innerHTML from the block.
	 * @return array{template: string, css: string, js: string, form: array}
	 */
	public static function parse( string $content ): array {
		$result = [
			'template' => '',
			'css'      => '',
			'js'       => '',
			'form'     => [],
		];

		if ( empty( $content ) ) {
			return $result;
		}

		if ( preg_match( '/<template>\n?([\s\S]*?)\n?<\/template>/', $content, $match ) ) {
			$result['template'] = $match[1];
		}

		if ( preg_match( '/<style>([\s\S]*?)<\/style>/', $content, $match ) ) {
			$result['css'] = $match[1];
		}

		if ( preg_match( '/<script type="text\/fl-ds">([\s\S]*?)<\/script>/', $content, $match ) ) {
			$result['js'] = $match[1];
		}

		if ( preg_match( '/<script type="application\/json" data-type="form">\n?([\s\S]*?)\n?<\/script>/', $content, $match ) ) {
			$decoded = json_decode( $match[1], true );
			if ( is_array( $decoded ) ) {
				$result['form'] = $decoded;
			}
		}

		return $result;
	}
}
