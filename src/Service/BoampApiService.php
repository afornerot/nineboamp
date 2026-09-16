<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class BoampApiService
{
    private const BASE_URL = 'https://boamp-datadila.opendatasoft.com/api/explore/v2.1/catalog/datasets/boamp/records';

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
    ): array {
        $currentDate = (new \DateTime())->format('Y-m-d');

        $escape = static function (string $value): string {
            return str_replace(
                ['\\', "'", '"'],
                ['\\\\', "\\'", '\\"'],
                $value,
            );
        };

        $keywordConditions = array_map(
            static fn (string $k): string => sprintf(
                "(objet LIKE '%%%s%%' OR descripteur_libelle LIKE '%%%s%%')",
                $escape($k),
                $escape($k),
            ),
            $keywords,
        );
        $whereClause = '('.implode(' OR ', $keywordConditions).") AND datelimitereponse >= date'{$currentDate}'";

        $params = [
            'where' => $whereClause,
            'limit' => $limit,
        ];

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

            if (0 === count($results)) {
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
