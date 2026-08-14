<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

interface ProxmoxClientInterface
{
    /** @return mixed */
    public function get(string $path, array $query = []): mixed;
    /** @return mixed */
    public function post(string $path, array $data = []): mixed;
    /** @return mixed */
    public function put(string $path, array $data = []): mixed;
    /** @return mixed */
    public function delete(string $path, array $data = []): mixed;
    /** @return array<string,mixed> */
    public function waitForTask(string $node, string $upid, int $timeoutSeconds = 900): array;
}

