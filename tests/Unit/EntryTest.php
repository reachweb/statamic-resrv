<?php

namespace Reach\StatamicResrv\Tests\Unit;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class EntryTest extends TestCase
{
    use CreatesEntries;

    public function test_where_item_id_returns_the_mirror_row_for_an_existing_entry()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $resrvEntry = ResrvEntry::whereItemId($entry->id());

        $this->assertInstanceOf(ResrvEntry::class, $resrvEntry);
        $this->assertEquals($entry->id(), $resrvEntry->item_id);
    }

    // whereItemId() is declared non-nullable: it resolves the mirror row or throws. Every callsite
    // calls methods on the result directly (or wraps it in try/catch), so this contract must not
    // silently regress to a nullable ->first().
    public function test_where_item_id_throws_when_the_mirror_row_is_missing()
    {
        $this->expectException(ModelNotFoundException::class);

        ResrvEntry::whereItemId('non-existent-id');
    }
}
