<?php

declare(strict_types=1);

namespace CloudPortal\Services\Notifications;

use CloudPortal\Security\Crypto;
use CloudPortal\Support\Uuid;
use PDO;

final class WebhookService
{
    public function __construct(private readonly PDO $pdo, private readonly Crypto $crypto)
    {
    }

    /** @param array<string,mixed> $payload */
    public function publish(string $event, array $payload): void
    {
        $hooks = $this->pdo->query('SELECT * FROM webhooks WHERE enabled=1 ORDER BY id')->fetchAll();
        foreach ($hooks as $hook) {
            $events = json_decode((string) $hook['events'], true);
            if (!is_array($events) || (!in_array('*', $events, true) && !in_array($event, $events, true))) {
                continue;
            }
            $this->deliver($hook, $event, $payload);
        }
    }

    /** @param array<string,mixed> $hook @param array<string,mixed> $payload */
    private function deliver(array $hook, string $event, array $payload): void
    {
        $deliveryId = Uuid::v4();
        $body = json_encode([
            'id' => $deliveryId,
            'event' => $event,
            'occurred_at' => gmdate(DATE_ATOM),
            'data' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $secret = $this->crypto->decrypt((string) $hook['secret_encrypted']);
        $signature = hash_hmac('sha256', $body, $secret);
        $curl = curl_init((string) $hook['url']);
        if ($curl === false) {
            return;
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Algen-Proxmox-CloudPortal/1.1',
                'X-CloudPortal-Event: ' . $event,
                'X-CloudPortal-Delivery: ' . $deliveryId,
                'X-CloudPortal-Signature: sha256=' . $signature,
            ],
        ]);
        curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $success = $code >= 200 && $code < 300;
        $statement = $this->pdo->prepare(
            'INSERT INTO webhook_deliveries (webhook_id, event_name, delivery_id, response_code, success, error_message)
             VALUES (:hook,:event,:delivery,:code,:success,:error)'
        );
        $statement->execute([
            'hook' => $hook['id'],
            'event' => $event,
            'delivery' => $deliveryId,
            'code' => $code > 0 ? $code : null,
            'success' => $success ? 1 : 0,
            'error' => $success ? null : mb_substr($error !== '' ? $error : 'HTTP ' . $code, 0, 1000),
        ]);
    }
}
