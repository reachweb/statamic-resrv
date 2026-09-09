<?php

namespace Reach\StatamicResrv\Tests\Rate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Tests\TestCase;

class RateUnitsPerAddonCpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_store_persists_units_per_addon()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $response = $this->post(cp_route('resrv.rate.store'), $this->payload(['units_per_addon' => 2]));
        $response->assertStatus(200)->assertJsonStructure(['id']);

        $this->assertDatabaseHas('resrv_rates', [
            'slug' => 'single-occupancy',
            'units_per_addon' => 2,
        ]);

        // store() answers a bare {id}; the value comes back through the rates index the CP reloads from.
        $rates = collect($this->get(cp_route('resrv.rate.index', ['collection' => 'pages']))->json())->keyBy('slug');
        $this->assertSame(2, $rates['single-occupancy']['units_per_addon']);
    }

    public function test_store_without_the_key_persists_null()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $response = $this->post(cp_route('resrv.rate.store'), $this->payload());
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rates', [
            'slug' => 'single-occupancy',
            'units_per_addon' => null,
        ]);
    }

    public function test_store_with_explicit_null_persists_null()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $response = $this->post(cp_route('resrv.rate.store'), $this->payload(['units_per_addon' => null]));
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rates', [
            'slug' => 'single-occupancy',
            'units_per_addon' => null,
        ]);
    }

    public function test_update_sets_units_per_addon()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create(['collection' => 'pages']);

        $response = $this->patch(cp_route('resrv.rate.update', $rate->id), $this->payload([
            'title' => $rate->title,
            'slug' => $rate->slug,
            'units_per_addon' => 2,
        ]));
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rates', [
            'id' => $rate->id,
            'units_per_addon' => 2,
        ]);
    }

    /**
     * The CP sends null when the admin empties the field, so an explicit null must clear a
     * stored divisor — unlike an omitted key (next test), which leaves the column alone.
     */
    public function test_update_with_explicit_null_clears_units_per_addon()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->unitsPerAddon(1)->create(['collection' => 'pages']);

        $response = $this->patch(cp_route('resrv.rate.update', $rate->id), $this->payload([
            'title' => $rate->title,
            'slug' => $rate->slug,
            'units_per_addon' => null,
        ]));
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rates', [
            'id' => $rate->id,
            'units_per_addon' => null,
        ]);
    }

    /**
     * A payload that says nothing about units_per_addon must not wipe the stored value
     * (same partial-update contract as the cancellation-policy keys).
     */
    public function test_update_without_the_key_keeps_units_per_addon()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->unitsPerAddon(1)->create(['collection' => 'pages']);

        $response = $this->patch(cp_route('resrv.rate.update', $rate->id), $this->payload([
            'title' => 'Renamed Rate',
            'slug' => $rate->slug,
        ]));
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rates', [
            'id' => $rate->id,
            'title' => 'Renamed Rate',
            'units_per_addon' => 1,
        ]);
    }

    public static function invalidUnitsPerAddon(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'fraction' => [1.5],
            'non-numeric' => ['abc'],
        ];
    }

    #[DataProvider('invalidUnitsPerAddon')]
    public function test_store_rejects_invalid_units_per_addon(mixed $value)
    {
        $this->withExceptionHandling();

        $this->makeStatamicItemWithResrvAvailabilityField();

        $response = $this->postJson(cp_route('resrv.rate.store'), $this->payload(['units_per_addon' => $value]));
        $response->assertStatus(422)->assertJsonValidationErrors('units_per_addon');

        $this->assertDatabaseMissing('resrv_rates', ['slug' => 'single-occupancy']);
    }

    #[DataProvider('invalidUnitsPerAddon')]
    public function test_update_rejects_invalid_units_per_addon_and_keeps_the_stored_value(mixed $value)
    {
        $this->withExceptionHandling();

        $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->unitsPerAddon(1)->create(['collection' => 'pages']);

        $response = $this->patchJson(cp_route('resrv.rate.update', $rate->id), $this->payload([
            'title' => $rate->title,
            'slug' => $rate->slug,
            'units_per_addon' => $value,
        ]));
        $response->assertStatus(422)->assertJsonValidationErrors('units_per_addon');

        $this->assertDatabaseHas('resrv_rates', [
            'id' => $rate->id,
            'units_per_addon' => 1,
        ]);
    }

    public function test_index_exposes_units_per_addon()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        Rate::factory()->unitsPerAddon(1)->create([
            'collection' => 'pages',
            'title' => 'Single occupancy',
            'slug' => 'single-occupancy',
        ]);
        Rate::factory()->create(['collection' => 'pages']);

        $response = $this->get(cp_route('resrv.rate.index', ['collection' => 'pages']));
        $response->assertStatus(200)->assertJsonCount(2);

        $rates = collect($response->json())->keyBy('slug');

        $this->assertSame(1, $rates['single-occupancy']['units_per_addon']);
        $this->assertArrayHasKey('units_per_addon', $rates['standard-rate']);
        $this->assertNull($rates['standard-rate']['units_per_addon']);
    }

    public function test_for_entry_exposes_units_per_addon()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        Rate::factory()->unitsPerAddon(1)->create([
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Single occupancy',
            'slug' => 'single-occupancy',
        ]);

        $response = $this->get(cp_route('resrv.rate.forEntry', $item->id()));
        $response->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonFragment(['slug' => 'single-occupancy', 'units_per_addon' => 1]);
    }

    /**
     * There is no JS test runner for the CP, so pin the Vue sources: the rate panel must
     * bind and surface errors for the field, and the list's blank-rate template must carry
     * the key so a freshly added rate hydrates it as null rather than undefined.
     */
    public function test_rate_panel_and_rates_list_sources_carry_units_per_addon()
    {
        $ratePanel = file_get_contents(__DIR__.'/../../resources/js/components/RatePanel.vue');
        $ratesList = file_get_contents(__DIR__.'/../../resources/js/components/RatesList.vue');

        $this->assertStringContainsString('form.units_per_addon', $ratePanel);
        $this->assertStringContainsString('form.errors.units_per_addon', $ratePanel);
        $this->assertStringContainsString('units_per_addon: null', $ratesList);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Single occupancy',
            'slug' => 'single-occupancy',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'published' => true,
        ], $overrides);
    }
}
