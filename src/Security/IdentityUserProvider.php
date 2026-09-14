<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<User>
 */
class IdentityUserProvider implements UserProviderInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->userRepository->findOneBy(['username' => $identifier]);

        if (!$user) {
            throw new UserNotFoundException(sprintf('User with username "%s" not found.', $identifier));
        }

        return $user;
    }

    /**
     * @param array<string, string>                                    $attributes
     * @param array{mail: string, lastname: string, firstname: string} $mapping
     */
    public function loadUserByIdentifierAndAttributes(string $identifier, array $attributes, array $mapping): UserInterface
    {
        $email = $attributes[$mapping['mail']] ?? '';
        if ('' === $email) {
            throw new UserNotFoundException(sprintf('Attribut email "%s" manquant pour l\'utilisateur "%s". Vérifier la configuration du provider d\'identité.', $mapping['mail'], $identifier));
        }

        $user = $this->userRepository->findOneBy(['username' => $identifier]);

        $isNew = null === $user;
        if ($isNew) {
            // L'email doit être libre : pas de collision possible avec un
            // compte existant (500 Doctrine) lors de la création.
            $existing = $this->userRepository->findOneBy(['email' => $email]);
            if (null !== $existing) {
                throw new AuthenticationException(sprintf('Authentification refusée : l\'email "%s" est déjà utilisé par un autre compte.', $email));
            }

            $user = new User();
            $user->setUsername($identifier);
            $user->setPassword(
                $this->passwordHasher->hashPassword($user, Uuid::uuid4()->toString())
            );
            $user->setRoles(['ROLE_USER']);
        }

        $snapshot = [
            'email' => $user->getEmail(),
            'lastname' => $user->getLastname(),
            'firstname' => $user->getFirstname(),
        ];

        if (!$isNew && $snapshot['email'] !== $email) {
            $existing = $this->userRepository->findOneBy(['email' => $email]);
            if (null !== $existing && $existing->getId() !== $user->getId()) {
                throw new AuthenticationException(sprintf('Authentification refusée : l\'email "%s" est déjà utilisé par un autre compte.', $email));
            }
        }

        $firstname = $attributes[$mapping['firstname']] ?? null;
        $user->setFirstname(is_string($firstname) ? $firstname : null);
        $user->setEmail($email);
        $lastname = $attributes[$mapping['lastname']] ?? null;
        $user->setLastname(is_string($lastname) ? $lastname : null);

        $dirty = $snapshot !== [
            'email' => $user->getEmail(),
            'lastname' => $user->getLastname(),
            'firstname' => $user->getFirstname(),
        ];

        if ($isNew || $dirty) {
            $this->em->persist($user);
            $this->em->flush();
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', get_class($user)));
        }

        $refreshed = $this->userRepository->find($user->getId());
        if (!$refreshed) {
            throw new UserNotFoundException(sprintf('User with id "%s" not found.', $user->getId()));
        }

        return $refreshed;
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class;
    }
}
