<?php

namespace Reach\StatamicResrv\Tests\Unit;

use Reach\StatamicResrv\Models\ExtraCondition;
use Reach\StatamicResrv\Tests\TestCase;

class ExtraConditionTimeTest extends TestCase
{
    /** Invoke the protected ExtraCondition::checkTime() via reflection. */
    private function checkTime(string $timeStart, string $timeEnd, string $payload): bool
    {
        $condition = (object) ['time_start' => $timeStart, 'time_end' => $timeEnd];

        $method = new \ReflectionMethod(ExtraCondition::class, 'checkTime');
        $method->setAccessible(true);

        return $method->invoke(new ExtraCondition, $condition, $payload);
    }

    public function test_same_day_window_matches_a_time_inside_the_range()
    {
        $this->assertTrue($this->checkTime('09:00', '17:00', '2026-06-15 12:00:00'));
    }

    public function test_same_day_window_does_not_match_a_time_after_the_range()
    {
        // Previous bug: adding a day to the end stretched the span to ~32 h, so 20:00 matched.
        $this->assertFalse($this->checkTime('09:00', '17:00', '2026-06-15 20:00:00'));
    }

    public function test_same_day_window_does_not_match_a_time_before_the_range()
    {
        $this->assertFalse($this->checkTime('09:00', '17:00', '2026-06-15 08:00:00'));
    }

    public function test_same_day_window_is_inclusive_of_its_boundaries()
    {
        $this->assertTrue($this->checkTime('09:00', '17:00', '2026-06-15 09:00:00'));
        $this->assertTrue($this->checkTime('09:00', '17:00', '2026-06-15 17:00:00'));
    }

    public function test_overnight_window_matches_a_late_night_time()
    {
        $this->assertTrue($this->checkTime('21:00', '08:00', '2026-06-15 23:00:00'));
    }

    public function test_overnight_window_matches_an_early_morning_time()
    {
        // Previous bug: pinning the payload to "today" placed 07:00 below the 21:00 start.
        $this->assertTrue($this->checkTime('21:00', '08:00', '2026-06-15 07:00:00'));
    }

    public function test_overnight_window_does_not_match_a_midday_time()
    {
        $this->assertFalse($this->checkTime('21:00', '08:00', '2026-06-15 12:00:00'));
    }

    public function test_overnight_window_is_inclusive_of_its_boundaries()
    {
        $this->assertTrue($this->checkTime('21:00', '08:00', '2026-06-15 21:00:00'));
        $this->assertTrue($this->checkTime('21:00', '08:00', '2026-06-15 08:00:00'));
    }

    public function test_overnight_window_excludes_a_time_just_after_the_end()
    {
        $this->assertFalse($this->checkTime('21:00', '08:00', '2026-06-15 09:00:00'));
    }

    public function test_time_window_is_evaluated_by_time_of_day_regardless_of_the_date()
    {
        // Same time-of-day on different calendar dates must yield the same result.
        $this->assertTrue($this->checkTime('09:00', '17:00', '2020-01-01 10:00:00'));
        $this->assertTrue($this->checkTime('09:00', '17:00', '2099-12-31 10:00:00'));
        $this->assertFalse($this->checkTime('09:00', '17:00', '2099-12-31 18:00:00'));
    }
}
