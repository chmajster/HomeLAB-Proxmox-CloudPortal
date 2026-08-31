#!/usr/bin/env php
<?php

declare(strict_types=1);

use CloudPortal\Application;
use CloudPortal\Services\Proxmox\ConsoleToken;

$root = dirname(__DIR__);
$autoload = is_file($root . '/vendor/autoload.php') ? $root . '/vendor/autoload.php' : $root . '/autoload.php';
require $autoload;

$options = getopt('', ['listen::']);
$listen = trim((string) ($options['listen'] ?? '127.0.0.1:6080'));
if (preg_match('/^(\[[0-9A-Fa-f:]+\]|[A-Za-z0-9._-]+):(\d{1,5})$/', $listen, $match) !== 1) {
    fwrite(STDERR, "Invalid --listen value. Expected host:port.\n");
    exit(2);
}
$port = (int) $match[2];
if ($port < 1 || $port > 65535) {
    fwrite(STDERR, "Invalid console gateway port.\n");
    exit(2);
}

$server = @stream_socket_server('tcp://' . $listen, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
if (!is_resource($server)) {
    fwrite(STDERR, "Unable to start console gateway: {$error} ({$errno})\n");
    exit(1);
}
stream_set_blocking($server, true);
fwrite(STDOUT, "Cloud Portal console gateway listening on {$listen}\n");

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGCHLD, SIG_IGN);
}

while (true) {
    $client = @stream_socket_accept($server, -1);
    if (!is_resource($client)) continue;

    if (function_exists('pcntl_fork')) {
        $pid = pcntl_fork();
        if ($pid > 0) {
            fclose($client);
            continue;
        }
        if ($pid === 0) {
            fclose($server);
            handleClient($client, $root);
            fclose($client);
            exit(0);
        }
    }

    handleClient($client, $root);
    fclose($client);
}

/** @param resource $client */
function handleClient($client, string $root): void
{
    stream_set_timeout($client, 10);
    try {
        [$requestLine, $headers, $clientRemainder] = readHeaders($client, 16384);
        if (preg_match('#^GET\s+/(?:console/ws/)?([^\s?]+)(?:\?[^\s]*)?\s+HTTP/1\.[01]$#', $requestLine, $match) !== 1) {
            reject($client, 400, 'Bad Request');
            return;
        }
        $key = trim((string) ($headers['sec-websocket-key'] ?? ''));
        if ($key === '' || strtolower((string) ($headers['upgrade'] ?? '')) !== 'websocket' || !str_contains(strtolower((string) ($headers['connection'] ?? '')), 'upgrade')) {
            reject($client, 400, 'WebSocket upgrade required');
            return;
        }

        $app = new Application($root);
        $secret = (string) $app->config->get('security.encryption_key', $app->config->get('app.key', ''));
        $claims = (new ConsoleToken($secret))->verify(rawurldecode($match[1]));
        $connectionId = positiveInt($claims['connection_id'] ?? null);
        $vmid = boundedInt($claims['vmid'] ?? null, 100, 999999999);
        $proxyPort = boundedInt($claims['port'] ?? null, 1, 65535);
        $node = trim((string) ($claims['node'] ?? ''));
        $ticket = (string) ($claims['ticket'] ?? '');
        if ($connectionId === null || $vmid === null || $proxyPort === null || $ticket === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $node) !== 1) {
            throw new RuntimeException('Invalid console token claims.');
        }

        $statement = $app->pdo()->prepare("SELECT hostname,port,realm,api_token_id,api_token_secret_encrypted,verify_ssl,status FROM proxmox_connections WHERE id=:id LIMIT 1");
        $statement->execute(['id' => $connectionId]);
        $connection = $statement->fetch();
        if (!is_array($connection) || ($connection['status'] ?? null) === 'disabled') throw new RuntimeException('Proxmox connection unavailable.');

        $host = preg_replace('#^https?://#i', '', rtrim(trim((string) $connection['hostname']), '/'));
        if ($host === '' || preg_match('/[\r\n]/', (string) $host)) throw new RuntimeException('Invalid Proxmox hostname.');
        $upstream = openTlsSocket($host, (int) $connection['port'], (bool) $connection['verify_ssl']);
        $tokenId = normalizedTokenId((string) $connection['api_token_id'], (string) $connection['realm']);
        $tokenSecret = $app->crypto()->decrypt((string) $connection['api_token_secret_encrypted']);
        if (preg_match('/[\r\n]/', $tokenId . $tokenSecret . $ticket)) throw new RuntimeException('Invalid console credential data.');

        $upstreamKey = base64_encode(random_bytes(16));
        $path = '/api2/json/nodes/' . rawurlencode($node) . '/qemu/' . $vmid . '/vncwebsocket?port=' . $proxyPort . '&vncticket=' . rawurlencode($ticket);
        $hostHeader = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $host . ']' : $host;
        $request = "GET {$path} HTTP/1.1\r\n"
            . "Host: {$hostHeader}:" . (int) $connection['port'] . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$upstreamKey}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Sec-WebSocket-Protocol: binary\r\n"
            . "Authorization: PVEAPIToken={$tokenId}={$tokenSecret}\r\n\r\n";
        writeAll($upstream, $request);
        [$upstreamStatus, $upstreamHeaders, $upstreamRemainder] = readHeaders($upstream, 32768);
        if (preg_match('#^HTTP/1\.[01]\s+101\b#', $upstreamStatus) !== 1) {
            throw new RuntimeException('Proxmox rejected the WebSocket console connection.');
        }
        $upstreamAccept = trim((string) ($upstreamHeaders['sec-websocket-accept'] ?? ''));
        $expectedUpstreamAccept = base64_encode(sha1($upstreamKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if ($upstreamAccept === '' || !hash_equals($expectedUpstreamAccept, $upstreamAccept)) {
            throw new RuntimeException('Invalid Proxmox WebSocket handshake.');
        }

        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $protocolHeader = str_contains(strtolower((string) ($headers['sec-websocket-protocol'] ?? '')), 'binary')
            ? "Sec-WebSocket-Protocol: binary\r\n"
            : '';
        writeAll($client, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n{$protocolHeader}\r\n");

        if ($clientRemainder !== '') writeAll($upstream, $clientRemainder);
        if ($upstreamRemainder !== '') writeAll($client, $upstreamRemainder);
        tunnel($client, $upstream);
        fclose($upstream);
    } catch (Throwable $exception) {
        error_log('Console gateway connection failed: ' . $exception->getMessage());
        reject($client, 502, 'Console connection failed');
    }
}

/** @param resource $stream @return array{string,array<string,string>,string} */
function readHeaders($stream, int $limit): array
{
    $buffer = '';
    while (!str_contains($buffer, "\r\n\r\n")) {
        $chunk = fread($stream, 4096);
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($stream);
            if (!empty($meta['timed_out'])) throw new RuntimeException('WebSocket handshake timed out.');
            if (feof($stream)) throw new RuntimeException('WebSocket peer closed during handshake.');
            usleep(10000);
            continue;
        }
        $buffer .= $chunk;
        if (strlen($buffer) > $limit) throw new RuntimeException('WebSocket headers are too large.');
    }
    [$headerBlock, $remainder] = explode("\r\n\r\n", $buffer, 2);
    $lines = explode("\r\n", $headerBlock);
    $requestLine = array_shift($lines) ?: '';
    $headers = [];
    foreach ($lines as $line) {
        if (!str_contains($line, ':')) continue;
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }
    return [$requestLine, $headers, $remainder];
}

