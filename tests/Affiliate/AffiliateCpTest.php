<?php

namespace Reach\StatamicResrv\Tests\Extra;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Tests\TestCase;

class AffiliateCpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_can_index_affiliates()
    {
        $affiliate = Affiliate::factory()->create();

        $response = $this->get(cp_route('resrv.affiliate.index'));
        $response->assertStatus(200)->assertSee($affiliate->name);
    }

    public function test_can_show_cp_index_page()
    {
        Affiliate::factory()->create();

        $response = $this->get(cp_route('resrv.affiliates.index'));
        $response->assertStatus(200)->assertSee('affiliate');
    }

    public function test_can_create_an_affiliate()
    {
        $affiliate = Affiliate::factory()->make();

        $response = $this->post(cp_route('resrv.affiliate.create'), $affiliate->toArray());
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_affiliates', [
            ...$affiliate->toArray(),
        ]);
    }

    public function test_can_update_an_affiliate()
    {
        $affiliate = Affiliate::factory()->create();

        $affiliate = $affiliate->toArray();

        $affiliate['name'] = 'Something else';
        $affiliate['code'] = 'NEWCODE';

        $response = $this->patch(cp_route('resrv.affiliate.update', $affiliate['id']), $affiliate);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_affiliates', [
            'name' => 'Something else',
            'code' => 'NEWCODE',
        ]);
    }

    public function test_can_delete_an_affiliate()
    {
        $affiliate = Affiliate::factory()->create();

        $response = $this->delete(cp_route('resrv.affiliate.delete', $affiliate->id));
        $response->assertStatus(200);

        $this->assertSoftDeleted($affiliate);
    }

    public function test_can_create_affiliate_with_coupons()
    {
        $item = $this->makeStatamicItem();
        $coupon1 = DynamicPricing::factory()->create(['coupon' => 'TEST123']);
        $coupon1->entries()->sync([$item->id()]);

        $coupon2 = DynamicPricing::factory()->create(['coupon' => 'TEST456']);
        $coupon2->entries()->sync([$item->id()]);

        $affiliate = Affiliate::factory()->make()->toArray();
        $affiliate['coupons'] = [$coupon1->id, $coupon2->id];

        $response = $this->post(cp_route('resrv.affiliate.create'), $affiliate);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_affiliates', [
            'name' => $affiliate['name'],
            'code' => $affiliate['code'],
        ]);

        $this->assertDatabaseHas('resrv_affiliate_dynamic_pricing', [
            'dynamic_pricing_id' => $coupon1->id,
        ]);

        $this->assertDatabaseHas('resrv_affiliate_dynamic_pricing', [
            'dynamic_pricing_id' => $coupon2->id,
        ]);
    }

    public function test_can_update_affiliate_coupons()
    {
        $item = $this->makeStatamicItem();
        $coupon1 = DynamicPricing::factory()->create(['coupon' => 'TEST123']);
        $coupon1->entries()->sync([$item->id()]);

        $coupon2 = DynamicPricing::factory()->create(['coupon' => 'TEST456']);
        $coupon2->entries()->sync([$item->id()]);

        $coupon3 = DynamicPricing::factory()->create(['coupon' => 'TEST789']);
        $coupon3->entries()->sync([$item->id()]);

        $affiliate = Affiliate::factory()->create();
        $affiliate->coupons()->sync([$coupon1->id]);

        $updateData = $affiliate->toArray();
        $updateData['coupons'] = [$coupon2->id, $coupon3->id];

        $response = $this->patch(cp_route('resrv.affiliate.update', $affiliate->id), $updateData);
        $response->assertStatus(200);

        $this->assertDatabaseMissing('resrv_affiliate_dynamic_pricing', [
            'affiliate_id' => $affiliate->id,
            'dynamic_pricing_id' => $coupon1->id,
        ]);

        $this->assertDatabaseHas('resrv_affiliate_dynamic_pricing', [
            'affiliate_id' => $affiliate->id,
            'dynamic_pricing_id' => $coupon2->id,
        ]);

        $this->assertDatabaseHas('resrv_affiliate_dynamic_pricing', [
            'affiliate_id' => $affiliate->id,
            'dynamic_pricing_id' => $coupon3->id,
        ]);
    }

    public function test_affiliate_index_includes_coupons()
    {
        $item = $this->makeStatamicItem();
        $coupon = DynamicPricing::factory()->create(['coupon' => 'TEST123']);
        $coupon->entries()->sync([$item->id()]);

        $affiliate = Affiliate::factory()->create();
        $affiliate->coupons()->sync([$coupon->id]);

        $response = $this->get(cp_route('resrv.affiliate.index'));
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertNotEmpty($data[0]['coupons_ids']);
        $this->assertContains($coupon->id, $data[0]['coupons_ids']);
    }

    public function test_can_create_affiliate_without_coupons()
    {
        $affiliate = Affiliate::factory()->make()->toArray();
        $affiliate['coupons'] = [];

        $response = $this->post(cp_route('resrv.affiliate.create'), $affiliate);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_affiliates', [
            'name' => $affiliate['name'],
        ]);
    }

    public function test_coupon_cannot_be_assigned_to_multiple_affiliates()
    {
        $item = $this->makeStatamicItem();
        $coupon = DynamicPricing::factory()->create(['coupon' => 'TEST123']);
        $coupon->entries()->sync([$item->id()]);

        // Create first affiliate with the coupon
        $affiliate1 = Affiliate::factory()->create(['code' => 'AFF001', 'email' => 'aff1@example.com']);
        $affiliate1->coupons()->sync([$coupon->id]);

        // Try to create second affiliate with the same coupon - should fail validation
        $affiliate2Data = Affiliate::factory()->make(['code' => 'AFF002', 'email' => 'aff2@example.com'])->toArray();
        $affiliate2Data['coupons'] = [$coupon->id];

        $this->withoutExceptionHandling();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->post(cp_route('resrv.affiliate.create'), $affiliate2Data);

        // Verify the coupon is still only associated with affiliate1
        $this->assertDatabaseHas('resrv_affiliate_dynamic_pricing', [
            'affiliate_id' => $affiliate1->id,
            'dynamic_pricing_id' => $coupon->id,
        ]);

        // Count should be 1
        $count = \DB::table('resrv_affiliate_dynamic_pricing')
            ->where('dynamic_pricing_id', $coupon->id)
            ->count();
        $this->assertEquals(1, $count);
    }
}
