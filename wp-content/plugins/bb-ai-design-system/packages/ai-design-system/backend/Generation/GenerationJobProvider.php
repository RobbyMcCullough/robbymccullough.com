<?php

namespace FL\DesignSystem\Generation;

use FL\DesignSystem\Contracts\AuthInterface;
use FL\DesignSystem\Contracts\SettingsStoreInterface;
use FL\DesignSystem\Usage\TokenUsageTable;

/**
 * Background generation job system.
 *
 * Decouples the browser connection from the Claude API call for page
 * generation. A background PHP worker calls Claude server-to-server
 * (bypassing reverse proxies) and writes streamed text to a temp file.
 * The frontend polls for new content every few seconds.
 */
class GenerationJobProvider {

	public const API_URL     = 'https://api.anthropic.com/v1/messages';
	public const API_VERSION = '2023-06-01';

	private const TRANSIENT_PREFIX = 'fl_ds_gen_';
	private const TRANSIENT_TTL    = 900; // 15 minutes

	private SettingsStoreInterface $settings;
	private AuthInterface $auth;

	public function __construct( SettingsStoreInterface $settings, AuthInterface $auth ) {
		$this->settings = $settings;
		$this->auth     = $auth;
	}

	public function boot() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'fl_ds_cleanup_generation_files', [ __CLASS__, 'cleanup_stale_files' ] );
		add_action( 'fl_ds_cleanup_token_usage', [ TokenUsageTable::class, 'prune' ] );

		if ( ! wp_next_scheduled( 'fl_ds_cleanup_generation_files' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'fl_ds_cleanup_generation_files' );
		}

		if ( ! wp_next_scheduled( 'fl_ds_cleanup_token_usage' ) ) {
			wp_schedule_event( time(), 'daily', 'fl_ds_cleanup_token_usage' );
		}
	}

	public function register_routes() {
		$job_id_pattern = '(?P<job_id>[a-zA-Z0-9_-]+)';

		register_rest_route( 'fl-design-system/v1', '/generate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_start' ],
			'permission_callback' => [ $this->auth, 'content_creator_permission_callback' ],
		] );

		register_rest_route( 'fl-design-system/v1', '/generate/' . $job_id_pattern, [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_poll' ],
			'permission_callback' => [ $this->auth, 'content_creator_permission_callback' ],
		] );

		register_rest_route( 'fl-design-system/v1', '/generate/' . $job_id_pattern, [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'handle_cancel' ],
			'permission_callback' => [ $this->auth, 'content_creator_permission_callback' ],
		] );
	}

	/**
	 * Start a background generation job.
	 *
	 * Validates the request, creates a job, sends the response to the
	 * browser, then continues executing the Claude API call in the same
	 * PHP process after the connection is closed.
	 */
	public function handle_start( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		// M-15: per-user rate limit (30/min) atomically against the
		// throttle table. INSERT-then-UPDATE-with-cap-predicate so
		// parallel POSTs cannot all observe the pre-increment count.
		if ( ! ThrottleTable::consume_rate_limit_slot( (int) $user_id, MINUTE_IN_SECONDS, 30 ) ) {
			header( 'Retry-After: ' . MINUTE_IN_SECONDS );
			return new \WP_Error(
				'rate_limited',
				__( 'Too many requests. Please wait a moment.', 'fl-design-system' ),
				[ 'status' => 429 ],
			);
		}

		// Allow external code to block generation (e.g. quota enforcement).
		$can_generate = apply_filters( 'fl_ds_can_generate', true, $user_id );
		if ( is_wp_error( $can_generate ) ) {
			return $can_generate;
		}
		if ( false === $can_generate ) {
			return new \WP_Error(
				'generation_blocked',
				__( 'You are not allowed to generate content.', 'fl-design-system' ),
				[ 'status' => 403 ],
			);
		}

		// M-15: atomic concurrency reservation. Cap of 3 active jobs.
		// Strategy mirrors the rate-limit slot: INSERT the row at count=1
		// or UPDATE with `WHERE count < 3`. Either path returns true only
		// when the increment actually landed.
		if ( ! ThrottleTable::consume_concurrency_slot( (int) $user_id, 3 ) ) {
			return new \WP_Error(
				'concurrency_limit',
				__( 'Too many active generations. Please wait for one to complete.', 'fl-design-system' ),
				[ 'status' => 429 ],
			);
		}

		$api_key = $this->settings->get( 'ai.api_key' );
		if ( ! $api_key ) {
			return new \WP_Error(
				'missing_api_key',
				__( 'No AI API key configured. Add one in Design System settings.', 'fl-design-system' ),
				[ 'status' => 400 ],
			);
		}

		$input = json_decode( $request->get_body() );
		if ( ! $input || empty( $input->messages ) ) {
			return new \WP_Error(
				'missing_messages',
				__( 'Request must include messages.', 'fl-design-system' ),
				[ 'status' => 400 ],
			);
		}

		// Sanitize the request body — only allowed fields are forwarded.
		$clean           = new \stdClass();
		$clean->messages = $input->messages;

		// Use the client-requested model if it exists in the registry (e.g. Haiku
		// for summarization). Fall back to the configured default otherwise.
		$default_model = $this->settings->get( 'ai.model' );
		$default_model = $default_model ? $default_model : 'claude-sonnet-4-6';

		$client_model = isset( $input->model ) ? $input->model : null;
		$clean->model = ( $client_model && ModelRegistry::get( $client_model ) )
			? $client_model
			: $default_model;

		$max_tokens        = isset( $input->max_tokens ) ? min( (int) $input->max_tokens, 70000 ) : 8192;
		$clean->max_tokens = $max_tokens;

		// Opt-in: stream `input_json_delta` chunks for tool-use blocks to a
		// per-block tempfile so the JS client can drive a partial-JSON parser
		// during the stream. Default off; existing callers see no change.
		$stream_tool_input = isset( $input->streamToolInput ) && (bool) $input->streamToolInput;

		if ( isset( $input->tools ) ) {
			$clean->tools = $input->tools;
		}
		if ( isset( $input->tool_choice ) ) {
			$clean->tool_choice = $input->tool_choice;
		}
		if ( isset( $input->system ) ) {
			$clean->system = $input->system;
		}
		if ( isset( $input->thinking ) ) {
			$clean->thinking = $input->thinking;
		}

		// Always stream from Claude so the worker can write progressively.
		$clean->stream = true;
		$clean_body    = json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		// Create job.
		$job_id = wp_generate_uuid4();
		$file   = tempnam( sys_get_temp_dir(), 'fl_ds_gen_' );

		$job = [
			'status'            => 'running',
			'file'              => $file,
			'body'              => $clean_body,
			'stop_reason'       => null,
			'error'             => null,
			'user_id'           => $user_id,
			'stream_tool_input' => $stream_tool_input,
			// Map of content-block index => ['path' => string, 'name' => string].
			// Populated lazily by execute_generation when a tool_use block starts
			// and `stream_tool_input` is on; consumed by handle_poll.
			'tool_input_files'  => [],
		];
		set_transient( self::TRANSIENT_PREFIX . $job_id, $job, self::TRANSIENT_TTL );

		// M-15: token reservation. Counts the upper-bound cost of this
		// generation against the user's quota until the job completes.
		// Released by `release_slot` on every exit path; TTL fallback is
		// 5 minutes (RESERVATION_TTL) for crash safety.
		ThrottleTable::record_reservation( (int) $user_id, $job_id, (int) $max_tokens );

		$this->dispatch_started_job( $job_id, $job, $api_key, $user_id );

		exit;
	}

	/**
	 * Send the queued-job response, close the browser connection, and run
	 * the Claude API call in the same process.
	 *
	 * Extracted from handle_start so tests can subclass and stub the live
	 * cURL + connection-close path while still exercising the full
	 * request → persisted-transient mapping seam.
	 *
	 * @param string $job_id
	 * @param array  $job
	 * @param string $api_key
	 * @param int    $user_id
	 */
	protected function dispatch_started_job( string $job_id, array $job, string $api_key, int $user_id ): void {
		// We bypass WordPress's REST response handling and send manually
		// so we can set Connection: close + Content-Length. This tells
		// the web server to close the browser connection immediately
		// after receiving the response body, even though PHP continues.
		$response_body = wp_json_encode( [ 'jobId' => $job_id ] );

		http_response_code( 200 );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Length: ' . strlen( $response_body ) );
		header( 'Connection: close' );

		// Clear any output buffers so the response reaches the server.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		echo $response_body;
		flush();

		// On PHP-FPM, explicitly close the FastCGI connection.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}

		// Continue with the Claude API call after the browser is disconnected.
		@set_time_limit( 660 );
		ignore_user_abort( true );

		$body        = $job['body'];
		$job['body'] = null;
		set_transient( self::TRANSIENT_PREFIX . $job_id, $job, self::TRANSIENT_TTL );

		$this->execute_generation( $job_id, $job, $body, $api_key, $user_id );
	}

	/**
	 * Poll for generation progress.
	 *
	 * Returns new content since the given offset and the current job status.
	 */
	public function handle_poll( \WP_REST_Request $request ) {
		$job_id              = $request->get_param( 'job_id' );
		$offset              = (int) $request->get_param( 'offset' );
		$tool_input_offsets  = $this->parse_tool_input_offsets( $request->get_param( 'toolInputOffsets' ) );
		$job                 = get_transient( self::TRANSIENT_PREFIX . $job_id );

		if ( ! $job ) {
			return new \WP_Error(
				'job_not_found',
				__( 'Generation job not found or expired.', 'fl-design-system' ),
				[ 'status' => 404 ],
			);
		}

		// M-7: ownership check. Return 404 (not 403) on cross-user access
		// so we do not confirm the existence of someone else's job_id.
		// Server-side log captures the real reason for support debugging.
		if ( ! $this->user_owns_job( $job ) ) {
			$this->log_cross_user_access( $job_id, $job, 'poll' );
			return new \WP_Error(
				'job_not_found',
				__( 'Generation job not found or expired.', 'fl-design-system' ),
				[ 'status' => 404 ],
			);
		}

		$content = '';
		$file    = $job['file'] ?? '';

		if ( $file && file_exists( $file ) ) {
			$size = filesize( $file );
			if ( $size > $offset ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$content = file_get_contents( $file, false, null, $offset );
				$offset  = $size;
			}
		}

		$status = $job['status'] ?? 'pending';
		$done   = in_array( $status, [ 'complete', 'error', 'cancelled' ], true );

		$response = [
			'status'                   => $status,
			'content'                  => $content,
			'offset'                   => $offset,
			'done'                     => $done,
			'stopReason'               => $job['stop_reason'] ?? null,
			'error'                    => $job['error'] ?? null,
			'toolCalls'                => $job['tool_calls'] ?? [],
			'inputTokens'              => $job['input_tokens'] ?? null,
			'outputTokens'             => $job['output_tokens'] ?? null,
			'cost'                     => $job['cost'] ?? null,
			'cacheCreationInputTokens' => $job['cache_creation_input_tokens'] ?? null,
			'cacheReadInputTokens'     => $job['cache_read_input_tokens'] ?? null,
		];

		// Streaming tool-input deltas — additive field. Only present when the
		// job opted in via `streamToolInput` and at least one tool block has
		// begun streaming. Each entry carries its own offset cursor so the
		// client can track multiple concurrent tool blocks independently.
		$tool_input_deltas = $this->collect_tool_input_deltas( $job, $tool_input_offsets );
		if ( ! empty( $tool_input_deltas ) ) {
			$response['toolInputDeltas'] = $tool_input_deltas;
		}

		// Clean up temp file, transient, and active jobs tracking when finished.
		if ( $done ) {
			if ( $file && file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $file );
			}
			ToolInputStream::cleanup_for_job( $job );
			delete_transient( self::TRANSIENT_PREFIX . $job_id );
			$this->release_slot( (int) ( $job['user_id'] ?? get_current_user_id() ), $job_id );
		}

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Parse the per-tool-block offset map from the poll request.
	 *
	 * Accepts either a JSON-encoded string (`{"0":50,"1":30}`) or an array
	 * (PHP-style nested params). Unknown / malformed input falls back to an
	 * empty map so a poll without offsets returns full chunks from offset 0.
	 *
	 * @param mixed $raw
	 * @return array<int,int>
	 */
	private function parse_tool_input_offsets( $raw ): array {
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : [];
		}
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$offsets = [];
		foreach ( $raw as $idx => $offset ) {
			if ( is_numeric( $idx ) && is_numeric( $offset ) ) {
				$offsets[ (int) $idx ] = (int) $offset;
			}
		}
		return $offsets;
	}

	/**
	 * Build the `toolInputDeltas` response chunk by reading new content past
	 * the supplied per-block offsets. Returns an empty array when the job has
	 * not started any tool block yet (legacy callers see no shape change).
	 *
	 * @param array            $job
	 * @param array<int,int>   $offsets
	 * @return array
	 */
	private function collect_tool_input_deltas( array $job, array $offsets ): array {
		$files = $job['tool_input_files'] ?? [];
		if ( ! is_array( $files ) || empty( $files ) ) {
			return [];
		}
		$deltas = [];
		foreach ( $files as $idx => $info ) {
			if ( ! is_array( $info ) || empty( $info['path'] ) ) {
				continue;
			}
			$idx_int   = (int) $idx;
			$req_off   = $offsets[ $idx_int ] ?? 0;
			$delta     = ToolInputStream::read_delta( $info['path'], $req_off );
			if ( null === $delta ) {
				continue;
			}
			$deltas[] = [
				'index'   => $idx_int,
				'name'    => (string) ( $info['name'] ?? '' ),
				'content' => $delta['content'],
				'offset'  => $delta['offset'],
			];
		}
		return $deltas;
	}

	/**
	 * Release the user's concurrency slot and token reservation.
	 *
	 * Safe to call multiple times for the same job (idempotent). The slot is
	 * released here — not only in handle_poll() — so client polling failures
	 * (panel close, navigate, cancel) don't leak concurrency slots.
	 *
	 * M-15: backed by atomic SQL counters in {@see ThrottleTable} rather
	 * than the previous transient list. The release path is split:
	 * concurrency decrements by exactly one; the token reservation row is
	 * deleted by job_id so a stale increment cannot underflow the count.
	 */
	private function release_slot( int $user_id, string $job_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		ThrottleTable::release_concurrency_slot( $user_id );
		ThrottleTable::release_reservation( $user_id, $job_id );
	}

	/**
	 * Cancel a running job.
	 */
	public function handle_cancel( \WP_REST_Request $request ) {
		$job_id = $request->get_param( 'job_id' );
		$job    = get_transient( self::TRANSIENT_PREFIX . $job_id );

		if ( ! $job ) {
			return new \WP_Error(
				'job_not_found',
				__( 'Generation job not found or expired.', 'fl-design-system' ),
				[ 'status' => 404 ],
			);
		}

		// M-7: ownership check. Same 404-with-server-log shape as the
		// poll path so an attacker cannot enumerate another user's job_ids.
		if ( ! $this->user_owns_job( $job ) ) {
			$this->log_cross_user_access( $job_id, $job, 'cancel' );
			return new \WP_Error(
				'job_not_found',
				__( 'Generation job not found or expired.', 'fl-design-system' ),
				[ 'status' => 404 ],
			);
		}

		// Mark cancelled so the in-flight worker's chunk check aborts cURL.
		// The worker also treats a missing transient as cancelled, so deletion
		// order below is safe — either signal causes the worker to stop.
		$job['status'] = 'cancelled';
		set_transient( self::TRANSIENT_PREFIX . $job_id, $job, self::TRANSIENT_TTL );

		$file = $job['file'] ?? '';
		if ( $file && file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $file );
		}
		ToolInputStream::cleanup_for_job( $job );

		// Release the slot immediately. Without this, the slot stays reserved
		// until the job transient's TTL expires (client has aborted and won't
		// poll to `done`), causing 429 concurrency_limit on subsequent sends.
		$this->release_slot( (int) ( $job['user_id'] ?? get_current_user_id() ), $job_id );
		delete_transient( self::TRANSIENT_PREFIX . $job_id );

		return new \WP_REST_Response( [ 'cancelled' => true ], 200 );
	}

	/**
	 * Calls Claude's API with streaming and writes text content to the temp file.
	 */
	public function execute_generation( string $job_id, array $job, string $body, string $api_key, int $user_id = 0 ) {
		$file = $job['file'] ?? '';
		if ( ! $file ) {
			$this->fail_job( $job_id, $job, 'No temp file for job.', $user_id );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( $file, 'ab' );
		if ( ! $fh ) {
			$this->fail_job( $job_id, $job, 'Could not open temp file for writing.', $user_id );
			return;
		}

		$sse_buffer         = '';
		$stop_reason        = null;
		$error_msg          = null;
		$chunk_count        = 0;
		$tool_calls         = [];
		$current_tool_index = -1;
		$tool_input_buffers = [];
		$message_started    = false;
		$input_tokens       = 0;
		$output_tokens      = 0;
		// Anthropic prompt-caching counters — passthrough only (PR 7A).
		$cache_creation_input_tokens = 0;
		$cache_read_input_tokens     = 0;

		// Per-tool-block streaming state. Only populated when the job opted in
		// via `stream_tool_input`; otherwise the legacy in-memory-only buffer
		// at `$tool_input_buffers` is the only path.
		$stream_tool_input  = ! empty( $job['stream_tool_input'] );
		$tool_input_handles = []; // [idx => resource]
		$tool_input_paths   = []; // [idx => ['path' => string, 'name' => string]]

		$ch = curl_init( self::API_URL );

		curl_setopt_array( $ch, [
			CURLOPT_POST            => true,
			CURLOPT_POSTFIELDS      => $body,
			CURLOPT_HTTPHEADER      => [
				'Content-Type: application/json',
				'x-api-key: ' . $api_key,
				'anthropic-version: ' . self::API_VERSION,
			],
			CURLOPT_TIMEOUT         => 600,
			CURLOPT_LOW_SPEED_LIMIT => 1,
			CURLOPT_LOW_SPEED_TIME  => 60,
			CURLOPT_RETURNTRANSFER  => false,
			CURLOPT_WRITEFUNCTION   => function (
				$ch,
				$chunk
			) use (
				$fh,
				&$sse_buffer,
				&$stop_reason,
				&$error_msg,
				&$chunk_count,
				&$tool_calls,
				&$current_tool_index,
				&$tool_input_buffers,
				&$message_started,
				$job_id,
				&$input_tokens,
				&$output_tokens,
				&$cache_creation_input_tokens,
				&$cache_read_input_tokens,
				$stream_tool_input,
				&$tool_input_handles,
				&$tool_input_paths
			) {
				$sse_buffer .= $chunk;

				// Parse complete SSE events (separated by double newlines).
				$parts      = explode( "\n\n", $sse_buffer );
				$sse_buffer = array_pop( $parts );

				foreach ( $parts as $part ) {
					$part = trim( $part );
					if ( '' === $part ) {
						continue;
					}

					$event = '';
					$data  = '';

					foreach ( explode( "\n", $part ) as $line ) {
						if ( 0 === strpos( $line, 'event:' ) ) {
							$event = trim( substr( $line, 6 ) );
						} elseif ( 0 === strpos( $line, 'data:' ) ) {
							$data = trim( substr( $line, 5 ) );
						}
					}

					if ( '' === $data ) {
						continue;
					}

					$decoded = json_decode( $data );
					if ( ! $decoded ) {
						continue;
					}

					// Track when a real message has started so we don't write
					// raw JSON error responses to the temp file.
					if ( 'message_start' === $event || 'content_block_start' === $event ) {
						$message_started = true;
					}

					// Capture input token count from message_start.
					if ( 'message_start' === $event && isset( $decoded->message->usage->input_tokens ) ) {
						$input_tokens = (int) $decoded->message->usage->input_tokens;
					}

					// Anthropic prompt-caching counters (PR 7A passthrough).
					if ( 'message_start' === $event && isset( $decoded->message->usage->cache_creation_input_tokens ) ) {
						$cache_creation_input_tokens = (int) $decoded->message->usage->cache_creation_input_tokens;
					}
					if ( 'message_start' === $event && isset( $decoded->message->usage->cache_read_input_tokens ) ) {
						$cache_read_input_tokens = (int) $decoded->message->usage->cache_read_input_tokens;
					}

					// Text content — write to temp file for progressive polling.
					if (
						$message_started
						&& 'content_block_delta' === $event
						&& isset( $decoded->delta->type )
						&& 'text_delta' === $decoded->delta->type
						&& isset( $decoded->delta->text )
					) {
						fwrite( $fh, $decoded->delta->text );
						fflush( $fh );
					}

					// Tool use — track content blocks for chat agent support.
					if (
						'content_block_start' === $event
						&& isset( $decoded->content_block->type )
						&& 'tool_use' === $decoded->content_block->type
					) {
						$idx                        = $decoded->index;
						$tool_calls[ $idx ]         = [
							'id'    => $decoded->content_block->id,
							'name'  => $decoded->content_block->name,
							'input' => null,
						];
						$tool_input_buffers[ $idx ] = '';
						$current_tool_index         = $idx;

						// When the job opted into tool-input streaming, open a
						// per-block tempfile and record its path on the job
						// transient so handle_poll can serve incremental
						// `partial_json` chunks during the stream.
						if ( $stream_tool_input ) {
							$path = ToolInputStream::path_for( $job_id, (int) $idx );
							$th   = ToolInputStream::open( $path );
							if ( $th ) {
								$tool_input_handles[ $idx ] = $th;
								$tool_input_paths[ $idx ]   = [
									'path' => $path,
									'name' => $decoded->content_block->name,
								];
								$current = get_transient( self::TRANSIENT_PREFIX . $job_id );
								if ( $current && 'cancelled' !== ( $current['status'] ?? '' ) ) {
									$current['tool_input_files']        = $current['tool_input_files'] ?? [];
									$current['tool_input_files'][ $idx ] = $tool_input_paths[ $idx ];
									set_transient( self::TRANSIENT_PREFIX . $job_id, $current, self::TRANSIENT_TTL );
								}
							}
						}
					}

					// Tool input JSON fragments.
					if (
						'content_block_delta' === $event
						&& isset( $decoded->delta->type )
						&& 'input_json_delta' === $decoded->delta->type
						&& isset( $decoded->delta->partial_json )
					) {
						$idx = $decoded->index;
						if ( isset( $tool_input_buffers[ $idx ] ) ) {
							$tool_input_buffers[ $idx ] .= $decoded->delta->partial_json;
						}
						// Tee the chunk to the per-block tempfile when streaming
						// is on. The in-memory buffer above stays as the single
						// source of truth for the finalized `tool_calls[].input`
						// resolved at content_block_stop.
						if ( $stream_tool_input && isset( $tool_input_handles[ $idx ] ) ) {
							ToolInputStream::write( $tool_input_handles[ $idx ], $decoded->delta->partial_json );
						}
					}

					// Finalize tool input on block stop.
					if ( 'content_block_stop' === $event && isset( $decoded->index ) ) {
						$idx = $decoded->index;
						if ( isset( $tool_calls[ $idx ] ) && isset( $tool_input_buffers[ $idx ] ) ) {
							$parsed_input                = json_decode( $tool_input_buffers[ $idx ] );
							$tool_calls[ $idx ]['input'] = $parsed_input ?: new \stdClass();
							unset( $tool_input_buffers[ $idx ] );
						}
						if ( isset( $tool_input_handles[ $idx ] ) ) {
							ToolInputStream::close( $tool_input_handles[ $idx ] );
							unset( $tool_input_handles[ $idx ] );
						}
					}

					if (
						'message_delta' === $event
						&& isset( $decoded->delta->stop_reason )
					) {
						$stop_reason = $decoded->delta->stop_reason;
					}

					// Capture output token count from message_delta.
					if ( 'message_delta' === $event && isset( $decoded->usage->output_tokens ) ) {
						$output_tokens = (int) $decoded->usage->output_tokens;
					}

					if ( 'error' === $event && isset( $decoded->error->message ) ) {
						$error_msg = $decoded->error->message;
					}
				}

				// Periodically check for cancellation. A missing transient is
				// treated the same as status='cancelled' so handle_cancel() can
				// delete the transient without racing the worker.
				$chunk_count++;
				if ( 0 === $chunk_count % 50 ) {
					$current = get_transient( self::TRANSIENT_PREFIX . $job_id );
					if ( ! $current || 'cancelled' === ( $current['status'] ?? '' ) ) {
						// Returning a length mismatch aborts the cURL transfer.
						return 0;
					}
				}

				return strlen( $chunk );
			},
		] );

		$http_status = 0;
		curl_setopt( $ch, CURLOPT_HEADERFUNCTION, function ( $ch, $header ) use ( &$http_status ) {
			if ( preg_match( '/^HTTP\/\S+\s+(\d+)/', $header, $matches ) ) {
				$http_status = (int) $matches[1];
			}
			return strlen( $header );
		} );

		$result = curl_exec( $ch );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fh );

		// Close any per-block tool input handles still open (e.g. stream
		// aborted before content_block_stop fired for every block).
		foreach ( $tool_input_handles as $idx => $th ) {
			ToolInputStream::close( $th );
		}
		$tool_input_handles = [];

		// Check for cURL-level errors.
		if ( false === $result && ! $error_msg ) {
			$curl_error = curl_error( $ch );
			if ( $curl_error ) {
				$error_msg = $curl_error;
			}
		}

		// Check for HTTP-level errors.
		if ( $http_status >= 400 && ! $error_msg ) {
			$error_msg = "API returned HTTP $http_status";

			// For non-SSE error responses, the upstream JSON body never
			// reaches the temp file because the WRITEFUNCTION only flushes
			// content past `\n\n` SSE boundaries. The body sits in
			// $sse_buffer instead — fall back to it so we can surface a
			// useful error.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$raw = file_get_contents( $file );
			if ( ! $raw && '' !== $sse_buffer ) {
				$raw = $sse_buffer;
			}
			if ( $raw ) {
				$decoded = json_decode( $raw );
				if ( isset( $decoded->error->message ) ) {
					$error_msg = $decoded->error->message;
				} else {
					// Non-standard error shape (or empty) — include a trimmed
					// raw body so the failure is self-diagnosing instead of
					// just "HTTP $code".
					$error_msg = "API returned HTTP $http_status: " . substr( (string) $raw, 0, 500 );
				}
			}
		}

		curl_close( $ch );

		// Update job state.
		$job = get_transient( self::TRANSIENT_PREFIX . $job_id );

		// Transient missing = handle_cancel() already released the slot and
		// deleted the job. Nothing left to do.
		if ( ! $job ) {
			$this->release_slot( $user_id, $job_id );
			return;
		}

		// Don't overwrite a cancellation — clean up the temp file.
		if ( 'cancelled' === $job['status'] ) {
			if ( $file && file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $file );
			}
			ToolInputStream::cleanup_for_job( $job );
			$this->release_slot( $user_id, $job_id );
			return;
		}

		// Extract model from the request body for usage tracking.
		$model        = '';
		$decoded_body = json_decode( $body );
		if ( $decoded_body && isset( $decoded_body->model ) ) {
			$model = $decoded_body->model;
		}

		if ( $error_msg ) {
			$job['status'] = 'error';
			$job['error']  = $error_msg;
		} else {
			$job['status']        = 'complete';
			$job['stop_reason']   = $stop_reason;
			$job['tool_calls']    = array_values( $tool_calls );
			$job['input_tokens']  = $input_tokens;
			$job['output_tokens'] = $output_tokens;
			$job['cost']          = ModelRegistry::get_cost( $model, $input_tokens, $output_tokens );
			// PR 7A: cache counter passthrough — measurement only, not used by ModelRegistry yet.
			$job['cache_creation_input_tokens'] = $cache_creation_input_tokens;
			$job['cache_read_input_tokens']     = $cache_read_input_tokens;

			// Log usage to the database.
			if ( $input_tokens > 0 || $output_tokens > 0 ) {
				$context = 'chat';
				if ( $decoded_body && isset( $decoded_body->tools ) && is_array( $decoded_body->tools ) ) {
					$tool_names = array_map( function ( $t ) {
						return $t->name ?? '';
					}, $decoded_body->tools );
					if ( in_array( 'generate_page', $tool_names, true ) ) {
						$context = 'page_generation';
					} elseif ( in_array( 'generate_block', $tool_names, true ) ) {
						$context = 'block_generation';
					}
				}

				TokenUsageTable::log( [
					'user_id'       => $user_id,
					'provider'      => 'anthropic',
					'model'         => $model,
					'input_tokens'  => $input_tokens,
					'output_tokens' => $output_tokens,
					'cost'          => ModelRegistry::get_cost( $model, $input_tokens, $output_tokens ),
					'context'       => $context,
				] );
			}
		}

		$job['body'] = null;
		set_transient( self::TRANSIENT_PREFIX . $job_id, $job, self::TRANSIENT_TTL );

		// Release the slot now that the worker is done. handle_poll() will
		// also try to release on the client's final poll, but releasing here
		// makes cleanup independent of client polling discipline.
		$this->release_slot( $user_id, $job_id );
	}

	/**
	 * Delete orphaned temp files created by the generation system.
	 *
	 * Scans the system temp directory for files matching the fl_ds_gen_
	 * prefix that are older than 1 hour and removes them.
	 */
	public static function cleanup_stale_files() {
		$dir     = sys_get_temp_dir();
		$prefix  = 'fl_ds_gen_';
		$max_age = HOUR_IN_SECONDS;
		$now     = time();

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$files = @glob( $dir . '/' . $prefix . '*' );
		if ( ! $files ) {
			return;
		}

		foreach ( $files as $file ) {
			if ( is_file( $file ) && ( $now - filemtime( $file ) ) > $max_age ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $file );
			}
		}
	}

	/**
	 * Mark a job as failed.
	 */
	private function fail_job( string $job_id, array $job, string $message, int $user_id = 0 ) {
		$file = $job['file'] ?? '';
		if ( $file && file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $file );
		}
		ToolInputStream::cleanup_for_job( $job );

		$job['status'] = 'error';
		$job['error']  = $message;
		$job['body']   = null;
		set_transient( self::TRANSIENT_PREFIX . $job_id, $job, self::TRANSIENT_TTL );

		$this->release_slot( $user_id > 0 ? $user_id : (int) ( $job['user_id'] ?? 0 ), $job_id );
	}

	/**
	 * M-7: ownership check for poll/cancel.
	 *
	 * @param array $job The transient job record.
	 * @return bool
	 */
	private function user_owns_job( array $job ): bool {
		$owner = (int) ( $job['user_id'] ?? 0 );
		if ( $owner <= 0 ) {
			// No owner recorded (legacy or external job). Refuse access:
			// fall back to "not yours" and rely on the audit log to surface
			// any unexpected legacy records.
			return false;
		}
		return (int) get_current_user_id() === $owner;
	}

	/**
	 * Log a cross-user job access attempt at WP_DEBUG. The HTTP response
	 * is a 404 to avoid confirming job existence; this entry preserves
	 * the trace so support can debug "my job vanished" reports.
	 *
	 * @param string $job_id
	 * @param array  $job
	 * @param string $action 'poll' or 'cancel'.
	 */
	private function log_cross_user_access( string $job_id, array $job, string $action ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf(
			'[fl-ds-generation-job] Cross-user %s denied: job_id=%s requested by user_id=%d but owned by user_id=%d.',
			$action,
			$job_id,
			(int) get_current_user_id(),
			(int) ( $job['user_id'] ?? 0 )
		) );
	}
}
