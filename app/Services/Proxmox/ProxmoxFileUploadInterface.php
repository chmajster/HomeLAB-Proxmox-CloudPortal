<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

interface ProxmoxFileUploadInterface
{
    public function uploadIso(string $node, string $storage, string $path, string $filename): mixed;
}
