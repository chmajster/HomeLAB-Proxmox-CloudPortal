<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

interface ProxmoxClientProviderInterface
{
    public function forConnection(int $connectionId): ProxmoxClientInterface;
}

