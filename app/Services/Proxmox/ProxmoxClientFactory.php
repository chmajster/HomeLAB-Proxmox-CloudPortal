<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

use CloudPortal\Http\HttpException;
use CloudPortal\Security\Crypto;
use PDO;

final class ProxmoxClientFactory implements ProxmoxClientProviderInterface
{
    public function __construct(private readonly PDO $pdo, private readonly Crypto $crypto)
    {
    }

    public function forConnection(int $connectionId): ProxmoxClientInterface
    {
        $statement = $this->pdo->prepare('SELECT * FROM proxmox_connections WHERE id = :id AND status <> \'disabled\'');
        $statement->execute(['id' => $connectionId]);
        $connection = $statement->fetch();
        if (!is_array($connection)) {
            throw new HttpException(404, 'Proxmox connection not found or disabled.');
        }
        return $this->fromRecord($connection);
    }

    /** @param array<string,mixed> $connection */
    public function fromRecord(array $connection): ProxmoxClientInterface
    {
        return new ProxmoxClient(
            (string) $connection['hostname'],
            (int) $connection['port'],
            (string) $connection['realm'],
            (string) $connection['api_token_id'],
            $this->crypto->decrypt((string) $connection['api_token_secret_encrypted']),
            (bool) $connection['verify_ssl'],
        );
    }
}
