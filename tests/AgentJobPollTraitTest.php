<?php

namespace App\Tests\Controller;

use App\Controller\Trait\AgentJobPollTrait;
use App\Service\AgentJobResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

class AgentJobPollTraitTest extends TestCase
{
    public function testPendingJobIsPolledAgain(): void
    {
        $response = (new AgentJobPollStubController())->respond(new AgentJobResult(AgentJobResult::PENDING));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['status' => 'pending'], json_decode($response->getContent(), true));
    }

    public function testFailedPollIsRetriedByTheJs(): void
    {
        $response = (new AgentJobPollStubController())->respond(new AgentJobResult(AgentJobResult::UNREACHABLE, error: 'Connection refused'));

        // Le JS réessaie sur tout code HTTP d'erreur.
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('unreachable', json_decode($response->getContent(), true)['status']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function finalFailures(): iterable
    {
        yield 'erreur de l\'agent' => [AgentJobResult::ERROR];
        yield 'job introuvable' => [AgentJobResult::NOT_FOUND];
        yield 'délai dépassé' => [AgentJobResult::TIMEOUT];
    }

    #[DataProvider('finalFailures')]
    public function testFinalFailureIsShownWithItsReason(string $status): void
    {
        $response = (new AgentJobPollStubController())->respond(new AgentJobResult($status, error: 'la raison'));

        // 200 : le JS affiche l'erreur au lieu de réessayer.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['status' => 'error', 'error' => 'la raison'], json_decode($response->getContent(), true));
    }
}

class AgentJobPollStubController
{
    use AgentJobPollTrait;

    public function respond(AgentJobResult $job): JsonResponse
    {
        return $this->agentJobResponse($job);
    }
}
