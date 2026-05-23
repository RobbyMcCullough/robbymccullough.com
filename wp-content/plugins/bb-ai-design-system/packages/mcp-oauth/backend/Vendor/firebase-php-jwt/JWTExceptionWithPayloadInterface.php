<?php
/**
 * firebase/php-jwt v6.10.2
 *
 * Vendored from https://github.com/firebase/php-jwt
 * License: BSD-3-Clause
 */

namespace Firebase\JWT;

interface JWTExceptionWithPayloadInterface
{
    /**
     * Get the payload that caused this exception.
     *
     * @return object
     */
    public function getPayload(): object;

    /**
     * Set the payload that caused this exception.
     *
     * @param object $payload
     * @return void
     */
    public function setPayload(object $payload): void;
}
