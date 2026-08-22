<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;

final class PlacementAdminController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function nodes(Request $request): Response
    {
        $this->app->auth()->requirePermission('admin.access');
        $statement = $this->app->pdo()->prepare('SELECT node_name,status,maintenance_mode,placement_weight,cpu_usage,memory_total,memory_used,last_seen_at FROM proxmox_nodes WHERE connection_id=:connection ORDER BY node_name');
        $statement->execute(['connection' => (int) $request->param('connectionId')]);
        return Response::json(['data' => $statement->fetchAll()]);
    }

    public function updateNode(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $this->app->auth()->requirePermission('admin.access');
        $maintenance = filter_var($request->input('maintenance_mode', false), FILTER_VALIDATE_BOOL) ? 1 : 0;
        $weight = (int) $request->input('placement_weight', 100);
        if ($weight < 1 || $weight > 1000) {
            throw new HttpException(422, 'placement_weight must be between 1 and 1000.');
        }
        $connection = (int) $request->param('connectionId');
        $node = $request->param('node');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $node) !== 1) {
            throw new HttpException(422, 'Invalid Proxmox node name.');
        }
        $statement = $this->app->pdo()->prepare('UPDATE proxmox_nodes SET maintenance_mode=:maintenance,placement_weight=:weight WHERE connection_id=:connection AND node_name=:node');
        $statement->execute(['maintenance' => $maintenance, 'weight' => $weight, 'connection' => $connection, 'node' => $node]);
        if ($statement->rowCount() === 0) {
            $exists = $this->app->pdo()->prepare('SELECT 1 FROM proxmox_nodes WHERE connection_id=:connection AND node_name=:node');
            $exists->execute(['connection' => $connection, 'node' => $node]);
            if (!$exists->fetchColumn()) throw new HttpException(404, 'Proxmox node not found.');
        }
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'placement.node.update', 'success', 'proxmox_node', $connection . '/' . $node, ['maintenance_mode' => (bool) $maintenance, 'placement_weight' => $weight]);
        return Response::json(['data' => ['node_name' => $node, 'maintenance_mode' => (bool) $maintenance, 'placement_weight' => $weight]]);
    }
}
