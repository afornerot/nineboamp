<?php

namespace App\Service;

use App\Entity\BoampReport;

class BoampRunResult
{
    /**
     * @param array<array{idweb: string, title: string, score: int, priority: string, buyer: string, deadline: ?string}> $qualifiedMarkets
     */
    public function __construct(
        private BoampReport $report,
        private int $candidatesCount,
        private int $skippedExisting,
        private int $skippedNoIdweb,
        private int $skippedNoDetails,
        private int $skippedParseError,
        private int $skippedScoringError,
        private array $qualifiedMarkets,
    ) {
    }

    public function getReport(): BoampReport
    {
        return $this->report;
    }

    public function getCandidatesCount(): int
    {
        return $this->candidatesCount;
    }

    public function getSkippedExisting(): int
    {
        return $this->skippedExisting;
    }

    public function getSkippedNoIdweb(): int
    {
        return $this->skippedNoIdweb;
    }

    public function getSkippedNoDetails(): int
    {
        return $this->skippedNoDetails;
    }

    public function getSkippedParseError(): int
    {
        return $this->skippedParseError;
    }

    public function getSkippedScoringError(): int
    {
        return $this->skippedScoringError;
    }

    /**
     * @return array<array{idweb: string, title: string, score: int, priority: string, buyer: string, deadline: ?string}>
     */
    public function getQualifiedMarkets(): array
    {
        return $this->qualifiedMarkets;
    }
}
