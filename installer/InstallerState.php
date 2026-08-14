<?php

declare(strict_types=1);

namespace CloudPortal\Installer;

use CloudPortal\Http\HttpException;

final class InstallerState
{
    private const SESSION_KEY = 'cloud_portal_installer';
    public const VISIBLE_STEPS = [0, 1, 2, 4, 5, 6, 9];

    /** @return array<string,mixed> */
    public function all(): array
    {
        $this->boot();
        return $_SESSION[self::SESSION_KEY];
    }

    public function completedStep(): int
    {
        return (int) ($this->all()['completed_step'] ?? -1);
    }

    public function nextStep(): int
    {
        $completed = $this->completedStep();
        foreach ($this->visibleSteps() as $step) {
            if ($step > $completed) return $step;
        }
        return 9;
    }

    public function canView(int $step): bool
    {
        return in_array($step, $this->visibleSteps(), true) && $step <= $this->nextStep();
    }

    public function assertSubmittable(int $step): void
    {
        if (!in_array($step, $this->visibleSteps(), true) || $step > $this->nextStep()) {
            throw new HttpException(409, 'Installer steps must be completed in order.');
        }
    }

    public function markCompleted(int $step): void
    {
        $this->boot();
        $_SESSION[self::SESSION_KEY]['completed_step'] = max($this->completedStep(), $step);
        $_SESSION[self::SESSION_KEY]['updated_at'] = time();
    }

    public function put(string $key, mixed $value): void
    {
        $this->boot();
        $_SESSION[self::SESSION_KEY][$key] = $value;
        $_SESSION[self::SESSION_KEY]['updated_at'] = time();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /** @return list<int> */
    public function visibleSteps(): array
    {
        if (($this->all()['requirements_auto_passed'] ?? false) !== true) return self::VISIBLE_STEPS;
        return array_values(array_filter(self::VISIBLE_STEPS, static fn (int $step): bool => $step !== 1));
    }

    /** @return array{app_key:string,encryption_key:string,csrf_secret:string} */
    public function security(): array
    {
        $security = $this->get('security');
        if (!is_array($security)) {
            $security = [
                'app_key' => base64_encode(random_bytes(32)),
                'encryption_key' => base64_encode(random_bytes(32)),
                'csrf_secret' => base64_encode(random_bytes(32)),
            ];
            $this->put('security', $security);
        }
        return $security;
    }

    /** @param array<string,mixed> $summary */
    public function finish(array $summary): void
    {
        $_SESSION['installer_finish'] = ['summary' => $summary, 'created_at' => time()];
        $this->clear();
    }

    /** @return array<string,mixed>|null */
    public function finishSummary(): ?array
    {
        $finish = $_SESSION['installer_finish'] ?? null;
        if (!is_array($finish) || time() - (int) ($finish['created_at'] ?? 0) > 3600 || !is_array($finish['summary'] ?? null)) {
            unset($_SESSION['installer_finish']);
            return null;
        }
        return $finish['summary'];
    }

    public function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    private function boot(): void
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [
                'install_id' => bin2hex(random_bytes(16)),
                'completed_step' => -1,
                'started_at' => time(),
                'updated_at' => time(),
            ];
        }
    }
}
