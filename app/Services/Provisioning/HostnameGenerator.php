<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use PDO;

final class HostnameGenerator
{
    public function __construct(private readonly PDO $pdo, private readonly string $pattern)
    {
    }

    public function generate(int $projectId, int $userId): string
    {
        $project = $this->value('SELECT slug FROM projects WHERE id=:id', $projectId, 'project-' . $projectId);
        $user = $this->value('SELECT username FROM users WHERE id=:id', $userId, 'user-' . $userId);
        $counter = $this->nextCounter($projectId);

        $hostname = $this->expandPattern($project, $user, $counter);
        $hostname = $this->label($hostname, 'vm-' . $counter);

        if (strlen($hostname) > 63) {
            $suffix = '-' . $counter;
            $hostname = rtrim(substr($hostname, 0, max(1, 63 - strlen($suffix))), '-') . $suffix;
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $hostname) !== 1) {
            throw new \RuntimeException('Generated hostname is not a valid DNS/Proxmox hostname label.');
        }
        return $hostname;
    }

    private function expandPattern(string $project, string $user, int $counter): string
    {
        $replacements = [
            'project' => $this->label($project, 'project'),
            'user' => $this->label($user, 'user'),
            'counter' => (string) $counter,
        ];
        $seenCounter = false;
        $expanded = preg_replace_callback(
            '/\{(project|user|counter)(?::0([1-9][0-9]?))?\}/',
            static function (array $match) use ($replacements, $counter, &$seenCounter): string {
                $name = (string) $match[1];
                $width = isset($match[2]) ? (int) $match[2] : 0;
                if ($name !== 'counter' && $width > 0) {
                    throw new \RuntimeException('Width formatting is supported only for {counter:0N}.');
                }
                if ($name === 'counter') {
                    $seenCounter = true;
                    return $width > 0 ? str_pad((string) $counter, $width, '0', STR_PAD_LEFT) : (string) $counter;
                }
                return $replacements[$name];
            },
            $this->pattern,
        );
        if (!is_string($expanded) || !$seenCounter) {
            throw new \RuntimeException('Hostname generator pattern must contain {counter} or {counter:0N}.');
        }
        if (preg_match('/[{}]/', $expanded) === 1) {
            throw new \RuntimeException('Hostname generator pattern contains an unsupported placeholder.');
        }
        return $expanded;
    }

    private function nextCounter(int $projectId): int
    {
        $scope = 'project:' . $projectId . ':' . substr(hash('sha256', $this->pattern), 0, 32);
        $insert = $this->pdo->prepare('INSERT INTO hostname_sequences(scope_key,`last_value`) VALUES(:scope,0) ON DUPLICATE KEY UPDATE scope_key=VALUES(scope_key)');
        $insert->execute(['scope' => $scope]);
        $select = $this->pdo->prepare('SELECT `last_value` FROM hostname_sequences WHERE scope_key=:scope FOR UPDATE');
        $select->execute(['scope' => $scope]);
        $counter = (int) $select->fetchColumn() + 1;
        $update = $this->pdo->prepare('UPDATE hostname_sequences SET `last_value`=:counter WHERE scope_key=:scope');
        $update->execute(['counter' => $counter, 'scope' => $scope]);
        return $counter;
    }

    private function value(string $sql, int $id, string $fallback): string
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $id]);
        $value = $statement->fetchColumn();
        return is_string($value) && trim($value) !== '' ? $value : $fallback;
    }

    private function label(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        if ($value === '') {
            $value = strtolower($fallback);
            $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? 'vm';
            $value = trim($value, '-');
        }
        return $value;
    }
}
