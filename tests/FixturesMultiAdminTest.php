<?php

namespace App\Tests\DataFixtures;

use App\DataFixtures\UserFixtures;
use App\Entity\User;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class FixturesMultiAdminTest extends TestCase
{
    /** @var list<User> */
    private array $persisted = [];

    public function testMultipleAdminsGetDistinctEmails(): void
    {
        $fixtures = $this->fixtures(
            appAdmin: 'alice, bob',
            appAdminEmail: 'alice@example.com, bob@example.net'
        );
        $fixtures->load($this->trackableManager());

        $this->assertCount(2, $this->persisted);
        $this->assertSame('alice@example.com', $this->persisted[0]->getEmail());
        $this->assertSame('bob@example.net', $this->persisted[1]->getEmail());
        $this->assertContains('ROLE_ADMIN', $this->persisted[1]->getRoles());
        $password = $this->persisted[0]->getPassword();

        $this->assertIsString($password);
        $this->assertStringStartsWith('HASHED::', $password);
    }

    public function testMismatchedListsAreRefused(): void
    {
        $fixtures = $this->fixtures('alice, bob', 'alice@example.com');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('même nombre d\'éléments');

        $fixtures->load($this->trackableManager());
    }

    public function testDuplicateEmailIsRefused(): void
    {
        $fixtures = $this->fixtures('alice, bob', 'same@example.com, same@example.com');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('email dupliqué');

        $fixtures->load($this->trackableManager());
    }

    private function fixtures(string $appAdmin, string $appAdminEmail): UserFixtures
    {
        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturnCallback(static fn (User $user, string $raw): string => 'HASHED::'.$raw);

        $fixtures = new UserFixtures($appAdmin, $appAdminEmail, 'not-a-real-secret', $hasher);
        $fixtures->setReferenceRepository($this->createStub(\Doctrine\Common\DataFixtures\ReferenceRepository::class));

        return $fixtures;
    }

    private function trackableManager(): ObjectManager
    {
        $repository = $this->createStub(\App\Repository\UserRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $manager = $this->createStub(ObjectManager::class);
        $manager->method('getRepository')->willReturn($repository);
        $manager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof User) {
                $this->persisted[] = $entity;
            }
        });

        return $manager;
    }
}
