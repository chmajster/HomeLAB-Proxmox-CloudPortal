<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;

final class QuotaAdminController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function upsert(Request $request): Response
    {
        $this->mutating($request);
        [$column, $subjectId] = $this->subject($request);
        $values = [
            'max_vms' => $this->integer($request->input('max_vms'), 0, 100000),
            'max_vcpu' => $this->integer($request->input('max_vcpu'), 0, 1000000),
            'max_ram_mb' => $this->integer($request->input('max_ram_mb'), 0, PHP_INT_MAX),
            'max_storage_gb' => $this->integer($request->input('max_storage_gb'), 0, PHP_INT_MAX),
            'max_snapshots' => $this->integer($request->input('max_snapshots'), 0, 1000000),
            'max_backups' => $this->integer($request->input('max_backups', 0), 0, 1000000),
            'max_backup_storage_gb' => $this->integer($request->input('max_backup_storage_gb', 0), 0, PHP_INT_MAX),
            'max_parallel_jobs' => $this->integer($request->input('max_parallel_jobs', 0), 0, 1000000),
        ];
        $maxIps = $request->input('max_ip_addresses');
        $values['max_ip_addresses'] = $maxIps === null || $maxIps === '' ? null : $this->integer($maxIps, 0, 1000000);

        $sql = "INSERT INTO quotas ({$column},max_vms,max_vcpu,max_ram_mb,max_storage_gb,max_snapshots,max_backups,max_backup_storage_gb,max_ip_addresses,max_parallel_jobs)
                VALUES (:subject,:max_vms,:max_vcpu,:max_ram_mb,:max_storage_gb,:max_snapshots,:max_backups,:max_backup_storage_gb,:max_ip_addresses,:max_parallel_jobs)
                ON DUPLICATE KEY UPDATE max_vms=VALUES(max_vms),max_vcpu=VALUES(max_vcpu),max_ram_mb=VALUES(max_ram_mb),
                  max_storage_gb=VALUES(max_storage_gb),max_snapshots=VALUES(max_snapshots),max_backups=VALUES(max_backups),
                  max_backup_storage_gb=VALUES(max_backup_storage_gb),max_ip_addresses=VALUES(max_ip_addresses),max_parallel_jobs=VALUES(max_parallel_jobs)";
        $this->app->pdo()->prepare($sql)->execute(['subject' => $subjectId, ...$values]);
        $lookup = $this->app->pdo()->prepare("SELECT id FROM quotas WHERE {$column}=:id");
        $lookup->execute(['id' => $subjectId]);
        $id = (int) $lookup->fetchColumn();
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'admin.quotas.update', 'success', 'quota', $id, ['subject_column' => $column, 'subject_id' => $subjectId]);
        return Response::json(['data' => ['id' => $id, 'updated' => true]], 200);
    }

    public function templateLimits(Request $request): Response
    {
        $this->admin();
        $rows = $this->app->pdo()->query(
            "SELECT qtl.id,qtl.project_id,qtl.user_id,qtl.template_id,qtl.max_vms,qtl.created_at,qtl.updated_at,
                    p.name AS project_name,u.username,t.name AS template_name
             FROM quota_template_limits qtl
             LEFT JOIN projects p ON p.id=qtl.project_id
             LEFT JOIN users u ON u.id=qtl.user_id
             JOIN vm_templates t ON t.id=qtl.template_id
             ORDER BY qtl.id"
        )->fetchAll();
        return Response::json(['data' => $rows]);
    }

    public function upsertTemplateLimit(Request $request): Response
    {
        $this->mutating($request);
        [$column, $subjectId] = $this->subject($request);
        $templateId = $this->integer($request->input('template_id'), 1, PHP_INT_MAX);
        $maxVms = $this->integer($request->input('max_vms'), 0, 100000);
        $otherColumn = $column === 'project_id' ? 'user_id' : 'project_id';
        $statement = $this->app->pdo()->prepare(
            "INSERT INTO quota_template_limits ({$column},{$otherColumn},template_id,max_vms)
             VALUES (:subject,NULL,:template,:max_vms)
             ON DUPLICATE KEY UPDATE max_vms=VALUES(max_vms)"
        );
        $statement->execute(['subject' => $subjectId, 'template' => $templateId, 'max_vms' => $maxVms]);
        $lookup = $this->app->pdo()->prepare("SELECT id FROM quota_template_limits WHERE {$column}=:subject AND template_id=:template");
        $lookup->execute(['subject' => $subjectId, 'template' => $templateId]);
        $id = (int) $lookup->fetchColumn();
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'admin.quota_template_limit.update', 'success', 'quota_template_limit', $id, ['subject_column' => $column, 'subject_id' => $subjectId, 'template_id' => $templateId, 'max_vms' => $maxVms]);
        return Response::json(['data' => ['id' => $id, 'updated' => true]], 200);
    }

    public function deleteTemplateLimit(Request $request): Response
    {
        $this->mutating($request);
        $id = $this->integer($request->param('id'), 1, PHP_INT_MAX);
        $statement = $this->app->pdo()->prepare('DELETE FROM quota_template_limits WHERE id=:id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() !== 1) throw new HttpException(404, 'Template quota limit not found.');
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'admin.quota_template_limit.delete', 'success', 'quota_template_limit', $id);
        return Response::json(['data' => ['deleted' => true]]);
    }

    /** @return array{string,int} */
    private function subject(Request $request): array
    {
        $subject = (string) $request->input('subject_type');
        if (!in_array($subject, ['project', 'user'], true)) throw new HttpException(422, 'Invalid quota subject.');
        $subjectId = $this->integer($request->input('subject_id'), 1, PHP_INT_MAX);
        return [$subject === 'project' ? 'project_id' : 'user_id', $subjectId];
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($int === false) throw new HttpException(422, 'Numeric value is outside the allowed range.');
        return (int) $int;
    }

    private function mutating(Request $request): void
    {
        $this->admin();
        $this->app->csrf->verify($request);
    }

    private function admin(): void
    {
        $this->app->auth()->requirePermission('admin.access');
    }
}
