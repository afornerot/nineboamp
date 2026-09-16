<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Product;
use App\Form\ProductType;
use App\Repository\ProductRepository;
use App\Service\ProductMetadataExtractor;
use Bnine\FilesBundle\Service\FileService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ProductController extends AbstractController
{
    use CrudDeleteTrait;
    use LayoutRenderTrait;

    #[Route('/user/product', name: 'app_product_list')]
    public function list(ProductRepository $repository): Response
    {
        $products = $repository->findAll();

        return $this->render('product/list.html.twig', [
            'usemenu' => true,
            'usesidebar' => false,
            'title' => 'Produits',
            'routesubmit' => 'app_product_submit',
            'routeupdate' => 'app_product_update',
            'products' => $products,
        ]);
    }

    #[Route('/user/product/submit', name: 'app_product_submit')]
    public function submit(Request $request, EntityManagerInterface $em, ProductMetadataExtractor $extractor): Response
    {
        $product = new Product();

        $form = $this->createForm(ProductType::class, $product, ['mode' => 'submit']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ((!$product->getKeywords() || !$product->getSectors() || !$product->getDescription()) && $product->getFiche()) {
                $meta = $extractor->extractMissing($product);
                if (!$product->getKeywords() && [] !== $meta['keywords']) {
                    $product->setKeywords(implode(', ', $meta['keywords']));
                }
                if (!$product->getSectors() && [] !== $meta['sectors']) {
                    $product->setSectors(implode(', ', $meta['sectors']));
                }
                if ('' === $this->stringifyOrEmpty($product->getDescription()) && '' !== $meta['description']) {
                    $product->setDescription($meta['description']);
                }
            }

            $em->persist($product);
            $em->flush();

            return $this->redirectToRoute('app_product_list');
        }

        return $this->render('product/edit.html.twig', [
            'usemenu' => true,
            'usesidebar' => false,
            'title' => 'Nouveau produit',
            'routecancel' => 'app_product_list',
            'routedelete' => 'app_product_delete',
            'form' => $form,
            'mode' => 'submit',
            'product' => $product,
        ]);
    }

    #[Route('/user/product/update/{id}', name: 'app_product_update')]
    public function update(int $id, Request $request, ProductRepository $repository, EntityManagerInterface $em, FileService $fileService, ProductMetadataExtractor $extractor): Response
    {
        $product = $repository->find($id);
        if (!$product) {
            return $this->redirectToRoute('app_product_list');
        }

        $form = $this->createForm(ProductType::class, $product, ['mode' => 'update']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ((!$product->getKeywords() || !$product->getSectors() || '' === $this->stringifyOrEmpty($product->getDescription())) && $product->getFiche()) {
                $meta = $extractor->extractMissing($product);
                if (!$product->getKeywords() && [] !== $meta['keywords']) {
                    $product->setKeywords(implode(', ', $meta['keywords']));
                }
                if (!$product->getSectors() && [] !== $meta['sectors']) {
                    $product->setSectors(implode(', ', $meta['sectors']));
                }
                if ('' === $this->stringifyOrEmpty($product->getDescription()) && '' !== $meta['description']) {
                    $product->setDescription($meta['description']);
                }
            }

            $em->flush();

            return $this->redirectToRoute('app_product_list');
        }

        return $this->render('product/edit.html.twig', [
            'usemenu' => true,
            'usesidebar' => false,
            'title' => 'Modifier produit',
            'routecancel' => 'app_product_list',
            'routedelete' => 'app_product_delete',
            'form' => $form,
            'mode' => 'update',
            'product' => $product,
        ]);
    }

    #[Route('/user/product/delete/{id}', name: 'app_product_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, ProductRepository $repository, EntityManagerInterface $em): Response
    {
        $product = $repository->find($id);
        if (!$product) {
            return $this->redirectToRoute('app_product_list');
        }

        return $this->deleteEntity($request, $product, $id, 'delete-product', 'produit', $em, [
            'list' => 'app_product_list',
            'update' => 'app_product_update',
        ]);
    }

    private function stringifyOrEmpty(mixed $value): string
    {
        if (null === $value) {
            return '';
        }
        if (is_string($value)) {
            return trim($value);
        }

        return (string) $value;
    }
}
