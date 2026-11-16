<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Engagement\Infrastructure\Models\Review;
use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Orders\Contracts\PurchaseHistory;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Verified-purchase reviews: only customers who received the product can review it, once.
 * Reviews are published after moderation; the reviewer's name is shortened (e.g. "Sara A.").
 */
final class ReviewController
{
    public function __construct(private readonly PurchaseHistory $purchases, private readonly UserDirectory $users, private readonly AuditLogger $audit) {}

    public function index(Request $request, string $productId): JsonResponse
    {
        $approved = Review::query()->where('product_id', $productId)->where('status', 'approved');
        $summary = (clone $approved)->selectRaw('count(*) as n, avg(rating) as avg')->first();
        $distribution = (clone $approved)->selectRaw('rating, count(*) as n')->groupBy('rating')->pluck('n', 'rating');
        $page = $approved->latest()->paginate(min(20, $request->integer('per_page', 10)));

        $mine = null;
        if ($user = $request->user('sanctum')) {
            $own = Review::query()->where('product_id', $productId)->where('user_id', $user->getAuthIdentifier())->first();
            $mine = [
                'can_review' => $own === null && $this->purchases->deliveredOrderFor((string) $user->getAuthIdentifier(), $productId) !== null,
                'status' => $own?->status,
            ];
        }

        return ApiResponse::success([
            'average' => $summary && $summary->n > 0 ? round((float) $summary->avg, 1) : null,
            'count' => (int) ($summary->n ?? 0),
            'distribution' => (object) collect(range(5, 1))->mapWithKeys(fn ($r) => [$r => (int) ($distribution[$r] ?? 0)])->all(),
            'reviews' => $page->getCollection()->map(fn (Review $r) => $this->present($r, false))->all(),
            'mine' => $mine,
        ], 200, ['pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function store(Request $request, string $productId): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:2000'],
            'photos' => ['sometimes', 'array', 'max:3'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:6144', 'dimensions:min_width=200,min_height=200,max_width=8000,max_height=8000'],
        ]);
        $photos = $request->file('photos', []);
        unset($data['photos']);
        $userId = (string) $request->user()->getAuthIdentifier();
        $orderId = $this->purchases->deliveredOrderFor($userId, $productId) ?? throw new ApiException('REVIEW_NOT_ALLOWED', 403);
        if (Review::query()->where('product_id', $productId)->where('user_id', $userId)->exists()) {
            throw new ApiException('REVIEW_ALREADY_EXISTS', 409);
        }
        $review = Review::query()->create($data + [
            'product_id' => $productId, 'user_id' => $userId, 'order_id' => $orderId, 'locale' => app()->getLocale(), 'status' => 'pending',
        ]);
        if ($photos !== []) {
            $review->update(['photos' => $this->storePhotos($review->id, (array) $photos)]);
        }

        return ApiResponse::success($this->present($review, false), 201);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $status = in_array($request->query('status'), ['pending', 'approved', 'rejected'], true) ? $request->query('status') : 'pending';
        $page = Review::query()->where('status', $status)->latest()->paginate(50);
        $titles = DB::table('products')->whereIn('id', $page->getCollection()->pluck('product_id'))->pluck('title', 'id');

        return ApiResponse::paged($page, $page->getCollection()->map(fn (Review $r) => $this->present($r, true) + [
            'product_title' => json_decode((string) ($titles[$r->product_id] ?? '{}'), true),
        ])->all());
    }

    public function moderate(Request $request, Review $review, string $action): JsonResponse
    {
        abort_unless(in_array($action, ['approve', 'reject'], true), 404);
        $old = $review->status;
        $review->update(['status' => $action === 'approve' ? 'approved' : 'rejected', 'moderated_by' => $request->user()->getAuthIdentifier()]);
        $this->audit->record('review.'.$action.'d', 'product_review', $review->id, ['status' => $old], ['status' => $review->status]);

        return ApiResponse::success($this->present($review, true));
    }

    /** @return array<string, mixed> */
    private function present(Review $r, bool $admin): array
    {
        $contact = $this->users->contact($r->user_id);
        $parts = preg_split('/\s+/', trim((string) ($contact['name'] ?? ''))) ?: [];
        $short = ($parts[0] ?? '') !== '' ? $parts[0].(isset($parts[1]) ? ' '.mb_substr($parts[1], 0, 1).'.' : '') : null;

        return [
            'id' => $r->id, 'rating' => $r->rating, 'title' => $r->title, 'body' => $r->body, 'locale' => $r->locale,
            'author' => $short, 'verified' => $r->order_id !== null, 'created_at' => $r->created_at?->toIso8601String(),
            'photos' => array_map(fn (string $p) => Storage::disk((string) config('gulfcart.catalog_disk', 'public'))->url($p), $r->photos ?? []),
        ] + ($admin ? ['status' => $r->status, 'product_id' => $r->product_id, 'order_id' => $r->order_id] : []);
    }

    /**
     * Re-encodes each photo as JPEG (max 1600 px): drops EXIF data such as the GPS position phones
     * embed, and anything that is not really an image.
     *
     * @param  list<UploadedFile>  $files
     * @return list<string>
     */
    private function storePhotos(string $reviewId, array $files): array
    {
        $disk = Storage::disk((string) config('gulfcart.catalog_disk', 'public'));
        $paths = [];
        foreach (array_values($files) as $i => $file) {
            $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
            if ($src === false) {
                continue;
            }
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = min(1, 1600 / max($w, $h));
            $dst = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
            imagefill($dst, 0, 0, (int) imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
            ob_start();
            imagejpeg($dst, null, 82);
            $path = "reviews/{$reviewId}/".($i + 1).'.jpg';
            $disk->put($path, (string) ob_get_clean(), 'public');
            $paths[] = $path;
        }

        return $paths;
    }
}
