<?php

namespace Reach\StatamicResrv\Tests\Unit;

use Reach\StatamicResrv\Enums\AddonBlocker;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Support\AddonQuote;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class AddonQuoteTest extends TestCase
{
    use CreatesEntries;

    // Every caller trusts price() to re-price what the entry can book, for every stay, and to leave
    // the rest alone with a reason: the CP syncs the rows by their keys, and the checkout rejects
    // any row with an error.
    public function test_price_reprices_bookable_rows_per_stay_and_leaves_the_rest_with_a_reason()
    {
        $this->travelTo(today()->setHour(12));
        $itemId = $this->createEntries()->first()->id();

        $perday = Extra::factory()->create(); // 4.65 a night
        ResrvEntry::whereItemId($itemId)->extras()->attach($perday->id);

        $option = Option::factory()->create(['item_id' => $itemId]);
        $fixed = OptionValue::factory()->fixed()->create(['option_id' => $option->id]); // 30

        $other = Option::factory()->create(['item_id' => $itemId, 'slug' => 'other-option']);
        $unpublished = OptionValue::factory()->fixed()->create(['option_id' => $other->id, 'published' => false]);

        // Two and three nights.
        $quote = new AddonQuote($itemId, [
            ['date_start' => today()->addDay(), 'date_end' => today()->addDays(3), 'quantity' => 1],
            ['date_start' => today()->addDays(5), 'date_end' => today()->addDays(8), 'quantity' => 1],
        ]);

        $unknown = ['id' => 999, 'quantity' => 1, 'price' => '5.00', 'total' => '5.00', 'name' => 'Gone'];
        $stale = ['id' => $other->id, 'value' => $unpublished->id, 'price' => '1.00'];

        $priced = $quote->price(
            [$perday->id => ['id' => $perday->id, 'quantity' => 2, 'price' => '0.01', 'total' => '0.02', 'name' => 'Towels'], 999 => $unknown],
            [$option->id => ['id' => $option->id, 'value' => $fixed->id, 'price' => '0.01'], $other->id => $stale],
        );

        // 4.65 × 5 nights for one unit, twice that for the line.
        $this->assertEquals(['id' => $perday->id, 'quantity' => 2, 'price' => '23.25', 'total' => '46.50', 'name' => 'Towels'], $priced['extras'][$perday->id]);
        $this->assertSame($unknown, $priced['extras'][999]);
        $this->assertSame('60.00', $priced['options'][$option->id]['price']);
        $this->assertSame($stale, $priced['options'][$other->id]);

        $this->assertSame('46.50', $priced['extras_total']->format());
        $this->assertSame('60.00', $priced['options_total']->format());
        $this->assertSame(
            [AddonBlocker::ExtraUnavailable, AddonBlocker::OptionValueUnavailable],
            array_column($priced['blockers'], 'reason'),
        );
    }
}
