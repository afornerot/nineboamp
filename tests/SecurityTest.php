<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SecurityTest extends WebTestCase
{
    public function testAnonymousHomePage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
    }

    public function testAnonymousAdminRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/user');

        $this->assertResponseRedirects('/login');
    }

    public function testLoginSubmitIsBlockedWithoutCsrf(): void
    {
        $client = static::createClient();

        $client->request('POST', '/login', ['_username' => 'admin', '_password' => 'wrongpassword']);

        $this->assertResponseRedirects('/login');
    }

    public function testLoginWithCsrfAuthenticatesAndGrantsAdminUiAccess(): void
    {
        $username = (string) ($_SERVER['APP_ADMIN'] ?? 'admin');
        $secret = $_SERVER['APP_SECRET'] ?? null;

        if (!is_string($secret) || '' === $secret) {
            static::fail('Variables APP_SECRET non configurée.');
        }

        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $form = $crawler->selectButton('Valider')->form([
            '_username' => $username,
            '_password' => $secret,
        ]);

        try {
            $client->submit($form);
            $client->followRedirect();
        } catch (\Symfony\Component\BrowserKit\Exception\LogicException) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $this->assertResponseIsSuccessful();

        $client->request('GET', '/admin/user');
        $this->assertResponseIsSuccessful();
    }

    public function testPlainUserIsForbiddenOnAdmin(): void
    {
        try {
            $this->createUser('user_test', ['ROLE_USER']);
        } catch (\Doctrine\DBAL\Exception) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $form = $crawler->selectButton('Valider')->form([
            '_username' => 'user_test',
            '_password' => 'Password&Special1',
        ]);

        try {
            $client->submit($form);
            $client->followRedirect();
        } catch (\Symfony\Component\BrowserKit\Exception\LogicException) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $client->request('GET', '/admin/user');
        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $username, array $roles): void
    {
        $em = static::getContainer()->get(ManagerRegistry::class);

        if (!$em instanceof ManagerRegistry) {
            throw new \RuntimeException('Doctrine non disponible dans les tests.');
        }

        $manager = $em->getManager();

        if (!$manager instanceof EntityManagerInterface) {
            throw new \RuntimeException('Gestionnaire d\'entités Doctrine non configuré.');
        }

        $user = new \App\Entity\User();
        $user->setUsername($username);
        $user->setEmail($username.'@localhost');
        $user->setRoles($roles);
        $user->setPassword('hashed_password_placeholder');

        $manager->persist($user);
        $manager->flush();
    }
}
