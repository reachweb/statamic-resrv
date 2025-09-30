<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Antlers;
use Statamic\Tags\Collection\Collection;

class AvailabilityHookTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public $collectionTag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();

        $this->collectionTag = (new Collection)
            ->setParser(Antlers::parser())
            ->setContext([]);
    }

    public function test_that_it_passes_availability_data_to_the_entries()
    {
        $this->setTagParameters(['collection' => 'pages', 'query_scope' => 'resrv_search', 'resrv_search:resrv_availability' => [
            'dates' => [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ],
        ]]);

        $returnedEntries = $this->collectionTag->index();

        $this->assertCount(3, $returnedEntries);

        $this->assertArrayHasKey('live_availability', $returnedEntries->first()->toArray());
    }

    private function setTagParameters($parameters)
    {
        $this->collectionTag->setParameters($parameters);
    }
}
