<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuditLogController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_type' => ['nullable', 'string', 'max:100'],
            'entity_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'uuid'],
            'action' => ['nullable', 'string', 'max:100'],
        ]);
        $page = AuditLog::query()
            ->when($data['entity_type'] ?? null, fn ($q, $v) => $q->where('entity_type', $v))
            ->when($data['entity_id'] ?? null, fn ($q, $v) => $q->where('entity_id', $v))
            ->when($data['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($data['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', $v.'%'))
            ->orderByDesc('created_at')->paginate(min(200, $request->integer('per_page', 50)));

        return ApiResponse::paged($page, $page->getCollection()->map(fn (AuditLog $l) => $l->only([
            'id', 'user_id', 'action', 'entity_type', 'entity_id', 'old_values', 'new_values', 'ip_address', 'request_id',
        ]) + ['created_at' => $l->created_at?->toIso8601String()])->all());
    }
}
