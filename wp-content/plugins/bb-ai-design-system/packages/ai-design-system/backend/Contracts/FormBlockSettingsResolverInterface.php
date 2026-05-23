<?php

namespace FL\DesignSystem\Contracts;

/**
 * Contract for resolving the saved settings of a rendered block.
 *
 * Form submissions include a block_id, and the server needs to look
 * up that block's saved settings to find the form's action config at
 * `settings.{form_id}.actions`. Resolution is platform-specific
 * (BB layout data vs. block editor post content), so this is an
 * injectable interface.
 */
interface FormBlockSettingsResolverInterface {

	/**
	 * Resolve the saved settings object for a block.
	 *
	 * The optional `$context` array carries side-channel hints from the
	 * submission — most importantly `post_id`, which lets an integration
	 * look up layout data for a single post instead of scanning recent
	 * posts. Context keys are advisory: implementations must still work
	 * when none are present.
	 *
	 * @param string $block_id Opaque block identifier supplied by the frontend.
	 * @param array  $context  Optional hints (e.g. [ 'post_id' => 123 ]).
	 * @return array|null The flat settings associative array, or null if the
	 *                    block cannot be found.
	 */
	public function resolve( string $block_id, array $context = [] ): ?array;
}
