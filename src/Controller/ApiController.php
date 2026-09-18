<?php

namespace App\Controller;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ApiController extends AbstractController
{
    public function __construct(
        private readonly ProductRepository $productRepository,
    ) {
    }

    #[Route('/api/products/search', name: 'api_products_search', methods: ['GET'])]
    public function searchProducts(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');

        $products = '' !== $query
            ? $this->productRepository->searchByName($query)
            : $this->productRepository->findAll();

        $result = array_map(fn (Product $p) => [
            'id' => $p->getId(),
            'name' => $p->getName(),
            'description' => mb_substr($p->getDescription() ?? '', 0, 200),
        ], $products);

        return $this->json($result);
    }

    #[Route('/api/product/{id}', name: 'api_product_get', methods: ['GET'])]
    public function getProduct(int $id): JsonResponse
    {
        $product = $this->productRepository->find($id);

        if (!$product instanceof Product) {
            return $this->json(['error' => 'Produit non trouvé'], 404);
        }

        return $this->json([
            'id' => $product->getId(),
            'name' => $product->getName(),
            'description' => $product->getDescription(),
            'keywords' => $product->getKeywords(),
            'sectors' => $product->getSectors(),
            'fiche' => $product->getFiche(),
        ]);
    }
}
