<?php

declare(strict_types=1);

namespace CloudPortal\Services\DNS;

interface DnsApiClientInterface
{
    /** @return array{fqdn:string,forward_zone:string,reverse_zone:string,a_record_id:int,ptr_record_id:int} */
    public function ensureVmRecords(string $hostname, string $ipAddress, ?string $preferredForwardZone = null): array;

    public function verifyVmRecords(string $fqdn, string $ipAddress): void;

    public function deleteRecord(string $zone, int $recordId): void;
}
