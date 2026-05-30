<?php

namespace Reach\StatamicResrv\Tests\Unit;

use Reach\StatamicResrv\Models\ExtraCondition;
use Reach\StatamicResrv\Tests\TestCase;

class ExtraConditionTimeTest extends TestCase
{
    /**
     * Invoke the protected ExtraCondition::checkTime() with a time window and a payload datetime.
     */
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
        // 20:00 is outside the 09:00-17:00 window. The previous implementation wrongly
        // returned true because it added a day to the end, stretching the span to ~32h.
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
        // 07:00 falls inside the overnight 21:00-08:00 window. The previous implementation
        // wrongly returned false because it pinned the payload to "today", below the start.
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
        // The same time of day on different calendar dates yields the same result.
        $this->assertTrue($this->checkTime('09:00', '17:00', '2020-01-01 10:00:00'));
        $this->assertTrue($this->checkTime('09:00', '17:00', '2099-12-31 10:00:00'));
        $this->assertFalse($this->checkTime('09:00', '17:00', '2099-12-31 18:00:00'));
    }
}
