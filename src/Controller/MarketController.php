<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Market;
use App\Form\MarketEditType;
use App\Repository\MarketRepository;
use App\Service\AmoxtliService;
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
    public function rescore(int $id, MarketRepository $marketRepository, \App\Service\BoampFinderService $boampFinder): Response
    {
        $market = $marketRepository->find($id);
        if (!$market) {
            return $this->redirectToRoute('app_market_list');
        }

        try {
            $boampFinder->rescoreMarket($market);
            $this->addFlash('success', 'Scoring relancé avec succès. Score : ' . $market->getScore() . '/100 (priorité ' . $market->getPriority() . ')');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur lors du scoring : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_market_show', ['id' => $id]);
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

        $html = $this->renderView('market/print.html.twig', [
            'market' => $market,
            'products' => $products,
            'chatMessages' => $chatMessages,
        ]);

        $dompdf = new \Dompdf\Dompdf();
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
