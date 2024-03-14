<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Reach\StatamicResrv\Scopes\ResrvSearch;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Entry;

class AvailabilityScopeTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
    }

    /** @test */
    public function it_can_filter_entries()
    {
        $query = Entry::query()->where('collection', 'pages');

        $beforeScope = $query->get()->pluck('id')->all();

        $this->assertCount(5, $beforeScope);
        $this->assertContains($this->entries->first()->id(), $beforeScope);
        $this->assertContains($this->entries->get('none-available')->id(), $beforeScope);
        $this->assertContains($this->entries->get('two-available')->id(), $beforeScope);
        $this->assertContains($this->entries->get('stop-sales')->id(), $beforeScope);

        $values = ['resrv_search:resrv_availability' => [
            'dates' => [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ],
        ]];

        (new ResrvSearch)->apply($query, $values);

        $afterScope = $query->get()->pluck('id')->all();

        $this->assertCount(3, $afterScope);
        $this->assertContains($this->entries->first()->id(), $afterScope);
        $this->assertNotContains($this->entries->get('none-available')->id(), $afterScope);
        $this->assertContains($this->entries->get('two-available')->id(), $afterScope);
        $this->assertNotContains($this->entries->get('stop-sales')->id(), $afterScope);
        $this->assertContains($this->entries->get('half-price')->id(), $afterScope);
    }

    /** @test */
    public function it_returns_nothing_for_7_days()
    {
        $query = Entry::query()->where('collection', 'pages');

        $values = ['resrv_search:resrv_availability' => [
            'dates' => [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(7, 'day'),
            ],
        ]];

        (new ResrvSearch)->apply($query, $values);

        $afterScope = $query->get()->pluck('id')->all();

        $this->assertCount(0, $afterScope);
        // Another one because it was showing up as risky
        $this->assertEmpty($afterScope);
    }

    /** @test */
    public function it_returns_the_correct_one_for_quantity_2()
    {
        $query = Entry::query()->where('collection', 'pages');

        $values = ['resrv_search:resrv_availability' => [
            'dates' => [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ],
            'quantity' => 2,
        ]];

        (new ResrvSearch)->apply($query, $values);

        $afterScope = $query->get()->pluck('id')->all();

        $this->assertCount(1, $afterScope);
        $this->assertContains($this->entries->get('two-available')->id(), $afterScope);
    }
}
