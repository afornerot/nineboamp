<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class CasAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        #[Autowire(param: 'modeAuth')]
        private string $modeAuth,
        private CasService $casService,
        private IdentityUserProvider $identityUserProvider,
        #[Autowire(param: 'casMail')]
        private string $casMail,
        #[Autowire(param: 'casLastname')]
        private string $casLastname,
        #[Autowire(param: 'casFirstname')]
        private string $casFirstname,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if ('CAS' !== $this->modeAuth) {
            return false;
        }

        $this->casService->init($request);

        return \phpCAS::isSessionAuthenticated() || $request->query->has('ticket');
    }

    public function authenticate(Request $request): Passport
    {
        \phpCAS::forceAuthentication();

        $username = \phpCAS::getUser();
        $attributes = \phpCAS::getAttributes();

        if (!$username) {
            throw new AuthenticationException('CAS authentication failed.');
        }

        $mapping = [
            'mail' => $this->casMail,
            'lastname' => $this->casLastname,
            'firstname' => $this->casFirstname,
        ];

        $userBadge = new UserBadge($username, function ($userIdentifier) use ($attributes, $mapping) {
            return $this->identityUserProvider->loadUserByIdentifierAndAttributes($userIdentifier, $attributes, $mapping);
        });

        return new SelfValidatingPassport($userBadge);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        throw $exception;
    }
}
