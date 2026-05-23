<?php

namespace FL\DesignSystem\Form;

use FL\DesignSystem\Contracts\FormBlockSettingsResolverInterface;

/**
 * REST endpoint and orchestration for AI-generated form submissions.
 *
 * Registers `POST /fl-design-system/v1/form-submit`. Submissions flow:
 *   1. Honeypot + time-trap checks via SpamGuard.
 *   2. Resolve the block's saved settings by `block_id`.
 *   3. Pull `settings.{form_id}.actions` and dispatch each through the
 *      registered handler for its `type`.
 */
class FormSubmissionProvider {

	public const REST_NAMESPACE = 'fl-design-system/v1';
	public const REST_ROUTE     = '/form-submit';

	private FormActionRegistry $registry;
	private SpamGuard $spam_guard;
	private FormBlockSettingsResolverInterface $block_resolver;

	public function __construct(
		FormActionRegistry $registry,
		SpamGuard $spam_guard,
		FormBlockSettingsResolverInterface $block_resolver
	) {
		$this->registry       = $registry;
		$this->spam_guard     = $spam_guard;
		$this->block_resolver = $block_resolver;
	}

	/**
	 * Hook the REST route registration on rest_api_init.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the form-submit REST route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_submission' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle an incoming form submission.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function handle_submission( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}

		$block_id = isset( $body['block_id'] ) ? (string) $body['block_id'] : '';
		$form_id  = isset( $body['form_id'] ) ? (string) $body['form_id'] : '';
		$form_key = isset( $body['form_key'] ) ? (string) $body['form_key'] : '';
		$fields   = isset( $body['fields'] ) && is_array( $body['fields'] ) ? $body['fields'] : [];
		$honeypot = isset( $body['_fl_hp'] ) ? (string) $body['_fl_hp'] : '';
		$token    = isset( $body['_fl_ts'] ) ? (string) $body['_fl_ts'] : '';
		$post_id  = isset( $body['post_id'] ) ? (int) $body['post_id'] : 0;

		// form_key is the stable submission identifier. Legacy submissions
		// that predate stable keys fall back to the HTML form id so the
		// HMAC (signed with id in that case) still validates.
		$identifier = '' !== $form_key ? $form_key : $form_id;

		if ( '' === $block_id || '' === $identifier ) {
			return $this->error_response(
				422,
				[ '_form' => __( 'Submission is missing block_id or form identifier.', 'fl-design-system' ) ]
			);
		}

		$spam_check = $this->spam_guard->validate( $token, $honeypot, $block_id, $identifier );
		if ( ! $spam_check['valid'] ) {
			// Silent drop on honeypot so bots don't learn their input was flagged.
			if ( SpamGuard::REASON_HONEYPOT === $spam_check['reason'] ) {
				return new \WP_REST_Response(
					[
						'success' => true,
					],
					200
				);
			}

			return $this->error_response(
				422,
				[ '_form' => __( 'Submission rejected.', 'fl-design-system' ) ],
				[ 'reason' => $spam_check['reason'] ]
			);
		}

		// M-12: per-identity rate limit. Keyed on user_id when logged in,
		// IP fallback only for anonymous submissions, so corporate proxies
		// sharing a NAT IP are not penalized as a single attacker.
		$user_id = get_current_user_id();
		$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( ! $this->spam_guard->check_rate_limit( (int) $user_id, $ip ) ) {
			return $this->error_response(
				429,
				[ '_form' => __( 'Too many submissions. Please wait a few minutes.', 'fl-design-system' ) ],
				[ 'reason' => SpamGuard::REASON_RATE_LIMITED ]
			);
		}

		// M-12: per-token idempotency window. A repeat submission with the
		// same (token, payload_hash) within IDEMPOTENCY_WINDOW returns the
		// cached prior result without re-firing the form's actions. Why:
		// legitimate retries (network blip, double-click, browser
		// auto-resubmit on back) should not register as separate sends.
		$payload_hash = SpamGuard::payload_hash( [
			'block_id' => $block_id,
			'form_id'  => $form_id,
			'form_key' => $form_key,
			'fields'   => $fields,
			'post_id'  => $post_id,
		] );
		$cached       = $this->spam_guard->get_idempotent_result( $token, $payload_hash );
		if ( null !== $cached ) {
			$status = (int) ( $cached['status'] ?? 200 );
			$body   = is_array( $cached['body'] ?? null ) ? $cached['body'] : [];
			return new \WP_REST_Response( $body, $status );
		}

		$settings = $this->block_resolver->resolve(
			$block_id,
			$post_id > 0 ? [ 'post_id' => $post_id ] : []
		);
		if ( null === $settings ) {
			return $this->error_response(
				404,
				[ '_form' => __( 'Form configuration not found.', 'fl-design-system' ) ]
			);
		}

		$form_settings = $this->find_form_settings( $settings, $form_key, $form_id );
		$actions       = is_array( $form_settings['actions'] ?? null ) ? $form_settings['actions'] : null;

		if ( null === $actions || [] === $actions ) {
			return $this->error_response(
				422,
				[ '_form' => __( 'No submission actions configured for this form.', 'fl-design-system' ) ]
			);
		}

		$submission = [
			'block_id' => $block_id,
			'form_id'  => $form_id,
			'form_key' => $form_key,
			'post_id'  => $post_id,
			'fields'   => $fields,
			'context'  => $this->build_context( $form_settings ),
		];

		// Per-action failures are isolated: one handler failing does not
		// abort siblings. Redirect still requires every action to succeed,
		// so a flaky email cannot navigate the browser away unaware.
		$any_success    = false;
		$any_failure    = false;
		$errors         = [];
		$action_errors  = [];
		$redirect       = null;
		$redirect_delay = 0;

		foreach ( $actions as $index => $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}

			$type    = isset( $action['type'] ) ? (string) $action['type'] : '';
			$handler = '' !== $type ? $this->registry->get( $type ) : null;

			if ( null === $handler ) {
				$any_failure = true;
				$message     = sprintf(
					/* translators: %s: action type */
					__( 'Unknown action type: %s', 'fl-design-system' ),
					$type
				);
				$errors[ '_action_' . $index ] = $message;
				$action_errors[]               = [
					'index' => $index,
					'type'  => $type,
					'error' => $message,
				];
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[fl-ds-form] Action %d (%s) failed: unknown action type', $index, $type ) );
				continue;
			}

			$result = $handler->handle( $submission, $action );
			if ( empty( $result['success'] ) ) {
				$any_failure = true;
				$message     = ! empty( $result['error'] ) ? (string) $result['error'] : '';
				if ( '' !== $message ) {
					$errors[ '_action_' . $index ] = $message;
				}
				$action_errors[] = [
					'index' => $index,
					'type'  => $type,
					'error' => $message,
				];
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[fl-ds-form] Action %d (%s) failed: %s', $index, $type, $message ) );
				continue;
			}

			$any_success = true;

			if ( ! empty( $result['redirect'] ) && null === $redirect ) {
				$redirect       = (string) $result['redirect'];
				$redirect_delay = isset( $result['redirect_delay'] ) ? max( 0, (int) $result['redirect_delay'] ) : 0;
			}
		}

		// 500 only when every action failed — preserves the legacy
		// failure shape for clients that don't read action_errors.
		if ( ! $any_success ) {
			$body = [] !== $errors ? $errors : [ '_form' => __( 'Submission failed.', 'fl-design-system' ) ];
			$this->spam_guard->record_idempotent_result(
				$token,
				$payload_hash,
				[
					'status' => 500,
					'body'   => [
						'success' => false,
						'errors'  => $body,
					],
				]
			);
			return $this->error_response( 500, $body );
		}

		$response = [ 'success' => true ];
		// Suppress redirect when any sibling failed, even if the redirect
		// handler itself succeeded — don't navigate away on partial failure.
		if ( null !== $redirect && ! $any_failure ) {
			$response['redirect'] = $redirect;
			if ( $redirect_delay > 0 ) {
				$response['redirect_delay'] = $redirect_delay;
			}
		}
		if ( [] !== $action_errors ) {
			$response['action_errors'] = $action_errors;
		}

		$this->spam_guard->record_idempotent_result(
			$token,
			$payload_hash,
			[
				'status' => 200,
				'body'   => $response,
			]
		);

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Locate the form's settings entry.
	 *
	 * Prefers matching against the entry's stable `key` property so
	 * renames of the HTML `id` don't break submissions. Falls back to
	 * a direct lookup by the HTML id for legacy entries that predate
	 * stable keys.
	 *
	 * @param  array  $settings Resolved block settings map.
	 * @param  string $form_key Stable submission identifier (UUID, or '').
	 * @param  string $form_id  HTML form id (legacy fallback lookup).
	 * @return array|null The matched entry, or null.
	 */
	private function find_form_settings( array $settings, string $form_key, string $form_id ): ?array {
		if ( '' !== $form_key ) {
			foreach ( $settings as $entry ) {
				if ( is_array( $entry ) && isset( $entry['key'] ) && $entry['key'] === $form_key ) {
					return $entry;
				}
			}
		}

		if ( '' !== $form_id && isset( $settings[ $form_id ] ) && is_array( $settings[ $form_id ] ) ) {
			return $settings[ $form_id ];
		}

		return null;
	}

	/**
	 * Build the context array exposed to action handlers.
	 *
	 * @param  array|null $form_settings Saved form settings (may contain title).
	 * @return array
	 */
	private function build_context( ?array $form_settings ): array {
		$admin_email = function_exists( 'get_option' ) ? (string) get_option( 'admin_email', '' ) : '';
		$site_url    = function_exists( 'home_url' ) ? (string) home_url() : '';

		$title = '';
		if ( is_array( $form_settings ) && isset( $form_settings['title'] ) ) {
			$title = (string) $form_settings['title'];
		}

		return [
			'admin_email' => $admin_email,
			'site_url'    => $site_url,
			'form_title'  => $title,
		];
	}

	/**
	 * Build a uniform error response for submission failures.
	 *
	 * @param  int   $status HTTP status.
	 * @param  array $errors Associative error map (field => message).
	 * @param  array $extra  Optional extra keys to merge (e.g. debug reason).
	 * @return \WP_REST_Response
	 */
	private function error_response( int $status, array $errors, array $extra = [] ): \WP_REST_Response {
		$body = array_merge(
			[
				'success' => false,
				'errors'  => $errors,
			],
			$extra
		);
		return new \WP_REST_Response( $body, $status );
	}
}
