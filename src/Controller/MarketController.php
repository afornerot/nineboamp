<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Market;
use App\Entity\MarketProduct;
use App\Form\MarketEditType;
use App\Repository\MarketRepository;
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

class MarketController extends AbstractController
{
    use LayoutRenderTrait;

    public function __construct(
        private DompdfFactory $dompdfFactory,
    ) {
    }

    #[Route('/user/market', name: 'app_market_list')]
    public function list(MarketRepository $repository): Response
    {
        $markets = $repository->findBy([], ['score' => 'DESC', 'updatedAt' => 'DESC']);

        return $this->render('market/list.html.twig', [
            'usemenu' => true,
            'usesidebar' => false,
            'title' => 'Marchés publics',
            'markets' => $markets,
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
    public function rescore(int $id, MarketRepository $marketRepository, ScoringAgentService $scoringAgent): JsonResponse
    {
        $market = $marketRepository->find($id);
        if (!$market) {
            return new JsonResponse(['error' => 'Marché introuvable'], 404);
        }

        $rawData = $market->getRawData();
        if (null === $rawData) {
            return new JsonResponse(['error' => 'Aucune donnée brute disponible pour ce marché'], 400);
        }

        $products = [];
        foreach ($market->getMarketProducts() as $mp) {
            $product = $mp->getProduct();
            $products[] = [
                'name' => $product?->getName() ?? 'Unknown',
                'description' => $product?->getDescription() ?? '',
                'keywords' => $product?->getKeywords() ?? '',
                'sectors' => $product?->getSectors() ?? '',
                'score' => $mp->getScore(),
            ];
        }

        $jobId = $scoringAgent->startScoringJob(
            $market->getId(),
            $market->getTitle() ?? '',
            $market->getBuyer() ?? '',
            $market->getDescription() ?? '',
            $market->getAmount() ?? '',
            $market->getDeadline()?->format('Y-m-d'),
            $products
        );

        if (null === $jobId) {
            return new JsonResponse(['error' => 'Impossible de démarrer le scoring'], 500);
        }

        return new JsonResponse(['pending' => true, 'jobId' => $jobId]);
    }

    #[Route('/user/market/{id}/rescore/poll/{jobId}', name: 'app_market_rescore_poll', methods: ['GET'])]
    public function pollRescore(int $id, string $jobId, MarketRepository $marketRepository, ScoringAgentService $scoringAgent, EntityManagerInterface $em): JsonResponse
    {
        $result = $scoringAgent->getScoringResult($jobId);

        if ('not_found' === $result['status']) {
            return new JsonResponse(['status' => 'not_found', 'error' => 'Job non trouvé']);
        }

        if ('pending' === $result['status']) {
            return new JsonResponse(['status' => 'pending']);
        }

        if ('done' === $result['status']) {
            $market = $marketRepository->find($id);
            if (!$market) {
                return new JsonResponse(['status' => 'error', 'error' => 'Marché introuvable'], 404);
            }

            $data = $result['result'] ?? [];

            $market->setScore($data['score'] ?? 0);
            $market->setPriority($data['priority'] ?? 'C');
            $market->setExplanation($data['explanation'] ?? null);

            foreach ($market->getMarketProducts() as $mp) {
                $market->removeMarketProduct($mp);
                $em->remove($mp);
            }

            $productRepo = $em->getRepository(\App\Entity\Product::class);

            foreach ($data['products'] ?? [] as $p) {
                $product = $productRepo->findOneBy(['name' => $p['name'] ?? '']);
                if (!$product) {
                    continue;
                }
                $mp = new MarketProduct();
                $mp->setMarket($market);
                $mp->setProduct($product);
                $mp->setScore($p['score'] ?? null);
                $mp->setPriority($p['priority'] ?? null);
                $mp->setRelevance($p['relevance'] ?? null);
                $mp->setScoreDetails($p['scoreDetails'] ?? null);
                $market->addMarketProduct($mp);
                $em->persist($mp);
            }

            $em->persist($market);
            $em->flush();

            return new JsonResponse([
                'status' => 'done',
                'score' => $market->getScore(),
                'priority' => $market->getPriority(),
                'explanation' => $market->getExplanation(),
            ]);
        }

        return new JsonResponse(['status' => 'error', 'error' => $result['result']['error'] ?? 'Erreur inconnue'], 500);
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
