<?php
/**
 * firebase/php-jwt v6.10.2
 *
 * Vendored from https://github.com/firebase/php-jwt
 * License: BSD-3-Clause
 */

namespace Firebase\JWT;

class BeforeValidException extends \UnexpectedValueException implements JWTExceptionWithPayloadInterface
{
    private object $payload;

    public function setPayload(object $payload): void
    {
        $this->payload = $payload;
    }

    public function getPayload(): object
    {
        return $this->payload;
    }
}
