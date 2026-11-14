<?php

namespace App\Modules\Suppliers\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Suppliers\Application\SourcingNotifier;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Supplier lifecycle: create (pending) → record written authorization (approved) →
 * activate. Only approved suppliers with a valid authorization can be active.
 */
final class SupplierController
{
    public function __construct(private readonly AuditLogger $audit, private readonly SourcingNotifier $notifier) {}

    public function index(Request $request): JsonResponse
    {
        $page = Supplier::query()->with('authorizations')->orderBy('name')->paginate(min(100, $request->integer('per_page', 25)));

        return ApiResponse::paged($page, $page->getCollection()->map(fn ($s) => $this->present($s))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $supplier = Supplier::query()->create($data + ['commercial_authorization_status' => 'pending', 'is_active' => false, 'metadata' => $data['metadata'] ?? []]);
        $this->audit->record('supplier.created', 'supplier', $supplier->id, null, $data);

        return ApiResponse::success($this->present($supplier->load('authorizations')), 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return ApiResponse::success($this->present($supplier->load('authorizations')));
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate($this->rules(false, $supplier));
        $old = $supplier->only(array_keys($data));
        $supplier->update($data);
        $this->audit->record('supplier.updated', 'supplier', $supplier->id, $old, $data);
        $this->notifier->supplier($supplier->id);

        return ApiResponse::success($this->present($supplier->load('authorizations')));
    }

    public function addAuthorization(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate([
            'agreement_reference' => ['required', 'string', 'max:255'],
            'allows_resale' => ['required', 'boolean'],
            'allows_api_purchasing' => ['required', 'boolean'],
            'allows_direct_shipping' => ['required', 'boolean'],
            'allows_image_use' => ['required', 'boolean'],
            'countries' => ['required', 'array', 'min:1'],
            'countries.*' => ['string', 'in:AE,SA', 'distinct'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'document_path' => ['nullable', 'string', 'max:500'],
        ]);

        $auth = DB::transaction(function () use ($data, $supplier, $request) {
            $auth = $supplier->authorizations()->create([
                ...collect($data)->except('countries')->all(),
                'countries' => '{'.implode(',', $data['countries']).'}',
                'approved_by' => $request->user()?->getAuthIdentifier(),
            ]);
            if ($supplier->currentAuthorization() !== null) {
                $supplier->update(['commercial_authorization_status' => 'approved']);
            }

            return $auth;
        });
        $this->audit->record('supplier.authorization_recorded', 'supplier', $supplier->id, null, $data + ['authorization_id' => $auth->id]);
        $this->notifier->supplier($supplier->id);

        return ApiResponse::success($this->present($supplier->refresh()->load('authorizations')), 201);
    }

    public function status(Request $request, Supplier $supplier): JsonResponse
    {
        $action = $request->validate(['action' => ['required', 'in:activate,deactivate,suspend,revoke']])['action'];
        $old = $supplier->only(['is_active', 'commercial_authorization_status']);

        match ($action) {
            'activate' => $this->activate($supplier),
            'deactivate' => $supplier->update(['is_active' => false]),
            'suspend' => $supplier->update(['is_active' => false, 'commercial_authorization_status' => 'suspended']),
            'revoke' => $supplier->update(['is_active' => false, 'commercial_authorization_status' => 'revoked']),
        };

        $this->audit->record("supplier.{$action}", 'supplier', $supplier->id, $old, $supplier->only(['is_active', 'commercial_authorization_status']));
        $this->notifier->supplier($supplier->id);

        return ApiResponse::success($this->present($supplier->load('authorizations')));
    }

    private function activate(Supplier $supplier): void
    {
        $auth = $supplier->currentAuthorization();
        if ($auth === null) {
            throw new ApiException('SUPPLIER_NOT_AUTHORIZED', 422, [], 'A valid written authorization that allows resale is required before activation.');
        }
        if (in_array($supplier->commercial_authorization_status, ['suspended', 'revoked'], true)) {
            throw new ApiException('SUPPLIER_NOT_AUTHORIZED', 422, [], 'Suspended or revoked suppliers need a new authorization.');
        }
        $supplier->update(['commercial_authorization_status' => 'approved', 'is_active' => true]);
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating, ?Supplier $supplier = null): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $types = array_merge(['mock', 'manual'], array_keys((array) config('gulfcart.supplier_connectors', [])));

        return [
            'name' => [$req, 'string', 'max:150'],
            'code' => [$req, 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/', Rule::unique('suppliers', 'code')->ignore($supplier?->id)],
            'connector_type' => [$req, 'string', Rule::in($types)],
            'base_currency' => [$req, 'string', 'regex:/^[A-Z]{3}$/'],
            'api_base_url' => ['nullable', 'url:https', 'max:500'],
            'credentials_ref' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.:\/-]+$/'],
            'direct_shipping_enabled' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'array'],
            'metadata.shipping_cost_per_unit' => ['sometimes', 'numeric', 'min:0'],
            'metadata.delivery_min_days' => ['sometimes', 'integer', 'between:1,90'],
            'metadata.delivery_max_days' => ['sometimes', 'integer', 'between:1,90'],
            'metadata.mock' => ['sometimes', 'array'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(Supplier $s): array
    {
        return [
            'id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'connector_type' => $s->connector_type,
            'base_currency' => $s->base_currency, 'api_base_url' => $s->api_base_url,
            'has_credentials' => $s->credentials_ref !== null,
            'commercial_authorization_status' => $s->commercial_authorization_status,
            'direct_shipping_enabled' => $s->direct_shipping_enabled, 'is_active' => $s->is_active,
            'metadata' => (object) $s->metadata,
            'authorizations' => $s->authorizations->map(fn ($a) => [
                'id' => $a->id, 'agreement_reference' => $a->agreement_reference,
                'allows_resale' => $a->allows_resale, 'allows_api_purchasing' => $a->allows_api_purchasing,
                'allows_direct_shipping' => $a->allows_direct_shipping, 'allows_image_use' => $a->allows_image_use,
                'countries' => $a->countryList(), 'valid_from' => $a->valid_from->toDateString(),
                'valid_until' => $a->valid_until?->toDateString(),
            ])->values(),
        ];
    }
}
