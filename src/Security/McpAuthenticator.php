<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class McpAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        #[Autowire(param: 'mcpSecret')]
        private readonly string $mcpSecret,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return str_starts_with($request->getPathInfo(), '/mcp');
    }

    public function authenticate(Request $request): Passport
    {
        $header = $request->headers->get('Authorization');

        if (null === $header) {
            throw new AuthenticationException('Missing or invalid Authorization header.');
        }

        if (!str_starts_with($header, 'Bearer ')) {
            throw new AuthenticationException('Missing or invalid Authorization header.');
        }

        $token = substr($header, 7);

        if ('' === $token) {
            throw new AuthenticationException('Missing or invalid Authorization header.');
        }

        if (!hash_equals($this->mcpSecret, $token)) {
            throw new AuthenticationException('Invalid MCP token.');
        }

        return new SelfValidatingPassport(
            new UserBadge('mcp-system')
        );
    }

    public function onAuthenticationSuccess(Request $request, \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token, string $firewallName): ?\Symfony\Component\HttpFoundation\Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?\Symfony\Component\HttpFoundation\Response
    {
        return new \Symfony\Component\HttpFoundation\JsonResponse(
            ['error' => 'Unauthorized', 'message' => $exception->getMessage()],
            \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED
        );
    }
}
