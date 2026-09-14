<?php

namespace App\Tests;

use App\Entity\Cron;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DeleteCsrfTest extends WebTestCase
{
    public function testDeleteRouteRejectsGetRequests(): void
    {
        $client = static::createClient();

        foreach (['/admin/cron/delete/999', '/admin/user/delete/999', '/admin/groupe/delete/999'] as $path) {
            $client->request('GET', $path);

            $this->assertResponseStatusCodeSame(405, 'Les routes de suppression n\'acceptent que POST ('.$path.').');
        }
    }

    public function testDeleteWithoutCsrfTokenIsRefused(): void
    {
        $client = static::createClient();
        $this->adminLogin($client);

        $em = $this->entityManager();
        $crons = $em->getRepository(Cron::class)->findAll();

        if ([] === $crons) {
            static::markTestSkipped('Aucun cron en base pour tester la suppression.');
        }

        $id = $crons[0]->getId();

        $client->request('POST', '/admin/cron/delete/'.$id, []);

        $this->assertResponseRedirects(null, 302, 'Aucune suppression sans token CSRF.');
        $this->assertNotNull($em->getRepository(Cron::class)->find($id), 'L\'entité doit toujours exister.');
    }

    private function adminLogin(KernelBrowser $client): void
    {
        $username = $_SERVER['APP_ADMIN'] ?? 'admin';
        $secret = static::getContainer()->getParameter('appSecret');

        if (!is_string($secret) || '' === $secret) {
            static::fail('Paramètre appSecret non configuré.');
        }

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
    }

    private function entityManager(): EntityManagerInterface
    {
        $registry = static::getContainer()->get(ManagerRegistry::class);

        if (!$registry instanceof ManagerRegistry) {
            throw new \RuntimeException('Doctrine non disponible dans les tests.');
        }

        $manager = $registry->getManager();

        if (!$manager instanceof EntityManagerInterface) {
            throw new \RuntimeException('Gestionnaire d\'entités Doctrine non configuré.');
        }

        return $manager;
    }
}
