<?php

declare(strict_types=1);

namespace SweetSen\Support;

/**
 * A business-rule refusal with a stable machine code (e.g. already_released, forbidden).
 * The code is what UIs and tests switch on; the message is for people.
 */
class DomainError extends \RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(public readonly string $reason, string $message = '', public readonly array $context = [])
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
