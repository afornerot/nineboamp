<?php

namespace App\Tests\Repository;

use App\Entity\Market;
use App\Entity\MarketChatMessage;
use App\Repository\MarketChatMessageRepository;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * findRecentForMarket() doit renvoyer la fenêtre des messages les plus récents,
 * pas les plus anciens : l'historique du chat était tronqué à la première
 * moitié de la conversation.
 */
class MarketChatMessageRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MarketChatMessageRepository $repo;
    private Market $market;

    protected function setUp(): void
    {
        try {
            static::bootKernel();
            $manager = static::getContainer()->get(ManagerRegistry::class)->getManager();
        } catch (DbalException) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        \assert($manager instanceof EntityManagerInterface);
        $this->em = $manager;
        $this->repo = $this->em->getRepository(MarketChatMessage::class);

        $this->market = new Market();
        $this->market->setIdweb('TEST-'.bin2hex(random_bytes(6)));
        $this->market->setTitle('Marché de test historique');
        $this->em->persist($this->market);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (!isset($this->em) || !$this->em->isOpen()) {
            return;
        }

        $this->em->createQuery('DELETE App\Entity\MarketChatMessage m WHERE m.market = :market')
            ->setParameter('market', $this->market->getId())
            ->execute();
        $this->em->remove($this->em->find(Market::class, $this->market->getId()));
        $this->em->flush();
    }

    public function testReturnsMostRecentMessagesInChronologicalOrder(): void
    {
        for ($i = 1; $i <= 12; ++$i) {
            $this->addMessage(sprintf('message %02d', $i), new \DateTime(sprintf('2026-01-01 10:%02d:00', $i)));
        }

        $messages = $this->repo->findRecentForMarket((int) $this->market->getId(), 5);

        $this->assertSame(
            ['message 08', 'message 09', 'message 10', 'message 11', 'message 12'],
            array_map(fn (MarketChatMessage $m) => $m->getContent(), $messages)
        );
    }

    public function testReturnsWholeConversationWhenLimitIsZero(): void
    {
        for ($i = 1; $i <= 7; ++$i) {
            $this->addMessage(sprintf('message %02d', $i), new \DateTime(sprintf('2026-01-01 10:%02d:00', $i)));
        }

        $messages = $this->repo->findRecentForMarket((int) $this->market->getId(), 0);

        $this->assertCount(7, $messages);
        $this->assertSame('message 01', $messages[0]->getContent());
        $this->assertSame('message 07', $messages[6]->getContent());
    }

    public function testImportantMessagesUseTheSameRecentWindow(): void
    {
        for ($i = 1; $i <= 6; ++$i) {
            $this->addMessage(sprintf('message %02d', $i), new \DateTime(sprintf('2026-01-01 10:%02d:00', $i)), $i % 2 === 0);
        }

        $important = $this->repo->findImportantForMarket((int) $this->market->getId(), 2);

        $this->assertSame(
            ['message 04', 'message 06'],
            array_map(fn (MarketChatMessage $m) => $m->getContent(), $important)
        );
    }

    private function addMessage(string $content, \DateTime $createdAt, bool $important = true): void
    {
        $message = new MarketChatMessage();
        $message->setMarket($this->market);
        $message->setRole('user');
        $message->setContent($content);
        $message->setImportant($important);
        $message->setCreatedAt($createdAt);
        $this->em->persist($message);
        $this->em->flush();
    }
}
