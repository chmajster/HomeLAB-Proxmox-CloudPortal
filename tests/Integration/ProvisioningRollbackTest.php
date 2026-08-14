<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Integration;

use CloudPortal\Database\Database;
use CloudPortal\Services\IPAM\IPAMService;
use CloudPortal\Services\Quota\QuotaService;
use CloudPortal\Support\Config;

final class ProvisioningRollbackTest extends MariaDbTestCase
{
    public function testTransactionRollsBackQuotaAndIpReservationOnFailure(): void
    {
        $f = $this->fixture();
        foreach (['project_id'=>$f['project'],'user_id'=>$f['user']] as $column=>$id) {
            self::$pdo->prepare("INSERT INTO quotas({$column},max_vms,max_vcpu,max_ram_mb,max_storage_gb,max_snapshots,max_ip_addresses) VALUES(:id,1,2,4096,40,1,1)")->execute(['id'=>$id]);
        }
        self::$pdo->exec("INSERT INTO proxmox_connections(name,hostname,api_token_id,api_token_secret_encrypted) VALUES('rollback-cluster','pve.test','u!t','encrypted')");
        $connection = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare("INSERT INTO networks(connection_id,name,bridge,subnet) VALUES(:c,'rollback-net','vmbr0','192.0.2.0/24')")->execute(['c'=>$connection]);
        $network = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare("INSERT INTO ip_addresses(network_id,address) VALUES(:n,'192.0.2.20')")->execute(['n'=>$network]);
        $database = new Database(new Config([]), self::$pdo);
        $key = '00000000-0000-4000-8000-000000000099';
        try {
            $database->transaction(function ($pdo) use ($f, $network, $key): void {
                (new QuotaService($pdo))->reserve($key, $f['project'], $f['user'], ['vms'=>1,'vcpu'=>2,'ram_mb'=>4096,'storage_gb'=>40,'ip_addresses'=>1]);
                (new IPAMService($pdo))->reserve($network, $key);
                throw new \RuntimeException('simulated Proxmox failure');
            });
            self::fail('Expected simulated failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('simulated Proxmox failure', $exception->getMessage());
        }
        $reservation = self::$pdo->prepare('SELECT COUNT(*) FROM quota_reservations WHERE reservation_key=:key');
        $reservation->execute(['key'=>$key]);
        self::assertSame(0, (int) $reservation->fetchColumn());
        $ip = self::$pdo->prepare('SELECT state FROM ip_addresses WHERE network_id=:network');
        $ip->execute(['network'=>$network]);
        self::assertSame('free', $ip->fetchColumn());
    }
}

