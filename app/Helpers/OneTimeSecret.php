<?php

namespace App\Helpers;

/**
 * A value shown once on a page, such as a new API token.
 *
 * A template prints it like a string. The error handler saves stack traces as JSON, and
 * json_encode() leaves a private property out, so a trace that holds this object does not hold
 * the value.
 */
final class OneTimeSecret
{
    public function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
