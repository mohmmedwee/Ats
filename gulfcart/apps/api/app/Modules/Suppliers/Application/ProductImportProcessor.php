<?php

namespace App\Modules\Suppliers\Application;

use App\Modules\Catalog\Contracts\CatalogWriter;
use App\Modules\Catalog\Domain\ProductDraft;
use App\Modules\Suppliers\Infrastructure\Models\ProductImport;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use App\Modules\Suppliers\Infrastructure\Models\SupplierProduct;
use App\Modules\Suppliers\Infrastructure\Models\SupplierVariantMapping;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use SplFileObject;
use Throwable;

/**
 * CSV supplier feed import: one row per variant (docs/SUPPLIER_IMPORT.md).
 * Valid rows are applied per product in a transaction; invalid rows are reported
 * with row numbers and never partially applied. Products are created as drafts.
 */
final class ProductImportProcessor
{
    public const COLUMNS = [
        'external_product_id', 'external_variant_id', 'sku', 'title_en', 'title_ar',
        'description_en', 'description_ar', 'category_slug', 'brand', 'attributes',
        'cost_price', 'cost_currency', 'stock_status', 'image_url', 'weight_grams', 'hs_code', 'countries',
    ];

    private const MAX_ROWS = 5000;

    public function __construct(private readonly CatalogWriter $catalog, private readonly SourcingNotifier $notifier) {}

    public function process(ProductImport $import): void
    {
        $import->update(['status' => 'processing']);
        $supplier = Supplier::query()->findOrFail($import->supplier_id);
        $imageRights = (bool) ($supplier->currentAuthorization()?->allows_image_use ?? false);

        try {
            [$rows, $errors] = $this->read($import->file_path);
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'errors' => [['row' => 0, 'errors' => [$e->getMessage()]]], 'completed_at' => now()]);

