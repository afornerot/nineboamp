<?php

namespace App\Controller;

use App\Entity\Market;
use App\Entity\MarketChatMessage;
use App\Repository\MarketChatMessageRepository;
use App\Repository\MarketRepository;
use App\Service\AgentService;
use App\Service\AiService;
use App\Service\AmoxtliService;
use App\Service\PromptLoader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;

class MarketChatController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private MarketChatMessageRepository $chatRepo,
        private MarketRepository $marketRepo,
        private AmoxtliService $amoxtliService,
        private AiService $aiService,
        private PromptLoader $promptLoader,
        private ?AgentService $agentService = null,
        private ?\Psr\Log\LoggerInterface $logger = null,
    ) {
    }

    #[Route('/user/market/{id}/chat', name: 'app_market_chat', methods: ['POST'])]
    public function chat(int $id, Request $request): JsonResponse
    {
        $market = $this->marketRepo->find($id);
        if (!$market) {
            return new JsonResponse(['error' => 'Marché introuvable'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $userMessage = trim($data['message'] ?? '');

        if ('' === $userMessage) {
            return new JsonResponse(['error' => 'Message vide'], 400);
        }

        $chatMessage = new MarketChatMessage();
        $chatMessage->setMarket($market);
        $chatMessage->setRole('user');
        $chatMessage->setContent($userMessage);
        $this->em->persist($chatMessage);
        $this->em->flush();

        $allSources = [];
        $debugData = [];
        $jobId = null;

        if ($this->agentService !== null && $this->agentService->isHealthy()) {
            $history = $this->buildHistoryForAgent($market->getId());
            $marketInfo = $this->formatMarketInfoForAgent($market);
            $fullMessage = $marketInfo . "\n\n## Question de l'utilisateur\n" . $userMessage;
            
            $jobId = $this->agentService->startChat($market->getId(), $fullMessage, $history);
            
            if (null !== $jobId) {
                $debugData = [
                    'agent' => true,
                    'job_id' => $jobId,
                ];
            }
        }

        if (null === $jobId) {
            $reply = $this->chatWithAiService($market, $userMessage, $debugData);
            if ('' === $reply) {
                $reply = "Désolé, je n'ai pas pu traiter votre question. Veuillez réessayer.";
            }

            $assistantMessage = new MarketChatMessage();
            $assistantMessage->setMarket($market);
            $assistantMessage->setRole('assistant');
            $assistantMessage->setContent($reply);
            $assistantMessage->setSources($allSources);
            $assistantMessage->setDebugData($debugData);
            $this->em->persist($assistantMessage);
            $this->em->flush();

            return new JsonResponse([
                'userMessageId' => $chatMessage->getId(),
                'assistantMessageId' => $assistantMessage->getId(),
                'reply' => $reply,
                'sources' => $allSources,
                'createdAt' => $assistantMessage->getCreatedAt()?->format('c'),
            ]);
        }

        return new JsonResponse([
            'userMessageId' => $chatMessage->getId(),
            'assistantMessageId' => null,
            'reply' => null,
            'pending' => true,
            'jobId' => $jobId,
        ]);
    }

    #[Route('/user/market/{id}/chat/poll/{jobId}', name: 'app_market_chat_poll', methods: ['GET'])]
    public function pollChat(int $id, string $jobId): JsonResponse
    {
        if (null === $this->agentService) {
            return new JsonResponse(['status' => 'error', 'error' => 'Agent non disponible'], 500);
        }

        $result = $this->agentService->getResult($jobId);

        if ('not_found' === $result['status']) {
            return new JsonResponse(['status' => 'not_found', 'error' => 'Job non trouvé']);
        }

        if ('pending' === $result['status']) {
            return new JsonResponse(['status' => 'pending']);
        }

        if ('done' === $result['status']) {
            $market = $this->marketRepo->find($id);
            if (!$market) {
                return new JsonResponse(['status' => 'error', 'error' => 'Marché introuvable'], 404);
            }

            $assistantMessage = new MarketChatMessage();
            $assistantMessage->setMarket($market);
            $assistantMessage->setRole('assistant');
            $assistantMessage->setContent($result['result']['answer'] ?? '');
            $assistantMessage->setSources($result['result']['sources'] ?? []);
            $assistantMessage->setDebugData(['agent' => true, 'tools_used' => $result['result']['tools_used'] ?? []]);
            $this->em->persist($assistantMessage);
            $this->em->flush();

            return new JsonResponse([
                'status' => 'done',
                'assistantMessageId' => $assistantMessage->getId(),
                'result' => $result['result'],
            ]);
        }

        return new JsonResponse(['status' => 'error', 'error' => 'Statut inconnu'], 500);
    }

    #[Route('/user/market/{id}/chat/save', name: 'app_market_chat_save', methods: ['POST'])]
    public function saveChatResult(int $id, Request $request): JsonResponse
    {
        $market = $this->marketRepo->find($id);
        if (!$market) {
            return new JsonResponse(['error' => 'Marché introuvable'], 404);
        }

        $data = json_decode($request->getContent(), true);

        $assistantMessage = new MarketChatMessage();
        $assistantMessage->setMarket($market);
        $assistantMessage->setRole('assistant');
        $assistantMessage->setContent($data['reply'] ?? '');
        $assistantMessage->setSources($data['sources'] ?? []);
        $assistantMessage->setDebugData([
            'agent' => true,
            'tools_used' => $data['toolsUsed'] ?? [],
        ]);
        $this->em->persist($assistantMessage);
        $this->em->flush();

        return new JsonResponse([
            'assistantMessageId' => $assistantMessage->getId(),
        ]);
    }

    /**
     * Chat using the traditional AiService (fallback).
     */
    private function chatWithAiService(Market $market, string $userMessage, array $debugData): string
    {
        $plannedResult = $this->planQueries($userMessage);
        $plannedQueries = $plannedResult['queries'];
        
        if (!isset($debugData['agent'])) {
            $debugData['phase1'] = [
                'question' => $userMessage,
                'plannedQueries' => $plannedQueries,
                'llmRawResponse' => $plannedResult['rawResponse'],
            ];
        }

        $context = [];
        if (!empty($plannedQueries)) {
            $ragResults = $this->searchWithQueries($market->getId(), $plannedQueries);
            $context = $ragResults['sections'];
            $allSources = $ragResults['sources'];
            if (!isset($debugData['agent'])) {
                $debugData['phase2'] = [
                    'queriesExecuted' => $plannedQueries,
                    'contextCount' => count($context),
                    'sources' => $allSources,
                ];
            }
        }

        $history = $this->buildHistory($market->getId());
        $messages = $this->buildAnswerMessages($market, $history, $context, $userMessage);
        
        if (!isset($debugData['agent'])) {
            $debugData['phase3'] = [
                'systemPrompt' => $messages[0]['content'] ?? '',
            ];
        }

        $reply = $this->aiService->askMessages($messages, 0.3, 2048);

        return $reply;
    }

    /**
     * Build history for Agent (simplified format).
     */
    private function buildHistoryForAgent(int $marketId): array
    {
        $messages = $this->chatRepo->findRecentForMarket($marketId, 10);

        return array_map(fn (MarketChatMessage $m) => [
            'role' => $m->getRole(),
            'content' => $m->getContent(),
        ], $messages);
    }

    private function formatMarketInfoForAgent(Market $market): string
    {
        $products = $market->getMarketProducts()->toArray();
        usort($products, fn ($a, $b) => ($b->getScore() ?? 0) <=> ($a->getScore() ?? 0));
        $topProducts = array_slice($products, 0, 3);

        $productsStr = '';
        if (!empty($topProducts)) {
            $lines = [];
            foreach ($topProducts as $i => $mp) {
                $product = $mp->getProduct();
                $line = ($i + 1) . '. ' . $product->getName() . ' (ID: ' . $product->getId() . ') — Score: ' . ($mp->getScore() ?? 'N/A') . '/100';
                if ($mp->getRelevance()) {
                    $line .= "\n   Pertinence: " . mb_substr($mp->getRelevance(), 0, 200);
                }
                $lines[] = $line;
            }
            $productsStr = implode("\n", $lines);
        } else {
            $productsStr = 'Aucun produit associé.';
        }

        return '## Informations sur le marché #' . $market->getId() . "\n"
            . "- Titre: " . $market->getTitle() . "\n"
            . "- Acheteur: " . ($market->getBuyer() ?: 'N/A') . "\n"
            . "- Montant: " . ($market->getAmount() ?: 'N/A') . "\n"
            . "- Deadline: " . ($market->getDeadline()?->format('d/m/Y') ?: 'N/A') . "\n"
            . "- Score: " . ($market->getScore() ?: 'N/A') . "/100\n"
            . "- URL: " . ($market->getUrl() ?: 'N/A') . "\n"
            . "\n## Produits associés\n"
            . $productsStr . "\n"
            . "\n## Description\n"
            . mb_substr($market->getDescription() ?: 'Non disponible', 0, 1000);
    }

    #[Route('/user/market/{id}/chat/{messageId}', name: 'app_market_chat_delete', methods: ['DELETE'])]
    public function deleteMessage(int $id, int $messageId, \App\Repository\MarketChatMessageRepository $chatMessageRepo): JsonResponse
    {
        $market = $this->marketRepo->find($id);
        if (!$market) {
            return new JsonResponse(['error' => 'Marché introuvable'], 404);
        }

        $message = $chatMessageRepo->find($messageId);
        if (!$message || $message->getMarket()?->getId() !== $id) {
            return new JsonResponse(['error' => 'Message introuvable'], 404);
        }

        $this->em->remove($message);
        $this->em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/user/market/{id}/chat/history', name: 'app_market_chat_history', methods: ['GET'])]
    public function history(int $id): JsonResponse
    {
        $market = $this->marketRepo->find($id);
        if (!$market) {
            return new JsonResponse(['error' => 'Marché introuvable'], 404);
        }

        $messages = $this->chatRepo->findRecentForMarket($id, 50);

        $result = array_map(function (MarketChatMessage $m) {
            return [
                'id' => $m->getId(),
                'role' => $m->getRole(),
                'content' => $m->getContent(),
                'sources' => $m->getSources(),
                'createdAt' => $m->getCreatedAt()?->format('c'),
            ];
        }, $messages);

        return new JsonResponse(['messages' => $result]);
    }

    private function searchContext(int $marketId, string $query, int $limit = 3): array
    {
        $workspacePath = $this->amoxtliService->getWorkspacePath((string) $marketId);

        if (!is_dir($workspacePath . '/.amoxtli')) {
            return ['sections' => [], 'sources' => []];
        }

        $process = new Process([
            $this->amoxtliService->getBinaryPath(),
            '-C', $workspacePath,
            'search',
            $query,
            '--json',
            '-n', (string) $limit,
        ]);

        $process->setTimeout(30);

        try {
            $process->run();
        } catch (ProcessFailedException) {
            return ['sections' => [], 'sources' => []];
        }

        if (!$process->isSuccessful()) {
            return ['sections' => [], 'sources' => []];
        }

        $data = json_decode($process->getOutput(), true);

        if (!is_array($data) || empty($data['results'])) {
            return ['sections' => [], 'sources' => []];
        }

        $sections = [];
        $sources = [];
        foreach ($data['results'] as $result) {
            if (preg_match('/file:\/\/\/([^\s\n]+)/', $result['source'] ?? '', $m)) {
                $source = basename($m[1]);
                if (!in_array($source, $sources, true)) {
                    $sources[] = $source;
                }
            }
            foreach ($result['sections'] ?? [] as $section) {
                $sections[] = $section['content'] ?? '';
            }
        }

        return ['sections' => $sections, 'sources' => $sources];
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @param array<int, string> $context
     */
    private function buildMessages(Market $market, array $history, array $context, string $currentQuery): array
    {
        $template = $this->promptLoader->renderSystem('chat.context');

        $metadata = "- Titre: ".$market->getTitle()."\n"
            ."- Acheteur: ".($market->getBuyer() ?: 'N/A')."\n"
            ."- Montant: ".($market->getAmount() ?: 'N/A')."\n"
            ."- Deadline: ".($market->getDeadline()?->format('d/m/Y') ?: 'N/A')."\n"
            ."- Score: ".($market->getScore() ?: 'N/A')."/100 (Priorité: ".($market->getPriority() ?: 'N/A').")\n"
            ."- Statut: ".$market->getStatuslabel()."\n"
            ."- URL: ".($market->getUrl() ?: 'N/A');

        $description = mb_substr($market->getDescription() ?: 'Non disponible', 0, 800);

        $products = $this->formatTopProducts($market);

        $documents = !empty($context)
            ? $this->formatDocuments($context)
            : 'Aucun document indexé.';

        $systemPrompt = str_replace(
            ['{{metadata}}', '{{description}}', '{{products}}', '{{documents}}'],
            [$metadata, $description, $products, $documents],
            $template
        );

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        $recentHistory = array_slice($history, -5);
        foreach ($recentHistory as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => mb_substr($msg['content'], 0, 1000),
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $currentQuery];

        return $messages;
    }

    private function formatTopProducts(Market $market): string
    {
        $products = $market->getMarketProducts()->toArray();
        usort($products, fn ($a, $b) => ($b->getScore() ?? 0) <=> ($a->getScore() ?? 0));
        $topProducts = array_slice($products, 0, 3);

        if (empty($topProducts)) {
            return 'Aucun produit associé.';
        }

        $lines = [];
        foreach ($topProducts as $i => $mp) {
            $product = $mp->getProduct();
            $line = ($i + 1).". ".$product->getName()." (ID: ".$product->getId().") — Score: ".($mp->getScore() ?? 'N/A')."/100";
            if ($mp->getRelevance()) {
                $line .= "\n   Pertinence: ".mb_substr($mp->getRelevance(), 0, 200);
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, string> $context
     */
    private function formatDocuments(array $context): string
    {
        $parts = [];
        foreach ($context as $i => $section) {
            $truncated = mb_substr($section, 0, 5000);
            if (mb_strlen($section) > 5000) {
                $truncated .= "\n... [extrait tronqué]";
            }
            $parts[] = "=== DOCUMENT " . ($i + 1) . " ===\n"
                . "Ce document EST ta source. Cite les informations EXACTEMENT comme elles apparaissent ci-dessous.\n"
                . $truncated
                . "\n=== FIN DOCUMENT ===";
        }

        return implode("\n\n", $parts);
    }

    private function buildHistory(int $marketId): array
    {
        $messages = $this->chatRepo->findRecentForMarket($marketId, 20);

        return array_map(fn (MarketChatMessage $m) => [
            'role' => $m->getRole(),
            'content' => $m->getContent(),
        ], $messages);
    }

    /**
     * Phase 1: Planifie les queries de recherche pour le RAG
     *
     * @return array{queries: array<int, string>, rawResponse: string}
     */
    private function planQueries(string $question): array
    {
        $planningPrompt = $this->promptLoader->renderSystem('chat.query_planning');

        $messages = [
            ['role' => 'system', 'content' => $planningPrompt],
            ['role' => 'user', 'content' => $question],
        ];

        $response = $this->aiService->askMessages($messages, 0.5, 200);

        if ('' === $response) {
            return ['queries' => [], 'rawResponse' => ''];
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['queries'])) {
            return ['queries' => [], 'rawResponse' => $response];
        }

        $queries = array_slice($data['queries'], 0, 3);
        $filtered = array_filter($queries, fn ($q) => is_string($q) && '' !== trim($q));

        return ['queries' => array_values($filtered), 'rawResponse' => $response];
    }

    /**
     * Phase 2: Recherche dans amoxtli avec plusieurs queries
     *
     * @param array<int, string> $queries
     * @return array{sections: array<int, string>, sources: array<int, string>}
     */
    private function searchWithQueries(int $marketId, array $queries): array
    {
        $workspacePath = $this->amoxtliService->getWorkspacePath((string) $marketId);

        if (!is_dir($workspacePath . '/.amoxtli')) {
            return ['sections' => [], 'sources' => []];
        }

        $allSections = [];
        $allSources = [];

        foreach ($queries as $query) {
            $result = $this->searchContext($marketId, $query, 3);
            foreach ($result['sections'] as $section) {
                if (!in_array($section, $allSections, true)) {
                    $allSections[] = $section;
                }
            }
            foreach ($result['sources'] as $source) {
                if (!in_array($source, $allSources, true)) {
                    $allSources[] = $source;
                }
            }
        }

        return ['sections' => $allSections, 'sources' => $allSources];
    }

    /**
     * Phase 3: Construit les messages pour la réponse finale
     *
     * @param array<int, array{role: string, content: string}> $history
     * @param array<int, string> $context
     */
    private function buildAnswerMessages(Market $market, array $history, array $context, string $currentQuery): array
    {
        $template = $this->promptLoader->renderSystem('chat.context');

        $metadata = "- Titre: " . $market->getTitle() . "\n"
            . "- Acheteur: " . ($market->getBuyer() ?: 'N/A') . "\n"
            . "- Montant: " . ($market->getAmount() ?: 'N/A') . "\n"
            . "- Deadline: " . ($market->getDeadline()?->format('d/m/Y') ?: 'N/A') . "\n"
            . "- Score: " . ($market->getScore() ?: 'N/A') . "/100 (Priorité: " . ($market->getPriority() ?: 'N/A') . ")\n"
            . "- Statut: " . $market->getStatuslabel() . "\n"
            . "- URL: " . ($market->getUrl() ?: 'N/A');

        $description = mb_substr($market->getDescription() ?: 'Non disponible', 0, 800);

        $products = $this->formatTopProducts($market);

        $documents = !empty($context)
            ? $this->formatDocuments($context)
            : 'Aucun document trouvé dans le RAG. Réponds uniquement basé sur les métadonnées ci-dessus.';

        $systemPrompt = str_replace(
            ['{{metadata}}', '{{description}}', '{{products}}', '{{documents}}'],
            [$metadata, $description, $products, $documents],
            $template
        );

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        $recentHistory = array_slice($history, -5);
        foreach ($recentHistory as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => mb_substr($msg['content'], 0, 1000),
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $currentQuery];

        return $messages;
    }

    #[Route('/admin/market/{id}/chat/debug', name: 'app_admin_market_chat_debug', methods: ['GET'])]
    public function debug(int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $market = $this->marketRepo->find($id);
        if (!$market) {
            throw $this->createNotFoundException('Marché introuvable');
        }

        $lastAssistantMessage = $this->chatRepo->findOneBy(
            ['market' => $market, 'role' => 'assistant'],
            ['createdAt' => 'DESC']
        );

        $debugData = $lastAssistantMessage?->getDebugData();
        $lastUserMessage = $this->chatRepo->findOneBy(
            ['market' => $market, 'role' => 'user'],
            ['createdAt' => 'DESC']
        );

        return $this->render('admin/chat_debug.html.twig', [
            'market' => $market,
            'debugData' => $debugData,
            'lastUserMessage' => $lastUserMessage?->getContent(),
        ]);
    }
}
