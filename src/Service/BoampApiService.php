<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class BoampApiService
{
    private const BASE_URL = 'https://boamp-datadila.opendatasoft.com/api/explore/v2.1/catalog/datasets/boamp/records';
    private const MIN_PREFIX_LENGTH = 4;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string[] $keywords
     * @param string[] $departments
     *
     * @return array<array<string, mixed>>
     */
    public function searchMarkets(
        array $keywords,
        int $limit = 50,
        ?string $sortBy = 'dateparution DESC',
        ?string $type = null,
        ?array $departments = null,
        int $offset = 0,
        ?\DateTimeInterface $minDeadline = null,
    ): array {
        $minDate = ($minDeadline ?? new \DateTime())->format('Y-m-d');

        $escape = static function (string $value): string {
            return str_replace(
                ['\\', "'", '"'],
                ['\\\\', "\\'", '\\"'],
                $value,
            );
        };

        $keywordConditions = array_map(
            static function (string $k) use ($escape): string {
                $pattern = self::likePattern($escape(trim($k)));

                return sprintf("(objet LIKE '%s' OR descripteur_libelle LIKE '%s')", $pattern, $pattern);
            },
            $keywords,
        );
        $whereClause = '('.implode(' OR ', $keywordConditions).") AND datelimitereponse >= date'{$minDate}'";

        $params = [
            'where' => $whereClause,
            'limit' => $limit,
        ];

        if ($offset > 0) {
            $params['offset'] = $offset;
        }

        if ($sortBy) {
            $params['order_by'] = $sortBy;
        } else {
            $params['order_by'] = 'datelimitereponse ASC';
        }

        if ($type) {
            $params['refine'] = "type_marche:{$type}";
        }

        if ($departments) {
            $deptConditions = array_map(
                fn (string $d) => "code_departement=\"{$d}\"",
                $departments
            );
            $whereClause .= ' AND ('.implode(' OR ', $deptConditions).')';
            $params['where'] = $whereClause;
        }

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL, [
                'query' => $params,
            ]);

            $data = $response->toArray();
            $results = $data['results'] ?? [];

            if (0 === count($results) && 0 === $offset) {
                $this->logger->warning('BoampApiService: 0 résultat retourné par l\'API', [
                    'params' => $params,
                ]);
            }

            return $results;
        } catch (\Throwable $e) {
            $this->logger->error('BoampApiService: erreur recherche', [
                'error' => $e->getMessage(),
                'params' => $params,
            ]);

            return [];
        }
    }

    /**
     * En ODSQL, LIKE compare des mots entiers, sans tenir compte de la casse ni
     * des accents. « % » n'y est pas un joker : seul « * » en fin de motif
     * élargit la recherche (« documentaire* » trouve aussi « documentaires »).
     * Les mots courts restent exacts, sinon « SIG* » ramènerait « signature ».
     */
    private static function likePattern(string $keyword): string
    {
        $words = preg_split('/\s+/u', $keyword) ?: [$keyword];

        return mb_strlen((string) end($words)) >= self::MIN_PREFIX_LENGTH ? $keyword.'*' : $keyword;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMarketDetails(string $idweb): ?array
    {
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL, [
                'query' => [
                    'where' => sprintf('idweb=\'%s\'', str_replace("'", "\\'", $idweb)),
                ],
            ]);

            $results = $response->toArray()['results'] ?? [];

            return $results[0] ?? null;
        } catch (\Throwable $e) {
            $this->logger->error('BoampApiService: erreur details', [
                'error' => $e->getMessage(),
                'idweb' => $idweb,
            ]);

            return null;
        }
    }
}
