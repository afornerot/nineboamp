<?php

namespace App\EventListener;

use App\Message\IndexMarketMessage;
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

        $id = $parameters->get('id');

        if ($id) {
            $this->messageBus->dispatch(new IndexMarketMessage((string) $id));
        }
    }
}
