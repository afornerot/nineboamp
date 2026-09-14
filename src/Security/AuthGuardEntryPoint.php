<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class AuthGuardEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        #[Autowire(param: 'modeAuth')]
        private string $modeAuth,
        private CasService $casService,
        private OidcClient $oidcClient,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return match ($this->modeAuth) {
            'CAS' => $this->startCas($request),
            'OIDC' => new RedirectResponse($this->oidcClient->createAuthorizationUrlSymfonySession($request->getSession())),
            default => new RedirectResponse($this->urlGenerator->generate('app_login')),
        };
    }

    private function startCas(Request $request): Response
    {
        $this->casService->init($request);
        \phpCAS::forceAuthentication();

        return new Response('', Response::HTTP_FOUND);
    }
}
