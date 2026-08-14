<?php

declare(strict_types=1);

namespace CloudPortal\Services\IPAM;

use CloudPortal\Http\HttpException;
use PDO;

final class IPAMService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id:int,address:string} */
    public function reserve(int $networkId, string $reservationKey): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, address FROM ip_addresses
             WHERE network_id = :network AND state = 'free'
             ORDER BY INET6_ATON(address) LIMIT 1 FOR UPDATE SKIP LOCKED"
        );
        $statement->execute(['network' => $networkId]);
        $ip = $statement->fetch();
        if (!is_array($ip)) {
            throw new HttpException(409, 'No free IP address is available in the selected network.');
        }
        $update = $this->pdo->prepare(
            "UPDATE ip_addresses SET state = 'reserved', reservation_key = :key, reserved_at = CURRENT_TIMESTAMP
             WHERE id = :id AND state = 'free'"
        );
        $update->execute(['key' => $reservationKey, 'id' => $ip['id']]);
        if ($update->rowCount() !== 1) {
            throw new HttpException(409, 'IP address allocation collision.');
        }
        return ['id' => (int) $ip['id'], 'address' => (string) $ip['address']];
    }

    public function allocate(string $reservationKey, int $vmId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE ip_addresses SET state = 'allocated', virtual_machine_id = :vm, reservation_key = NULL,
                    allocated_at = CURRENT_TIMESTAMP
             WHERE reservation_key = :key AND state = 'reserved'"
        );
        $statement->execute(['vm' => $vmId, 'key' => $reservationKey]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Reserved IP address was not found.');
        }
    }

    public function releaseReservation(string $reservationKey): void
    {
        $this->pdo->prepare(
            "UPDATE ip_addresses SET state = 'free', reservation_key = NULL, reserved_at = NULL
             WHERE reservation_key = :key AND state = 'reserved'"
        )->execute(['key' => $reservationKey]);
    }

    public function releaseVm(int $vmId): void
    {
        $this->pdo->prepare(
            "UPDATE ip_addresses SET state = 'free', virtual_machine_id = NULL, allocated_at = NULL
             WHERE virtual_machine_id = :vm"
        )->execute(['vm' => $vmId]);
    }
}

