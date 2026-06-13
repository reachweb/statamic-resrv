<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_a_reference_that_is_not_already_in_use()
    {
        $item = $this->makeStatamicItem();
        Reservation::factory(['item_id' => $item->id(), 'reference' => 'TAKEN1'])->create();

        Str::createRandomStringsUsing(function () {
            static $calls = 0;

            return $calls++ === 0 ? 'taken1' : 'fresh2';
        });

        try {
            $reference = (new Reservation)->createRandomReference();
        } finally {
            Str::createRandomStringsNormally();
        }

        $this->assertSame('FRESH2', $reference);
    }

    public function test_reference_generation_fails_loudly_instead_of_spinning_when_the_keyspace_is_exhausted()
    {
        $item = $this->makeStatamicItem();
        Reservation::factory(['item_id' => $item->id(), 'reference' => 'DUPREF'])->create();

        // Force every candidate to collide so the retry loop hits its cap and throws instead of spinning forever.
        Str::createRandomStringsUsing(fn () => 'dupref');

        try {
            $this->expectException(\RuntimeException::class);

            (new Reservation)->createRandomReference();
        } finally {
            Str::createRandomStringsNormally();
        }
    }
}
