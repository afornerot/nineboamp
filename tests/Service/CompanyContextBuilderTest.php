<?php

namespace App\Tests\Service;

use App\Entity\Competence;
use App\Repository\CompetenceRepository;
use App\Service\CompanyContextBuilder;
use PHPUnit\Framework\TestCase;

class CompanyContextBuilderTest extends TestCase
{
    public function testBuildGroupsCompetencesByCategoryInPromptOrder(): void
    {
        // Ordre du dépôt volontairement mélangé : le rendu suit Competence::CATEGORIES.
        $builder = $this->builder([
            $this->competence(Competence::CATEGORY_EXCLUSION, 'Pas de génie civil'),
            $this->competence(Competence::CATEGORY_SAVOIR_FAIRE, 'Développement web'),
            $this->competence(Competence::CATEGORY_PRESENTATION, 'Cadoles est une SCOP.'),
            $this->competence(Competence::CATEGORY_SAVOIR_FAIRE, 'Hébergement'),
        ]);

        $this->assertSame(
            "Cadoles est une SCOP.\n\n"
            ."### Savoir-faire\n- Développement web\n- Hébergement\n\n"
            ."### Ce que nous ne faisons pas\n- Pas de génie civil",
            $builder->build(),
        );
    }

    public function testBuildSkipsEmptyCategoriesAndBlankLabels(): void
    {
        $builder = $this->builder([
            $this->competence(Competence::CATEGORY_OFFRE, '   '),
            $this->competence(Competence::CATEGORY_SAVOIR_FAIRE, ' Formation '),
        ]);

        $this->assertSame("### Savoir-faire\n- Formation", $builder->build());
    }

    public function testBuildKeepsEachLabelOnOneLine(): void
    {
        // Saisi dans un textarea : sans aplatissement, la 2e ligne sortirait de
        // la puce et « ### … » serait lu comme un titre de catégorie.
        $builder = $this->builder([
            $this->competence(Competence::CATEGORY_EXCLUSION, "Pas de génie civil\r\n### Savoir-faire\n- Travaux publics"),
        ]);

        $this->assertSame("### Ce que nous ne faisons pas\n- Pas de génie civil ### Savoir-faire - Travaux publics", $builder->build());
    }

    public function testBuildWithoutCompetenceSaysSoInsteadOfReturningAnEmptyString(): void
    {
        $this->assertSame("Compétences de l'entreprise non renseignées.", $this->builder([])->build());
    }

    public function testApplyReplacesThePlaceholder(): void
    {
        $builder = $this->builder([$this->competence(Competence::CATEGORY_SAVOIR_FAIRE, 'Formation')]);

        $this->assertSame(
            "## Contexte\n### Savoir-faire\n- Formation\nSuite",
            $builder->apply("## Contexte\n{{competences}}\nSuite"),
        );
    }

    public function testApplyDoesNotQueryWhenThePromptHasNoPlaceholder(): void
    {
        $repository = $this->createMock(CompetenceRepository::class);
        $repository->expects($this->never())->method('findActiveOrdered');

        $this->assertSame('Prompt sans placeholder', (new CompanyContextBuilder($repository))->apply('Prompt sans placeholder'));
    }

    /**
     * @param Competence[] $competences
     */
    private function builder(array $competences): CompanyContextBuilder
    {
        $repository = $this->createStub(CompetenceRepository::class);
        $repository->method('findActiveOrdered')->willReturn($competences);

        return new CompanyContextBuilder($repository);
    }

    private function competence(string $category, string $label): Competence
    {
        return (new Competence())->setCategory($category)->setLabel($label);
    }
}
