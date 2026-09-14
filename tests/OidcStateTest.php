<?php

namespace App\Tests\Security;

use App\Security\IdentityUserProvider;
use App\Security\OidcAuthenticator;
use App\Security\OidcClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class OidcStateTest extends TestCase
{
    public function testStateMismatchIsRefusedBeforeCallingProvider(): void
    {
        $request = $this->createRequest(new Session(new MockArraySessionStorage()));
        $request->getSession()->set('openid_connect_state', 'session-state');
        $request->query->set('code', 'test-code');
        $request->query->set('state', 'attacker-state');

        $this->expectException(AuthenticationException::class);

        $this->createAuthenticator()->authenticate($request);
    }

    public function testSessionStateMissingIsRefused(): void
    {
        $request = $this->createRequest(new Session(new MockArraySessionStorage()));
        $request->query->set('code', 'test-code');
        $request->query->set('state', 'any-state');

        $this->expectException(AuthenticationException::class);

        $this->createAuthenticator()->authenticate($request);
    }

    public function testSessionStateConsumedAfterAuthentication(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('openid_connect_state', 'session-state');
        $request = $this->createRequest($session);
        $request->query->set('code', 'test-code');
        $request->query->set('state', 'session-state');

        // Client factice : l'échange de code viendra plus tard (avec le vrai
        // provider). On teste que le state est bien consommé avant cet appel.
        $oidcClient = $this->createConfiguredMock(OidcClient::class, [
            'exchangeCode' => [],
        ]);

        $authenticator = new OidcAuthenticator(
            'OIDC',
            $this->createStub(IdentityUserProvider::class),
            $oidcClient,
            'preferred_username',
            'email',
            'family_name',
            'given_name',
            $this->createStub(UrlGeneratorInterface::class)
        );

        try {
            $authenticator->authenticate($request);
        } catch (AuthenticationException $e) {
            $this->assertStringContainsString('claim is missing', $e->getMessage());
        } finally {
            $this->assertNull($session->get('openid_connect_state'), 'Le state doit être consommé avant l\'échange de code.');
        }
    }

    private function createAuthenticator(): OidcAuthenticator
    {
        return new OidcAuthenticator(
            'OIDC',
            $this->createStub(IdentityUserProvider::class),
            $this->createStub(OidcClient::class),
            'preferred_username',
            'email',
            'family_name',
            'given_name',
            $this->createStub(UrlGeneratorInterface::class),
        );
    }

    private function createRequest(Session $session): Request
    {
        $request = new Request();

        $request->setSession($session);

        return $request;
    }
}
