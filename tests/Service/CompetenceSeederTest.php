<?php

namespace App\Tests\Service;

use App\Entity\Competence;
use App\Repository\CompetenceRepository;
use App\Service\CompanyContextBuilder;
use App\Service\CompetenceSeeder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class CompetenceSeederTest extends TestCase
{
    public function testSeedIfEmptyCreatesTheDefaultCompetences(): void
    {
        $repository = $this->createStub(CompetenceRepository::class);
        $repository->method('count')->willReturn(0);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $em->expects($this->once())->method('flush');

        $created = (new CompetenceSeeder($repository, $em))->seedIfEmpty();

        $this->assertSame(13, $created);
        $this->assertCount(13, $persisted);
        $this->assertContainsOnlyInstancesOf(Competence::class, $persisted);

        // Le rendu des valeurs par défaut reprend le bloc autrefois en dur dans les prompts.
        $rendered = $this->render($persisted);
        $this->assertStringStartsWith('Cadoles est une SCOP de services informatiques basée à Dijon', $rendered);
        $this->assertStringContainsString("\n\n### Savoir-faire\n- Développement web et logiciel (Symfony, React, Python, Go)\n", $rendered);
        $this->assertStringContainsString("\n\n### Ce que nous pouvons offrir\n- Développement de logiciels sur mesure\n", $rendered);
        $this->assertStringContainsString("\n\n### Ce que nous ne faisons pas\n- Nous ne sommes PAS des géomètres", $rendered);
        $this->assertStringEndsWith('nous ne réalisons pas les prestations de relevé ni de levé de terrain', $rendered);
    }

    public function testSeedIfEmptyLeavesAnExistingTableUntouched(): void
    {
        $repository = $this->createStub(CompetenceRepository::class);
        $repository->method('count')->willReturn(1);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $this->assertSame(0, (new CompetenceSeeder($repository, $em))->seedIfEmpty());
    }

    /**
     * @param Competence[] $competences
     */
    private function render(array $competences): string
    {
        $repository = $this->createStub(CompetenceRepository::class);
        $repository->method('findActiveOrdered')->willReturn($competences);

        return (new CompanyContextBuilder($repository))->build();
    }
}
