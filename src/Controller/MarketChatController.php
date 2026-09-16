<?php

namespace App\Controller;

use App\Entity\Market;
use App\Entity\MarketChatMessage;
use App\Repository\MarketChatMessageRepository;
use App\Repository\MarketRepository;
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

        $context = $this->searchContext($id, $userMessage);

        $history = $this->buildHistory($market->getId());
        $messages = $this->buildMessages($market, $history, $context, $userMessage);

        $reply = $this->aiService->askMessages($messages, 0.3, 2048);

        if ('' === $reply) {
            $reply = "Désolé, je n'ai pas pu traiter votre question. Veuillez réessayer.";
        }

        $sources = $this->extractSources($context);

        $assistantMessage = new MarketChatMessage();
        $assistantMessage->setMarket($market);
        $assistantMessage->setRole('assistant');
        $assistantMessage->setContent($reply);
        $assistantMessage->setSources($sources);
        $this->em->persist($assistantMessage);
        $this->em->flush();

        return new JsonResponse([
            'userMessageId' => $chatMessage->getId(),
            'assistantMessageId' => $assistantMessage->getId(),
            'reply' => $reply,
            'sources' => $sources,
            'createdAt' => $assistantMessage->getCreatedAt()?->format('c'),
        ]);
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

    private function searchContext(int $marketId, string $query): array
    {
        $workspacePath = $this->amoxtliService->getWorkspacePath((string) $marketId);

        if (!is_dir($workspacePath.'/.amoxtli')) {
            return [];
        }

        $process = new Process([
            $this->amoxtliService->getBinaryPath(),
            '-C', $workspacePath,
            'search',
            $query,
            '--json',
            '-n', '3',
        ]);

        $process->setTimeout(30);

        try {
            $process->run();
        } catch (ProcessFailedException) {
            return [];
        }

        if (!$process->isSuccessful()) {
            return [];
        }

        $data = json_decode($process->getOutput(), true);

        if (!is_array($data) || empty($data['results'])) {
            return [];
        }

        $sections = [];
        foreach ($data['results'] as $result) {
            foreach ($result['sections'] ?? [] as $section) {
                $sections[] = $section['content'] ?? '';
            }
        }

        return $sections;
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
            $line = ($i + 1).". ".$mp->getProduct()->getName()." — Score: ".($mp->getScore() ?? 'N/A')."/100";
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
            $parts[] = "--- Extrait ".($i + 1)." ---\n".mb_substr($section, 0, 500);
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
     * @param array<int, string> $context
     * @return array<int, string>
     */
    private function extractSources(array $context): array
    {
        $sources = [];
        foreach ($context as $section) {
            if (preg_match('/file:\/\/\/([^\s\n]+)/', $section, $m)) {
                $source = basename($m[1]);
                if (!in_array($source, $sources, true)) {
                    $sources[] = $source;
                }
            }
        }

        return $sources;
    }
}
