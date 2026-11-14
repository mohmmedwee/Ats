<?php

namespace App\Modules\Suppliers\Application\Connectors\Cj;

use App\Modules\Suppliers\Application\ProductImportProcessor;
use App\Modules\Suppliers\Domain\Connector\ConnectorException;
use App\Modules\Suppliers\Domain\Connector\ExternalProduct;
use App\Modules\Suppliers\Domain\Connector\PurchaseLine;
use App\Modules\Suppliers\Infrastructure\Models\ProductImport;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use App\Modules\Suppliers\Infrastructure\Models\SupplierProduct;
use App\Shared\Money\Decimal;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports CJ products into the catalog as drafts by turning them into a standard supplier feed
 * (docs/SUPPLIER_IMPORT.md) and running the normal import. Products still go through review
 * (Arabic title, category, image rights) before publishing.
 */
final class CjProductImporter
{
    public function __construct(private readonly ProductImportProcessor $processor) {}

    /**
     * @param  list<string>  $pids
     * @param  list<string>  $countries
     * @return array{import: ProductImport, skipped: array<string, string>}
     */
    public function import(Supplier $supplier, array $pids, array $countries, ?string $categorySlug = null, ?string $uploadedBy = null): array
    {
        $connector = new CjDropshippingConnector($supplier);
        $client = $connector->client();

        $rows = [];
        $shipping = [];
        $skipped = [];
        foreach (array_unique($pids) as $pid) {
            try {
                $product = $client->get('product/query', ['pid' => $pid]);
                if (! is_array($product)) {
                    throw new ConnectorException('not found');
                }
                $stock = $connector->getProduct($pid);
                $productRows = $this->rows($pid, $product, $stock, $countries, $categorySlug);
                if ($productRows === []) {
                    throw new ConnectorException('no variants');
                }
                $rows = array_merge($rows, $productRows);
                $shipping[$pid] = $this->shippingEstimate($connector, $pid, $productRows[0]['external_variant_id'], $countries);
            } catch (Throwable $e) {
                $skipped[$pid] = $e->getMessage();
            }
        }

        $path = 'imports/cj-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.csv';
        Storage::disk('local')->put($path, $this->csv($rows));

        $import = ProductImport::query()->create([
            'supplier_id' => $supplier->id, 'uploaded_by' => $uploadedBy, 'file_path' => $path, 'status' => 'queued',
        ]);
        $this->processor->process($import);

        foreach ($shipping as $pid => $cost) {
            $sp = SupplierProduct::query()->where('supplier_id', $supplier->id)->where('external_product_id', $pid)->first();
            if ($sp !== null) {
                $sp->update([
                    'source_url' => "https://cjdropshipping.com/product/p-{$pid}.html",
                    'supplier_data' => array_merge((array) $sp->supplier_data, array_filter(['shipping_cost' => $cost, 'source' => 'cj'])),
                ]);
            }
        }

        return ['import' => $import->refresh(), 'skipped' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  list<string>  $countries
     * @return list<array<string, string>>
     */
    private function rows(string $pid, array $product, ExternalProduct $remote, array $countries, ?string $categorySlug): array
    {
        $title = Str::limit(trim((string) ($product['productNameEn'] ?? '')), 250, '');
        $description = Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) ($product['description'] ?? ''))) ?? ''), 4000, '');
        $keyNames = array_values(array_filter(array_map(fn ($k) => strtolower(trim($k)), explode('-', (string) ($product['productKeyEn'] ?? '')))));
        $mainImage = self::httpsUrl($product['bigImage'] ?? null);

        $rows = [];
        foreach ((array) ($product['variants'] ?? []) as $v) {
            if (! is_array($v) || ! isset($v['vid'])) {
                continue;
            }
            $vid = (string) $v['vid'];
            $remoteVariant = $remote->variant($vid);
            if ($remoteVariant === null) {
                continue;
            }
            $sku = 'CJ-'.(preg_replace('/[^A-Za-z0-9._-]/', '-', (string) ($v['variantSku'] ?? $vid)) ?: $vid);
            $weight = isset($v['variantWeight']) && is_numeric($v['variantWeight']) ? (string) max(1, min(100000, (int) round((float) $v['variantWeight']))) : '';

            $rows[] = [
                'external_product_id' => $pid,
                'external_variant_id' => $vid,
                'sku' => Str::limit($sku, 100, ''),
                'title_en' => $title,
                'title_ar' => '',
                'description_en' => $description,
                'description_ar' => '',
                'category_slug' => $categorySlug ?? '',
                'brand' => '',
                'attributes' => $this->attributes($keyNames, (string) ($v['variantKey'] ?? '')),
                'cost_price' => Decimal::round($remoteVariant->cost, 4),
                'cost_currency' => 'USD',
                'stock_status' => $remoteVariant->stockStatus,
                'image_url' => self::httpsUrl($v['variantImage'] ?? null) ?? $mainImage ?? '',
                'weight_grams' => $weight,
                'hs_code' => '',
                'countries' => implode('|', $countries),
            ];
        }

        return $rows;
    }

    /** @param list<string> $names e.g. ["color","size"] with key "Black-XL" */
    private function attributes(array $names, string $key): string
    {
        $clean = fn (string $s) => trim(str_replace(['=', ';', '|'], ' ', $s));
        $values = array_values(array_filter(array_map('trim', explode('-', $key)), fn ($x) => $x !== ''));
        if ($values === []) {
            return '';
        }
        if (count($names) !== count($values)) {
            return 'option='.$clean($key);
        }

        return implode(';', array_map(fn ($n, $v) => $clean($n).'='.$clean($v), $names, $values));
    }

    /** Highest 1-unit shipping cost (USD) across the target countries, used as the per-unit estimate in pricing. */
    private function shippingEstimate(CjDropshippingConnector $connector, string $pid, string $vid, array $countries): ?string
    {
        $max = null;
        foreach ($countries as $country) {
            try {
                $price = $connector->freight([new PurchaseLine($pid, $vid, 1, '0', 'USD')], $country)['price'];
            } catch (Throwable) {
                continue;
            }
            $max = $max === null || Decimal::cmp($price, $max) > 0 ? $price : $max;
        }

        return $max;
    }

    private static function httpsUrl(mixed $url): ?string
    {
        $url = is_string($url) ? trim($url) : '';
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }
        if (str_starts_with($url, 'http://')) {
            $url = 'https://'.substr($url, 7);
        }

        return str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    /** @param list<array<string, string>> $rows */
    private function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ProductImportProcessor::COLUMNS, escape: '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($c) => $row[$c] ?? '', ProductImportProcessor::COLUMNS), escape: '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
