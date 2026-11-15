<?php

namespace Database\Seeders;

use App\Modules\Catalog\Application\ListingRefresher;
use App\Modules\Catalog\Infrastructure\Models\Brand;
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

        $tree = [
            'women' => ['Women', 'نساء', null], 'dresses' => ['Dresses', 'فساتين', 'women'], 'tops' => ['Tops', 'بلوزات', 'women'],
            'bags' => ['Bags', 'حقائب', 'women'], 'shoes' => ['Shoes', 'أحذية', null], 'men' => ['Men', 'رجال', null],
            'beauty' => ['Beauty', 'جمال', null], 'home' => ['Home', 'المنزل', null], 'kids' => ['Kids', 'أطفال', null],
            'accessories' => ['Accessories', 'إكسسوارات', null],
        ];
        $cats = [];
        foreach ($tree as $slug => [$en, $ar, $parent]) {
            $cats[$slug] = Category::query()->firstOrCreate(['slug' => $slug], [
                'name' => ['en' => $en, 'ar' => $ar], 'parent_id' => $parent ? $cats[$parent]->id : null, 'sort_order' => count($cats),
            ]);
        }

        $fashion = $this->supplier('demo-fashion', 'Demo Fashion Wholesale', ['AE', 'SA'], '6.00');
        $lifestyle = $this->supplier('demo-lifestyle', 'Demo Lifestyle Goods', ['AE', 'SA'], '4.00');

        // [category, supplier, en, ar, cost USD, options, gradient top, gradient bottom, hs]
        $items = [
            ['dresses', $fashion, 'Linen Summer Dress', 'فستان صيفي من الكتان', '14.00', ['S', 'M', 'L'], [244, 194, 180], [214, 120, 110], '620442'],
            ['dresses', $fashion, 'Satin Maxi Dress', 'فستان ساتان طويل', '19.50', ['S', 'M', 'L', 'XL'], [70, 60, 120], [30, 25, 60], '620443'],
            ['dresses', $fashion, 'Floral Midi Dress', 'فستان ميدي مزهر', '12.90', ['XS', 'S', 'M'], [250, 220, 230], [230, 140, 170], '620444'],
            ['dresses', $fashion, 'Modest Abaya, Embroidered', 'عباية مطرزة', '24.00', ['52', '54', '56', '58'], [60, 60, 66], [15, 15, 20], '621142'],
            ['tops', $fashion, 'Ribbed Knit Top', 'بلوزة محبوكة', '6.50', ['S', 'M', 'L'], [240, 235, 220], [200, 190, 160], '611020'],
            ['tops', $fashion, 'Oversized Cotton Shirt', 'قميص قطن واسع', '9.90', ['S', 'M', 'L'], [200, 220, 240], [110, 150, 200], '620630'],
            ['tops', $fashion, 'Pleated Chiffon Blouse', 'بلوزة شيفون', '8.40', ['S', 'M', 'L'], [250, 240, 200], [230, 200, 120], '620640'],
            ['bags', $fashion, 'Leather Tote Bag', 'حقيبة جلد', '21.00', ['One size'], [180, 120, 80], [110, 65, 35], '420221'],
            ['bags', $fashion, 'Mini Crossbody Bag', 'حقيبة كروس صغيرة', '7.90', ['Black', 'Beige'], [230, 215, 195], [180, 150, 120], '420222'],
            ['shoes', $fashion, 'Everyday Sneakers', 'حذاء رياضي يومي', '15.00', ['38', '39', '40', '41', '42'], [245, 245, 245], [190, 195, 200], '640411'],
            ['shoes', $fashion, 'Block Heel Sandals', 'صندل بكعب عريض', '11.50', ['37', '38', '39', '40'], [235, 200, 170], [190, 140, 100], '640299'],
            ['men', $fashion, 'Classic Linen Shirt', 'قميص كتان كلاسيكي', '10.90', ['M', 'L', 'XL'], [215, 225, 215], [140, 165, 140], '620520'],
            ['men', $fashion, 'Slim Chino Trousers', 'بنطال تشينو', '13.40', ['30', '32', '34', '36'], [210, 190, 150], [150, 125, 80], '620342'],
            ['beauty', $lifestyle, 'Oud Eau de Parfum 50 ml', 'عطر عود 50 مل', '18.00', ['50 ml'], [120, 70, 40], [50, 25, 15], '330300'],
            ['beauty', $lifestyle, 'Vitamin C Serum', 'سيروم فيتامين سي', '6.20', ['30 ml'], [255, 220, 150], [245, 160, 60], '330499'],
            ['beauty', $lifestyle, 'Matte Lipstick Set', 'مجموعة أحمر شفاه', '5.40', ['Set of 3'], [230, 120, 140], [170, 40, 70], '330410'],
            ['home', $lifestyle, 'Ceramic Coffee Set', 'طقم قهوة سيراميك', '11.75', ['Set of 6'], [90, 140, 180], [40, 80, 120], '691200'],
            ['home', $lifestyle, 'Scented Candle Trio', 'ثلاث شموع معطرة', '4.90', ['Trio'], [235, 210, 170], [200, 150, 90], '340600'],
            ['home', $lifestyle, 'Linen Cushion Cover', 'غطاء وسادة كتان', '3.80', ['45 × 45 cm'], [220, 210, 200], [160, 145, 130], '630492'],
            ['kids', $lifestyle, 'Kids Cotton Pyjamas', 'بيجامة أطفال قطن', '6.90', ['2Y', '4Y', '6Y', '8Y'], [200, 230, 250], [120, 180, 230], '610822'],
            ['kids', $lifestyle, 'Wooden Puzzle Set', 'ألغاز خشبية', '5.20', ['Set'], [250, 230, 160], [230, 180, 60], '950300'],
            ['accessories', $lifestyle, 'Gold-Tone Hoop Earrings', 'أقراط ذهبية اللون', '3.40', ['One size'], [250, 225, 150], [210, 170, 60], '711719'],
            ['accessories', $lifestyle, 'Silk Scarf', 'وشاح حرير', '7.60', ['90 × 90 cm'], [240, 180, 190], [190, 100, 130], '621410'],
            ['accessories', $lifestyle, 'Phone Case, Clear', 'غطاء جوال شفاف', '2.10', ['iPhone 15', 'iPhone 16'], [220, 230, 240], [160, 175, 195], '392690'],
        ];

        foreach ($items as $n => [$cat, $supplier, $en, $ar, $cost, $options, $top, $bottom, $hs]) {
            $slug = 'demo-'.($n + 1);
            if (Product::query()->where('slug', $slug)->exists()) {
                continue;
            }
            $product = Product::query()->create([
                'category_id' => $cats[$cat]->id, 'title' => ['en' => $en, 'ar' => $ar], 'slug' => $slug,
                'description' => ['en' => "{$en}. Demo product for the GulfCart storefront.", 'ar' => "{$ar}. منتج تجريبي لمتجر غلف كارت."],
                'status' => 'published', 'brand' => 'GulfCart Demo', 'attributes' => [], 'weight_grams' => 450,
                'hs_code' => $hs, 'published_at' => now()->subMinutes($n),
            ]);
            $path = "products/{$product->id}/main.png";
            Storage::disk((string) config('gulfcart.catalog_disk', 'public'))->put($path, $this->png($top, $bottom));
            $product->images()->create(['storage_path' => $path, 'rights_status' => 'approved', 'rights_basis' => 'own_photo']);

            $sp = $supplier->products()->create([
                'product_id' => $product->id, 'external_product_id' => strtoupper($slug), 'cost_price' => $cost,
                'cost_currency' => 'USD', 'stock_status' => $n % 7 === 3 ? 'low_stock' : 'in_stock',
            ]);
            $sized = preg_match('/^(XS|S|M|L|XL|\d{2})$/', $options[0]) === 1;
            // Clothing gets colours × sizes: one picture per colour, larger sizes and some colours cost more.
            $colours = $sized && in_array($cat, ['dresses', 'tops', 'men', 'kids'], true)
                ? [['Black', 'أسود', [40, 40, 46], [20, 20, 24], '0'], ['Beige', 'بيج', [236, 222, 200], [200, 180, 150], '0'], ['Navy', 'كحلي', [40, 60, 110], [20, 30, 70], '1.50']]
                : [null];
            $i = 0;
            foreach ($colours as $colour) {
                $colourImage = null;
                if ($colour !== null) {
                    $cpath = "products/{$product->id}/".strtolower($colour[0]).'.png';
                    Storage::disk((string) config('gulfcart.catalog_disk', 'public'))->put($cpath, $this->png($colour[2], $colour[3]));
                    $colourImage = $product->images()->create(['storage_path' => $cpath, 'rights_status' => 'approved', 'rights_basis' => 'own_photo', 'sort_order' => $i + 1]);
                }
                foreach ($options as $j => $option) {
                    $i++;
                    $attributes = $colour !== null ? ['color' => $colour[0], 'size' => $option] : [$sized ? 'size' : 'option' => $option];
                    $variant = $product->variants()->create(['sku' => strtoupper($slug).'-'.$i, 'attributes' => $attributes]);
                    $extra = $colour !== null ? $colour[4] : '0';
                    if ($sized && in_array($option, ['XL', '56', '58'], true)) {
                        $extra = (string) ((float) $extra + 2);
                    }
                    $sp->mappings()->create([
                        'variant_id' => $variant->id, 'external_variant_id' => strtoupper($slug).'-V'.$i,
                        'cost_price' => $extra === '0' ? null : number_format((float) $cost + (float) $extra, 2, '.', ''),
                        'stock_status' => $colour !== null && $colour[0] === 'Navy' && $j === 0 ? 'out_of_stock' : null,
                    ]);
                    if ($colourImage !== null && $j === 0) {
                        $colourImage->update(['variant_id' => $variant->id]);
                    }
                }
            }
            foreach ($countries->active() as $country) {
                $product->availability()->create(['country_id' => $country->id, 'is_available' => true, 'estimated_delivery_min_days' => 6, 'estimated_delivery_max_days' => 11]);
            }
            $repricer->reprice($product->id, notify: false);
            $listing->refresh($product->id);
        }

        $this->brands($cats);
        $this->electronics($countries, $repricer, $listing, $lifestyle);

        $this->command?->info('Demo catalog ready: 30 products, 16 categories, 11 brands, 2 mock suppliers.');
    }

    /** Fictional demo brands for the fashion and lifestyle items (real trademarks are never used in demo data). */
    private function brands(array $cats): void
    {
        $map = [
            'luma' => ['Luma', 'لوما', ['dresses', 'tops', 'bags'], true],
            'sahara' => ['Sahara', 'صحارى', ['shoes', 'men'], true],
            'oudia' => ['Oudia', 'عودية', ['beauty'], true],
            'dar-home' => ['Dar Home', 'دار هوم', ['home'], false],
            'little-dune' => ['Little Dune', 'ليتل دون', ['kids', 'accessories'], false],
        ];
        foreach ($map as $slug => [$en, $ar, $categories, $featured]) {
            $brand = Brand::query()->firstOrCreate(['slug' => $slug], ['name' => ['en' => $en, 'ar' => $ar], 'is_authorized' => true, 'is_featured' => $featured, 'authorization_note' => 'Demo']);
            $ids = array_map(fn ($c) => $cats[$c]->id, $categories);
            Product::query()->whereIn('category_id', $ids)->where('slug', 'like', 'demo-%')->update(['brand_id' => $brand->id, 'brand' => $en]);
        }
    }

    /**
     * Electronics: brand authorization required, options by colour and storage/size with their own costs,
     * specifications and warranty text.
     */
    private function electronics(CountryDirectory $countries, Repricer $repricer, ListingRefresher $listing, Supplier $supplier): void
    {
        $root = Category::query()->firstOrCreate(['slug' => 'electronics'], ['name' => ['en' => 'Electronics', 'ar' => 'إلكترونيات'], 'sort_order' => 1, 'requires_authorized_brand' => true]);
        $sub = [];
        foreach (['mobiles' => ['Mobiles', 'جوالات'], 'laptops' => ['Laptops', 'لابتوبات'], 'audio' => ['Audio', 'سماعات وصوتيات'], 'smartwatches' => ['Smart watches', 'ساعات ذكية'], 'tech-accessories' => ['Tech accessories', 'ملحقات إلكترونية']] as $slug => [$en, $ar]) {
            $sub[$slug] = Category::query()->firstOrCreate(['slug' => $slug], ['name' => ['en' => $en, 'ar' => $ar], 'parent_id' => $root->id, 'sort_order' => count($sub)]);
        }
        $brand = fn (string $slug, string $en, string $ar) => Brand::query()->firstOrCreate(['slug' => $slug], ['name' => ['en' => $en, 'ar' => $ar], 'is_authorized' => true, 'is_featured' => true, 'authorization_note' => 'Demo distributor agreement']);

        $black = [[45, 45, 50], [15, 15, 20]];
        $silver = [[225, 228, 232], [170, 175, 182]];
        $white = [[248, 248, 248], [205, 208, 212]];
        $items = [
            ['mobiles', $brand('nova', 'Nova', 'نوفا'), 'Nova X5 Smartphone', 'جوال نوفا X5', '180', 'storage', ['128 GB' => '0', '256 GB' => '40'], ['Black' => $black, 'Silver' => $silver], '851713',
                "Display: 6.5\" AMOLED, 120 Hz\nProcessor: Octa-core 2.8 GHz\nRAM: 8 GB\nCamera: 50 MP main + 12 MP ultra-wide\nBattery: 5000 mAh, 45 W fast charging\nSIM: Dual SIM (nano + eSIM)\nNetwork: 5G",
                "الشاشة: 6.5 بوصة AMOLED بتردد 120 هرتز\nالمعالج: ثماني النواة 2.8 جيجاهرتز\nالذاكرة: 8 جيجابايت\nالكاميرا: 50 ميجابكسل رئيسية + 12 ميجابكسل واسعة\nالبطارية: 5000 مللي أمبير، شحن سريع 45 واط\nالشريحة: شريحتان (نانو + eSIM)\nالشبكة: 5G"],
            ['laptops', $brand('volt', 'Volt', 'فولت'), 'Volt Book 14', 'لابتوب فولت بوك 14', '320', 'storage', ['8 GB / 256 GB' => '0', '16 GB / 512 GB' => '90'], ['Silver' => $silver], '847130',
                "Display: 14\" FHD IPS\nProcessor: 8-core, up to 4.4 GHz\nGraphics: Integrated\nWeight: 1.3 kg\nBattery: up to 12 hours\nKeyboard: English/Arabic",
                "الشاشة: 14 بوصة FHD IPS\nالمعالج: ثماني النواة حتى 4.4 جيجاهرتز\nكرت الشاشة: مدمج\nالوزن: 1.3 كغ\nالبطارية: حتى 12 ساعة\nلوحة المفاتيح: إنجليزي/عربي"],
            ['audio', $brand('aurora', 'Aurora Audio', 'أورورا'), 'Aurora Buds Pro', 'سماعات أورورا بودز برو', '22', 'option', ['Standard' => '0'], ['Black' => $black, 'White' => $white], '851830',
                "Noise cancelling: Active (ANC)\nBattery: 8 h + 24 h with case\nBluetooth: 5.3\nWater resistance: IPX4",
                "عزل الضوضاء: نشط (ANC)\nالبطارية: 8 ساعات + 24 ساعة مع العلبة\nبلوتوث: 5.3\nمقاومة الماء: IPX4"],
            ['smartwatches', $brand('pulse', 'Pulse', 'بلس'), 'Pulse Watch 2', 'ساعة بلس 2', '35', 'size', ['41 mm' => '0', '45 mm' => '5'], ['Black' => $black, 'Silver' => $silver], '910212',
                "Display: AMOLED, always-on\nHealth: heart rate, SpO2, sleep\nGPS: Built-in\nBattery: up to 7 days\nWater resistance: 5 ATM",
                "الشاشة: AMOLED تعمل دائماً\nالصحة: نبض القلب، الأكسجين، النوم\nGPS: مدمج\nالبطارية: حتى 7 أيام\nمقاومة الماء: 5 ATM"],
            ['tech-accessories', $brand('volt', 'Volt', 'فولت'), 'Volt 65W USB-C Charger', 'شاحن فولت 65 واط USB-C', '9', 'option', ['UK plug (UAE/KSA)' => '0'], ['White' => $white], '850440',
                "Output: 65 W USB-C PD\nPorts: 2 × USB-C, 1 × USB-A\nPlug: Type G (UAE and Saudi Arabia)",
                "القدرة: 65 واط USB-C PD\nالمنافذ: 2 USB-C و1 USB-A\nالقابس: نوع G (الإمارات والسعودية)"],
        ];

        foreach ($items as $n => [$cat, $b, $en, $ar, $cost, $optionKey, $options, $colours, $hs, $specsEn, $specsAr]) {
            $slug = 'demo-e'.($n + 1);
            if (Product::query()->where('slug', $slug)->exists()) {
                continue;
            }
            $product = Product::query()->create([
                'category_id' => $sub[$cat]->id, 'brand_id' => $b->id, 'brand' => $b->name['en'],
                'title' => ['en' => $en, 'ar' => $ar], 'slug' => $slug,
                'description' => ['en' => "{$en}. Demo product for the GulfCart storefront.", 'ar' => "{$ar}. منتج تجريبي لمتجر غلف كارت."],
                'specifications' => ['en' => $specsEn, 'ar' => $specsAr],
                'warranty' => ['en' => '1-year warranty, replacement within 14 days for defects', 'ar' => 'ضمان سنة، واستبدال خلال 14 يوماً للعيوب المصنعية'],
                'status' => 'published', 'attributes' => [], 'weight_grams' => 600, 'hs_code' => $hs, 'published_at' => now()->subMinutes(30 + $n),
            ]);
            $sp = $supplier->products()->create(['product_id' => $product->id, 'external_product_id' => strtoupper($slug), 'cost_price' => $cost, 'cost_currency' => 'USD', 'stock_status' => 'in_stock']);
            $i = 0;
            foreach ($colours as $colour => [$top, $bottom]) {
                $path = "products/{$product->id}/".strtolower($colour).'.png';
                Storage::disk((string) config('gulfcart.catalog_disk', 'public'))->put($path, $this->png($top, $bottom));
                $image = $product->images()->create(['storage_path' => $path, 'rights_status' => 'approved', 'rights_basis' => 'own_photo', 'sort_order' => $i]);
                $first = true;
                foreach ($options as $option => $extra) {
                    $i++;
                    $attributes = count($colours) > 1 || $optionKey !== 'option' ? ['color' => $colour, $optionKey => $option] : ['color' => $colour];
                    if ($optionKey === 'option' && count($options) === 1) {
                        $attributes = ['color' => $colour];
                    }
                    $variant = $product->variants()->create(['sku' => strtoupper($slug).'-'.$i, 'attributes' => $attributes]);
                    $sp->mappings()->create([
                        'variant_id' => $variant->id, 'external_variant_id' => strtoupper($slug).'-V'.$i,
                        'cost_price' => $extra === '0' ? null : number_format((float) $cost + (float) $extra, 2, '.', ''),
                    ]);
                    if ($first) {
                        $image->update(['variant_id' => $variant->id]);
                        $first = false;
                    }
                }
            }
            foreach ($countries->active() as $country) {
                $product->availability()->create(['country_id' => $country->id, 'is_available' => true, 'estimated_delivery_min_days' => 5, 'estimated_delivery_max_days' => 9]);
            }
            $repricer->reprice($product->id, notify: false);
            $listing->refresh($product->id);
        }
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

    /**
     * 300×400 PNG: vertical gradient with a soft rounded shape (no GD required).
     *
     * @param  array{0: int, 1: int, 2: int}  $top
     * @param  array{0: int, 1: int, 2: int}  $bottom
     */
    private function png(array $top, array $bottom): string
    {
        [$w, $h] = [300, 400];
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $t = $y / ($h - 1);
            $raw .= "\0";
            for ($x = 0; $x < $w; $x++) {
                $c = [];
                for ($k = 0; $k < 3; $k++) {
                    $c[$k] = $top[$k] + ($bottom[$k] - $top[$k]) * $t;
                }
                // soft ellipse "product" silhouette and a floor shadow
                $dx = ($x - 150) / 95;
                $dy = ($y - 190) / 140;
                $d = $dx * $dx + $dy * $dy;
                if ($d < 1) {
                    $f = 0.22 * (1 - $d);
                    for ($k = 0; $k < 3; $k++) {
                        $c[$k] = $c[$k] + (255 - $c[$k]) * $f;
                    }
                }
                $sx = ($x - 150) / 110;
                $sy = ($y - 352) / 14;
                if ($sx * $sx + $sy * $sy < 1) {
                    for ($k = 0; $k < 3; $k++) {
                        $c[$k] *= 0.88;
                    }
                }
                $raw .= chr((int) $c[0]).chr((int) $c[1]).chr((int) $c[2]);
            }
        }
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)).$chunk('IDAT', (string) gzcompress($raw, 6)).$chunk('IEND', '');
    }
}
