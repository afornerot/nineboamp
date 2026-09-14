<?php

namespace App\Tests;

use App\Service\CronSchedule;
use PHPUnit\Framework\TestCase;

class CronScheduleTest extends TestCase
{
    public function testHourlyIntervalKeepsFixedSlotWhenScheduledDateIsFuture(): void
    {
        $scheduled = new \DateTime('2026-09-10 06:00:00');
        $now = new \DateTime('2026-09-10 05:30:00');

        $next = CronSchedule::nextDate(3600, $now, $scheduled);

        $this->assertSame('2026-09-10 07:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function testOverdueScheduledDateResetsFromNow(): void
    {
        $scheduled = new \DateTime('2026-09-10 06:00:00');
        $now = new \DateTime('2026-09-10 10:00:00');

        $next = CronSchedule::nextDate(3600, $now, $scheduled);

        $this->assertSame('2026-09-10 11:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function testNonHourIntervalRunsFromNow(): void
    {
        $scheduled = new \DateTime('2026-09-10 06:00:00');
        $now = new \DateTime('2026-09-10 09:07:00');

        $next = CronSchedule::nextDate(90, $now, $scheduled);

        $this->assertSame('2026-09-10 09:08:30', $next->format('Y-m-d H:i:s'));
    }

    public function testZeroIntervalMeansEveryCycle(): void
    {
        $now = new \DateTime('2026-09-10 09:07:00');

        $next = CronSchedule::nextDate(0, $now, new \DateTime('2026-09-10 06:00:00'));

        $this->assertEqualsWithDelta($now->getTimestamp(), $next->getTimestamp(), 1);
    }

    public function testNullScheduledDateAndNegativeIntervalAreHandled(): void
    {
        $now = new \DateTime('2026-09-10 09:07:00');

        $next = CronSchedule::nextDate(-5, $now, null);

        $this->assertSame('2026-09-10 09:07:00', $next->format('Y-m-d H:i:s'));
    }
}
