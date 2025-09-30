<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Tests\TestCase;

class DataImportCpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_can_show_cp_index_page()
    {
        $response = $this->get(cp_route('resrv.dataimport.index'));
        $response->assertStatus(200)->assertSee('Import');
    }
}
