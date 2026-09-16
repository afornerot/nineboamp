<?php

namespace App\Tests\Service;

use App\Entity\BoampReport;
use App\Service\BoampRunResult;
use PHPUnit\Framework\TestCase;

class BoampRunResultTest extends TestCase
{
    public function testGettersExposeCounters(): void
    {
        $report = (new BoampReport())->setMarketsFound(10);
        $result = new BoampRunResult(
            $report,
            35,
            5,
            2,
            1,
            0,
            1,
            [
                ['idweb' => '26-12345', 'title' => 'X', 'score' => 80, 'priority' => 'A', 'buyer' => 'Y', 'deadline' => '2026-12-31'],
            ],
        );

        $this->assertSame($report, $result->getReport());
        $this->assertSame(35, $result->getCandidatesCount());
        $this->assertSame(5, $result->getSkippedExisting());
        $this->assertSame(2, $result->getSkippedNoIdweb());
        $this->assertSame(1, $result->getSkippedNoDetails());
        $this->assertSame(0, $result->getSkippedParseError());
        $this->assertSame(1, $result->getSkippedScoringError());
        $this->assertCount(1, $result->getQualifiedMarkets());
        $this->assertSame('26-12345', $result->getQualifiedMarkets()[0]['idweb']);
    }

    public function testEmptyQualifiedMarketsReturnsEmptyArray(): void
    {
        $result = new BoampRunResult(new BoampReport(), 0, 0, 0, 0, 0, 0, []);
        $this->assertSame([], $result->getQualifiedMarkets());
    }
}
