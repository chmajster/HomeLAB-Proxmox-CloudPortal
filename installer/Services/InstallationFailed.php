<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Services;

final class InstallationFailed extends \RuntimeException
{
    /** @param list<array{name:string,status:string,detail:string}> $stages */
    public function __construct(string $message, public readonly array $stages, \Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }
}
