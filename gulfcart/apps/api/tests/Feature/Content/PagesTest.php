<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Database\Seeders\PageSeeder;
use Tests\TestCase;

final class PagesTest extends TestCase
{
    public function test_policy_pages_are_seeded_bilingual_and_listed_in_site_settings(): void
    {
        $this->seed(PageSeeder::class);
        config(['gulfcart.support.whatsapp' => '971500000000', 'gulfcart.tracking.meta_pixel_id' => '123']);

        $this->getJson('/api/v1/pages/returns', ['Accept-Language' => 'ar'])->assertOk()
            ->assertJsonPath('data.title', 'الإرجاع والاسترداد')->assertJsonPath('data.contact', null);
        $this->getJson('/api/v1/pages/contact')->assertOk()->assertJsonPath('data.contact.whatsapp', '971500000000');
        $this->getJson('/api/v1/site')->assertOk()
            ->assertJsonPath('data.tracking.meta_pixel_id', '123')
            ->assertJsonCount(7, 'data.pages');
        $this->getJson('/api/v1/pages/nope')->assertNotFound();
    }

    public function test_catalog_manager_edits_a_page_and_unpublished_pages_are_hidden(): void
    {
        $this->seed(PageSeeder::class);
        $staff = $this->asStaff(['catalog_manager']);
        $id = collect($this->getJson('/api/v1/admin/pages', $staff)->assertOk()->json('data'))->firstWhere('slug', 'faq')['id'];

        $this->patchJson("/api/v1/admin/pages/{$id}", ['is_published' => false], $this->asStaff(['catalog_manager']))->assertOk();
        $this->getJson('/api/v1/pages/faq')->assertNotFound();
        $this->patchJson("/api/v1/admin/pages/{$id}", ['is_published' => true], $this->asStaff(['support']))->assertForbidden();
    }
}
