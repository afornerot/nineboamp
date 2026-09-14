<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<McpSystemUser>
 */
class McpSystemUserProvider implements UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if ('mcp-system' !== $identifier) {
            throw new UserNotFoundException();
        }

        return new McpSystemUser();
    }

    public function refreshUser(UserInterface $user): McpSystemUser
    {
        if (!$user instanceof McpSystemUser) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', get_class($user)));
        }

        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return McpSystemUser::class === $class;
    }
}
