<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Form\CronType;
use App\Repository\CronRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CronController extends AbstractController
{
    use CrudDeleteTrait;
    use LayoutRenderTrait;

    public function __construct(
        private CronRepository $cronRepository,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/cron', name: 'app_admin_cron')]
    public function list(): Response
    {
        $crons = $this->cronRepository->findAll();

        return $this->renderLayout('cron/list.html.twig', 'Liste des Crons', [
            'routeupdate' => 'app_admin_cron_update',
            'crons' => $crons,
        ]);
    }

    #[Route('/admin/cron/update/{id}', name: 'app_admin_cron_update')]
    public function update(int $id, Request $request): Response
    {
        $cron = $this->cronRepository->find($id);
        if (!$cron) {
            return $this->redirectToRoute('app_admin_cron');
        }

        $form = $this->createForm(CronType::class, $cron, ['mode' => 'update']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            return $this->redirectToRoute('app_admin_cron');
        }

        return $this->renderLayout('cron/edit.html.twig', 'Modification Cron = '.$cron->getCommand(), [
            'routecancel' => 'app_admin_cron',
            'routedelete' => 'app_admin_cron_delete',
            'mode' => 'update',
            'form' => $form,
        ]);
    }

    #[Route('/admin/cron/delete/{id}', name: 'app_admin_cron_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $cron = $this->cronRepository->find($id);
        if (!$cron) {
            return $this->redirectToRoute('app_admin_cron');
        }

        return $this->deleteEntity($request, $cron, $id, 'delete-cron', 'cron', $this->em, [
            'list' => 'app_admin_cron',
            'update' => 'app_admin_cron_update',
            'conflictMessage' => 'Suppression impossible : erreur technique.',
        ]);
    }
}