            return;
        }

        $groups = [];
        foreach ($rows as $line => $row) {
            $groups[$row['external_product_id']][$line] = $row;
        }

        $created = 0;
        $updated = 0;
        $touched = [];
        foreach ($groups as $externalId => $variantRows) {
            try {
                $result = DB::transaction(fn () => $this->applyProduct($supplier, (string) $externalId, $variantRows, $imageRights));
                $result['created'] ? $created++ : $updated++;
                $touched[] = $result['product_id'];
            } catch (Throwable $e) {
                foreach (array_keys($variantRows) as $line) {
                    $errors[] = ['row' => $line, 'errors' => [$e instanceof ApiException ? $e->localizedMessage() : $e->getMessage()]];
                }
            }
        }

        foreach (array_unique($touched) as $productId) {
            $this->notifier->product($productId);
        }

        usort($errors, fn ($a, $b) => $a['row'] <=> $b['row']);
        $import->update([
            'status' => 'completed',
            'total_rows' => count($rows) + count(array_filter($errors, fn ($e) => $e['row'] > 0 && ! isset($rows[$e['row']]))),
            'created_count' => $created,
            'updated_count' => $updated,
            'error_count' => count($errors),
            'errors' => array_slice($errors, 0, 500),
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array{product_id: string, created: bool}
     */
    private function applyProduct(Supplier $supplier, string $externalId, array $rows, bool $imageRights): array
    {
        $first = reset($rows);
        $countries = array_values(array_filter(array_map('trim', explode('|', strtoupper($first['countries'])))));
        $draft = new ProductDraft(
            title: ['en' => $first['title_en'], 'ar' => $first['title_ar'] ?: null],
            description: ($first['description_en'] || $first['description_ar']) ? ['en' => $first['description_en'] ?: null, 'ar' => $first['description_ar'] ?: null] : null,
            categorySlug: $first['category_slug'] ?: null,
            brand: $first['brand'] ?: null,
            weightGrams: $first['weight_grams'] !== '' ? (int) $first['weight_grams'] : null,
            hsCode: $first['hs_code'] ?: null,
            countryCodes: $countries,
        );

        $sp = SupplierProduct::query()->withTrashed()->where('supplier_id', $supplier->id)->where('external_product_id', $externalId)->first();
        $created = $sp === null;
        if ($created) {
            $productId = $this->catalog->createProduct($draft);
            $sp = SupplierProduct::query()->create([
                'supplier_id' => $supplier->id, 'product_id' => $productId, 'external_product_id' => $externalId,
                'cost_price' => $first['cost_price'], 'cost_currency' => strtoupper($first['cost_currency']),
                'stock_status' => $first['stock_status'], 'is_active' => true,
            ]);
        } else {
            $this->catalog->updateDraftProduct($sp->product_id, $draft);
            $sp->restore();
            $sp->update(['cost_price' => $first['cost_price'], 'cost_currency' => strtoupper($first['cost_currency']), 'stock_status' => $first['stock_status']]);
        }

        foreach ($rows as $row) {
            $variantId = $this->catalog->upsertVariant($sp->product_id, $row['sku'], $this->attributes($row['attributes']), $row['weight_grams'] !== '' ? (int) $row['weight_grams'] : null);
            SupplierVariantMapping::query()->updateOrCreate(
                ['supplier_product_id' => $sp->id, 'variant_id' => $variantId],
                ['external_variant_id' => $row['external_variant_id'], 'cost_price' => $row['cost_price'], 'stock_status' => $row['stock_status']],
            );
            if ($row['image_url'] !== '') {
                $this->catalog->addImageFromUrl($sp->product_id, $row['image_url'], $imageRights, $imageRights ? 'supplier_agreement' : null);
            }
        }

        return ['product_id' => $sp->product_id, 'created' => $created];
    }

    /**
     * @return array{0: array<int, array<string, string>>, 1: list<array{row: int, errors: list<string>}>}
     */
    private function read(string $path): array
    {
        $file = new SplFileObject(Storage::disk('local')->path($path));
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);

        $header = null;
        $rows = [];
        $errors = [];
        $line = 0;
        foreach ($file as $cells) {
            $line++;
            if (! is_array($cells) || $cells === [null]) {
                continue;
            }
            $cells = array_map(fn ($c) => trim((string) $c), $cells);
            if ($header === null) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]) ?? $cells[0];
                $header = $cells;
                $missing = array_diff(self::COLUMNS, $header);
                if ($missing !== []) {
                    throw new \RuntimeException('Missing columns: '.implode(', ', $missing));
                }

                continue;
            }
            if (count($rows) + count($errors) >= self::MAX_ROWS) {
                throw new \RuntimeException('The file has more than '.self::MAX_ROWS.' rows.');
            }
            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            $v = Validator::make($row, [
                'external_product_id' => ['required', 'max:255'],
                'external_variant_id' => ['required', 'max:255'],
                'sku' => ['required', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
                'title_en' => ['required', 'max:255'],
                'title_ar' => ['nullable', 'max:255'],
                'category_slug' => ['nullable', 'exists:categories,slug'],
                'cost_price' => ['required', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
                'cost_currency' => ['required', 'regex:/^[A-Za-z]{3}$/'],
                'stock_status' => ['required', 'in:in_stock,low_stock,out_of_stock,unknown'],
                'image_url' => ['nullable', 'url:https', 'max:2000'],
                'weight_grams' => ['nullable', 'integer', 'between:1,100000'],
                'hs_code' => ['nullable', 'regex:/^\d{4,12}$/'],
                'countries' => ['required', 'regex:/^(AE|SA)(\|(AE|SA))*$/i'],
                'attributes' => ['nullable', 'regex:/^([^=;]+=[^=;]+)(;[^=;]+=[^=;]+)*$/'],
            ]);
            if ($v->fails()) {
                $errors[] = ['row' => $line, 'errors' => $v->errors()->all()];

                continue;
            }
            $rows[$line] = $row;
        }

        if ($header === null) {
            throw new \RuntimeException('The file is empty.');
        }

        return [$rows, $errors];
    }

    /** @return array<string, string> "size=M;color=Black" */
    private function attributes(string $raw): array
    {
        $out = [];
        foreach (array_filter(explode(';', $raw)) as $pair) {
            [$k, $v] = array_map('trim', explode('=', $pair, 2));
            $out[strtolower($k)] = $v;
        }

        return $out;
    }
}
