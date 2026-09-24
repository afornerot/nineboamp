<?php

namespace App\Controller;

use App\Entity\Market;
use App\Entity\MarketReport;
use App\Repository\MarketReportRepository;
use App\Repository\MarketRepository;
use App\Service\MarketReportService;
use App\Service\DompdfFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MarketReportController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private MarketRepository $marketRepo,
        private MarketReportRepository $reportRepo,
        private MarketReportService $reportService,
        private DompdfFactory $dompdfFactory,
    ) {
    }

    #[Route('/user/market/{id}/report/generate', name: 'app_market_report_generate', methods: ['POST'])]
    public function generate(int $id, Request $request): JsonResponse
    {
        $market = $this->marketRepo->find($id);
        if (!$market) {
            return new JsonResponse(['error' => 'Marché introuvable'], 404);
        }

        $jobId = $this->reportService->startReport($market);
        if (null === $jobId) {
            return new JsonResponse(['error' => 'Impossible de démarrer le job de rapport'], 500);
        }

        $report = new MarketReport();
        $report->setMarket($market);
        $report->setJobId($jobId);
        $report->setStatus(MarketReport::STATUS_PENDING);
        $this->em->persist($report);
        $this->em->flush();

        return new JsonResponse([
            'reportId' => $report->getId(),
            'jobId' => $jobId,
            'status' => $report->getStatus(),
        ]);
    }

    #[Route('/user/market/{id}/report/poll/{reportId}', name: 'app_market_report_poll', methods: ['GET'])]
    public function poll(int $id, int $reportId): JsonResponse
    {
        $market = $this->marketRepo->find($id);
        if (!$market) {
            return new JsonResponse(['error' => 'Marché introuvable'], 404);
        }

        $report = $this->reportRepo->find($reportId);
        if (!$report || $report->getMarket()?->getId() !== $id) {
            return new JsonResponse(['error' => 'Rapport introuvable'], 404);
        }

        $jobId = $report->getJobId();
        if (null === $jobId) {
            return new JsonResponse(['error' => 'Job ID manquant'], 500);
        }

        $result = $this->reportService->getResult($jobId);
        $status = $result['status'] ?? 'unknown';
        $payload = $result['result'] ?? [];

        $now = new \DateTime();
        $now->setTimezone(new \DateTimeZone('Europe/Paris'));

        if ('done' === $status) {
            $markdown = $payload['markdown'] ?? '';
            if ('' === $markdown) {
                $report->setStatus(MarketReport::STATUS_ERROR);
                $report->setErrorMessage('Réponse vide de l\'agent');
                $report->setUpdatedAt($now);
                $this->em->flush();

                return new JsonResponse(['status' => 'error', 'error' => 'Réponse vide']);
            }

            $report->setMarkdownContent($markdown);
            $report->setStatus(MarketReport::STATUS_DONE);
            $report->setUpdatedAt($now);
            $this->em->flush();

            return new JsonResponse([
                'status' => 'done',
                'reportId' => $report->getId(),
                'markdown' => $markdown,
            ]);
        }

        if ('error' === $status || 'not_found' === $status) {
            $report->setStatus(MarketReport::STATUS_ERROR);
            $report->setErrorMessage($payload['error'] ?? 'Erreur inconnue');
            $report->setUpdatedAt($now);
            $this->em->flush();

            return new JsonResponse([
                'status' => 'error',
                'error' => $payload['error'] ?? 'Erreur inconnue',
            ]);
        }

        return new JsonResponse(['status' => 'pending']);
    }

    #[Route('/user/market/{id}/report/pdf', name: 'app_market_report_pdf', methods: ['GET'])]
    public function pdf(int $id, Request $request, \App\Repository\MarketChatMessageRepository $chatRepo): Response
    {
        $market = $this->marketRepo->find($id);
        if (!$market) {
            return $this->redirectToRoute('app_market_list');
        }

        $reportId = $request->query->get('reportId');
        $report = null;
        if ($reportId) {
            $report = $this->reportRepo->find((int) $reportId);
        }
        if (!$report) {
            $report = $this->reportRepo->findLatestForMarket($id);
        }

        if (!$report || !$report->isDone()) {
            $this->addFlash('error', 'Aucun rapport disponible pour ce marché.');
            return $this->redirectToRoute('app_market_show', ['id' => $id]);
        }

        $products = $market->getMarketProducts()->toArray();
        usort($products, fn ($a, $b) => ($b->getScore() ?? 0) <=> ($a->getScore() ?? 0));
        $chatMessages = $chatRepo->findRecentForMarket($id, 0);

        $html = $this->renderView('market/report_print.html.twig', [
            'market' => $market,
            'report' => $report,
            'products' => $products,
            'chatMessages' => $chatMessages,
        ]);

        $dompdf = $this->dompdfFactory->create();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf('rapport-positionnement-%s.pdf', $market->getIdweb() ?? $market->getId());

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $filename),
        ]);
    }

    #[Route('/user/market/{id}/report/list', name: 'app_market_report_list', methods: ['GET'])]
    public function listReports(int $id): JsonResponse
    {
        $market = $this->marketRepo->find($id);
        if (!$market) {
            return new JsonResponse(['error' => 'Marché introuvable'], 404);
        }

        $reports = $this->reportRepo->findAllForMarket($id);

        $payload = array_map(fn (MarketReport $r) => [
            'id' => $r->getId(),
            'status' => $r->getStatus(),
            'createdAt' => $r->getCreatedAt()?->format('c'),
            'updatedAt' => $r->getUpdatedAt()?->format('c'),
        ], $reports);

        return new JsonResponse(['reports' => $payload]);
    }

    #[Route('/user/market/{id}/report/{reportId}', name: 'app_market_report_delete', methods: ['DELETE'])]
    public function delete(int $id, int $reportId): JsonResponse
    {
        $market = $this->marketRepo->find($id);
        if (!$market) {
            return new JsonResponse(['error' => 'Marché introuvable'], 404);
        }

        $report = $this->reportRepo->find($reportId);
        if (!$report || $report->getMarket()?->getId() !== $id) {
            return new JsonResponse(['error' => 'Rapport introuvable'], 404);
        }

        $this->em->remove($report);
        $this->em->flush();

        return new JsonResponse(['success' => true, 'id' => $reportId]);
    }
}
