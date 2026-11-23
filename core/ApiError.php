<?php

namespace Core;

/**
 * An error answer of the API: thrown anywhere in an API call, turned into JSON by the kernel.
 * The message is shown to the caller, so it never holds anything internal.
 */
class ApiError extends \RuntimeException
{
    /**
     * @param int    $status    HTTP status
     * @param string $errorCode Short machine-readable reason, such as not_found
     * @param array  $headers   Extra headers by name, such as Retry-After
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $headers = []
    ) {
        parent::__construct($message);
    }
}
