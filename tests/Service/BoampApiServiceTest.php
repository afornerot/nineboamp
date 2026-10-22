<?php

namespace App\Tests\Service;

use App\Service\BoampApiService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class BoampApiServiceTest extends TestCase
{
    public function testKeywordsUseTrailingWildcardInsteadOfPercent(): void
    {
        $query = $this->searchAndCaptureQuery(['documentaire', 'IA', 'intelligence artificielle', "aide à l'enseignement"]);

        $this->assertStringContainsString("objet LIKE 'documentaire*'", $query['where']);
        $this->assertStringContainsString("descripteur_libelle LIKE 'documentaire*'", $query['where']);
        $this->assertStringContainsString("objet LIKE 'intelligence artificielle*'", $query['where']);
        $this->assertStringContainsString("objet LIKE 'aide à l\\'enseignement*'", $query['where']);
        $this->assertStringNotContainsString('%', $query['where']);
    }

    public function testShortKeywordsStayExact(): void
    {
        $query = $this->searchAndCaptureQuery(['IA', 'SIG']);

        $this->assertStringContainsString("(objet LIKE 'IA' OR descripteur_libelle LIKE 'IA')", $query['where']);
        $this->assertStringContainsString("(objet LIKE 'SIG' OR descripteur_libelle LIKE 'SIG')", $query['where']);
    }

    public function testOffsetAndMinDeadlineAreSent(): void
    {
        $query = $this->searchAndCaptureQuery(['logiciel'], offset: 200, minDeadline: new \DateTimeImmutable('2026-11-21'));

        $this->assertSame('200', $query['offset']);
        $this->assertStringContainsString("datelimitereponse >= date'2026-11-21'", $query['where']);
    }

    public function testDefaultsToTodayWithoutOffset(): void
    {
        $query = $this->searchAndCaptureQuery(['logiciel']);

        $this->assertArrayNotHasKey('offset', $query);
        $this->assertStringContainsString(sprintf("datelimitereponse >= date'%s'", date('Y-m-d')), $query['where']);
    }

    /**
     * @param string[] $keywords
     *
     * @return array<int|string, string>
     */
    private function searchAndCaptureQuery(array $keywords, int $offset = 0, ?\DateTimeInterface $minDeadline = null): array
    {
        $url = null;
        $client = new MockHttpClient(function (string $method, string $requestUrl) use (&$url): MockResponse {
            $url = $requestUrl;

            return new MockResponse('{"results":[]}');
        });

        $service = new BoampApiService($client, $this->createMock(LoggerInterface::class));
        $service->searchMarkets($keywords, 100, 'dateparution DESC', offset: $offset, minDeadline: $minDeadline);

        parse_str((string) parse_url((string) $url, \PHP_URL_QUERY), $query);

        return array_filter($query, 'is_string');
    }
}
