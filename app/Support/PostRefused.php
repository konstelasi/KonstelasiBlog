<?php

namespace App\Support;

use RuntimeException;

/**
 * A write to the authoring API that the app refuses, with a stable code a
 * client can branch on and the message a person can read. The controller
 * turns it into a JSON error, so the codes below are part of the API.
 */
final class PostRefused extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  Messages by field, in the shape of a validation error.
     */
    public function __construct(
        public readonly string $problem,
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
