<?php

namespace App\Tests\Security;

use App\Security\McpAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

class McpAuthenticatorTest extends TestCase
{
    public function testEmptyTokenIsRefused(): void
    {
        $this->expectException(AuthenticationException::class);

        $request = new Request([], [], [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ']);
        (new McpAuthenticator('test-secret'))->authenticate($request);
    }

    public function testValidTokenIsAccepted(): void
    {
        $request = new Request([], [], [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer test-secret']);
        $passport = (new McpAuthenticator('test-secret'))->authenticate($request);

        $badges = $passport->getBadges();

        $this->assertCount(1, $badges);
        $this->assertInstanceOf(UserBadge::class, reset($badges));
        $this->assertSame('mcp-system', reset($badges)->getUserIdentifier());
    }

    public function testInvalidTokenIsRefused(): void
    {
        $this->expectException(AuthenticationException::class);

        $request = new Request([], [], [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer wrong-token']);
        (new McpAuthenticator('test-secret'))->authenticate($request);
    }

    public function testMissingHeaderIsRefused(): void
    {
        $this->expectException(AuthenticationException::class);

        (new McpAuthenticator('test-secret'))->authenticate(new Request());
    }

    public function testNonBearerHeaderIsRefused(): void
    {
        $this->expectException(AuthenticationException::class);

        $request = new Request([], [], [], [], [], ['HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz']);
        (new McpAuthenticator('test-secret'))->authenticate($request);
    }

    public function testSupportsOnlyMcpPaths(): void
    {
        $authenticator = new McpAuthenticator('test-secret');

        $this->assertTrue($authenticator->supports(new Request([], [], [], [], [], ['REQUEST_URI' => '/mcp'])));
        $this->assertFalse($authenticator->supports(new Request([], [], [], [], [], ['REQUEST_URI' => '/other'])));
    }
}
