<?php

namespace App\Tests\Service;

use App\Service\AiService;
use App\Service\ProductSheetGenerator;
use App\Service\PromptLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ProductSheetGeneratorTest extends TestCase
{
    private const ANSWER = <<<'TXT'
        ===DESCRIPTION===
        "Passerelle LLM auto-hébergée
        qui centralise les accès."
        ===MOTS-CLES===
        passerelle LLM, IA générative; Passerelle LLM
        - anonymisation
        un mot-clé beaucoup trop long pour être retenu
        ===SECTEURS===
        Collectivités, Santé
        ===FICHE===
        # Xolo

        ## En bref
        Une passerelle.

        ## Ce que le produit ne fait pas
        À compléter : limites du produit.
        TXT;

    public function testGenerateSplitsTheAnswerIntoFormFields(): void
    {
        $result = $this->generator(self::ANSWER)->generate(['name' => 'xolo', 'fiche' => '# Ancienne fiche']);

        $this->assertSame('Passerelle LLM auto-hébergée qui centralise les accès.', $result['description']);
        $this->assertSame('passerelle LLM, IA générative, anonymisation', $result['keywords']);
        $this->assertSame('Collectivités, Santé', $result['sectors']);
        $this->assertStringStartsWith('# Xolo', $result['fiche']);
        $this->assertStringEndsWith('À compléter : limites du produit.', $result['fiche']);
        $this->assertStringNotContainsString('===', $result['fiche']);
    }

    public function testGenerateUnwrapsAFicheReturnedInACodeBlock(): void
    {
        $answer = "===DESCRIPTION===\nd\n===MOTS-CLES===\nk\n===SECTEURS===\ns\n=== FICHE ===\n```markdown\n# Xolo\n\nTexte\n```\n";

        $this->assertSame("# Xolo\n\nTexte", $this->generator($answer)->generate(['name' => 'xolo', 'fiche' => 'x'])['fiche']);
    }

    public function testGenerateSendsFormContentAndInstructionToTheModel(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->expects($this->once())
            ->method('ask')
            ->with(
                'NOM:xolo|DESC:(aucun)|MC:llm|SEC:(aucun)|CONSIGNE:trop commercial|FICHE:# Brouillon',
                'SYSTEME',
                $this->anything(),
                $this->anything(),
                180,
            )
            ->willReturn(self::ANSWER);

        $this->generator(null, $ai)->generate(['name' => ' xolo ', 'keywords' => 'llm', 'fiche' => '# Brouillon'], ' trop commercial ');
    }

    public function testGenerateBoundsTheFicheAndTheInstructionSentToTheModel(): void
    {
        $sent = '';
        $ai = $this->createStub(AiService::class);
        $ai->method('ask')->willReturnCallback(function (string $prompt) use (&$sent): string {
            $sent = $prompt;

            return self::ANSWER;
        });

        $this->generator(null, $ai)->generate(['name' => 'xolo', 'fiche' => str_repeat('f', 50000)], str_repeat('z', 5000));

        $this->assertSame(ProductSheetGenerator::MAX_FICHE_INPUT_LENGTH, substr_count($sent, 'f'));
        $this->assertSame(ProductSheetGenerator::MAX_INSTRUCTION_LENGTH, substr_count($sent, 'z'));
    }

    public function testGenerateNeedsANameAndSomethingToRewrite(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->expects($this->never())->method('ask');
        $generator = $this->generator(null, $ai);

        foreach ([
            [['fiche' => '# Fiche'], '', 'nom du produit'],
            [['name' => 'xolo', 'fiche' => "  \n"], '', 'Rien à réécrire'],
        ] as [$current, $instruction, $expected]) {
            try {
                $generator->generate($current, $instruction);
                $this->fail('génération attendue en échec : '.$expected);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function testAnInstructionAloneIsEnoughToStartFromAnEmptySheet(): void
    {
        $result = $this->generator(self::ANSWER)->generate(['name' => 'xolo'], 'Passerelle LLM auto-hébergée');

        $this->assertNotSame('', $result['fiche']);
    }

    public function testGenerateReportsASilentModel(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("L'IA n'a pas répondu");

        $this->generator('')->generate(['name' => 'xolo', 'fiche' => '# Fiche']);
    }

    public function testGenerateReportsAnAnswerWithoutSheet(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("n'est pas exploitable");

        $this->generator("Voici la fiche demandée :\n# Xolo")->generate(['name' => 'xolo', 'fiche' => '# Fiche']);
    }

    private function generator(?string $response, ?AiService $ai = null): ProductSheetGenerator
    {
        if (null === $ai) {
            $ai = $this->createStub(AiService::class);
            $ai->method('ask')->willReturn((string) $response);
        }

        $loader = $this->createStub(PromptLoader::class);
        $loader->method('renderSystem')->willReturn('SYSTEME');
        $loader->method('renderUser')->willReturnCallback(
            static fn (string $name, array $vars): string => sprintf('NOM:%s|DESC:%s|MC:%s|SEC:%s|CONSIGNE:%s|FICHE:%s', $vars['name'], $vars['description'], $vars['keywords'], $vars['sectors'], $vars['instruction'], $vars['fiche']),
        );

        return new ProductSheetGenerator($ai, $loader, new NullLogger());
    }
}
