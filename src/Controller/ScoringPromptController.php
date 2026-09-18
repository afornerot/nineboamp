<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Service\PromptLoader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ScoringPromptController extends AbstractController
{
    use LayoutRenderTrait;

    public function __construct(
        private PromptLoader $promptLoader,
    ) {
    }

    #[Route('/admin/scoring-prompt', name: 'app_admin_scoring_prompt', methods: ['GET'])]
    #[Route('/admin/scoring-prompt/{sourceFile}', name: 'app_admin_scoring_prompt_view', methods: ['GET'], requirements: ['sourceFile' => '[A-Za-z0-9_.-]+'])]
    public function view(?string $sourceFile): Response
    {
        $promptsDir = $this->promptLoader->getPromptsDirectory();
        $files = glob($promptsDir . '/*.md') ?: [];

        $prompts = array_map(fn ($f) => [
            'name' => basename($f, '.md'),
            'file' => $f,
        ], $files);

        $selectedName = $sourceFile ?: ($prompts[0]['name'] ?? null);
        $selectedContent = $selectedName ? $this->promptLoader->load($selectedName)?->getBody() : null;

        return $this->renderLayout('scoring_prompt/view.html.twig', 'Prompts de scoring BOAMP', [
            'prompts' => $prompts,
            'selectedName' => $selectedName,
            'selectedContent' => $selectedContent,
        ]);
    }
}
