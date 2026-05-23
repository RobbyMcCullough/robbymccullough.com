<?php

namespace FL\DesignSystem\Chat;

use FL\DesignSystem\Contracts\AuthInterface;
use FL\DesignSystem\Contracts\ChatStoreInterface;

class ChatHistoryProvider {

	private ChatStoreInterface $store;
	private AuthInterface $auth;

	public function __construct( ChatStoreInterface $store, AuthInterface $auth ) {
		$this->store = $store;
		$this->auth  = $auth;
	}

	public function boot() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register generic chat history routes.
	 *
	 * Creates GET/POST/DELETE /chat/{type}/{id} for any item type.
	 */
	public function register_routes() {
		$ns    = 'fl-design-system/v1';
		$route = '/chat/(?P<type>[a-z_-]+)/(?P<id>\d+)';

		register_rest_route( $ns, $route, [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_chat' ],
			'permission_callback' => [ $this->auth, 'content_creator_permission_callback' ],
		] );

		register_rest_route( $ns, $route, [
			'methods'             => 'POST',
			'callback'            => [ $this, 'save_chat' ],
			'permission_callback' => [ $this->auth, 'content_creator_permission_callback' ],
		] );

		register_rest_route( $ns, $route, [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'delete_chat' ],
			'permission_callback' => [ $this->auth, 'content_creator_permission_callback' ],
		] );
	}

	/**
	 * Get a conversation for the current user and item.
	 *
	 * @param  \WP_REST_Request  $request
	 * @return \WP_REST_Response
	 */
	public function get_chat( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id  = $this->auth->user_id();
		$type     = $request->get_param( 'type' );
		$item_id  = $request->get_param( 'id' );
		$messages = $this->store->get( $user_id, $type, $item_id );

		$response = [ 'messages' => $messages ?? [] ];

		if ( method_exists( $this->store, 'get_summary' ) ) {
			$summary = $this->store->get_summary( $user_id, $type, $item_id );
			if ( $summary ) {
				$response['summary'] = $summary;
			}
		}

		if ( method_exists( $this->store, 'get_mode' ) ) {
			$mode = $this->store->get_mode( $user_id, $type, $item_id );
			if ( $mode ) {
				$response['mode'] = $mode;
			}
		}

		if ( method_exists( $this->store, 'get_active_job' ) ) {
			$active_job = $this->store->get_active_job( $user_id, $type, $item_id );
			if ( $active_job ) {
				$response['activeJob'] = $active_job;
			}
		}

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Save or append chat messages.
	 *
	 * Accepts either:
	 * - { messages: [...] } for full replace
	 * - { append: [...] } for appending to existing conversation
	 *
	 * @param  \WP_REST_Request            $request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function save_chat( \WP_REST_Request $request ) {
		$user_id = $this->auth->user_id();
		$type    = $request->get_param( 'type' );
		$item_id = $request->get_param( 'id' );
		$body    = $request->get_json_params();

		$summary = isset( $body['summary'] ) && is_object( (object) $body['summary'] )
			? (object) $body['summary']
			: null;

		$mode = isset( $body['mode'] ) && is_string( $body['mode'] )
			? $body['mode']
			: null;

		$active_job = isset( $body['activeJob'] ) && is_array( $body['activeJob'] )
			? $body['activeJob']
			: null;

		if ( isset( $body['append'] ) && is_array( $body['append'] ) ) {
			$success = $this->store->append( $user_id, $type, $item_id, $body['append'] );
		} elseif ( isset( $body['messages'] ) && is_array( $body['messages'] ) ) {
			$success = $this->store->save( $user_id, $type, $item_id, $body['messages'], $summary, $mode, $active_job );
		} else {
			return new \WP_Error(
				'validation_error',
				__( 'Request must include a messages or append array.', 'fl-design-system' ),
				[ 'status' => 422 ],
			);
		}

		if ( ! $success ) {
			return new \WP_Error(
				'save_failed',
				__( 'Failed to save chat history.', 'fl-design-system' ),
				[ 'status' => 500 ],
			);
		}

		return new \WP_REST_Response( [ 'saved' => true ], 200 );
	}

	/**
	 * Delete a conversation for the current user and item.
	 *
	 * @param  \WP_REST_Request  $request
	 * @return \WP_REST_Response
	 */
	public function delete_chat( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = $this->auth->user_id();
		$type    = $request->get_param( 'type' );
		$item_id = $request->get_param( 'id' );

		$this->store->delete( $user_id, $type, $item_id );

		return new \WP_REST_Response( [ 'deleted' => true ], 200 );
	}
}
