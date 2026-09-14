<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class OidcAuthenticator extends AbstractAuthenticator
{
    private const SESSION_STATE_KEY = 'openid_connect_state';

    public function __construct(
        #[Autowire(param: 'modeAuth')]
        private string $modeAuth,
        private IdentityUserProvider $identityUserProvider,
        private OidcClient $oidcClient,
        #[Autowire(param: 'oidcUsernameAttribute')]
        private string $oidcUsernameAttribute,
        #[Autowire(param: 'oidcMailAttribute')]
        private string $oidcMailAttribute,
        #[Autowire(param: 'oidcLastnameAttribute')]
        private string $oidcLastnameAttribute,
        #[Autowire(param: 'oidcFirstnameAttribute')]
        private string $oidcFirstnameAttribute,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if ('OIDC' !== $this->modeAuth) {
            return false;
        }

        return $request->query->has('code')
            && $request->query->has('state')
            && $request->getSession()->has(self::SESSION_STATE_KEY);
    }

    public function authenticate(Request $request): Passport
    {
        $sessionState = $request->getSession()->get(self::SESSION_STATE_KEY);

        if (!is_string($sessionState) || '' === $sessionState) {
            throw new AuthenticationException('OIDC authentication failed: missing session state.');
        }

        $requestState = $request->query->get('state');

        if (!is_string($requestState) || !hash_equals($sessionState, $requestState)) {
            throw new AuthenticationException('OIDC authentication failed: invalid state.');
        }

        $request->getSession()->remove(self::SESSION_STATE_KEY);

        $code = $request->query->get('code');

        if (!is_string($code) || '' === $code) {
            throw new AuthenticationException('OIDC authentication failed: missing authorization code.');
        }

        $claims = $this->oidcClient->exchangeCode($code);

        $username = $claims[$this->oidcUsernameAttribute] ?? null;

        if (!is_string($username) || '' === $username) {
            throw new AuthenticationException(sprintf('OIDC authentication failed: "%s" claim is missing or empty.', $this->oidcUsernameAttribute));
        }

        $mapping = [
            'mail' => $this->oidcMailAttribute,
            'lastname' => $this->oidcLastnameAttribute,
            'firstname' => $this->oidcFirstnameAttribute,
        ];

        $userBadge = new UserBadge($username, function ($userIdentifier) use ($claims, $mapping) {
            return $this->identityUserProvider->loadUserByIdentifierAndAttributes($userIdentifier, $claims, $mapping);
        });

        return new SelfValidatingPassport($userBadge);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        if ($request->query->has('code')) {
            return new RedirectResponse($this->urlGenerator->generate('app_home'));
        }

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        throw $exception;
    }
}
