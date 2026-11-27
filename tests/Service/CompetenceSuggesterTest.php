<?php

namespace App\Tests\Service;

use App\Entity\Competence;
use App\Entity\Product;
use App\Repository\CompetenceRepository;
use App\Repository\ProductRepository;
use App\Service\AiService;
use App\Service\CompanyContextBuilder;
use App\Service\CompetenceSuggester;
use App\Service\PromptLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CompetenceSuggesterTest extends TestCase
{
    public function testSuggestKeepsOnlyNewWellFormedSuggestions(): void
    {
        $response = json_encode(['suggestions' => [
            ['category' => 'savoir_faire', 'label' => '- Passerelle LLM auto-hébergée', 'reason' => 'Fiche Xolo'],
            ['category' => 'savoir_faire', 'label' => 'formation aux logiciels libres.', 'reason' => 'déjà connue'],
            ['category' => 'presentation', 'label' => 'Cadoles est leader', 'reason' => 'catégorie interdite'],
            ['category' => 'exclusion', 'label' => '', 'reason' => 'libellé vide'],
            ['category' => 'exclusion', 'label' => 'Pas de prestation de relevé', 'reason' => 42],
            ['category' => 'savoir_faire', 'label' => 'Passerelle LLM auto-hébergée', 'reason' => 'doublon'],
            'pas un objet',
        ]], \JSON_THROW_ON_ERROR);

        $suggestions = $this->suggester("```json\n".$response."\n```")->suggest();

        $this->assertSame([
            ['category' => 'savoir_faire', 'label' => 'Passerelle LLM auto-hébergée', 'reason' => 'Fiche Xolo'],
            ['category' => 'exclusion', 'label' => 'Pas de prestation de relevé', 'reason' => ''],
        ], $suggestions);
    }

    public function testSuggestSendsCatalogueAndCurrentCompetencesToTheModel(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->expects($this->once())
            ->method('ask')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('ACTUEL:### Savoir-faire'),
                    $this->stringContains('### xolo'),
                    $this->stringContains('Passerelle LLM souveraine'),
                    $this->stringContains('MAX:8'),
                ),
                'SYSTEME',
            )
            ->willReturn('{"suggestions":[]}');

        $this->assertSame([], $this->suggester(null, $ai)->suggest());
    }

    public function testSuggestRefusesAnEmptyCatalogueWithoutCallingTheModel(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->expects($this->never())->method('ask');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('catalogue produits est vide');

        $this->suggester(null, $ai, [])->suggest();
    }

    public function testSuggestReportsASilentModel(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("L'IA n'a pas répondu");

        $this->suggester('')->suggest();
    }

    public function testSuggestReportsAnUnusableAnswer(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("n'est pas exploitable");

        $this->suggester('Désolé, je ne peux pas.')->suggest();
    }

    /**
     * @param Product[]|null $products
     */
    private function suggester(?string $response, ?AiService $ai = null, ?array $products = null): CompetenceSuggester
    {
        if (null === $ai) {
            $ai = $this->createStub(AiService::class);
            $ai->method('ask')->willReturn((string) $response);
        }

        $loader = $this->createStub(PromptLoader::class);
        $loader->method('renderSystem')->willReturn('SYSTEME');
        $loader->method('renderUser')->willReturnCallback(
            static fn (string $name, array $vars): string => 'ACTUEL:'.$vars['competences'].'|CAT:'.$vars['catalogue'].'|MAX:'.$vars['max'],
        );

        $existing = (new Competence())->setCategory(Competence::CATEGORY_SAVOIR_FAIRE)->setLabel('Formation aux logiciels libres');
        $competences = $this->createStub(CompetenceRepository::class);
        $competences->method('findAll')->willReturn([$existing]);
        $competences->method('findActiveOrdered')->willReturn([$existing]);

        $productRepository = $this->createStub(ProductRepository::class);
        $productRepository->method('findAll')->willReturn($products ?? [
            (new Product())->setName('xolo')->setDescription('Passerelle LLM souveraine')->setFiche('# Xolo'),
        ]);

        return new CompetenceSuggester($ai, $loader, new CompanyContextBuilder($competences), $competences, $productRepository, new NullLogger());
    }
}
