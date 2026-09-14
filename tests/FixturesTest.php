<?php

namespace App\Tests;

use App\Entity\Cron;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class FixturesTest extends KernelTestCase
{
    public function testInitLoadsAdminUserAndCronEntries(): void
    {
        try {
            $kernel = static::bootKernel();
            $manager = $this->entityManager();

            $application = new Application($kernel);
            $command = $application->find('doctrine:fixtures:load');
            $fixturesInput = new ArrayInput(['--append' => true]);
            $fixturesInput->setInteractive(false);
            $command->run($fixturesInput, new NullOutput());
        } catch (\Doctrine\DBAL\Exception) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $admin = $manager->getRepository(User::class)->findOneBy(['username' => 'admin']);
        $this->assertNotNull($admin);
        $this->assertContains('ROLE_ADMIN', $admin->getRoles());

        $cron = $manager->getRepository(Cron::class)->findOneBy(['command' => 'app:bonjour']);
        $this->assertNotNull($cron);
        $this->assertSame(Cron::STATUT_DISABLED, $cron->getStatut());
        $this->assertSame(60, $cron->getRepeatinterval());
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
