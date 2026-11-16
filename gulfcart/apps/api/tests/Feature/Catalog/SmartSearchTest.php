<?php

namespace Tests\Feature\Catalog;

use Tests\Support\CatalogFixtures;
use Tests\TestCase;

final class SmartSearchTest extends TestCase
{
    use CatalogFixtures;

    /** @return list<string> */
    private function find(string $q): array
    {
        return collect($this->getJson('/api/v1/products?q='.urlencode($q), ['X-Country' => 'AE'])->assertOk()->json('data'))->pluck('slug')->all();
    }

    public function test_arabic_variants_typos_word_order_and_digits(): void
    {
        $this->sellableProduct(['AE'], ['slug' => 'bracelet', 'title' => ['en' => 'Gold Bracelet', 'ar' => 'إسوارة ذهبية']]);
        $this->sellableProduct(['AE'], ['slug' => 'dress', 'title' => ['en' => 'Linen Summer Dress', 'ar' => 'فستانٌ صيفي من الكتّان']]);
        $this->sellableProduct(['AE'], ['slug' => 'shoes', 'title' => ['en' => 'Everyday Sneakers 2026', 'ar' => 'حذاء رياضي يومي']]);
        $this->sellableProduct(['AE'], ['slug' => 'library', 'title' => ['en' => 'Book Shelf', 'ar' => 'مكتبة خشبية']]);

        $this->assertSame(['bracelet'], $this->find('اسواره'));          // إ → ا, ة → ه
        $this->assertSame(['dress'], $this->find('فستان'));             // tanween in the title ignored
        $this->assertSame(['dress'], $this->find('الكتان صيفي'));       // any word order
        $this->assertSame(['library'], $this->find('مكتبه'));
        $this->assertSame(['shoes'], $this->find('sneakrs'));           // typo
        $this->assertSame(['shoes'], $this->find('SNEAKERS ٢٠٢٦'));     // case and Arabic-Indic digits
        $this->assertSame(['dress'], $this->find('summer dres'));
        $this->assertSame([], $this->find('laptop'));
        $this->assertSame([], $this->find('100%_'));                    // LIKE wildcards are literal
    }
}
