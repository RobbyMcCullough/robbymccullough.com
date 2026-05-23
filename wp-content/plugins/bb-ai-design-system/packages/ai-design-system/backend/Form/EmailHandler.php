<?php

namespace FL\DesignSystem\Form;

use FL\DesignSystem\Contracts\EmailSenderInterface;

/**
 * Email action handler.
 *
 * Config shape:
 *   [
 *     'to'      => string, // recipient. '{admin_email}' token is resolved from submission context.
 *     'subject' => string, // optional; defaults to "New submission from {form_id}".
 *                          // '{form_title}' and '{form_id}' tokens are resolved.
 *     'from'    => string, // optional "Name <email>" or email. Sent as From header if present.
 *   ]
 */
class EmailHandler implements FormActionInterface {

	private EmailSenderInterface $sender;

	public function __construct( EmailSenderInterface $sender ) {
		$this->sender = $sender;
	}

	/**
	 * Send the submission as an email.
	 *
	 * @param  array $submission    Normalized submission payload.
	 * @param  array $action_config Action configuration.
	 * @return array
	 */
	public function handle( array $submission, array $action_config ): array {
		$context = is_array( $submission['context'] ?? null ) ? $submission['context'] : [];
		$form_id = (string) ( $submission['form_id'] ?? '' );
		$fields  = is_array( $submission['fields'] ?? null ) ? $submission['fields'] : [];

		$to = $this->resolve_token( (string) ( $action_config['to'] ?? '' ), $context, $form_id );
		$to = trim( $to );

		if ( '' === $to || ! filter_var( $to, FILTER_VALIDATE_EMAIL ) ) {
			return [
				'success'  => false,
				'redirect' => null,
				'error'    => 'Email action is missing a valid recipient.',
			];
		}

		$subject_raw = $action_config['subject'] ?? '';
		if ( '' === $subject_raw ) {
			$subject_raw = 'New submission from {form_title}';
		}
		$subject = $this->resolve_token( (string) $subject_raw, $context, $form_id );
		// M-13 CRLF guard: replace any embedded CR/LF with a space so a
		// crafted subject cannot inject additional headers via PHPMailer.
		$subject = self::strip_header_breaks( $subject );

		$body    = $this->format_body( $fields );
		$headers = [];

		$from = trim( (string) ( $action_config['from'] ?? '' ) );
		if ( '' !== $from ) {
			// M-13 CRLF guard for the From header. Reject (silently drop
			// the header) if the value contains CR/LF or fails is_email
			// shape validation. The submitter never sees this; the form
			// admin should validate the configuration before saving.
			if ( self::is_safe_header_value( $from ) ) {
				$headers['from'] = $from;
			} else {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[fl-ds-email] Dropped From header containing CRLF or invalid email shape.' );
			}
		}

		$ok = $this->sender->send( $to, $subject, $body, $headers );

		if ( ! $ok ) {
			return [
				'success'  => false,
				'redirect' => null,
				'error'    => 'Email delivery failed.',
			];
		}

		return [
			'success'  => true,
			'redirect' => null,
			'error'    => null,
		];
	}

	/**
	 * Replace `{admin_email}`, `{form_title}`, `{form_id}` tokens.
	 *
	 * @param  string $value   Raw value with optional tokens.
	 * @param  array  $context Submission context.
	 * @param  string $form_id Form identifier.
	 * @return string Resolved value.
	 */
	private function resolve_token( string $value, array $context, string $form_id ): string {
		$admin_email = isset( $context['admin_email'] ) ? (string) $context['admin_email'] : '';
		$form_title  = isset( $context['form_title'] ) && '' !== $context['form_title']
			? (string) $context['form_title']
			: $form_id;

		return strtr(
			$value,
			[
				'{admin_email}' => $admin_email,
				'{form_title}'  => $form_title,
				'{form_id}'     => $form_id,
			]
		);
	}

	/**
	 * M-13: replace CR / LF in a string with a space. Used on the
	 * Subject header where we keep the message rather than dropping it.
	 *
	 * @param string $str
	 * @return string
	 */
	private static function strip_header_breaks( string $str ): string {
		return str_replace( [ "\r", "\n" ], ' ', $str );
	}

	/**
	 * M-13: whether a value is a safe header source (no CR/LF, valid
	 * email shape). Accepts both `email@example.com` and
	 * `Name <email@example.com>` forms.
	 *
	 * @param string $value
	 * @return bool
	 */
	private static function is_safe_header_value( string $value ): bool {
		if ( 1 === preg_match( '/[\r\n]/', $value ) ) {
			return false;
		}
		// Extract bare email from possible "Name <email>" envelope.
		if ( preg_match( '/<([^>]+)>$/', $value, $m ) ) {
			$value = $m[1];
		}
		return false !== filter_var( $value, FILTER_VALIDATE_EMAIL );
	}

	/**
	 * Format submission fields as a plain-text body.
	 *
	 * @param  array $fields Submitted field values.
	 * @return string
	 */
	private function format_body( array $fields ): string {
		if ( empty( $fields ) ) {
			return '(No fields submitted.)';
		}

		$lines = [];
		foreach ( $fields as $name => $value ) {
			$label   = (string) $name;
			$printed = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			$lines[] = $label . ': ' . $printed;
		}
		return implode( "\n", $lines );
	}
}
