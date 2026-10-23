<?php

namespace App\Tests\EventListener;

use App\EventListener\AmoxtliIndexListener;
use App\Message\IndexMarketMessage;
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

    public function testUploadOfAvatarIsIgnored(): void
    {
        $request = new Request(['domain' => 'avatar', 'id' => '0'], [], ['_route' => 'bninefiles_files_uploadfile']);

        $this->assertSame([], $this->dispatched($request));
    }

    public function testDeleteOnMarketReadsRouteAttributes(): void
    {
        $request = new Request([], [], ['_route' => 'bninefiles_files_delete', 'domain' => 'boamp', 'id' => 12]);

        $this->assertSame(['12'], $this->dispatched($request));
    }

    public function testDeleteOfLogoIsIgnored(): void
    {
        $request = new Request([], [], ['_route' => 'bninefiles_files_delete', 'domain' => 'logo', 'id' => 3]);

        $this->assertSame([], $this->dispatched($request));
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
        $bus = new class implements MessageBusInterface {
            /** @var list<string> */
            public array $marketIds = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($message instanceof IndexMarketMessage) {
                    $this->marketIds[] = $message->getMarketId();
                }

                return new Envelope($message);
            }
        };

        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new JsonResponse(['success' => $success]),
        );
        (new AmoxtliIndexListener($bus))->onUploadResponse($event);

        return $bus->marketIds;
    }
}
