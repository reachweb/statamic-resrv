<?php

namespace Reach\StatamicResrv\Tests\Extra;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Tests\TestCase;

class AffiliateFrontTest extends TestCase
{
    use RefreshDatabase;

    public $item;

    public function setUp(): void
    {
        parent::setUp();
        $this->item = $this->makeStatamicItem();
        $this->withStandardFakeViews();
    }

    public function test_that_it_sets_cookie_if_code_in_url()
    {
        $affiliate = Affiliate::factory()->create();

        $response = $this->get('/'.$this->item->slug.'?afid='.$affiliate->code);
        $response->assertStatus(200)->assertCookie('resrv_afid', $affiliate->code);
    }

    public function test_that_it_does_not_set_cookie_if_code_if_wrong()
    {
        Affiliate::factory()->create();

        $response = $this->get('/'.$this->item->slug.'/?afid=SOMETHINGWRONG');
        $response->assertStatus(200)->assertCookieMissing('resrv_afid');
    }

    public function test_that_it_does_overwrite_a_previously_set_cookie()
    {
        $affiliate = Affiliate::factory()->create();
        $newAffiliate = Affiliate::factory()->create(['code' => 'NEWCODE']);

        $response = $this->get('/'.$this->item->slug.'?afid='.$affiliate->code);
        $response->assertStatus(200)->assertCookie('resrv_afid', $affiliate->code);

        $response = $this->get('/'.$this->item->slug.'?afid='.$newAffiliate->code);
        $response->assertStatus(200)->assertCookie('resrv_afid', $newAffiliate->code);
    }
}
