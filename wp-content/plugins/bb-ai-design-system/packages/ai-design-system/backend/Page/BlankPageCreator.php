<?php

namespace FL\DesignSystem\Page;

/**
 * Creates a blank draft page linked to a design system.
 *
 * Stateless: no constructor wiring needed. Both kit-driven and DS-driven
 * "new blank page" flows call this to share identical insert logic.
 *
 * Skips any importer — the page has no content but renders with the DS's
 * tokens, fonts, and base CSS via DesignSystemAssetProvider.
 */
class BlankPageCreator {

	/**
	 * Title used for "New Blank Page" drafts.
	 */
	private const BLANK_TITLE = 'Untitled';

	/**
	 * Insert a draft page and attach the DS reference.
	 *
	 * @param string $post_type Target post type.
	 * @param string $ds_uuid   DS UUID to attach.
	 * @param string $editor    Editor identifier (beaver-builder | block-editor).
	 * @return array{ postId: int, editUrl: string }|\WP_Error
	 */
	public function create( string $post_type, string $ds_uuid, string $editor ) {
		$post_id = \wp_insert_post(
			[
				'post_type'   => $post_type,
				'post_title'  => self::BLANK_TITLE,
				'post_status' => 'draft',
			],
			true
		);

		if ( \is_wp_error( $post_id ) ) {
			return $post_id;
		}

		\update_post_meta( $post_id, '_fl_ds_ref', $ds_uuid );

		if ( 'beaver-builder' === $editor && class_exists( 'FLBuilderModel' ) ) {
			\update_post_meta( $post_id, '_fl_builder_enabled', true );
		}

		$edit_url = ( 'beaver-builder' === $editor && class_exists( 'FLBuilderModel' ) )
			? (string) \FLBuilderModel::get_edit_url( $post_id )
			: (string) \get_edit_post_link( $post_id, 'raw' );

		return [
			'postId'  => (int) $post_id,
			'editUrl' => $edit_url,
		];
	}
}
