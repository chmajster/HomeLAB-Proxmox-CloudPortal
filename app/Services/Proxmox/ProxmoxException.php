<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

final class ProxmoxException extends \RuntimeException
{
    /** @param array<string,mixed>|null $response */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?array $response = null,
        public readonly int $curlCode = 0,
    )
    {
        parent::__construct($message);
    }
}
