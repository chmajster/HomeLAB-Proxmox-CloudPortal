<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

interface ProvisionerInterface
{
    /** @param array<string,mixed> $job */
    public function process(array $job): void;
}

