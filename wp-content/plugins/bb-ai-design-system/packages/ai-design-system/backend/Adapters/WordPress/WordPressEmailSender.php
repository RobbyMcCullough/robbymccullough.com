<?php

namespace FL\DesignSystem\Adapters\WordPress;

use FL\DesignSystem\Contracts\EmailSenderInterface;

/**
 * WordPress wp_mail() wrapper.
 */
class WordPressEmailSender implements EmailSenderInterface {

	/**
	 * Send an email via wp_mail().
	 *
	 * @param  string|string[] $to      Recipient(s).
	 * @param  string          $subject Subject line.
	 * @param  string          $body    Message body.
	 * @param  array           $headers Optional headers (see EmailSenderInterface).
	 * @return bool
	 */
	public function send( $to, string $subject, string $body, array $headers = [] ): bool {
		$wp_headers = [];

		// M-13 CRLF guard: defense-in-depth. EmailHandler already strips
		// breaks from subject and validates From, but this adapter runs
		// every wp_mail() call and is the right place to catch any future
		// caller that bypasses the handler.
		$subject = self::strip_header_breaks( $subject );

		$content_type = isset( $headers['content_type'] ) ? (string) $headers['content_type'] : 'text/plain';
		$wp_headers[] = 'Content-Type: ' . self::strip_header_breaks( $content_type ) . '; charset=UTF-8';

		if ( ! empty( $headers['from'] ) && self::is_safe_header_value( (string) $headers['from'] ) ) {
			$wp_headers[] = 'From: ' . $headers['from'];
		}

		if ( ! empty( $headers['reply_to'] ) ) {
			$reply_to = is_array( $headers['reply_to'] ) ? $headers['reply_to'] : [ $headers['reply_to'] ];
			foreach ( $reply_to as $address ) {
				if ( self::is_safe_header_value( (string) $address ) ) {
					$wp_headers[] = 'Reply-To: ' . $address;
				}
			}
		}

		return (bool) wp_mail( $to, $subject, $body, $wp_headers );
	}

	/**
	 * Drop CR / LF from a header-bound string.
	 *
	 * @param string $str
	 * @return string
	 */
	private static function strip_header_breaks( string $str ): string {
		return str_replace( [ "\r", "\n" ], ' ', $str );
	}

	/**
	 * Whether a header value is safe (no CR/LF, valid email shape after
	 * unwrapping a possible "Name <email>" envelope).
	 *
	 * @param string $value
	 * @return bool
	 */
	private static function is_safe_header_value( string $value ): bool {
		if ( 1 === preg_match( '/[\r\n]/', $value ) ) {
			return false;
		}
		if ( preg_match( '/<([^>]+)>$/', $value, $m ) ) {
			$value = $m[1];
		}
		return false !== filter_var( $value, FILTER_VALIDATE_EMAIL );
	}
}
