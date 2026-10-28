<?php

namespace App\EventListener;

use App\Message\IndexMarketMessage;
use App\Message\UnindexDocumentMessage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class AmoxtliIndexListener implements EventSubscriberInterface
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onUploadResponse',
        ];
    }

    public function onUploadResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        if ($route !== 'bninefiles_files_uploadfile' && $route !== 'bninefiles_files_delete') {
            return;
        }

        $response = $event->getResponse();
        $data = json_decode($response->getContent(), true);

        if (!($data['success'] ?? false)) {
            return;
        }

        // L'upload passe domaine et id dans la query, la suppression dans la route.
        $parameters = $route === 'bninefiles_files_delete' ? $request->attributes : $request->query;

        // Seules les pièces des marchés sont indexées, pas les avatars ni les logos.
        if ($parameters->get('domain') !== 'boamp') {
            return;
        }

        // L'id d'un upload vient de la query : seul un id numérique désigne un marché.
        $id = (string) $parameters->get('id');

        if (!ctype_digit($id)) {
            return;
        }

        if ('bninefiles_files_delete' === $route) {
            // La suppression d'une pièce la désindexe, sans réindexer le marché.
            $piece = $this->deletedPiece($request);

            if (null !== $piece) {
                $this->messageBus->dispatch(new UnindexDocumentMessage($id, $piece));
            }

            return;
        }

        $this->messageBus->dispatch(new IndexMarketMessage($id));
    }

    /**
     * Nom de la pièce supprimée, relatif au dossier du marché.
     *
     * Le bundle envoie le chemin du fichier supprimé dans le corps de la
     * requête. Le corps est déjà lu à ce stade : c'est la requête qui l'a
     * décodé pour répondre.
     */
    private function deletedPiece(\Symfony\Component\HttpFoundation\Request $request): ?string
    {
        $content = $request->getContent();

        if (!is_string($content) || '' === $content) {
            return null;
        }

        $data = json_decode($content, true);
        $path = is_array($data) ? ($data['path'] ?? null) : null;

        if (!is_string($path) || '' === $path) {
            return null;
        }

        // Un chemin absolu ou remontant hors du dossier du marché n'est pas
        // une pièce de ce marché.
        if (str_starts_with($path, '/') || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }
}
