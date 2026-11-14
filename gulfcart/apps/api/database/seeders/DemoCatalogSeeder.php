<?php

namespace Database\Seeders;

use App\Modules\Catalog\Application\ListingRefresher;
use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Pricing\Application\Repricer;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Local/staging demo data: categories, products with images, an authorized MOCK supplier and
 * prices. Never runs in production. Usage: php artisan db:seed --class=DemoCatalogSeeder
 */
final class DemoCatalogSeeder extends Seeder
{
    public function run(CountryDirectory $countries, Repricer $repricer, ListingRefresher $listing): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo data must not be seeded in production.');
        }

        $women = Category::query()->firstOrCreate(['slug' => 'women'], ['name' => ['en' => 'Women', 'ar' => 'نساء']]);
        $dresses = Category::query()->firstOrCreate(['slug' => 'dresses'], ['name' => ['en' => 'Dresses', 'ar' => 'فساتين'], 'parent_id' => $women->id]);
        $bags = Category::query()->firstOrCreate(['slug' => 'bags'], ['name' => ['en' => 'Bags', 'ar' => 'حقائب'], 'parent_id' => $women->id]);
        $home = Category::query()->firstOrCreate(['slug' => 'home'], ['name' => ['en' => 'Home', 'ar' => 'المنزل']]);

        $supplierA = $this->supplier('demo-fashion', 'Demo Fashion Wholesale', ['AE', 'SA'], '12.00');
        $supplierB = $this->supplier('demo-home', 'Demo Home Goods', ['AE', 'SA'], '6.00');

        $items = [
            [$dresses, $supplierA, ['en' => 'Linen Summer Dress', 'ar' => 'فستان صيفي من الكتان'], 'Breezy linen dress for warm days.', '24.00', ['S', 'M', 'L'], [230, 180, 160], '620442'],
            [$dresses, $supplierA, ['en' => 'Evening Maxi Dress', 'ar' => 'فستان سهرة طويل'], 'Flowing maxi dress with a satin finish.', '38.50', ['M', 'L'], [40, 40, 80], '620443'],
            [$bags, $supplierA, ['en' => 'Leather Tote Bag', 'ar' => 'حقيبة جلد'], 'Everyday tote in genuine leather.', '41.00', ['One size'], [120, 80, 40], '420221'],
            [$home, $supplierB, ['en' => 'Ceramic Coffee Set', 'ar' => 'طقم قهوة سيراميك'], 'Six cups and a serving pot.', '18.75', ['Set of 6'], [60, 120, 160], '691200'],
            [$home, $supplierB, ['en' => 'Scented Candle Trio', 'ar' => 'ثلاث شموع معطرة'], 'Oud, amber and rose.', '9.90', ['Trio'], [200, 150, 90], '340600'],
        ];

        foreach ($items as $n => [$category, $supplier, $title, $description, $cost, $sizes, $rgb, $hs]) {
            $slug = 'demo-'.($n + 1);
            if (Product::query()->where('slug', $slug)->exists()) {
                continue;
            }
            $product = Product::query()->create([
                'category_id' => $category->id, 'title' => $title, 'slug' => $slug,
                'description' => ['en' => $description, 'ar' => $title['ar']], 'status' => 'published', 'brand' => 'GulfCart Demo',
                'attributes' => [], 'weight_grams' => 600, 'hs_code' => $hs, 'published_at' => now(),
            ]);
            $path = "products/{$product->id}/main.png";
            Storage::disk((string) config('gulfcart.catalog_disk', 'public'))->put($path, $this->png(...$rgb));
            $product->images()->create(['storage_path' => $path, 'rights_status' => 'approved', 'rights_basis' => 'own_photo']);

            $sp = $supplier->products()->create([
                'product_id' => $product->id, 'external_product_id' => strtoupper($slug), 'cost_price' => $cost,
                'cost_currency' => 'USD', 'stock_status' => 'in_stock',
            ]);
            foreach ($sizes as $i => $size) {
                $variant = $product->variants()->create(['sku' => strtoupper($slug).'-'.($i + 1), 'attributes' => ['size' => $size]]);
                $sp->mappings()->create(['variant_id' => $variant->id, 'external_variant_id' => strtoupper($slug).'-V'.($i + 1)]);
            }
            foreach ($countries->active() as $country) {
                $product->availability()->create(['country_id' => $country->id, 'is_available' => true, 'estimated_delivery_min_days' => 7, 'estimated_delivery_max_days' => 12]);
            }
            $repricer->reprice($product->id, notify: false);
            $listing->refresh($product->id);
        }

        $this->command?->info('Demo catalog ready: 5 products, 2 mock suppliers.');
    }

    /** @param list<string> $countries */
    private function supplier(string $code, string $name, array $countries, string $shipping): Supplier
    {
        $supplier = Supplier::query()->firstOrCreate(['code' => $code], [
            'name' => $name, 'connector_type' => 'mock', 'base_currency' => 'USD',
            'commercial_authorization_status' => 'approved', 'is_active' => true, 'direct_shipping_enabled' => true,
            'metadata' => ['shipping_cost_per_unit' => $shipping, 'delivery_min_days' => 7, 'delivery_max_days' => 12],
        ]);
        if (! $supplier->authorizations()->exists()) {
            $supplier->authorizations()->create([
                'agreement_reference' => 'DEMO-'.strtoupper($code), 'allows_resale' => true, 'allows_api_purchasing' => true,
                'allows_direct_shipping' => true, 'allows_image_use' => true, 'countries' => '{'.implode(',', $countries).'}',
                'valid_from' => now()->subDay()->toDateString(),
            ]);
        }

        return $supplier;
    }

    /** A small solid-colour PNG (no GD required). */
    private function png(int $r, int $g, int $b): string
    {
        $w = 64;
        $raw = '';
        for ($y = 0; $y < $w; $y++) {
            $raw .= "\0".str_repeat(chr($r).chr($g).chr($b), $w);
        }
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $w, $w, 8, 2, 0, 0, 0)).$chunk('IDAT', (string) gzcompress($raw)).$chunk('IEND', '');
    }
}
