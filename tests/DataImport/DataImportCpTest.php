<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Reach\StatamicResrv\Jobs\ProcessDataImport;
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

    // store() mutates availability data, so it must be POST (CSRF-protected), not a GET link.
    // A GET to the URL no longer triggers an import — it falls through to the CP catch-all (404).
    public function test_store_route_is_not_accessible_via_get()
    {
        $status = $this->get(cp_route('resrv.dataimport.store'))->status();
        $this->assertContains($status, [404, 405]);
    }

    public function test_store_consumes_the_per_user_cache_key_not_the_global_one()
    {
        Queue::fake();

        // A leftover global/other-user import must NOT be picked up by this user's store().
        Cache::put('resrv-data-import', 'stale');
        $this->post(cp_route('resrv.dataimport.store'))->assertStatus(200);
        Queue::assertNotPushed(ProcessDataImport::class);

        // The signed-in admin's scoped key (id 1) is what store() consumes and dispatches.
        Cache::put('resrv-data-import-1', 'ready');
        $this->post(cp_route('resrv.dataimport.store'))->assertStatus(200);
        Queue::assertPushed(ProcessDataImport::class, 1);
    }
}
