<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\I18n\Translations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Search and advertising feeds:
 * - GET /seo/urls: published product and category slugs for the storefront sitemap.
 * - GET /feeds/products.xml?country=AE&lang=ar: Google Merchant Center RSS 2.0 feed, also accepted by
 *   Meta Commerce Manager, TikTok and Snapchat catalogs. Only sellable products, with live prices.
 */
final class FeedController
{
    public function __construct(private readonly CountryDirectory $countries) {}

    public function urls(): JsonResponse
    {
        return ApiResponse::success(Cache::remember('seo.urls', 3600, fn () => [
            'products' => Product::query()->where('status', ProductStatus::PUBLISHED)->orderByDesc('published_at')->limit(45000)
                ->get(['slug', 'updated_at'])->map(fn ($p) => ['slug' => $p->slug, 'updated_at' => $p->updated_at?->toIso8601String()])->all(),
            'categories' => Category::query()->where('is_active', true)->pluck('slug')->all(),
        ]));
    }

    public function products(Request $request): Response
    {
        $code = strtoupper((string) $request->query('country', 'AE'));
        $lang = $request->query('lang') === 'ar' ? 'ar' : 'en';
        $country = $this->countries->find($code) ?? throw new ApiException('COUNTRY_NOT_SUPPORTED', 422);

        $xml = Cache::remember("feed.products.{$code}.{$lang}", 3600, function () use ($country, $lang) {
            $site = rtrim((string) config('gulfcart.frontend_url'), '/').($lang === 'ar' ? '/ar' : '');
            $esc = fn (?string $v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $items = [];
            Product::query()
                ->select('products.*', 'pca.from_price', 'pca.from_price_currency')
                ->join('product_country_availability as pca', fn ($j) => $j->on('pca.product_id', '=', 'products.id')->where('pca.country_id', '=', $country->id))
                ->where('products.status', ProductStatus::PUBLISHED)->where('pca.is_available', true)->where('pca.is_sellable', true)
                ->with(['images', 'category'])->orderBy('products.id')
                ->chunk(500, function ($products) use (&$items, $esc, $site, $lang) {
                    foreach ($products as $p) {
                        $image = $p->images->first(fn ($i) => $i->isDisplayable())?->publicUrl();
                        if ($image === null) {
                            continue;
                        }
                        $title = Translations::pick($p->title, $lang);
                        $desc = trim(strip_tags((string) Translations::pick($p->description, $lang))) ?: $title;
                        $items[] = '<item>'
                            .'<g:id>'.$esc($p->id).'</g:id>'
                            .'<title>'.$esc(mb_substr((string) $title, 0, 150)).'</title>'
                            .'<description>'.$esc(mb_substr($desc, 0, 5000)).'</description>'
                            .'<link>'.$esc($site.'/products/'.$p->slug).'</link>'
                            .'<g:image_link>'.$esc($image).'</g:image_link>'
                            .'<g:availability>in_stock</g:availability>'
                            .'<g:condition>new</g:condition>'
                            .'<g:price>'.$esc(number_format((float) $p->getAttribute('from_price'), 2, '.', '').' '.$p->getAttribute('from_price_currency')).'</g:price>'
                            .($p->brand ? '<g:brand>'.$esc($p->brand).'</g:brand>' : '<g:identifier_exists>no</g:identifier_exists>')
                            .($p->category ? '<g:product_type>'.$esc(Translations::pick($p->category->name, $lang)).'</g:product_type>' : '')
                            .'</item>';
                    }
                });

            return '<?xml version="1.0" encoding="UTF-8"?>'
                .'<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>'
                .'<title>GulfCart</title><link>'.$esc($site).'</link><description>GulfCart products</description>'
                .implode('', $items).'</channel></rss>';
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
