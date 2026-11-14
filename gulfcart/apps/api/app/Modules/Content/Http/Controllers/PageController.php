<?php

namespace App\Modules\Content\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Content\Infrastructure\Models\Page;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\I18n\Translations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Content pages (policies, about, contact). Body is plain text with a tiny markup:
 * "## " heading lines, "- " bullet lines, blank line between paragraphs. Rendered escaped.
 */
final class PageController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(Page::query()->where('is_published', true)->orderBy('sort_order')->get()
            ->map(fn (Page $p) => ['slug' => $p->slug, 'title' => Translations::pick($p->title), 'in_footer' => $p->in_footer])->all());
    }

    public function show(string $slug): JsonResponse
    {
        $page = Page::query()->where('slug', $slug)->where('is_published', true)->first() ?? throw new ApiException('NOT_FOUND', 404);

        return ApiResponse::success([
            'slug' => $page->slug, 'title' => Translations::pick($page->title), 'body' => Translations::pick($page->body),
            'updated_at' => $page->updated_at?->toIso8601String(),
        ] + ['contact' => $page->slug === 'contact' ? $this->contact() : null]);
    }

    public function adminIndex(): JsonResponse
    {
        return ApiResponse::success(Page::query()->orderBy('sort_order')->get()->map(fn (Page $p) => $this->present($p))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $page = Page::query()->create($this->validated($request, null));
        Cache::forget('site.footer_pages.en');
        Cache::forget('site.footer_pages.ar');
        $this->audit->record('page.created', 'page', $page->id, null, ['slug' => $page->slug]);

        return ApiResponse::success($this->present($page), 201);
    }

    public function update(Request $request, Page $page): JsonResponse
    {
        $data = $this->validated($request, $page);
        $page->update($data);
        Cache::forget('site.footer_pages.en');
        Cache::forget('site.footer_pages.ar');
        $this->audit->record('page.updated', 'page', $page->id, null, ['slug' => $page->slug]);

        return ApiResponse::success($this->present($page));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Page $page): array
    {
        $creating = $page === null;

        return $request->validate(Translations::rules('title', $creating, $creating, 150) + Translations::rules('body', $creating, $creating, 60000) + [
            'slug' => [$creating ? 'required' : 'sometimes', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('pages', 'slug')->ignore($page?->id)],
            'is_published' => ['sometimes', 'boolean'],
            'in_footer' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Page $p): array
    {
        return $p->only(['id', 'slug', 'title', 'body', 'is_published', 'in_footer', 'sort_order']) + ['updated_at' => $p->updated_at?->toIso8601String()];
    }

    /** @return array<string, string|null> */
    private function contact(): array
    {
        return [
            'email' => config('gulfcart.support.email'),
            'whatsapp' => config('gulfcart.support.whatsapp'),
            'hours' => config('gulfcart.support.hours'),
        ];
    }
}
