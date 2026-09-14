<?php

namespace App\Tests\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CrudDeleteTraitTest extends TestCase
{
    public function testInvalidCsrfBlocksDeletion(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('remove');

        $controller = $this->controller();

        $response = $controller->testDelete($this->requestWithToken('bad-token'), new \stdClass(), 1, 'delete-x', $em, [
            'list' => 'route_list',
            'update' => 'route_update',
        ]);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/route/update', $response->getTargetUrl(), 'La mauvaise validation renvoie sur la route "update".');
    }

    private function requestWithToken(string $token): Request
    {
        return new Request([], ['_csrf_token' => $token]);
    }

    private function controller(): FlushStubController
    {
        return new FlushStubController();
    }
}

class FlushStubController
{
    use CrudDeleteTrait;

    public \Psr\Container\ContainerInterface $container;

    public function isCsrfTokenValid(string $tokenId, mixed $token): bool
    {
        return 'valid-token' === $token;
    }

    public function addFlash(string $type, mixed $message): void
    {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function redirectToRoute(string $route, array $parameters = [], int $status = 302): RedirectResponse
    {
        return new RedirectResponse('/'.str_replace('_', '/', $route));
    }

    /**
     * @param array{list: string, update?: string, conflictMessage?: string} $context
     */
    public function testDelete(Request $request, object $entity, int|string $id, string $tokenPrefix, EntityManagerInterface $em, array $context): Response
    {
        return $this->deleteEntity($request, $entity, $id, $tokenPrefix, 'x', $em, $context);
    }
}
