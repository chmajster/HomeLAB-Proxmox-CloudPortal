<?php

declare(strict_types=1);

namespace CloudPortal\Services\Quota;

final class QuotaExceeded extends \RuntimeException
{
    public function __construct(public readonly string $resource)
    {
        parent::__construct("Quota exceeded for {$resource}.");
    }
}

