<?php

namespace App\Controller;

use App\Controller\Trait\AgentJobPollTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Market;
use App\Form\MarketEditType;
use App\Repository\MarketRepository;
use App\Repository\ProductRepository;
use App\Service\AmoxtliService;
use App\Service\BoampFinderService;
use App\Service\DompdfFactory;
use App\Service\ScoringAgentService;
use Bnine\FilesBundle\Service\FileService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use SortDirection;

class MarketController extends AbstractController
{
    use AgentJobPollTrait;
    use LayoutRenderTrait;

    public function __construct(
        private DompdfFactory $dompdfFactory,
    ) {
    }

    #[Route('/user/market', name: 'app_market_list')]
    public function list(MarketRepository $repository): Response
    {
        $markets = $repository->findBy([], ['score' => SortDirection::Descending, 'updatedAt' => SortDirection::Descending]);

        return $this->render('market/list.html.twig', [
            'usemenu' => true,
            'usesidebar' => false,
            'title' => 'Marchés publics',
            'markets' => $markets,
            'status_options' => Market::STATUSES,
        ]);
    }

    #[Route('/user/market/{id}', name: 'app_market_show')]
    public function show(int $id, MarketRepository $marketRepository, FileService $fileService, AmoxtliService $amoxtliService, Request $request, EntityManagerInterface $em): Response
    {
        $market = $marketRepository->find($id);
        if (!$market) {
            return $this->redirectToRoute('app_market_list');
        }

        $fileService->init('boamp', (string) $id);
        $amoxtliService->initWorkspace((string) $id);

        $form = $this->createForm(MarketEditType::class, $market);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $market->setPriority(Market::priorityFromScore($market->getScore()));
            $em->flush();

            return $this->redirectToRoute('app_market_show', ['id' => $id]);
        }

        return $this->render('market/show.html.twig', [
            'usemenu' => true,
            'usesidebar' => false,
            'title' => $market->getTitle(),
            'market' => $market,
            'editForm' => $form->createView(),
        ]);
    }

    #[Route('/user/market/{id}/rescore', name: 'app_market_rescore', methods: ['POST'])]
    public function rescore(int $id, MarketRepository $marketRepository, ProductRepository $productRepository, ScoringAgentService $scoringAgent): JsonResponse
    {
        $market = $marketRepository->find($id);
        if (!$market) {
            return new JsonResponse(['error' => 'Marché introuvable'], 404);
        }

        $products = [];
        foreach ($productRepository->findAll() as $product) {
            $products[] = [
                'id' => $product->getId(),
                'name' => $product->getName() ?? '',
                'description' => $product->getDescription() ?? '',
                'keywords' => $product->getKeywords() ?? '',
                'sectors' => $product->getSectors() ?? '',
            ];
        }

        try {
            $job = $scoringAgent->startScoringJob(
                $market->getId(),
                $market->getIdweb() ?? '',
                $market->getTitle() ?? '',
                $market->getBuyer() ?? '',
                $market->getDescription() ?? '',
                $market->getAmount() ?? '',
                $market->getDeadline()?->format('Y-m-d'),
                $products
            );
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }

        return new JsonResponse(['pending' => true, 'jobId' => $job['job_id']]);
    }

    #[Route('/user/market/{id}/rescore/poll/{jobId}', name: 'app_market_rescore_poll', methods: ['GET'])]
    public function pollRescore(int $id, string $jobId, MarketRepository $marketRepository, ScoringAgentService $scoringAgent, BoampFinderService $finder): JsonResponse
    {
        $job = $scoringAgent->getScoringResult($jobId);

        if ($job->isDone()) {
            $market = $marketRepository->find($id);
            if (!$market) {
                return new JsonResponse(['status' => 'error', 'error' => 'Marché introuvable']);
            }

            $finder->applyScoringResult($market, $job->result);

            return new JsonResponse([
                'status' => 'done',
                'score' => $market->getScore(),
                'priority' => $market->getPriority(),
                'explanation' => $market->getExplanation(),
            ]);
        }

        return $this->agentJobResponse($job);
    }

    #[Route('/user/market/{id}/indexed-docs', name: 'app_market_indexed_docs', methods: ['GET'])]
    public function indexedDocs(int $id, MarketRepository $marketRepository, AmoxtliService $amoxtliService): JsonResponse
    {
        $market = $marketRepository->find($id);
        if (!$market) {
            return new JsonResponse(['error' => 'Marché introuvable'], 404);
        }

        $data = $amoxtliService->listIndexedDocuments((string) $id);

        return new JsonResponse($data);
    }

    #[Route('/user/market/{id}/pdf', name: 'app_market_export_pdf')]
    public function exportPdf(int $id, MarketRepository $marketRepository, \App\Repository\MarketChatMessageRepository $chatRepo): Response
    {
        $market = $marketRepository->find($id);
        if (!$market) {
            return $this->redirectToRoute('app_market_list');
        }

        $products = $market->getMarketProducts()->toArray();
        usort($products, fn ($a, $b) => ($b->getScore() ?? 0) <=> ($a->getScore() ?? 0));

        $chatMessages = $chatRepo->findRecentForMarket($id, 0);

        $html = $this->renderView('market/market_print.html.twig', [
            'market' => $market,
            'products' => $products,
            'chatMessages' => $chatMessages,
        ]);

        $dompdf = $this->dompdfFactory->create();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf('dossier-marche-%s.pdf', $market->getIdweb() ?? $market->getId());

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $filename),
        ]);
    }

    #[Route('/user/market/{id}/status', name: 'app_market_status', methods: ['POST'])]
    public function updateStatus(int $id, Request $request, MarketRepository $marketRepository, EntityManagerInterface $em): JsonResponse
    {
        $market = $marketRepository->find($id);
        if (!$market) {
            return new JsonResponse(['error' => 'Marché introuvable'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $status = $data['status'] ?? null;

        if (!in_array($status, Market::STATUSES, true)) {
            return new JsonResponse(['error' => 'Statut invalide'], 400);
        }

        $market->setStatus($status);
        $em->flush();

        return new JsonResponse(['success' => true, 'status' => $market->getStatusLabel()]);
    }

    #[Route('/user/report', name: 'app_report_list')]
    public function reports(\App\Repository\BoampReportRepository $repository): Response
    {
        return $this->render('boamp/report/list.html.twig', [
            'usemenu' => true,
            'usesidebar' => false,
            'title' => 'Rapports BOAMP',
            'reports' => $repository->findRecent(30),
        ]);
    }
}
