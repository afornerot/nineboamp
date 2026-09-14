<?php

namespace App\Controller\Trait;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logique partagée des suppressions admin : validation CSRF + flush + flash
 * générique avec log d'exception (jamais le message brut à l'utilisateur).
 */
trait CrudDeleteTrait
{
    /**
     * @param array{list: string, update?: string, conflictMessage?: string} $context
     */
    private function deleteEntity(Request $request, object $entity, string|int $id, string $tokenPrefix, string $entityLabel, EntityManagerInterface $em, array $context): Response
    {
        if (!$this->isCsrfTokenValid($tokenPrefix.$id, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token CSRF invalide — suppression non effectuée.');

            return $this->redirectAfterDelete($id, $context['update'] ?? null, $context['list']);
        }

        try {
            $em->remove($entity);
            $em->flush();
        } catch (\Exception $e) {
            $this->crudLogger()->error('Suppression '.$entityLabel.' en échec', ['id' => $id, 'exception' => $e]);
            $this->addFlash('error', $context['conflictMessage'] ?? 'Suppression impossible : erreur technique.');

            return $this->redirectAfterDelete($id, $context['update'] ?? null, $context['list']);
        }

        return $this->redirectToRoute($context['list']);
    }

    private function crudLogger(): LoggerInterface
    {
        $logger = $this->container->get('logger');

        if (!$logger instanceof LoggerInterface) {
            throw new \RuntimeException('Logger indisponible.');
        }

        return $logger;
    }

    private function redirectAfterDelete(int|string $id, ?string $updateRoute, string $listRoute): RedirectResponse
    {
        if (null !== $updateRoute) {
            return $this->redirectToRoute($updateRoute, ['id' => $id]);
        }

        return $this->redirectToRoute($listRoute);
    }
}
