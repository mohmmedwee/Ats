<?php

namespace Tests\Feature\Catalog;

use Tests\Support\CatalogFixtures;
use Tests\TestCase;

final class FeedTest extends TestCase
{
    use CatalogFixtures;

    public function test_product_feed_lists_sellable_products_with_price_and_links(): void
    {
        config(['gulfcart.frontend_url' => 'https://shop.example']);
        ['product' => $p] = $this->sellableProduct(['AE']);
        $this->sellableProduct(['SA']);

        $res = $this->get('/api/v1/feeds/products.xml?country=AE&lang=ar')->assertOk();
        $this->assertStringContainsString('application/xml', (string) $res->headers->get('Content-Type'));
        $xml = simplexml_load_string($res->getContent());
        $items = $xml->channel->item;
        $this->assertCount(1, $items);
        $g = $items[0]->children('http://base.google.com/ns/1.0');
        $this->assertSame($p->id, (string) $g->id);
        $this->assertSame('فستان صيفي', (string) $items[0]->title);
        $this->assertStringStartsWith('https://shop.example/ar/products/', (string) $items[0]->link);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2} AED$/', (string) $g->price);

        $this->getJson('/api/v1/seo/urls')->assertOk()->assertJsonCount(2, 'data.products');
    }
}
