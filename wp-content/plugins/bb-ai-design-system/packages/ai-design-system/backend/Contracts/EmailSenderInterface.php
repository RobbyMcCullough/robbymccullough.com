<?php

namespace FL\DesignSystem\Contracts;

/**
 * Contract for sending transactional email.
 *
 * Implementations wrap a platform-native sender (e.g. WordPress wp_mail).
 */
interface EmailSenderInterface {

	/**
	 * Send an email message.
	 *
	 * @param string|string[] $to      One or more recipient addresses.
	 * @param string          $subject Message subject.
	 * @param string          $body    Message body (plain text or HTML).
	 * @param array           $headers Optional headers. Recognized keys:
	 *                                 - 'from'         : string "Name <email>" or email
	 *                                 - 'reply_to'     : string or string[]
	 *                                 - 'content_type' : 'text/plain' (default) or 'text/html'
	 * @return bool True if the underlying sender accepted the message.
	 */
	public function send( $to, string $subject, string $body, array $headers = [] ): bool;
}
