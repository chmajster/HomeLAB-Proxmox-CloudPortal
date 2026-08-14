<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Validators;

final class InstallerValidationException extends \RuntimeException
{
    /** @param array<string,string> $fields */
    public function __construct(string $message, public readonly array $fields = [])
    {
        parent::__construct($message);
    }
}
