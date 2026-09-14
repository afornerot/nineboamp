<?php

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

class McpSystemUser implements UserInterface
{
    public function getUserIdentifier(): string
    {
        return 'mcp-system';
    }

    public function getRoles(): array
    {
        return ['ROLE_MCP'];
    }

    public function eraseCredentials(): void
    {
    }
}
