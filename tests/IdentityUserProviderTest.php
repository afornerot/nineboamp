<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\IdentityUserProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

class IdentityUserProviderTest extends TestCase
{
    private int $flushCount = 0;

    protected function setUp(): void
    {
        $this->flushCount = 0;
    }

    public function testNewProvisionedUserHasHashedPasswordAndRoleUser(): void
    {
        $provider = $this->provider(byUsername: null, byEmail: null, hashing: true);

        $user = $provider->loadUserByIdentifierAndAttributes('new-user', $this->attributes(), $this->mapping());

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(1, $this->flushCount, 'Un seul flush pour la création.');
        $this->assertIsString($user->getPassword());
        $this->assertStringStartsWith('HASHED::', $user->getPassword(), 'Password provient de hashPassword().');
        $this->assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testFlushIsSkippedWhenNothingChanged(): void
    {
        $existing = (new User())->setUsername('new-user');
        $existing->setEmail('jdoe@oidc.test');
        $existing->setFirstname('John');
        $existing->setLastname('Doe');

        $provider = $this->provider(byUsername: $existing, byEmail: $existing, hashing: true);

        $user = $provider->loadUserByIdentifierAndAttributes('new-user', $this->attributes(), $this->mapping());

        $this->assertSame(0, $this->flushCount, 'Aucun flush si aucune valeur diffère.');
        $this->assertSame($existing, $user);
    }

    public function testCollidingEmailOnCreateThrowsAuthenticationException(): void
    {
        $provider = $this->provider(byUsername: null, byEmail: new User(), hashing: true);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('est déjà utilisé par un autre compte');

        $provider->loadUserByIdentifierAndAttributes('new-user', $this->attributes('taken@localtest'), $this->mapping());
    }

    public function testMissingEmailClaimIsRefused(): void
    {
        $provider = $this->provider(byUsername: null, byEmail: null, hashing: true);

        $this->expectException(UserNotFoundException::class);

        $provider->loadUserByIdentifierAndAttributes('any-user', [], $this->mapping());
    }

    private function provider(?User $byUsername, ?User $byEmail, bool $hashing): IdentityUserProvider
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?User => array_key_exists('email', $criteria) ? $byEmail : $byUsername
        );

        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturnCallback(static fn (User $user, string $raw): string => 'HASHED::'.$raw);

        return new IdentityUserProvider($repository, $this->trackableEm(), $hasher);
    }

    private function trackableEm(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (?object $entity): void {
        });
        $em->method('flush')->willReturnCallback(function (): void {
            ++$this->flushCount;
        });

        return $em;
    }

    /** @return array<string, string> */
    private function attributes(string $email = 'jdoe@oidc.test'): array
    {
        return ['mail' => $email, 'given_name' => 'John', 'family_name' => 'Doe'];
    }

    /** @return array{mail: string, lastname: string, firstname: string} */
    private function mapping(): array
    {
        return ['mail' => 'mail', 'lastname' => 'family_name', 'firstname' => 'given_name'];
    }
}
