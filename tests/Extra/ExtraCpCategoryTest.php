<?php

namespace Reach\StatamicResrv\Tests\Extra;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\ExtraCategory;
use Reach\StatamicResrv\Tests\TestCase;

class ExtraCpCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_it_can_create_a_category()
    {
        $category = ExtraCategory::factory()->make();

        $response = $this->postJson(cp_route('resrv.extraCategory.create'), $category->toArray());

        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_extra_categories', $category->toArray());
    }

    public function it_can_update_a_category()
    {
        $category = ExtraCategory::factory()->create();

        $response = $this->patchJson(cp_route('resrv.extraCategory.update', $category->id), [
            'title' => 'Updated Category',
            'description' => 'Updated Description',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_extra_categories', [
            'id' => $category->id,
            'title' => 'Updated Category',
            'description' => 'Updated Description',
        ]);
    }

    public function test_it_can_delete_a_category()
    {
        $category = ExtraCategory::factory()->create();
        $extra = Extra::factory()->create(['category_id' => $category->id]);

        $response = $this->deleteJson(cp_route('resrv.extraCategory.delete', $category->id));

        $response->assertStatus(200);
        $this->assertDatabaseMissing('resrv_extra_categories', ['id' => $category->id]);
        $this->assertDatabaseHas('resrv_extras', [
            'id' => $extra->id,
            'category_id' => null,
        ]);
    }
}
