<?php

namespace App\Security;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client OIDC minimaliste : discovery, URL d'autorisation, échange de code et
 * retrieval des claims. Remplace jumbojett/openid-connect-php (abandonné).
 */
class OidcClient
{
    private const SESSION_STATE_KEY = 'openid_connect_state';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $discovery = null;

    private ?string $jwks = null;

    public function __construct(
        #[Autowire(param: 'oidcIssuer')]
        private string $oidcIssuer,
        #[Autowire(param: 'oidcClientId')]
        private string $oidcClientId,
        #[Autowire(param: 'oidcClientSecret')]
        private string $oidcClientSecret,
        #[Autowire(param: 'oidcRedirectUri')]
        private string $oidcRedirectUri,
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Construit l'URL d'autorisation et stocke le state en session.
     */
    public function createAuthorizationUrlSymfonySession(SessionInterface $session): string
    {
        $this->ensureDiscovery();

        $state = \bin2hex(\random_bytes(16));
        $session->set(self::SESSION_STATE_KEY, $state);

        $endpoint = $this->discovery['authorization_endpoint'] ?? null;

        if (!\is_string($endpoint) || '' === $endpoint) {
            throw new AuthenticationException('OIDC: authorization_endpoint introuvable dans la discovery.');
        }

        return sprintf(
            '%s%s%sclient_id=%s&response_type=code&scope=%s&redirect_uri=%s&state=%s',
            $endpoint,
            \str_contains($endpoint, '?') ? '&' : '?',
            '',
            \rawurlencode($this->oidcClientId),
            \rawurlencode('openid profile email'),
            \rawurlencode($this->oidcRedirectUri),
            \rawurlencode($state)
        );
    }

    /**
     * Échange le code d'autorisation contre un id_token, vérifie la signature
     * (JWKS), issuer, audience et expiration, et renvoie les claims.
     *
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code): array
    {
        $this->ensureDiscovery();

        $tokenEndpoint = $this->discovery['token_endpoint'] ?? null;

        if (!\is_string($tokenEndpoint) || '' === $tokenEndpoint) {
            throw new AuthenticationException('OIDC: token_endpoint introuvable dans la discovery.');
        }

        $response = $this->httpClient->request('POST', $tokenEndpoint, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->oidcRedirectUri,
                'client_id' => $this->oidcClientId,
                'client_secret' => $this->oidcClientSecret,
            ],
        ]);

        try {
            $token = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new AuthenticationException('OIDC: réponse token_endpoint illisible.', 0, $e);
        }

        // Le fournisseur signale ses refus dans error/error_description. Les
        // tester avant id_token évite de masquer la cause réelle (client non
        // autorisé, redirect_uri non enregistré, code expiré...) derrière un
        // « id_token absent » trompeur.
        if (isset($token['error'])) {
            $description = \is_string($token['error_description'] ?? null) && '' !== $token['error_description']
                ? $token['error_description']
                : 'sans description';

            throw new AuthenticationException(sprintf(
                'OIDC: le fournisseur a refusé l\'échange du code (%s : %s).',
                (string) $token['error'],
                $description
            ));
        }

        if (!\is_string($token['id_token'] ?? null) || '' === $token['id_token']) {
            throw new AuthenticationException('OIDC: id_token absent de la réponse du provider.');
        }

        $claims = $this->decodeIdToken($token['id_token']);

        // L'id_token du fournisseur ne contient pas toujours les claims de
        // profil (preferred_username, family_name...). On les complète via
        // userinfo_endpoint ; l'id_token reste prioritaire grâce à +=.
        $accessToken = $token['access_token'] ?? null;
        if (\is_string($accessToken) && '' !== $accessToken) {
            $claims += $this->fetchUserInfo($accessToken);
        }

        return $claims;
    }

    /**
     * Complète les claims via userinfo_endpoint.
     *
     * @return array<string, mixed>
     */
    private function fetchUserInfo(string $accessToken): array
    {
        $endpoint = $this->discovery['userinfo_endpoint'] ?? null;

        if (!\is_string($endpoint) || '' === $endpoint) {
            return [];
        }

        try {
            $response = $this->httpClient->request('GET', $endpoint, [
                'headers' => ['Authorization' => 'Bearer '.$accessToken],
            ]);

            return $response->toArray(false);
        } catch (\Throwable) {
            // Un userinfo indisponible ne doit pas bloquer : l'id_token suffit.
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeIdToken(string $idToken): array
    {
        try {
            $claims = JWT::decode($idToken, $this->getKeySet());
        } catch (\Throwable $e) {
            throw new AuthenticationException('OIDC: id_token invalide ('.$e->getMessage().').', 0, $e);
        }

        $data = (array) $claims;

        $issuer = $this->discovery['issuer'] ?? $this->oidcIssuer;

        if (\is_string($issuer) && isset($data['iss']) && $data['iss'] !== $issuer) {
            throw new AuthenticationException('OIDC: issuer id_token non correspondant.');
        }

        if (isset($data['aud']) && $this->oidcClientId !== ($data['aud'][0] ?? $data['aud'])) {
            throw new AuthenticationException('OIDC: audience id_token non correspondante.');
        }

        return $data;
    }

    /**
     * @return array<string, \Firebase\JWT\Key>
     */
    private function getKeySet(): array
    {
        if (null === $this->jwks) {
            $jwksUri = $this->discovery['jwks_uri'] ?? null;

            if (!\is_string($jwksUri) || '' === $jwksUri) {
                throw new AuthenticationException('OIDC: jwks_uri introuvable dans la discovery.');
            }

            $response = $this->httpClient->request('GET', $jwksUri);

            try {
                $keys = $response->toArray(false);
            } catch (\Throwable $e) {
                throw new AuthenticationException('OIDC: JWKS illisible.', 0, $e);
            }

            if ([] === $keys) {
                throw new AuthenticationException('OIDC: JWKS vide.');
            }

            $this->jwks = (string) \json_encode($keys);
        }

        return JWK::parseKeySet((array) \json_decode($this->jwks, true));
    }

    private function ensureDiscovery(): void
    {
        if ([] !== ($this->discovery ?? [])) {
            return;
        }

        $response = $this->httpClient->request('GET', rtrim($this->oidcIssuer, '/').'/.well-known/openid-configuration');

        try {
            $discovery = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new AuthenticationException('OIDC: discovery illisible.', 0, $e);
        }

        if ([] === $discovery) {
            throw new AuthenticationException('OIDC: discovery vide pour "'.$this->oidcIssuer.'".');
        }

        $this->discovery = $discovery;
    }
}
