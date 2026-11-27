<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Competence;
use App\Form\CompetenceType;
use App\Repository\CompetenceRepository;
use App\Service\CompanyContextBuilder;
use App\Service\CompetenceSuggester;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CompetenceController extends AbstractController
{
    use CrudDeleteTrait;
    use LayoutRenderTrait;

    private const SESSION_SUGGESTIONS = 'competence_suggestions';

    public function __construct(
        private CompetenceRepository $competenceRepository,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/competence', name: 'app_admin_competence')]
    public function list(Request $request, CompanyContextBuilder $contextBuilder, CompetenceSuggester $suggester): Response
    {
        // Une suggestion ajoutée entre-temps (à la main, depuis un autre onglet)
        // ne doit plus être proposée : l'accepter créerait un doublon.
        $session = $request->getSession();
        $suggestions = $session->get(self::SESSION_SUGGESTIONS);
        if (is_array($suggestions) && [] !== $suggestions) {
            $suggestions = $suggester->withoutKnown($suggestions);
            $session->set(self::SESSION_SUGGESTIONS, $suggestions);
        }

        return $this->renderLayout('competence/list.html.twig', 'Compétences de l\'entreprise', [
            'routesubmit' => 'app_admin_competence_submit',
            'routeupdate' => 'app_admin_competence_update',
            'competences' => $this->competenceRepository->findBy([], ['position' => 'ASC', 'id' => 'ASC']),
            'categories' => Competence::CATEGORIES,
            'preview' => $contextBuilder->build(),
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Demande à l'IA des compétences à ajouter d'après le catalogue produits.
     * Rien n'est enregistré en base : les propositions sont gardées en session
     * pour rester affichées pendant qu'on les ajoute une à une.
     */
    #[Route('/admin/competence/suggest', name: 'app_admin_competence_suggest', methods: ['POST'])]
    public function suggest(Request $request, CompetenceSuggester $suggester): Response
    {
        if (!$this->isCsrfTokenValid('suggest-competence', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token CSRF invalide — suggestion non lancée.');

            return $this->redirectToRoute('app_admin_competence');
        }

        // Libérer le verrou de session pendant l'appel LLM, pour ne pas bloquer
        // les autres requêtes de l'utilisateur ; la session se rouvre d'elle-même
        // à la prochaine écriture (suggestions, message flash).
        $request->getSession()->save();
        set_time_limit(240);

        try {
            $suggestions = [];
            foreach ($suggester->suggest() as $suggestion) {
                $suggestions[md5($suggestion['label'])] = $suggestion;
            }
            $request->getSession()->set(self::SESSION_SUGGESTIONS, $suggestions);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_competence');
    }

    /**
     * Écarte une suggestion (paramètre key) ou toutes (sans paramètre).
     */
    #[Route('/admin/competence/suggest/dismiss', name: 'app_admin_competence_suggest_dismiss', methods: ['POST'])]
    public function dismissSuggestion(Request $request): Response
    {
        if ($this->isCsrfTokenValid('suggest-competence', (string) $request->request->get('_csrf_token'))) {
            $key = (string) $request->request->get('key', '');
            if ('' === $key) {
                $request->getSession()->remove(self::SESSION_SUGGESTIONS);
            } else {
                $this->forgetSuggestion($request, $key);
            }
        }

        return $this->redirectToRoute('app_admin_competence');
    }

    private function forgetSuggestion(Request $request, string $key): void
    {
        $suggestions = $request->getSession()->get(self::SESSION_SUGGESTIONS);
        if (is_array($suggestions)) {
            unset($suggestions[$key]);
            $request->getSession()->set(self::SESSION_SUGGESTIONS, $suggestions);
        }
    }

    #[Route('/admin/competence/submit', name: 'app_admin_competence_submit')]
    public function submit(Request $request): Response
    {
        $competence = new Competence();
        $category = (string) $request->query->get('category', '');
        if (isset(Competence::CATEGORIES[$category])) {
            $competence->setCategory($category);
        }

        // Ajout depuis une suggestion de l'IA : formulaire prérempli, à relire avant de valider.
        $suggestionKey = (string) $request->query->get('suggestion', '');
        $suggestion = $request->getSession()->get(self::SESSION_SUGGESTIONS)[$suggestionKey] ?? null;
        if (is_array($suggestion)) {
            $competence->setCategory($suggestion['category']);
            $competence->setLabel($suggestion['label']);
        }
        $competence->setPosition($this->competenceRepository->nextPosition($competence->getCategory()));

        $form = $this->createForm(CompetenceType::class, $competence, ['mode' => 'submit']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($competence);
            $this->em->flush();
            $this->forgetSuggestion($request, $suggestionKey);

            return $this->redirectToRoute('app_admin_competence');
        }

        return $this->renderLayout('competence/edit.html.twig', 'Création Compétence', [
            'routecancel' => 'app_admin_competence',
            'mode' => 'submit',
            'form' => $form,
        ]);
    }

    #[Route('/admin/competence/update/{id}', name: 'app_admin_competence_update')]
    public function update(int $id, Request $request): Response
    {
        $competence = $this->competenceRepository->find($id);
        if (!$competence) {
            return $this->redirectToRoute('app_admin_competence');
        }

        $form = $this->createForm(CompetenceType::class, $competence, ['mode' => 'update']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            return $this->redirectToRoute('app_admin_competence');
        }

        return $this->renderLayout('competence/edit.html.twig', 'Modification Compétence', [
            'routecancel' => 'app_admin_competence',
            'routedelete' => 'app_admin_competence_delete',
            'mode' => 'update',
            'form' => $form,
        ]);
    }

    #[Route('/admin/competence/delete/{id}', name: 'app_admin_competence_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $competence = $this->competenceRepository->find($id);
        if (!$competence) {
            return $this->redirectToRoute('app_admin_competence');
        }

        return $this->deleteEntity($request, $competence, $id, 'delete-competence', 'compétence', $this->em, [
            'list' => 'app_admin_competence',
            'update' => 'app_admin_competence_update',
        ]);
    }
}
