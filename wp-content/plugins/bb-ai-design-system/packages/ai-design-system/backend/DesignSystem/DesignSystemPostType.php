<?php

namespace FL\DesignSystem\DesignSystem;

use FL\DesignSystem\Font\FontEntry;

/**
 * Custom post type for storing AI-generated design systems.
 *
 * Each design system is a post with CSS in post_content, a UUID
 * in meta, and optional fonts/JS metadata.
 */
class DesignSystemPostType {

	public const POST_TYPE            = 'fl-design-system';
	public const META_UUID            = '_fl_ds_uuid';
	public const META_FONTS           = '_fl_ds_fonts';
	public const META_BASE_JS         = '_fl_ds_base_js';
	public const META_GUIDANCE        = '_fl_ds_guidance';
	public const META_BRIEF           = '_fl_ds_brief';
	public const LAST_MCP_DS_HASH_KEY = '_fl_ds_last_mcp_hash';
	public const OPTION_DEFAULT       = 'fl_ds_site_default';
	public const CAPS_VERSION         = 1;

	/**
	 * Register the post type and its meta fields.
	 */
	public function register() {
		register_post_type(
			self::POST_TYPE,
			[
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'capability_type' => 'design_system',
				'map_meta_cap'    => true,
				'supports'        => [ 'title' ],
				'labels'          => [
					'name'          => __( 'Design Systems', 'fl-design-system' ),
					'singular_name' => __( 'Design System', 'fl-design-system' ),
				],
			]
		);

		add_filter( 'fl_assistant_post_types_known', static function ( array $types ): array {
			$types[] = self::POST_TYPE;
			return $types;
		} );

		$meta_fields = [
			self::META_UUID            => [
				'type'    => 'string',
				'default' => '',
			],
			self::META_FONTS           => [
				'type'    => 'string',
				'default' => '[]',
			],
			self::META_BASE_JS         => [
				'type'    => 'string',
				'default' => '',
			],
			self::META_GUIDANCE        => [
				'type'    => 'string',
				'default' => '',
			],
			self::META_BRIEF           => [
				'type'    => 'string',
				'default' => '',
			],
			self::LAST_MCP_DS_HASH_KEY => [
				'type'    => 'string',
				'default' => '',
			],
		];

		foreach ( $meta_fields as $key => $args ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				[
					'single'       => true,
					'type'         => $args['type'],
					'default'      => $args['default'],
					'show_in_rest' => false,
				]
			);
		}
	}

	/**
	 * Grant design system capabilities to existing roles.
	 *
	 * Roles with `unfiltered_html` receive owner caps (create, edit own,
	 * delete own). Roles that also have `edit_others_posts` receive
	 * editor caps (edit/delete others, read private).
	 *
	 * Uses a version option to avoid redundant writes on every request.
	 *
	 * @param bool $force Bypass version check (used on plugin activation).
	 */
	public static function grant_caps_to_roles( bool $force = false ): void {
		$option_key = 'fl_ds_caps_version';

		if ( ! $force && (int) get_option( $option_key, 0 ) >= self::CAPS_VERSION ) {
			return;
		}

		$owner_caps = [
			'edit_design_systems',
			'edit_published_design_systems',
			'publish_design_systems',
			'delete_design_systems',
			'delete_published_design_systems',
		];

		$editor_caps = [
			'edit_others_design_systems',
			'delete_others_design_systems',
			'read_private_design_systems',
		];

		foreach ( wp_roles()->role_objects as $role ) {
			if ( ! $role->has_cap( 'unfiltered_html' ) ) {
				continue;
			}

			foreach ( $owner_caps as $cap ) {
				$role->add_cap( $cap );
			}

			if ( $role->has_cap( 'edit_others_posts' ) ) {
				foreach ( $editor_caps as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}

		update_option( $option_key, self::CAPS_VERSION );
	}

	/**
	 * Find a design system post by UUID.
	 *
	 * @param  string $uuid Design system UUID.
	 * @return \WP_Post|null
	 */
	public static function get_by_uuid( string $uuid ): ?\WP_Post {
		$posts = get_posts( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_key'       => self::META_UUID,
			'meta_value'     => $uuid,
			'no_found_rows'  => true,
		] );

		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Resolve the design system post referenced by a given page.
	 *
	 * Looks up the `_fl_ds_ref` meta on the page and returns the
	 * corresponding design system post. Returns null if the page
	 * has no reference or the referenced DS doesn't exist.
	 *
	 * @param  int $post_id Page/post ID.
	 * @return \WP_Post|null
	 */
	public static function resolve_for_post( int $post_id ): ?\WP_Post {
		if ( $post_id ) {
			$ds_ref = get_post_meta( $post_id, '_fl_ds_ref', true );
			if ( ! empty( $ds_ref ) ) {
				return self::get_by_uuid( $ds_ref );
			}
		}
		return null;
	}

	/**
	 * Get the default design system post.
	 *
	 * @return \WP_Post|null
	 */
	public static function get_default(): ?\WP_Post {
		$uuid = self::get_default_uuid();

		if ( ! $uuid ) {
			return null;
		}

		return self::get_by_uuid( $uuid );
	}

	/**
	 * Get the UUID of the default design system.
	 *
	 * @return string|null
	 */
	public static function get_default_uuid(): ?string {
		$uuid = get_option( self::OPTION_DEFAULT, '' );
		return ! empty( $uuid ) ? $uuid : null;
	}

	/**
	 * Set the default design system UUID.
	 *
	 * @param  string $uuid Design system UUID.
	 * @return bool
	 */
	public static function set_default( string $uuid ): bool {
		return update_option( self::OPTION_DEFAULT, $uuid );
	}

	/**
	 * Create a new design system post.
	 *
	 * @param  array $args {
	 *     @type string   $label  Label / title.
	 *     @type array    $tokens Token map { name => value }.
	 *     @type string   $reset  Reset CSS.
	 *     @type string   $base   Base CSS.
	 *     @type string   $css    Raw CSS (legacy — parsed into tokens/reset/base).
	 *     @type string   $js     Optional base JavaScript.
	 *     @type string[] $fonts  Font family names.
	 *     @type string   $guidance Optional freeform guidance text.
	 * }
	 * @return \WP_Post|\WP_Error
	 */
	public static function create( array $args ) {
		$uuid                 = ! empty( $args['uuid'] ) ? sanitize_text_field( $args['uuid'] ) : wp_generate_uuid4();
		$structured           = self::resolve_structured_data( $args );
		$structured['tokens'] = $structured['tokens'] + SystemTokens::DEFAULTS;

		$post_id = wp_insert_post( [
			'post_type'    => self::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => sanitize_text_field( $args['label'] ?? __( 'Untitled', 'fl-design-system' ) ),
			'post_content' => wp_slash( wp_json_encode( $structured ) ),
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_UUID, $uuid );

		if ( isset( $args['js'] ) ) {
			update_post_meta( $post_id, self::META_BASE_JS, self::sanitize_js( $args['js'] ) );
		}

		if ( isset( $args['fonts'] ) && is_array( $args['fonts'] ) ) {
			$safe_fonts = self::sanitize_font_entries( $args['fonts'] );
			update_post_meta( $post_id, self::META_FONTS, wp_json_encode( $safe_fonts ) );
		}

		if ( ! empty( $args['guidance'] ) ) {
			update_post_meta( $post_id, self::META_GUIDANCE, self::sanitize_guidance( $args['guidance'] ) );
		}

		if ( ! empty( $args['brief'] ) ) {
			update_post_meta( $post_id, self::META_BRIEF, self::sanitize_guidance( $args['brief'] ) );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'create_failed',
				__( 'Failed to retrieve created design system.', 'fl-design-system' ),
				[ 'status' => 500 ],
			);
		}

		return $post;
	}

	/**
	 * Format a design system post for REST response.
	 *
	 * Returns the structured { tokens, reset, base } shape.
	 *
	 * @param  \WP_Post $post Design system post.
	 * @return array
	 */
	public static function format_for_response( \WP_Post $post ): array {
		$fonts_raw = get_post_meta( $post->ID, self::META_FONTS, true );
		$fonts     = FontEntry::normalize( $fonts_raw );

		$structured = self::get_structured_data( $post );

		return [
			'uuid'      => get_post_meta( $post->ID, self::META_UUID, true ) ?: '',
			'label'     => $post->post_title,
			'tokens'    => $structured['tokens'],
			'reset'     => $structured['reset'],
			'base'      => $structured['base'],
			'js'        => get_post_meta( $post->ID, self::META_BASE_JS, true ) ?: '',
			'guidance'  => get_post_meta( $post->ID, self::META_GUIDANCE, true ) ?: '',
			'brief'     => get_post_meta( $post->ID, self::META_BRIEF, true ) ?: '',
			'fonts'     => $fonts,
			'systemCss' => SystemTokens::get_css_for_tokens( $structured['tokens'] ),
			'author'    => [
				'id'   => (int) $post->post_author,
				'name' => get_the_author_meta( 'display_name', $post->post_author ),
			],
			'createdAt' => gmdate( 'c', strtotime( $post->post_date_gmt ) ),
			'updatedAt' => gmdate( 'c', strtotime( $post->post_modified_gmt ) ),
		];
	}

	/**
	 * Get structured data from a design system post.
	 *
	 * Reads post_content as JSON. Falls back to parsing raw CSS for
	 * backward compatibility with pre-structured storage.
	 *
	 * @param  \WP_Post $post Design system post.
	 * @return array { tokens: array, reset: string, base: string }
	 */
	public static function get_structured_data( \WP_Post $post ): array {
		$defaults = [
			'tokens' => [],
			'reset'  => '',
			'base'   => '',
		];

		$content = $post->post_content;

		if ( empty( $content ) ) {
			return $defaults;
		}

		$decoded = json_decode( $content, true );

		if ( is_array( $decoded ) && ( isset( $decoded['tokens'] ) || isset( $decoded['reset'] ) || isset( $decoded['base'] ) ) ) {
			return [
				'tokens' => isset( $decoded['tokens'] ) && is_array( $decoded['tokens'] ) ? $decoded['tokens'] : [],
				'reset'  => isset( $decoded['reset'] ) && is_string( $decoded['reset'] ) ? $decoded['reset'] : '',
				'base'   => isset( $decoded['base'] ) && is_string( $decoded['base'] ) ? $decoded['base'] : '',
			];
		}

		// Backward compat: parse raw CSS into structured form.
		return self::parse_raw_css_to_structured( $content );
	}

	/**
	 * Parse a raw comment-marked CSS string into structured data.
	 *
	 * Used for backward compatibility with design systems stored before
	 * the structured storage migration.
	 *
	 * @param  string $css Raw comment-marked CSS.
	 * @return array { tokens: array, reset: string, base: string }
	 */
	private static function parse_raw_css_to_structured( string $css ): array {
		$sections = [
			'tokens_css' => '',
			'reset'      => '',
			'base'       => '',
		];

		// Split on comment markers.
		$marker_pattern = '/\/\*\s*(Tokens|Reset|Base|Section:\s*.+?)\s*\*\//';
		$parts          = preg_split( $marker_pattern, $css, -1, PREG_SPLIT_DELIM_CAPTURE );

		$current_key = null;

		for ( $i = 0; $i < count( $parts ); $i++ ) {
			$part = trim( $parts[ $i ] );

			if ( 'Tokens' === $part ) {
				$current_key = 'tokens_css';
			} elseif ( 'Reset' === $part ) {
				$current_key = 'reset';
			} elseif ( 'Base' === $part ) {
				$current_key = 'base';
			} elseif ( $current_key && ! preg_match( '/^Section:/', $part ) ) {
				$sections[ $current_key ] = trim( $part );
				$current_key              = null;
			}
		}

		// Parse tokens from the :root block.
		$tokens = self::parse_tokens_from_css( $sections['tokens_css'] );

		return [
			'tokens' => $tokens,
			'reset'  => $sections['reset'],
			'base'   => $sections['base'],
		];
	}

	/**
	 * Parse CSS custom properties from a :root block.
	 *
	 * @param  string $css CSS containing a :root { ... } block.
	 * @return array Token map { name => value }.
	 */
	private static function parse_tokens_from_css( string $css ): array {
		$tokens = [];

		if ( preg_match( '/:root\s*\{([\s\S]*)\}/', $css, $root_match ) ) {
			// Strip comments.
			$body = preg_replace( '/\/\*[\s\S]*?\*\//', '', $root_match[1] );

			if ( preg_match_all( '/(--[\w-]+)\s*:\s*([^;]+)/', $body, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$tokens[ trim( $match[1] ) ] = trim( $match[2] );
				}
			}
		}

		return $tokens;
	}

	/**
	 * Reconstruct comment-marked CSS from structured data.
	 *
	 * @param  array $data { tokens: array, reset: string, base: string }
	 * @return string Comment-marked CSS string.
	 */
	public static function reconstruct_css( array $data ): string {
		$parts = [];

		$tokens = $data['tokens'] ?? [];
		$reset  = $data['reset'] ?? '';
		$base   = $data['base'] ?? '';

		if ( ! empty( $tokens ) ) {
			$lines = [];
			foreach ( $tokens as $name => $value ) {
				$lines[] = '  ' . $name . ': ' . $value . ';';
			}
			$parts[] = "/* Tokens */\n:root {\n" . implode( "\n", $lines ) . "\n}";
		}

		if ( '' !== $reset ) {
			$parts[] = "/* Reset */\n" . $reset;
		}

		if ( '' !== $base ) {
			$parts[] = "/* Base */\n" . $base;
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Resolve structured data from input args.
	 *
	 * Accepts either structured { tokens, reset, base } or raw CSS.
	 * If raw CSS is provided and no structured fields exist, parses
	 * the CSS into structured form.
	 *
	 * @param  array $args Input arguments.
	 * @return array { tokens: array, reset: string, base: string }
	 */
	public static function resolve_structured_data( array $args ): array {
		$has_structured = isset( $args['tokens'] ) || isset( $args['reset'] ) || isset( $args['base'] );

		if ( $has_structured ) {
			return [
				'tokens' => isset( $args['tokens'] ) && is_array( $args['tokens'] )
					? array_map( 'sanitize_text_field', $args['tokens'] )
					: [],
				'reset'  => self::sanitize_css( $args['reset'] ?? '' ),
				'base'   => self::sanitize_css( $args['base'] ?? '' ),
			];
		}

		// Legacy: raw CSS input.
		if ( isset( $args['css'] ) ) {
			return self::parse_raw_css_to_structured( self::sanitize_css( $args['css'] ) );
		}

		return [
			'tokens' => [],
			'reset'  => '',
			'base'   => '',
		];
	}

	/**
	 * Sanitize CSS content for storage.
	 *
	 * Strips HTML tags and dangerous CSS patterns.
	 *
	 * @param  string $css Raw CSS.
	 * @return string Sanitized CSS.
	 */
	public static function sanitize_css( string $css ): string {
		$css = wp_strip_all_tags( $css );
		$css = preg_replace( '/@import\b/i', '', $css );
		$css = preg_replace( '/expression\s*\(/i', '', $css );
		$css = preg_replace( '/javascript\s*:/i', '', $css );
		return $css;
	}

	/**
	 * Sanitize JavaScript content for storage.
	 *
	 * Strips `<script>` and `<style>` blocks (and stray opening/closing tokens
	 * of either) so accidental HTML can't piggyback on stored JS. Bare `<` is
	 * preserved — JS comparison operators (`for (var i = 0; i < n; i++)`) must
	 * survive intact. Stripping `</script>` matters because stored JS is
	 * rendered inside an inline `<script>...</script>` wrapper at output time
	 * (see Rendering\JsShim::wrap_base_js), and `</script>` is the only token
	 * that can break out of that context.
	 *
	 * @param  string $js Raw JavaScript.
	 * @return string Sanitized JavaScript.
	 */
	public static function sanitize_js( string $js ): string {
		$js = preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $js );
		$js = preg_replace( '#</?(script|style)\b[^>]*>?#i', '', $js );
		return $js;
	}

	/**
	 * Store guidance/brief prose as-is.
	 *
	 * Guidance and brief are markdown prose intended for LLM consumption,
	 * not browser-rendered HTML. They contain markdown structures like
	 * inline-code spans (`<html>`), fenced code blocks holding HTML
	 * examples (`<link>`), and prose with `&`, `<`, `>` characters that
	 * are not HTML. Running `wp_kses_post` here was corrupting all of
	 * these (stripping tag-like text inside backticks, dropping non-allowed
	 * tags from code fences, and entity-encoding prose characters).
	 *
	 * Safety is enforced at the render boundary instead: the chat UI
	 * renders guidance as a React text node (auto-escaped), and the REST
	 * response strips the field for non-author callers
	 * (see DesignSystemProvider::format_for_response). A future markdown
	 * renderer should sanitize its HTML output, not the input markdown.
	 *
	 * Method retained as a single seam in case hygiene is needed later.
	 *
	 * @param  string $value Raw guidance or brief text.
	 * @return string The input, unchanged.
	 */
	public static function sanitize_guidance( string $value ): string {
		return $value;
	}

	/**
	 * Sanitize and normalize a raw fonts input into the canonical storage shape.
	 *
	 * Accepts the legacy string[] and the new {family, variants}[] shape.
	 * Each family is run through `sanitize_text_field`; each variants string
	 * is restricted to the character set Google Fonts actually uses for the
	 * post-colon URL segment (letters, digits, `_`, `,`, `;`, `.`, `@`, `:`).
	 * Anything else is stripped.
	 *
	 * @param  mixed $raw Raw fonts value.
	 * @return array<int, array{family: string, variants: string}>
	 */
	public static function sanitize_font_entries( $raw ): array {
		$entries = FontEntry::normalize( $raw );
		$safe    = [];
		foreach ( $entries as $entry ) {
			$family   = sanitize_text_field( $entry['family'] );
			$variants = preg_replace( '/[^A-Za-z0-9_,;.@:]/', '', $entry['variants'] );
			if ( '' === $family ) {
				continue;
			}
			$safe[] = [
				'family'   => $family,
				'variants' => $variants,
			];
		}
		return $safe;
	}
}
