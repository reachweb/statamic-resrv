<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Reach\StatamicResrv\Http\Controllers\ExportCpController;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\Customer;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class ExportCpTest extends TestCase
{
    use CreatesEntries, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
        Cache::forget(ExportCpController::CUSTOMER_KEYS_CACHE_KEY);
    }

    public function test_can_show_export_page()
    {
        $response = $this->get(cp_route('resrv.export.index'));

        $response->assertStatus(200)->assertSee('Export reservations');
    }

    public function test_count_endpoint_returns_matching_reservations()
    {
        $item = $this->makeStatamicItem();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
        ])->count(3)->withCustomer()->create();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'date_start' => today()->subYear()->toIso8601String(),
            'date_end' => today()->subYear()->addDays(2)->toIso8601String(),
        ])->count(2)->withCustomer()->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->get(cp_route('resrv.export.count')."?start={$start}&end={$end}")
            ->assertStatus(200)
            ->assertJson(['count' => 3]);
    }

    public function test_count_endpoint_filters_by_status()
    {
        $item = $this->makeStatamicItem();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->count(2)->withCustomer()->create();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'expired',
        ])->count(3)->withCustomer()->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->get(cp_route('resrv.export.count')."?start={$start}&end={$end}&statuses[]=confirmed")
            ->assertStatus(200)
            ->assertJson(['count' => 2]);
    }

    public function test_count_endpoint_filters_by_item()
    {
        $first = $this->makeStatamicItem();
        $second = $this->makeStatamicItem();

        Reservation::factory(['item_id' => $first->id(), 'status' => 'confirmed'])->count(2)->withCustomer()->create();
        Reservation::factory(['item_id' => $second->id(), 'status' => 'confirmed'])->count(4)->withCustomer()->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->get(cp_route('resrv.export.count')."?start={$start}&end={$end}&item_id={$first->id()}")
            ->assertStatus(200)
            ->assertJson(['count' => 2]);
    }

    public function test_count_endpoint_filters_by_affiliate()
    {
        $item = $this->makeStatamicItem();
        $affiliate = Affiliate::factory()->create();

        $reservations = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->count(2)->withCustomer()->create();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->count(3)->withCustomer()->create();

        foreach ($reservations as $reservation) {
            $reservation->affiliate()->attach($affiliate->id, ['fee' => 0]);
        }

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->get(cp_route('resrv.export.count')."?start={$start}&end={$end}&affiliate_id={$affiliate->id}")
            ->assertStatus(200)
            ->assertJson(['count' => 2]);
    }

    public function test_count_endpoint_filters_by_customer_data_presence()
    {
        $item = $this->makeStatamicItem();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'expired',
        ])->count(2)->withCustomer()->create();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'expired',
        ])->count(3)->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->get(cp_route('resrv.export.count')."?start={$start}&end={$end}&with_customer_data=1")
            ->assertStatus(200)
            ->assertJson(['count' => 2]);
    }

    public function test_download_endpoint_returns_csv_with_selected_fields()
    {
        $item = $this->makeStatamicItem(['title' => 'Beach House']);

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'reference' => 'AAA111',
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
        ])->withCustomer()->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $response = $this->get(
            cp_route('resrv.export.download').
            "?start={$start}&end={$end}".
            '&fields[]=reference&fields[]=status&fields[]=entry_title&fields[]=customer_email'
        );

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertEquals(['Reference', 'Status', 'Item', 'Email'], $rows[0]);
        $this->assertEquals('AAA111', $rows[1][0]);
        $this->assertEquals('confirmed', $rows[1][1]);
        $this->assertEquals('Beach House', $rows[1][2]);
        $this->assertEquals($reservation->customer->email, $rows[1][3]);
    }

    public function test_download_endpoint_renders_extras_and_options()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->withCustomer()->create();

        $extra = Extra::factory()->create(['name' => 'Breakfast']);
        $reservation->extras()->attach($extra->id, ['quantity' => 2, 'price' => 10]);

        $option = Option::factory()->create(['name' => 'Bed type', 'item_id' => $item->id()]);
        $value = OptionValue::factory()->create(['option_id' => $option->id, 'name' => 'King']);
        $reservation->options()->attach($option->id, ['value' => $value->id]);

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $response = $this->get(
            cp_route('resrv.export.download').
            "?start={$start}&end={$end}&fields[]=extras&fields[]=options"
        );

        $response->assertStatus(200);
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Breakfast × 2', $csv);
        $this->assertStringContainsString('Bed type: King', $csv);
    }

    public function test_download_validates_field_whitelist()
    {
        $this->withExceptionHandling();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->getJson(
            cp_route('resrv.export.download').
            "?start={$start}&end={$end}&fields[]=password"
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fields.0']);
    }

    public function test_download_requires_at_least_one_field()
    {
        $this->withExceptionHandling();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->getJson(cp_route('resrv.export.download')."?start={$start}&end={$end}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fields']);
    }

    public function test_count_validates_date_range()
    {
        $this->withExceptionHandling();

        $this->getJson(cp_route('resrv.export.count').'?start=2026-05-10&end=2026-05-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end']);
    }

    public function test_export_page_lists_custom_customer_keys_discovered_in_database()
    {
        Customer::factory()->create([
            'data' => collect([
                'first_name' => 'Test',
                'tax_id' => 'EL123',
                'company' => 'Acme',
            ]),
        ]);

        $response = $this->get(cp_route('resrv.export.index'));

        $response->assertStatus(200);
        $response->assertSee('customer_tax_id');
        $response->assertSee('customer_company');
    }

    public function test_download_includes_dynamically_discovered_customer_field()
    {
        $item = $this->makeStatamicItem();

        $customer = Customer::factory()->create([
            'data' => collect([
                'first_name' => 'Iosif',
                'tax_id' => 'EL123456789',
            ]),
        ]);

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'reference' => 'TAX001',
            'customer_id' => $customer->id,
        ])->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $response = $this->get(
            cp_route('resrv.export.download').
            "?start={$start}&end={$end}&fields[]=reference&fields[]=customer_tax_id"
        );

        $response->assertStatus(200);
        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertEquals(['Reference', 'Tax Id'], $rows[0]);
        $this->assertEquals('TAX001', $rows[1][0]);
        $this->assertEquals('EL123456789', $rows[1][1]);
    }

    public function test_csv_injection_with_leading_whitespace_is_sanitized()
    {
        $item = $this->makeStatamicItem();

        $customer = Customer::factory()->create([
            'data' => collect([
                'first_name' => '  =1+1',
            ]),
        ]);

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'reference' => 'INJ001',
            'customer_id' => $customer->id,
        ])->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $response = $this->get(
            cp_route('resrv.export.download').
            "?start={$start}&end={$end}&fields[]=customer_first_name"
        );

        $response->assertStatus(200);
        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertSame("'  =1+1", $rows[1][0]);
    }

    public function test_download_includes_property_label_and_handle_for_advanced_availability()
    {
        $entries = $this->createAdvancedEntries();
        $entry = $entries->first();

        Reservation::factory([
            'item_id' => $entry->id(),
            'status' => 'confirmed',
            'reference' => 'PROP001',
            'property' => 'test',
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
        ])->withCustomer()->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $response = $this->get(
            cp_route('resrv.export.download').
            "?start={$start}&end={$end}&fields[]=reference&fields[]=entry_property&fields[]=entry_property_handle"
        );

        $response->assertStatus(200);
        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertEquals(['Reference', 'Property', 'Property handle'], $rows[0]);
        $this->assertEquals('PROP001', $rows[1][0]);
        $this->assertEquals('Test Property', $rows[1][1]);
        $this->assertEquals('test', $rows[1][2]);
    }

    public function test_download_falls_back_to_property_handle_when_entry_is_deleted()
    {
        $entries = $this->createAdvancedEntries();
        $entry = $entries->first();
        $itemId = $entry->id();

        Reservation::factory([
            'item_id' => $itemId,
            'status' => 'confirmed',
            'reference' => 'GONE001',
            'property' => 'test',
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
        ])->withCustomer()->create();

        $entry->delete();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $response = $this->get(
            cp_route('resrv.export.download').
            "?start={$start}&end={$end}&fields[]=reference&fields[]=entry_property&fields[]=entry_property_handle"
        );

        $response->assertStatus(200);
        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertEquals('GONE001', $rows[1][0]);
        $this->assertEquals('test', $rows[1][1]);
        $this->assertEquals('test', $rows[1][2]);
    }

    public function test_download_returns_blank_property_for_non_advanced_reservation()
    {
        $item = $this->makeStatamicItem();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'reference' => 'NOPROP',
            'property' => null,
        ])->withCustomer()->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $response = $this->get(
            cp_route('resrv.export.download').
            "?start={$start}&end={$end}&fields[]=reference&fields[]=entry_property&fields[]=entry_property_handle"
        );

        $response->assertStatus(200);
        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertEquals('NOPROP', $rows[1][0]);
        $this->assertSame('', $rows[1][1]);
        $this->assertSame('', $rows[1][2]);
    }

    public function test_dynamic_discovery_does_not_duplicate_standard_customer_keys()
    {
        Customer::factory()->create([
            'data' => collect([
                'first_name' => 'Iosif',
                'last_name' => 'Chatzimichail',
                'phone' => '123',
            ]),
        ]);

        $response = $this->get(cp_route('resrv.export.index'));

        $response->assertStatus(200);
        $body = $response->getContent();

        $count = substr_count($body, 'customer_first_name');
        $this->assertSame(1, $count, 'customer_first_name should appear exactly once in the field metadata');
    }
}
