<?php

namespace FL\DesignSystem\Generation;

/**
 * Per-tool-block streaming tempfile lifecycle.
 *
 * The legacy generation flow buffers `input_json_delta` events in memory
 * and resolves a finalized `input` only at `content_block_stop`. The
 * structured page generator needs each `partial_json` chunk to reach the
 * client incrementally so the section-by-section progress UX can match
 * the legacy parser's cadence.
 *
 * This helper owns the per-block tempfile, keyed by job id + content
 * block index. Files live alongside the existing text tempfile under
 * `sys_get_temp_dir()` and use the `fl_ds_gen_tool_` prefix so the
 * existing orphan sweep at `GenerationJobProvider::cleanup_stale_files`
 * catches them automatically.
 */
class ToolInputStream {

	private const FILE_PREFIX = 'fl_ds_gen_tool_';

	/**
	 * Deterministic tempfile path for a (job_id, block index) pair.
	 */
	public static function path_for( string $job_id, int $idx ): string {
		return sys_get_temp_dir() . '/' . self::FILE_PREFIX . $job_id . '_' . $idx . '.json';
	}

	/**
	 * Open a per-block tempfile for append. Returns a file handle or null
	 * on failure (caller falls back to in-memory-only buffering).
	 *
	 * @return resource|null
	 */
	public static function open( string $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = @fopen( $path, 'ab' );
		return $fh ?: null;
	}

	/**
	 * Append a partial_json chunk to an open per-block tempfile and flush.
	 *
	 * @param resource $fh
	 */
	public static function write( $fh, string $chunk ): void {
		fwrite( $fh, $chunk );
		fflush( $fh );
	}

	/**
	 * Close an open per-block tempfile handle (idempotent).
	 *
	 * @param resource|null $fh
	 */
	public static function close( $fh ): void {
		if ( is_resource( $fh ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fh );
		}
	}

	/**
	 * Read content past the supplied offset for one tool block. Returns
	 * `null` when the file does not yet exist (the block has not begun
	 * streaming) and `['content' => '', 'offset' => $offset]` when there
	 * is no new content past the cursor.
	 */
	public static function read_delta( string $path, int $offset ): ?array {
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$size = filesize( $path );
		if ( $size <= $offset ) {
			return [
				'content' => '',
				'offset'  => (int) $size,
			];
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $path, false, null, $offset );
		return [
			'content' => false === $content ? '' : $content,
			'offset'  => (int) $size,
		];
	}

	/**
	 * Delete every per-block tempfile recorded on the job.
	 *
	 * Safe to call multiple times — missing files are ignored.
	 *
	 * @param array $job Job transient payload.
	 */
	public static function cleanup_for_job( array $job ): void {
		$files = $job['tool_input_files'] ?? [];
		if ( ! is_array( $files ) ) {
			return;
		}
		foreach ( $files as $info ) {
			$path = is_array( $info ) ? ( $info['path'] ?? '' ) : '';
			if ( $path && file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $path );
			}
		}
	}
}
