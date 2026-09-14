<?php

namespace App\Security;

use Bnine\FilesBundle\Security\AbstractFileVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class FileVoter extends AbstractFileVoter
{
    private const PUBLIC_DOMAINS = ['avatar', 'logo'];

    /**
     * @param int|string $id
     */
    protected function canView(string $domain, $id, TokenInterface $token): bool
    {
        if (in_array($domain, self::PUBLIC_DOMAINS)) {
            return true;
        }

        return $this->canManage($domain, $id, $token);
    }

    /**
     * @param int|string $id
     */
    protected function canEdit(string $domain, $id, TokenInterface $token): bool
    {
        return $this->canManage($domain, $id, $token);
    }

    /**
     * @param int|string $id
     */
    protected function canDelete(string $domain, $id, TokenInterface $token): bool
    {
        return $this->canManage($domain, $id, $token);
    }

    /**
     * @param int|string $id
     */
    private function canManage(string $domain, $id, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user) {
            return false;
        }

        return in_array('ROLE_ADMIN', $user->getRoles());
    }
}
