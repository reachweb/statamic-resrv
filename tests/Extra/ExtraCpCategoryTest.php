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

    public function test_it_can_index_categories_and_extras()
    {
        $category = ExtraCategory::factory()->create();
        $extra = Extra::factory()->withCategory()->create();
        $uncategorizedExtra = Extra::factory()->create();

        $response = $this->getJson(cp_route('resrv.extraCategory.index'));

        $response->assertStatus(200);

        $response->assertJsonFragment($category->toArray());
        $response->assertJsonFragment($uncategorizedExtra->toArray());
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

    public function test_can_reorder_extra_categories()
    {
        $category = ExtraCategory::factory()->create();
        $category2 = ExtraCategory::factory()->create(['id' => 2, 'order' => 2]);
        $category3 = ExtraCategory::factory()->create(['id' => 3, 'order' => 3]);

        $response = $this->patch(cp_route('resrv.extraCategory.order'), [
            'id' => 1,
            'order' => 3,
        ]);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_extra_categories', [
            'id' => $category['id'],
            'order' => 3,
        ]);
        $this->assertDatabaseHas('resrv_extra_categories', [
            'id' => $category2['id'],
            'order' => 1,
        ]);
        $this->assertDatabaseHas('resrv_extra_categories', [
            'id' => $category3['id'],
            'order' => 2,
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
