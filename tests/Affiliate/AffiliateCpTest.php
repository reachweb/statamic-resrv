<?php

namespace Reach\StatamicResrv\Tests\Extra;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Tests\TestCase;

class AffiliateCpTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
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
}
