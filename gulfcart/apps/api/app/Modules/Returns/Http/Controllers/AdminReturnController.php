<?php

namespace App\Modules\Returns\Http\Controllers;

use App\Modules\Returns\Application\ReturnService;
use App\Modules\Returns\Infrastructure\Models\ReturnRequest;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminReturnController
{
    public function __construct(private readonly ReturnService $returns) {}

    public function index(Request $request): JsonResponse
    {
        $q = ReturnRequest::query()->with('items')->latest();
        if (is_string($s = $request->query('status'))) {
            $q->where('status', $s);
        }
        $page = $q->paginate(min(100, $request->integer('per_page', 25)));

        return ApiResponse::paged($page, $page->getCollection()->map(fn ($r) => ReturnController::present($r) + ['staff_note' => $r->staff_note, 'handled_by' => $r->handled_by])->all());
    }

    public function action(Request $request, ReturnRequest $return, string $action): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;
        $actor = (string) $request->user()->getAuthIdentifier();

        $result = match ($action) {
            'approve' => $this->returns->transition($return, 'approved', $actor, $note),
            'reject' => $this->returns->transition($return, 'rejected', $actor, $note),
            'receive' => $this->returns->transition($return, 'received', $actor, $note),
            'refund' => $this->returns->refund($return, $actor),
        };

        return ApiResponse::success(ReturnController::present($result->load('items')));
    }
}
