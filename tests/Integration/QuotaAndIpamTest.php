<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Integration;

use CloudPortal\Http\HttpException;
use CloudPortal\Services\IPAM\IPAMService;
use CloudPortal\Services\Quota\QuotaExceeded;
use CloudPortal\Services\Quota\QuotaService;

final class QuotaAndIpamTest extends MariaDbTestCase
{
    public function testConcurrentReservationIsCountedBeforeProvisioning(): void
    {
        $f = $this->fixture();
        $quota = self::$pdo->prepare('INSERT INTO quotas(project_id,max_vms,max_vcpu,max_ram_mb,max_storage_gb,max_snapshots,max_ip_addresses) VALUES(:id,1,2,4096,40,1,1)');
        $quota->execute(['id'=>$f['project']]);
        $quota = self::$pdo->prepare('INSERT INTO quotas(user_id,max_vms,max_vcpu,max_ram_mb,max_storage_gb,max_snapshots,max_ip_addresses) VALUES(:id,1,2,4096,40,1,1)');
        $quota->execute(['id'=>$f['user']]);
        $service = new QuotaService(self::$pdo);
        $request = ['vms'=>1,'vcpu'=>2,'ram_mb'=>4096,'storage_gb'=>40,'ip_addresses'=>1];
        $service->reserve('00000000-0000-4000-8000-000000000001', $f['project'], $f['user'], $request);
        $this->expectException(QuotaExceeded::class);
        $service->reserve('00000000-0000-4000-8000-000000000002', $f['project'], $f['user'], $request);
    }

    public function testIpamCannotReserveOneAddressTwice(): void
    {
        $f = $this->fixture();
        self::$pdo->exec("INSERT INTO proxmox_connections(name,hostname,api_token_id,api_token_secret_encrypted) VALUES('c".$f['project']."','pve.test','u!t','encrypted')");
        $connection = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare("INSERT INTO networks(connection_id,name,bridge,subnet) VALUES(:c,:n,'vmbr0','192.0.2.0/24')")->execute(['c'=>$connection,'n'=>'net'.$f['project']]);
        $network = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare("INSERT INTO ip_addresses(network_id,address) VALUES(:n,'192.0.2.10')")->execute(['n'=>$network]);
        $ipam = new IPAMService(self::$pdo);
        $ipam->reserve($network, '00000000-0000-4000-8000-000000000003');
        $this->expectException(HttpException::class);
        $ipam->reserve($network, '00000000-0000-4000-8000-000000000004');
    }
}

