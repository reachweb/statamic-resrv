<?php

namespace Reach\StatamicResrv\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Tags\Resrv;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Antlers;

class ResrvTagTest extends TestCase
{
    use CreatesEntries;

    protected $entry;

    protected $resrvEntry;

    protected $tag;

    protected function setUp(): void
    {
        parent::setUp();

        // Create an entry and associated resrv entry
        $this->entry = $this->makeStatamicItemWithAvailability();
        $this->resrvEntry = ResrvEntry::whereItemId($this->entry->id());

        // Properly initialize the tag with required Statamic objects
        $this->tag = (new Resrv)
            ->setParser(Antlers::parser())
            ->setContext([]);
    }

    public function test_cutoff_throws_exception_when_no_entry_id()
    {
        $this->tag->setParameters([]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Resrv Tag error: No entry ID provided or could be found in context.');

        $this->tag->cutoff();
    }

    public function test_cutoff_throws_exception_when_entry_not_found()
    {
        $this->tag->setParameters(['entry' => 'non-existent-entry']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Resrv Tag error: Entry not found for ID: non-existent-entry');

        $this->tag->cutoff();
    }

    public function test_cutoff_returns_no_rules_when_cutoff_disabled()
    {
        $this->tag->setParameters(['entry' => $this->entry->id()]);

        $result = $this->tag->cutoff();

        $expected = [
            'has_cutoff_rules' => false,
            'starting_time' => null,
            'cutoff_time' => null,
            'cutoff_hours' => null,
            'schedule_name' => null,
        ];

        $this->assertEquals($expected, $result);
    }

    public function test_cutoff_returns_default_schedule_when_enabled()
    {
        // Enable cutoff rules globally
        Config::set('resrv-config.enable_cutoff_rules', true);

        // Set up cutoff rules for the entry
        $this->resrvEntry->options = [
            'cutoff_rules' => [
                'enable_cutoff' => true,
                'default_starting_time' => '16:00',
                'default_cutoff_hours' => 3,
            ],
        ];
        $this->resrvEntry->save();

        $this->tag->setParameters(['entry' => $this->entry->id()]);

        // Mock current time to be 10am
        $this->travelTo(now()->setTime(10, 0, 0));

        $result = $this->tag->cutoff();

        $this->assertTrue($result['has_cutoff_rules']);
        $this->assertEquals('16:00', $result['starting_time']);
        $this->assertEquals('13:00', $result['cutoff_time']); // 16:00 - 3 hours
        $this->assertEquals(3, $result['cutoff_hours']);
        $this->assertEquals('Default Schedule', $result['schedule_name']);
        $this->assertFalse($result['is_past_cutoff']); // 10am is before 1pm cutoff
    }

    public function test_cutoff_detects_past_cutoff_time()
    {
        // Enable cutoff rules globally
        Config::set('resrv-config.enable_cutoff_rules', true);

        // Set up cutoff rules for the entry
        $this->resrvEntry->options = [
            'cutoff_rules' => [
                'enable_cutoff' => true,
                'default_starting_time' => '16:00',
                'default_cutoff_hours' => 3,
            ],
        ];
        $this->resrvEntry->save();

        $this->tag->setParameters(['entry' => $this->entry->id()]);

        // Mock current time to be 2pm (after 1pm cutoff)
        $this->travelTo(now()->setTime(14, 0, 0));

        $result = $this->tag->cutoff();

        $this->assertTrue($result['has_cutoff_rules']);
        $this->assertEquals('16:00', $result['starting_time']);
        $this->assertEquals('13:00', $result['cutoff_time']);
        $this->assertTrue($result['is_past_cutoff']); // 2pm is after 1pm cutoff
    }

    public function test_cutoff_uses_specific_schedule_when_date_matches()
    {
        // Enable cutoff rules globally
        Config::set('resrv-config.enable_cutoff_rules', true);

        $today = now()->format('Y-m-d');
        $tomorrow = now()->addDay()->format('Y-m-d');

        // Set up cutoff rules with a specific schedule for today
        $this->resrvEntry->options = [
            'cutoff_rules' => [
                'enable_cutoff' => true,
                'default_starting_time' => '16:00',
                'default_cutoff_hours' => 3,
                'schedules' => [
                    [
                        'date_start' => $today,
                        'date_end' => $today,
                        'starting_time' => '10:00',
                        'cutoff_hours' => 6,
                        'name' => 'Morning Schedule',
                    ],
                ],
            ],
        ];
        $this->resrvEntry->save();

        // Test with today's date (should use specific schedule)
        $this->tag->setParameters(['entry' => $this->entry->id(), 'date' => $today]);

        $result = $this->tag->cutoff();

        $this->assertTrue($result['has_cutoff_rules']);
        $this->assertEquals('10:00', $result['starting_time']);
        $this->assertEquals('04:00', $result['cutoff_time']); // 10:00 - 6 hours
        $this->assertEquals(6, $result['cutoff_hours']);
        $this->assertEquals('Morning Schedule', $result['schedule_name']);

        // Test with tomorrow's date (should use default schedule)
        $this->tag->setParameters(['entry' => $this->entry->id(), 'date' => $tomorrow]);

        $result = $this->tag->cutoff();

        $this->assertTrue($result['has_cutoff_rules']);
        $this->assertEquals('16:00', $result['starting_time']);
        $this->assertEquals('13:00', $result['cutoff_time']); // 16:00 - 3 hours
        $this->assertEquals(3, $result['cutoff_hours']);
        $this->assertEquals('Default Schedule', $result['schedule_name']);
    }

    public function test_cutoff_uses_entry_from_context_when_no_parameter()
    {
        // Enable cutoff rules globally
        Config::set('resrv-config.enable_cutoff_rules', true);

        // Set up cutoff rules for the entry
        $this->resrvEntry->options = [
            'cutoff_rules' => [
                'enable_cutoff' => true,
                'default_starting_time' => '14:00',
                'default_cutoff_hours' => 2,
            ],
        ];
        $this->resrvEntry->save();

        // Set context with entry ID
        $this->tag->setContext(['id' => $this->entry->id()]);
        $this->tag->setParameters([]);

        $result = $this->tag->cutoff();

        $this->assertTrue($result['has_cutoff_rules']);
        $this->assertEquals('14:00', $result['starting_time']);
        $this->assertEquals('12:00', $result['cutoff_time']); // 14:00 - 2 hours
    }

    public function test_cutoff_includes_cutoff_datetime_in_iso_format()
    {
        // Enable cutoff rules globally
        Config::set('resrv-config.enable_cutoff_rules', true);

        // Set up cutoff rules for the entry
        $this->resrvEntry->options = [
            'cutoff_rules' => [
                'enable_cutoff' => true,
                'default_starting_time' => '16:00',
                'default_cutoff_hours' => 3,
            ],
        ];
        $this->resrvEntry->save();

        $today = now()->format('Y-m-d');
        $this->tag->setParameters(['entry' => $this->entry->id(), 'date' => $today]);

        $result = $this->tag->cutoff();

        $this->assertArrayHasKey('cutoff_datetime', $result);

        // Parse the ISO datetime and verify it's correct
        $cutoffDateTime = Carbon::parse($result['cutoff_datetime']);
        $expectedDateTime = Carbon::parse($today.' 16:00')->subHours(3);

        $this->assertTrue($cutoffDateTime->equalTo($expectedDateTime));
    }

    public function test_cutoff_uses_today_as_default_date()
    {
        // Enable cutoff rules globally
        Config::set('resrv-config.enable_cutoff_rules', true);

        $this->resrvEntry->options = [
            'cutoff_rules' => [
                'enable_cutoff' => true,
                'default_starting_time' => '16:00',
                'default_cutoff_hours' => 3,
            ],
        ];
        $this->resrvEntry->save();

        // Don't provide a date parameter
        $this->tag->setParameters(['entry' => $this->entry->id()]);

        $result = $this->tag->cutoff();

        $this->assertTrue($result['has_cutoff_rules']);

        // Verify the cutoff datetime is for today
        $cutoffDateTime = Carbon::parse($result['cutoff_datetime']);
        $expectedDateTime = Carbon::parse(now()->format('Y-m-d').' 16:00')->subHours(3);

        $this->assertTrue($cutoffDateTime->equalTo($expectedDateTime));
    }

    public function test_cutoff_throws_exception_with_empty_context_and_no_parameter()
    {
        // Make sure both context and parameters are empty
        $this->tag->setContext([]);
        $this->tag->setParameters([]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Resrv Tag error: No entry ID provided or could be found in context.');

        $this->tag->cutoff();
    }

    public function test_cutoff_throws_exception_with_null_entry_id_in_context()
    {
        // Set context with null id
        $this->tag->setContext(['id' => null]);
        $this->tag->setParameters([]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Resrv Tag error: No entry ID provided or could be found in context.');

        $this->tag->cutoff();
    }

    public function test_cutoff_throws_exception_with_empty_string_entry_id()
    {
        $this->tag->setParameters(['entry' => '']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Resrv Tag error: No entry ID provided or could be found in context.');

        $this->tag->cutoff();
    }
}
