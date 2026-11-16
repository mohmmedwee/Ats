<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Countries\Domain\CountryData;
use App\Modules\Engagement\Application\ProductAlerts;
use App\Modules\Engagement\Infrastructure\Models\ProductAlert;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Customers ask to be told when a product is back in stock or its price drops. */
final class ProductAlertController
{
    public function __construct(private readonly ProductAlerts $alerts) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(ProductAlert::query()->where('user_id', $this->userId($request))->whereNull('notified_at')->latest()->get()
            ->map(fn (ProductAlert $a) => $this->present($a))->all());
    }

    public function store(Request $request, string $productId): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:back_in_stock,price_drop'], 'variant_id' => ['nullable', 'uuid']]);
        /** @var CountryData $country */
        $country = $request->attributes->get('country');
        $alert = $this->alerts->subscribe($this->userId($request), $productId, $data['variant_id'] ?? null, $data['type'], $country->code);

        return ApiResponse::success($this->present($alert), $alert->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, string $id): Response
    {
        $deleted = ProductAlert::query()->where('user_id', $this->userId($request))->whereKey($id)->delete();
        if ($deleted === 0) {
            throw new ApiException('NOT_FOUND', 404);
        }

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(ProductAlert $a): array
    {
        return ['id' => $a->id, 'product_id' => $a->product_id, 'variant_id' => $a->variant_id, 'type' => $a->type, 'created_at' => $a->created_at?->toIso8601String()];
    }

    private function userId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
