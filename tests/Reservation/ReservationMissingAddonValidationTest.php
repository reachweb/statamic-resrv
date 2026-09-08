<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Reach\StatamicResrv\Exceptions\ExtrasException;
use Reach\StatamicResrv\Exceptions\OptionsException;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Extra as ResrvExtra;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

/**
 * Checkout validation of a selection whose extra or option no longer exists. The selection is
 * session-backed, so it can outlive a CP deletion; Reservation::validateTotal() must reject it
 * with the explicit extras/options error the checkout renders, not fail on a missing model.
 *
 * Fixture: an entry with one 'double' rate at 100/night, a fixed 350 extra and a fixed option;
 * a normal reservation of 1 unit for 2 nights (200) and a parent with two 1-unit children.
 */
class ReservationMissingAddonValidationTest extends TestCase
{
    use CreatesEntries;
    use RefreshDatabase;

    private $entry;

    private Rate $rate;

    private ResrvExtra $portTaxes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(today()->setHour(12));

        $this->entry = $this->makeStatamicItemWithAvailability(
            collection: 'cabins',
            available: 5,
            price: 100,
            rateSlug: 'double',
        );

        $this->rate = Rate::forEntry($this->entry->id())->where('slug', 'double')->first();

        $this->portTaxes = ResrvExtra::factory()->fixed()->create([
            'name' => 'Port taxes',
            'slug' => 'port-taxes',
            'price' => '350',
        ]);

        ResrvEntry::whereItemId($this->entry->id())->extras()->attach($this->portTaxes->id);
    }

    private function createNormalReservation(): Reservation
    {
        return Reservation::factory()->withRate($this->rate->id)->create([
            'item_id' => $this->entry->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 1,
            'price' => '200.00',
            'payment' => '200.00',
        ]);
    }

    private function createParentReservation(): Reservation
    {
        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'item_id' => $this->entry->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 2,
            'price' => '400.00',
            'payment' => '400.00',
        ]);

        foreach (range(1, 2) as $child) {
            ChildReservation::factory()->withRate($this->rate->id)->create([
                'reservation_id' => $reservation->id,
                'date_start' => today()->toIso8601String(),
                'date_end' => today()->addDays(2)->toIso8601String(),
                'quantity' => 1,
            ]);
        }

        return $reservation;
    }

    private function createFixedOption(): array
    {
        $option = Option::factory()->create(['item_id' => $this->entry->id()]);
        $value = OptionValue::factory()->fixed()->create(['option_id' => $option->id]);

        return [$option, $value];
    }

    private function checkoutData(Reservation $reservation, string $total, array $overrides = []): array
    {
        return array_merge([
            'date_start' => $reservation->date_start,
            'date_end' => $reservation->date_end,
            'quantity' => $reservation->quantity,
            'rate_id' => $reservation->rate_id,
            'payment' => $reservation->payment,
            'price' => $reservation->price,
            'total' => Price::create($total),
            'extras' => collect(),
            'options' => collect(),
            'customer' => collect(),
        ], $overrides);
    }

    private function extraPayload(int $id, string $price): Collection
    {
        return collect([[
            'id' => $id,
            'quantity' => 1,
            'price' => $price,
            'name' => 'Port taxes',
        ]]);
    }

    private function optionPayload(Option $option, OptionValue $value, string $price): Collection
    {
        return collect([[
            'id' => $option->id,
            'value' => $value->id,
            'price' => $price,
            'optionName' => $option->name,
            'valueName' => $value->name,
        ]]);
    }

    public function test_a_live_extra_still_validates()
    {
        $reservation = $this->createNormalReservation();

        $data = $this->checkoutData($reservation, '550.00', [
            'extras' => $this->extraPayload($this->portTaxes->id, '350.00'),
        ]);

        $this->assertTrue($reservation->validateTotal($data, $this->entry->id()));
    }

    public function test_a_deleted_extra_is_rejected_with_an_extras_error()
    {
        $reservation = $this->createNormalReservation();

        $data = $this->checkoutData($reservation, '550.00', [
            'extras' => $this->extraPayload($this->portTaxes->id, '350.00'),
        ]);

        $this->portTaxes->delete();

        $this->expectException(ExtrasException::class);
        $reservation->validateTotal($data, $this->entry->id());
    }

    public function test_an_unknown_extra_is_rejected_with_an_extras_error()
    {
        $reservation = $this->createNormalReservation();

        $data = $this->checkoutData($reservation, '212.00', [
            'extras' => $this->extraPayload(999, '12.00'),
        ]);

        $this->expectException(ExtrasException::class);
        $reservation->validateTotal($data, $this->entry->id());
    }

    public function test_a_deleted_option_is_rejected_with_an_options_error()
    {
        $reservation = $this->createNormalReservation();
        [$option, $value] = $this->createFixedOption();

        $data = $this->checkoutData($reservation, '230.00', [
            'options' => $this->optionPayload($option, $value, '30.00'),
        ]);

        $this->assertTrue($reservation->validateTotal($data, $this->entry->id()));

        $option->delete();

        $this->expectException(OptionsException::class);
        $reservation->validateTotal($data, $this->entry->id());
    }

    public function test_a_deleted_extra_is_rejected_on_a_parent_reservation()
    {
        $reservation = $this->createParentReservation();

        $data = $this->checkoutData($reservation, '1100.00', [
            'extras' => $this->extraPayload($this->portTaxes->id, '700.00'),
        ]);

        $this->assertTrue($reservation->validateTotal($data, $this->entry->id()));

        $this->portTaxes->delete();

        $this->expectException(ExtrasException::class);
        $reservation->validateTotal($data, $this->entry->id());
    }

    public function test_a_deleted_option_is_rejected_on_a_parent_reservation()
    {
        $reservation = $this->createParentReservation();
        [$option, $value] = $this->createFixedOption();

        $data = $this->checkoutData($reservation, '460.00', [
            'options' => $this->optionPayload($option, $value, '60.00'),
        ]);

        $this->assertTrue($reservation->validateTotal($data, $this->entry->id()));

        $option->delete();

        $this->expectException(OptionsException::class);
        $reservation->validateTotal($data, $this->entry->id());
    }
}
