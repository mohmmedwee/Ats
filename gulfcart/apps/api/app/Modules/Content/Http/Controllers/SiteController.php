<?php

namespace App\Modules\Content\Http\Controllers;

use App\Modules\Content\Infrastructure\Models\Page;
use App\Shared\Http\ApiResponse;
use App\Shared\I18n\Translations;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/** Public storefront settings: support channels, footer pages and marketing tag IDs. */
final class SiteController
{
    public function show(): JsonResponse
    {
        $locale = app()->getLocale();
        $pages = Cache::remember("site.footer_pages.{$locale}", 300, fn () => Page::query()
            ->where('is_published', true)->where('in_footer', true)->orderBy('sort_order')->get()
            ->map(fn (Page $p) => ['slug' => $p->slug, 'title' => Translations::pick($p->title)])->all());

        return ApiResponse::success([
            'support' => [
                'email' => config('gulfcart.support.email'),
                'whatsapp' => config('gulfcart.support.whatsapp'),
                'hours' => config('gulfcart.support.hours'),
            ],
            'pages' => $pages,
            'tracking' => array_filter((array) config('gulfcart.tracking')),
        ]);
    }
}
