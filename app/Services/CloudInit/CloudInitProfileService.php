<?php

declare(strict_types=1);

namespace CloudPortal\Services\CloudInit;

use CloudPortal\Http\HttpException;
use PDO;

final class CloudInitProfileService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listForUser(int $userId, bool $isAdmin = false): array
    {
        $sql = "SELECT p.*,u.username AS owner_username,
                       GROUP_CONCAT(pk.ssh_key_id ORDER BY pk.ssh_key_id SEPARATOR ',') AS ssh_key_ids
                FROM cloud_init_profiles p
                LEFT JOIN users u ON u.id=p.owner_user_id
                LEFT JOIN cloud_init_profile_ssh_keys pk ON pk.profile_id=p.id
                WHERE p.owner_user_id=:user OR p.is_global=1";
        if ($isAdmin) $sql = str_replace('WHERE p.owner_user_id=:user OR p.is_global=1', 'WHERE 1=1', $sql);
        $sql .= ' GROUP BY p.id ORDER BY p.is_global DESC,p.name,p.id';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($isAdmin ? [] : ['user' => $userId]);
        return array_map([$this, 'normalizeRow'], $statement->fetchAll());
    }

    /** @return array<string,mixed> */
    public function resolveForOwner(int $profileId, int $ownerUserId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT p.*,u.username AS owner_username,
                    GROUP_CONCAT(pk.ssh_key_id ORDER BY pk.ssh_key_id SEPARATOR ',') AS ssh_key_ids
             FROM cloud_init_profiles p
             LEFT JOIN users u ON u.id=p.owner_user_id
             LEFT JOIN cloud_init_profile_ssh_keys pk ON pk.profile_id=p.id
             WHERE p.id=:id AND p.enabled=1 AND (p.is_global=1 OR p.owner_user_id=:owner)
             GROUP BY p.id LIMIT 1"
        );
        $statement->execute(['id' => $profileId, 'owner' => $ownerUserId]);
        $row = $statement->fetch();
        if (!is_array($row)) throw new HttpException(422, 'Wybrany profil Cloud-Init nie istnieje, jest wyłączony albo nie jest dostępny dla właściciela VM.');
        return $this->normalizeRow($row);
    }

    /** @param array<string,mixed> $input */
    public function create(int $actorUserId, bool $isAdmin, array $input): int
    {
        $data = $this->validate($actorUserId, $isAdmin, $input);
        return $this->transaction(function () use ($actorUserId, $data): int {
            $statement = $this->pdo->prepare(
                'INSERT INTO cloud_init_profiles
                 (owner_user_id,name,description,system_user,dns_servers,search_domain,timezone,packages,runcmd,qemu_guest_agent,custom_yaml,snippet_volume,is_global,enabled,created_by)
                 VALUES (:owner,:name,:description,:system_user,:dns,:search_domain,:timezone,:packages,:runcmd,:agent,:custom_yaml,:snippet,:global,:enabled,:created_by)'
            );
            $statement->execute([
                'owner' => $data['owner_user_id'], 'name' => $data['name'], 'description' => $data['description'],
                'system_user' => $data['system_user'], 'dns' => $data['dns_servers'], 'search_domain' => $data['search_domain'],
                'timezone' => $data['timezone'], 'packages' => $this->json($data['packages']), 'runcmd' => $this->json($data['runcmd']),
                'agent' => (int) $data['qemu_guest_agent'], 'custom_yaml' => $data['custom_yaml'], 'snippet' => $data['snippet_volume'],
                'global' => (int) $data['is_global'], 'enabled' => (int) $data['enabled'], 'created_by' => $actorUserId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->replaceKeys($id, $data['owner_user_id'], $data['ssh_key_ids']);
            return $id;
        });
    }

    /** @param array<string,mixed> $input */
    public function update(int $actorUserId, bool $isAdmin, int $profileId, array $input): void
    {
        $existing = $this->owned($actorUserId, $isAdmin, $profileId);
        $merged = [...$existing, ...$input];
        $data = $this->validate($actorUserId, $isAdmin, $merged, $existing);
        $this->transaction(function () use ($profileId, $data): void {
            $statement = $this->pdo->prepare(
                'UPDATE cloud_init_profiles SET owner_user_id=:owner,name=:name,description=:description,system_user=:system_user,
                 dns_servers=:dns,search_domain=:search_domain,timezone=:timezone,packages=:packages,runcmd=:runcmd,
                 qemu_guest_agent=:agent,custom_yaml=:custom_yaml,snippet_volume=:snippet,is_global=:global,enabled=:enabled WHERE id=:id'
            );
            $statement->execute([
                'owner' => $data['owner_user_id'], 'name' => $data['name'], 'description' => $data['description'],
                'system_user' => $data['system_user'], 'dns' => $data['dns_servers'], 'search_domain' => $data['search_domain'],
                'timezone' => $data['timezone'], 'packages' => $this->json($data['packages']), 'runcmd' => $this->json($data['runcmd']),
                'agent' => (int) $data['qemu_guest_agent'], 'custom_yaml' => $data['custom_yaml'], 'snippet' => $data['snippet_volume'],
                'global' => (int) $data['is_global'], 'enabled' => (int) $data['enabled'], 'id' => $profileId,
            ]);
            $this->replaceKeys($profileId, $data['owner_user_id'], $data['ssh_key_ids']);
        });
    }

    public function delete(int $actorUserId, bool $isAdmin, int $profileId): void
    {
        $this->owned($actorUserId, $isAdmin, $profileId);
        $statement = $this->pdo->prepare('DELETE FROM cloud_init_profiles WHERE id=:id');
        $statement->execute(['id' => $profileId]);
    }

    /** @return array<string,mixed> */
    public function owned(int $actorUserId, bool $isAdmin, int $profileId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM cloud_init_profiles WHERE id=:id LIMIT 1');
        $statement->execute(['id' => $profileId]);
        $row = $statement->fetch();
        if (!is_array($row)) throw new HttpException(404, 'Profil Cloud-Init nie istnieje.');
        if (!$isAdmin && ((int) ($row['is_global'] ?? 0) === 1 || (int) ($row['owner_user_id'] ?? 0) !== $actorUserId)) {
            throw new HttpException(403, 'Nie możesz modyfikować tego profilu Cloud-Init.');
        }
        $keys = $this->pdo->prepare('SELECT ssh_key_id FROM cloud_init_profile_ssh_keys WHERE profile_id=:id ORDER BY ssh_key_id');
        $keys->execute(['id' => $profileId]);
        $row['ssh_key_ids'] = array_map('intval', $keys->fetchAll(PDO::FETCH_COLUMN));
        return $this->normalizeRow($row);
    }

    /** @param array<string,mixed> $profile */
    public function vendorData(array $profile): string
    {
        $lines = ['#cloud-config'];
        if (!empty($profile['timezone'])) $lines[] = 'timezone: ' . $this->yamlString((string) $profile['timezone']);
        if (($profile['packages'] ?? []) !== []) {
            $lines[] = 'packages:';
            foreach ($profile['packages'] as $package) $lines[] = '  - ' . $this->yamlString((string) $package);
        }
        if (($profile['runcmd'] ?? []) !== []) {
            $lines[] = 'runcmd:';
            foreach ($profile['runcmd'] as $command) $lines[] = '  - ' . $this->yamlString((string) $command);
        }
        $custom = trim((string) ($profile['custom_yaml'] ?? ''));
        if ($custom !== '') {
            $custom = preg_replace('/^\s*#cloud-config\s*\r?\n?/i', '', $custom) ?? $custom;
            $lines[] = '# custom profile fragment';
            $lines[] = rtrim($custom);
        }
        if (count($lines) === 1) $lines[] = '{}';
        return implode("\n", $lines) . "\n";
    }

    /** @param array<string,mixed> $profile */
    public function needsSnippet(array $profile): bool
    {
        return !empty($profile['timezone']) || ($profile['packages'] ?? []) !== [] || ($profile['runcmd'] ?? []) !== [] || trim((string) ($profile['custom_yaml'] ?? '')) !== '';
    }

    /** @param array<string,mixed> $input @param array<string,mixed>|null $existing @return array<string,mixed> */
    private function validate(int $actorUserId, bool $isAdmin, array $input, ?array $existing = null): array
    {
        $global = $isAdmin && filter_var($input['is_global'] ?? false, FILTER_VALIDATE_BOOL);
        $owner = $global ? null : ($isAdmin && isset($input['owner_user_id']) && (int) $input['owner_user_id'] > 0 ? (int) $input['owner_user_id'] : $actorUserId);
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $systemUser = trim((string) ($input['system_user'] ?? 'clouduser'));
        $dns = $this->dns((string) ($input['dns_servers'] ?? ''));
        $search = trim((string) ($input['search_domain'] ?? ''));
        $timezone = trim((string) ($input['timezone'] ?? ''));
        $packages = $this->listValue($input['packages'] ?? []);
        $runcmd = $this->listValue($input['runcmd'] ?? []);
        $custom = trim((string) ($input['custom_yaml'] ?? ''));
        $snippet = trim((string) ($input['snippet_volume'] ?? ''));
        $keyIds = SshKeyService::ids($input['ssh_key_ids'] ?? ($existing['ssh_key_ids'] ?? []));
        $agent = !array_key_exists('qemu_guest_agent', $input) || filter_var($input['qemu_guest_agent'], FILTER_VALIDATE_BOOL);
        $enabled = !array_key_exists('enabled', $input) || filter_var($input['enabled'], FILTER_VALIDATE_BOOL);

        if ($name === '' || mb_strlen($name) > 100) throw new HttpException(422, 'Nazwa profilu Cloud-Init jest wymagana i może mieć maksymalnie 100 znaków.');
        if (mb_strlen($description) > 1000) throw new HttpException(422, 'Opis profilu Cloud-Init może mieć maksymalnie 1000 znaków.');
        if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $systemUser) !== 1) throw new HttpException(422, 'Nieprawidłowy użytkownik systemowy Cloud-Init.');
        if ($search !== '' && preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/', $search) !== 1) throw new HttpException(422, 'Nieprawidłowa domena wyszukiwania DNS.');
        if ($timezone !== '' && !in_array($timezone, timezone_identifiers_list(), true)) throw new HttpException(422, 'Nieprawidłowa strefa czasowa Cloud-Init.');
        if (count($packages) > 100 || count($runcmd) > 100) throw new HttpException(422, 'Profil może zawierać maksymalnie po 100 pakietów i poleceń runcmd.');
        foreach ($packages as $package) if (strlen($package) > 200 || preg_match('/[\r\n\0]/', $package)) throw new HttpException(422, 'Nieprawidłowa nazwa pakietu w profilu Cloud-Init.');
        foreach ($runcmd as $command) if (strlen($command) > 2000 || preg_match('/[\r\n\0]/', $command)) throw new HttpException(422, 'Jedno polecenie runcmd może mieć maksymalnie 2000 znaków i musi zajmować jeden wiersz.');
        if (strlen($custom) > 65535) throw new HttpException(422, 'Własny fragment YAML może mieć maksymalnie 65535 bajtów.');
        if ($snippet !== '' && preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]{0,99}:snippets/[A-Za-z0-9][A-Za-z0-9._-]{0,190}\.(?:ya?ml|cfg)$#i', $snippet) !== 1) {
            throw new HttpException(422, 'Referencja snippet musi mieć format storage:snippets/nazwa.yaml lub storage:snippets/nazwa.cfg.');
        }
        $candidate = ['timezone' => $timezone, 'packages' => $packages, 'runcmd' => $runcmd, 'custom_yaml' => $custom];
        if ($this->needsSnippet($candidate) && $snippet === '') {
            throw new HttpException(422, 'Packages, runcmd, timezone i własny YAML wymagają wskazania wcześniej umieszczonego pliku Proxmox snippets. API Proxmox nie obsługuje uploadu snippetów.');
        }
        if ($global && $keyIds !== []) throw new HttpException(422, 'Profil globalny nie może zawierać prywatnych kluczy SSH użytkownika. Klucze wybiera właściciel podczas tworzenia VM.');
        if ($owner !== null) $this->assertActiveUser($owner);

        return [
            'owner_user_id' => $owner, 'name' => $name, 'description' => $description === '' ? null : $description,
            'system_user' => $systemUser, 'dns_servers' => $dns === '' ? null : $dns, 'search_domain' => $search === '' ? null : $search,
            'timezone' => $timezone === '' ? null : $timezone, 'packages' => $packages, 'runcmd' => $runcmd,
            'qemu_guest_agent' => $agent, 'custom_yaml' => $custom === '' ? null : $custom, 'snippet_volume' => $snippet === '' ? null : $snippet,
            'is_global' => $global, 'enabled' => $enabled, 'ssh_key_ids' => $keyIds,
        ];
    }

    /** @param list<int> $keyIds */
    private function replaceKeys(int $profileId, ?int $ownerUserId, array $keyIds): void
    {
        $this->pdo->prepare('DELETE FROM cloud_init_profile_ssh_keys WHERE profile_id=:profile')->execute(['profile' => $profileId]);
        if ($keyIds === []) return;
        if ($ownerUserId === null) throw new HttpException(422, 'Profil globalny nie może zawierać kluczy SSH użytkownika.');
        (new SshKeyService($this->pdo))->resolve($ownerUserId, $keyIds);
        $insert = $this->pdo->prepare('INSERT INTO cloud_init_profile_ssh_keys (profile_id,ssh_key_id) VALUES (:profile,:key)');
        foreach ($keyIds as $keyId) $insert->execute(['profile' => $profileId, 'key' => $keyId]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeRow(array $row): array
    {
        foreach (['packages', 'runcmd'] as $field) {
            if (is_string($row[$field] ?? null)) {
                try { $row[$field] = json_decode((string) $row[$field], true, 64, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { $row[$field] = []; }
            }
            if (!is_array($row[$field] ?? null)) $row[$field] = [];
        }
        if (is_string($row['ssh_key_ids'] ?? null)) {
            $row['ssh_key_ids'] = SshKeyService::ids((string) $row['ssh_key_ids']);
        } elseif (!is_array($row['ssh_key_ids'] ?? null)) {
            $row['ssh_key_ids'] = [];
        } else {
            $row['ssh_key_ids'] = array_map('intval', $row['ssh_key_ids']);
        }
        foreach (['qemu_guest_agent', 'is_global', 'enabled'] as $field) $row[$field] = (bool) ($row[$field] ?? false);
        $row['needs_snippet'] = $this->needsSnippet($row);
        return $row;
    }

    /** @return list<string> */
    private function listValue(mixed $value): array
    {
        if (is_array($value)) $items = $value;
        else $items = preg_split('/\r?\n/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter(array_map(static fn (mixed $v): string => trim((string) $v), $items), static fn (string $v): bool => $v !== ''));
    }

    private function dns(string $value): string
    {
        $items = preg_split('/[\s,;]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($items) > 6) throw new HttpException(422, 'Profil może zawierać maksymalnie 6 serwerów DNS.');
        foreach ($items as $ip) if (filter_var($ip, FILTER_VALIDATE_IP) === false) throw new HttpException(422, 'Lista DNS zawiera nieprawidłowy adres IP.');
        return implode(',', array_values(array_unique($items)));
    }

    private function assertActiveUser(int $userId): void
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM users WHERE id=:id AND status='active'");
        $statement->execute(['id' => $userId]);
        if (!$statement->fetchColumn()) throw new HttpException(422, 'Właściciel profilu Cloud-Init nie istnieje albo nie jest aktywny.');
    }

    private function json(array $value): ?string
    {
        return $value === [] ? null : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function yamlString(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function transaction(callable $callback): mixed
    {
        $started = !$this->pdo->inTransaction();
        if ($started) $this->pdo->beginTransaction();
        try {
            $result = $callback();
            if ($started) $this->pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($started && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }
}
