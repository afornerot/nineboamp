<?php

namespace App\Tests\EventListener;

use App\EventListener\AmoxtliIndexListener;
use App\Message\IndexMarketMessage;
use App\Message\UnindexDocumentMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class AmoxtliIndexListenerTest extends TestCase
{
    public function testUploadOnMarketIndexesIt(): void
    {
        $request = new Request(['domain' => 'boamp', 'id' => '12'], [], ['_route' => 'bninefiles_files_uploadfile']);

        $this->assertSame(['12'], $this->dispatched($request));
    }

    public function testUploadWithAPathAsIdIsIgnored(): void
    {
        $request = new Request(['domain' => 'boamp', 'id' => '../amoxtli/5'], [], ['_route' => 'bninefiles_files_uploadfile']);

        $this->assertSame([], $this->dispatched($request));
    }

    public function testUploadOfAvatarIsIgnored(): void
    {
        $request = new Request(['domain' => 'avatar', 'id' => '0'], [], ['_route' => 'bninefiles_files_uploadfile']);

        $this->assertSame([], $this->dispatched($request));
    }

    public function testDeleteOnMarketReadsRouteAttributes(): void
    {
        $request = $this->deleteRequest(['path' => 'CCAP.pdf']);

        $this->assertSame([], $this->dispatched($request));
        $this->assertSame([['12', 'CCAP.pdf']], $this->unindexed($request));
    }

    public function testDeleteOfLogoIsIgnored(): void
    {
        $request = new Request([], [], ['_route' => 'bninefiles_files_delete', 'domain' => 'logo', 'id' => 3]);

        $this->assertSame([], $this->unindexed($request));
    }

    public function testDeleteWithoutAPieceIsIgnored(): void
    {
        $request = new Request([], [], ['_route' => 'bninefiles_files_delete', 'domain' => 'boamp', 'id' => 12]);

        $this->assertSame([], $this->unindexed($request));
    }

    public function testDeleteOfAPieceOutsideTheMarketIsIgnored(): void
    {
        $request = $this->deleteRequest(['path' => '../amoxtli/5/secret.pdf']);

        $this->assertSame([], $this->unindexed($request));
    }

    public function testFailedUploadIsIgnored(): void
    {
        $request = new Request(['domain' => 'boamp', 'id' => '12'], [], ['_route' => 'bninefiles_files_uploadfile']);

        $this->assertSame([], $this->dispatched($request, false));
    }

    /**
     * @return list<string> marchés envoyés à l'indexation
     */
    private function dispatched(Request $request, bool $success = true): array
    {
        $bus = $this->bus();

        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new JsonResponse(['success' => $success]),
        );
        (new AmoxtliIndexListener($bus))->onUploadResponse($event);

        return $bus->marketIds;
    }

    /**
     * @return list<array{string, string}> pièces désindexées, par marché
     */
    private function unindexed(Request $request): array
    {
        $bus = $this->bus();

        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new JsonResponse(['success' => true]),
        );
        (new AmoxtliIndexListener($bus))->onUploadResponse($event);

        return $bus->unindexed;
    }

    /**
     * Requête de suppression d'une pièce, comme le bundle de fichiers l'envoie.
     *
     * @param array<string, mixed> $body
     */
    private function deleteRequest(array $body): Request
    {
        $request = new Request([], [], ['_route' => 'bninefiles_files_delete', 'domain' => 'boamp', 'id' => 12]);
        $request->initialize([], [], $request->attributes->all(), [], [], [], json_encode($body));

        return $request;
    }

    private function bus(): object
    {
        return new class implements MessageBusInterface {
            /** @var list<string> */
            public array $marketIds = [];

            /** @var list<array{string, string}> */
            public array $unindexed = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($message instanceof IndexMarketMessage) {
                    $this->marketIds[] = $message->getMarketId();
                }

                if ($message instanceof UnindexDocumentMessage) {
                    $this->unindexed[] = [$message->getMarketId(), $message->getPiece()];
                }

                return new Envelope($message);
            }
        };
    }
}
