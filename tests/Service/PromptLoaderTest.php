<?php

namespace App\Tests\Service;

use App\Service\PromptLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PromptLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/prompt_loader_test_'.uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (scandir($this->tempDir) as $item) {
                if ('.' === $item || '..' === $item) {
                    continue;
                }
                @unlink($this->tempDir.'/'.$item);
            }
            @rmdir($this->tempDir);
        }
    }

    public function testLoadExtractSystemPrompt(): void
    {
        $this->writePrompt('extract.system.md', "---\npurpose: system\ntemperature: 0.0\n---\nSystem extract body.");
        $loader = $this->buildLoader();

        $prompt = $loader->load('extract.system');

        $this->assertNotNull($prompt);
        $this->assertSame('extract.system', $prompt->getName());
        $this->assertSame('System extract body.', $prompt->getBody());
        $this->assertSame('system', $prompt->getMetadataValue('purpose'));
        $this->assertSame(0.0, $prompt->getTemperature());
    }

    public function testLoadScoringRolePrompt(): void
    {
        $this->writePrompt('scoring.role.md', "---\npurpose: system\nvariant: scoring\n---\nTu es un expert BOAMP.");
        $loader = $this->buildLoader();

        $prompt = $loader->load('scoring.role');

        $this->assertNotNull($prompt);
        $this->assertStringContainsString('expert BOAMP', $prompt->getBody());
        $this->assertSame('scoring', $prompt->getMetadataValue('variant'));
    }

    public function testRenderReplacesPlaceholders(): void
    {
        $this->writePrompt('scoring.user.md', "---\n---\nIDWEB: {{idweb}}\nTitre: {{title}}\n");
        $loader = $this->buildLoader();

        $result = $loader->renderUser('scoring.user', [
            'idweb' => '26-12345',
            'title' => 'Audit informatique',
        ]);

        $this->assertStringContainsString('IDWEB: 26-12345', $result);
        $this->assertStringContainsString('Titre: Audit informatique', $result);
        $this->assertStringNotContainsString('{{', $result);
    }

    public function testMissingFileReturnsNull(): void
    {
        $loader = $this->buildLoader();

        $this->assertNull($loader->load('nonexistent'));
    }

    public function testFrontMatterParsedCorrectly(): void
    {
        $this->writePrompt('test.md', "---\npurpose: user\nmax_tokens: 2048\ntemperature: 0.5\ncustom_key: valeur\n---\nLe corps.");
        $loader = $this->buildLoader();

        $prompt = $loader->load('test');

        $this->assertNotNull($prompt);
        $this->assertSame('Le corps.', $prompt->getBody());
        $this->assertSame('user', $prompt->getMetadataValue('purpose'));
        $this->assertSame(2048, $prompt->getMaxTokens());
        $this->assertSame(0.5, $prompt->getTemperature());
        $this->assertSame('valeur', $prompt->getMetadataValue('custom_key'));
    }

    public function testRenderSystemWithoutFrontMatter(): void
    {
        $this->writePrompt('simple.md', "Pas de frontmatter ici.");
        $loader = $this->buildLoader();

        $prompt = $loader->load('simple');
        $this->assertNotNull($prompt);
        $this->assertSame('Pas de frontmatter ici.', $prompt->getBody());
        $this->assertSame([], $prompt->getMetadata());

        $rendered = $loader->renderSystem('simple');
        $this->assertSame('Pas de frontmatter ici.', $rendered);
    }

    public function testInvalidYamlFallsBackToRaw(): void
    {
        $this->writePrompt('broken.md', "---\n:not valid yaml: [unclosed\n---\nContenu");
        $loader = $this->buildLoader();

        $prompt = $loader->load('broken');

        $this->assertNotNull($prompt);
        $this->assertStringContainsString('Contenu', $prompt->getBody());
    }

    private function writePrompt(string $filename, string $content): void
    {
        $path = $this->tempDir.'/'.$filename;
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    private function buildLoader(): PromptLoader
    {
        return new PromptLoader($this->createMock(LoggerInterface::class), $this->tempDir);
    }
}