/** @return resource */
function openTlsSocket(string $host, int $port, bool $verify)
{
    $peerName = trim($host, '[]');
    $connectHost = filter_var($peerName, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $peerName . ']' : $peerName;
    $context = stream_context_create(['ssl' => [
        'verify_peer' => $verify,
        'verify_peer_name' => $verify,
        'allow_self_signed' => !$verify,
        'peer_name' => $peerName,
        'SNI_enabled' => true,
        'disable_compression' => true,
    ]]);
    $socket = @stream_socket_client('tls://' . $connectHost . ':' . $port, $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);
    if (!is_resource($socket)) throw new RuntimeException("Unable to connect to Proxmox console endpoint: {$error} ({$errno}).");
    stream_set_timeout($socket, 15);
    return $socket;
}

/** @param resource $left @param resource $right */
function tunnel($left, $right): void
{
    stream_set_blocking($left, false);
    stream_set_blocking($right, false);
    while (!feof($left) && !feof($right)) {
        $read = [$left, $right];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 30);
        if ($ready === false) break;
        if ($ready === 0) continue;
        foreach ($read as $source) {
            $target = $source === $left ? $right : $left;
            $data = fread($source, 65536);
            if ($data === false || $data === '') {
                if (feof($source)) return;
                continue;
            }
            writeAll($target, $data);
        }
    }
}

/** @param resource $stream */
function writeAll($stream, string $data): void
{
    $offset = 0;
    $length = strlen($data);
    while ($offset < $length) {
        $written = fwrite($stream, substr($data, $offset));
        if ($written === false || $written === 0) throw new RuntimeException('WebSocket write failed.');
        $offset += $written;
    }
}

/** @param resource $client */
function reject($client, int $status, string $message): void
{
    if (!is_resource($client)) return;
    $reasons = [400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 410 => 'Gone', 502 => 'Bad Gateway'];
    $reason = $reasons[$status] ?? 'Error';
    $body = $message . "\n";
    @fwrite($client, "HTTP/1.1 {$status} {$reason}\r\nConnection: close\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}");
}

function positiveInt(mixed $value): ?int
{
    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $parsed === false ? null : (int) $parsed;
}

function boundedInt(mixed $value, int $min, int $max): ?int
{
    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    return $parsed === false ? null : (int) $parsed;
}

function normalizedTokenId(string $tokenId, string $realm): string
{
    if (str_contains($tokenId, '@')) return $tokenId;
    if (str_contains($tokenId, '!')) {
        [$user, $token] = explode('!', $tokenId, 2);
        return $user . '@' . $realm . '!' . $token;
    }
    throw new RuntimeException('Invalid Proxmox API token identifier.');
}
