<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ExportCpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
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
}
