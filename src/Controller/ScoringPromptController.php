<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\ScoringPrompt;
use App\Form\ScoringPromptType;
use App\Repository\ScoringPromptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ScoringPromptController extends AbstractController
{
    use LayoutRenderTrait;

    #[Route('/admin/scoring-prompt', name: 'app_admin_scoring_prompt', defaults: ['sourceFile' => null])]
    #[Route('/admin/scoring-prompt/{sourceFile}', name: 'app_admin_scoring_prompt_edit', requirements: ['sourceFile' => '[A-Za-z0-9_.-]+'])]
    public function edit(?string $sourceFile, Request $request, ScoringPromptRepository $repository, EntityManagerInterface $em): Response
    {
        $prompts = $repository->findBy([], ['sourceFile' => 'ASC']);
        if ([] === $prompts) {
            $prompts = [$this->createFallbackPrompt()];
        }

        $selected = $sourceFile
            ? ($repository->findOneBy(['sourceFile' => $sourceFile]) ?? $prompts[0])
            : $prompts[0];

        $form = $this->createForm(ScoringPromptType::class, $selected);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($selected);
            $em->flush();

            $this->addFlash('success', sprintf('Prompt "%s" enregistré.', $selected->getSourceFile() ?? 'defaut'));

            return $this->redirectToRoute('app_admin_scoring_prompt_edit', ['sourceFile' => $selected->getSourceFile()]);
        }

        return $this->renderLayout('scoring_prompt/edit.html.twig', 'Prompts de scoring BOAMP', [
            'routecancel' => 'app_admin_scoring_prompt',
            'form' => $form->createView(),
            'prompts' => $prompts,
            'selected' => $selected,
        ]);
    }

    private function createFallbackPrompt(): ScoringPrompt
    {
        $prompt = new ScoringPrompt();
        $prompt->setSourceFile('prompt');
        $prompt->setRole('Tu es un expert en qualification de marchés publics français.');

        return $prompt;
    }
}
