<?php

namespace App\Security\Voter;

use Bnine\FilesBundle\Security\AbstractFileVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class FileVoter extends AbstractFileVoter
{
    private const PUBLIC_DOMAINS = ['avatar', 'logo'];

    protected function canView(string $domain, $id, TokenInterface $token): bool
    {
        $user = $token->getUser();
        return $user !== null && in_array('ROLE_USER', $user->getRoles());
    }

    protected function canEdit(string $domain, $id, TokenInterface $token): bool
    {
        $user = $token->getUser();
        return $user !== null && in_array('ROLE_USER', $user->getRoles());
    }

    protected function canDelete(string $domain, $id, TokenInterface $token): bool
    {
        $user = $token->getUser();
        return $user !== null && in_array('ROLE_USER', $user->getRoles());
    }
}
