<?php

namespace App\Security\Voter;

use App\Entity\User;
use Bnine\FilesBundle\Security\AbstractFileVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class FileVoter extends AbstractFileVoter
{
    private const PUBLIC_DOMAINS = ['avatar', 'logo', 'icon'];

    protected function canView(string $domain, $id, TokenInterface $token): bool
    {
        if (in_array($domain, self::PUBLIC_DOMAINS, true)) {
            return true;
        }

        return $this->canManage($domain, $id, $token);
    }

    protected function canEdit(string $domain, $id, TokenInterface $token): bool
    {
        return $this->canManage($domain, $id, $token);
    }

    protected function canDelete(string $domain, $id, TokenInterface $token): bool
    {
        return $this->canManage($domain, $id, $token);
    }

    private function canManage(string $domain, $id, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if ($user === null) {
            return false;
        }

        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        if ('avatar' === $domain) {
            return $user instanceof User && (int) $id === $user->getId();
        }

        return in_array('ROLE_USER', $user->getRoles());
    }
}
