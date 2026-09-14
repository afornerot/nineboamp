<?php

namespace App\Controller\Trait;

use Symfony\Component\HttpFoundation\Response;

/**
 * Raccourcis de rendu pour le layout squelette : applique les variables
 * globales (usemenu, usesidebar) et le title en une seule fois.
 */
trait LayoutRenderTrait
{
    /**
     * @param array<string, mixed> $parameters
     */
    private function renderLayout(string $view, string $title, array $parameters = []): Response
    {
        return $this->render($view, array_merge([
            'usemenu' => true,
            'usesidebar' => true,
            'title' => $title,
        ], $parameters));
    }
}
