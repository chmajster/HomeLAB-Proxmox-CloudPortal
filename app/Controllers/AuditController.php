<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use DateTimeImmutable;
use DateTimeZone;

final class AuditController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function search(Request $request): Response
    {
        $this->app->auth()->requirePermission('admin.access');
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(200, max(10, (int) $request->query('per_page', 50)));
        [$where, $params] = $this->filters($request);
        $count = $this->app->pdo()->prepare('SELECT COUNT(*) ' . $this->fromSql() . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sql = $this->selectSql() . $where . ' ORDER BY a.created_at DESC,a.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
        $statement = $this->app->pdo()->prepare($sql);
        $statement->execute($params);
        return Response::json(['data' => [
            'items' => array_map([$this, 'normalize'], $statement->fetchAll()),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ]]);
    }

    public function export(Request $request): Response
    {
        $this->app->auth()->requirePermission('admin.access');
        [$where, $params] = $this->filters($request);
        $statement = $this->app->pdo()->prepare($this->selectSql() . $where . ' ORDER BY a.created_at DESC,a.id DESC LIMIT 10000');
        $statement->execute($params);
        $rows = array_map([$this, 'normalize'], $statement->fetchAll());
        $format = strtolower(trim((string) $request->query('format', 'csv')));
        $stamp = gmdate('Ymd-His');

        if ($format === 'json') {
            return new Response(
                json_encode(['data' => $rows], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                200,
                ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="audit-' . $stamp . '.json"'],
            );
        }
        if ($format !== 'csv') throw new HttpException(422, 'Format eksportu musi mieć wartość csv albo json.');

        $stream = fopen('php://temp', 'w+');
        if ($stream === false) throw new \RuntimeException('Nie można przygotować eksportu CSV.');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['id','created_at','username','ip_address','action','result','project_id','project_name','virtual_machine_id','vm_name','job_id','job_public_id','proxmox_upid','resource_type','resource_id','metadata']);
        foreach ($rows as $row) {
            fputcsv($stream, array_map([$this, 'csvCell'], [
                $row['id'], $row['created_at'], $row['username'], $row['ip_address'], $row['action'], $row['result'],
                $row['project_id'], $row['project_name'], $row['virtual_machine_id'], $row['vm_name'], $row['job_id'],
                $row['job_public_id'], $row['proxmox_upid'], $row['resource_type'], $row['resource_id'],
                $row['metadata'] === null ? '' : json_encode($row['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]));
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($csv)) throw new \RuntimeException('Nie można odczytać eksportu CSV.');
        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="audit-' . $stamp . '.csv"',
        ]);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function filters(Request $request): array
    {
        $clauses = [];
        $params = [];
        $integer = function (string $query, string $column) use ($request, &$clauses, &$params): void {
            $value = trim((string) $request->query($query, ''));
            if ($value === '') return;
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new HttpException(422, 'Nieprawidłowy filtr ' . $query . '.');
            $clauses[] = $column . '=:' . $query;
            $params[$query] = (int) $id;
        };
        $integer('user_id', 'a.user_id');
        $integer('project_id', 'a.project_id');
        $integer('vm_id', 'a.virtual_machine_id');

        $result = trim((string) $request->query('result', ''));
        if ($result !== '') {
            if (!in_array($result, ['success', 'failure'], true)) throw new HttpException(422, 'Nieprawidłowy filtr result.');
            $clauses[] = 'a.result=:result';
            $params['result'] = $result;
        }
        $action = trim((string) $request->query('action', ''));
        if ($action !== '') {
            $clauses[] = 'a.action LIKE :action';
            $params['action'] = '%' . $this->like($action) . '%';
        }
        $upid = trim((string) $request->query('proxmox_upid', ''));
        if ($upid !== '') {
            $clauses[] = 'COALESCE(a.proxmox_upid,j.proxmox_upid) LIKE :upid';
            $params['upid'] = '%' . $this->like($upid) . '%';
        }
        $job = trim((string) $request->query('job', ''));
        if ($job !== '') {
            if (ctype_digit($job)) {
                $clauses[] = '(a.job_id=:job_numeric OR j.public_id=:job_text)';
                $params['job_numeric'] = (int) $job;
                $params['job_text'] = $job;
            } else {
                $clauses[] = 'j.public_id=:job_text';
                $params['job_text'] = $job;
            }
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $clauses[] = '(u.username LIKE :q OR a.ip_address LIKE :q OR a.action LIKE :q OR p.name LIKE :q OR vm.name LIKE :q OR j.public_id LIKE :q OR COALESCE(a.proxmox_upid,j.proxmox_upid) LIKE :q OR a.resource_id LIKE :q)';
            $params['q'] = '%' . $this->like($q) . '%';
        }
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            $value = trim((string) $request->query($field, ''));
            if ($value === '') continue;
            try {
                $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            } catch (\Throwable) {
                throw new HttpException(422, 'Nieprawidłowa data filtra ' . $field . '.');
            }
            $clauses[] = 'a.created_at ' . $operator . ' :' . $field;
            $params[$field] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }

    private function selectSql(): string
    {
        return 'SELECT a.id,a.created_at,a.user_id,u.username,a.ip_address,a.action,a.result,a.resource_type,a.resource_id,a.metadata,
                       a.project_id,p.name AS project_name,a.virtual_machine_id,vm.name AS vm_name,a.job_id,j.public_id AS job_public_id,
                       COALESCE(a.proxmox_upid,j.proxmox_upid) AS proxmox_upid ' . $this->fromSql();
    }

    private function fromSql(): string
    {
        return 'FROM audit_logs a
                LEFT JOIN users u ON u.id=a.user_id
                LEFT JOIN projects p ON p.id=a.project_id
                LEFT JOIN virtual_machines vm ON vm.id=a.virtual_machine_id
                LEFT JOIN jobs j ON j.id=a.job_id ';
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalize(array $row): array
    {
        if (is_string($row['metadata'] ?? null)) {
            try { $row['metadata'] = json_decode((string) $row['metadata'], true, 64, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { $row['metadata'] = null; }
        }
        foreach (['id','user_id','project_id','virtual_machine_id','job_id'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        return $row;
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($value, 0, 255));
    }

    private function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }
}
