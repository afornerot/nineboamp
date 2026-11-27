<?php

namespace App\Form;

use App\Entity\Product;
use App\Service\ProductSheetGenerator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom',
                'attr' => ['maxlength' => 255],
            ])
            ->add('description', TextType::class, [
                'label' => 'Description courte',
                'required' => false,
            ])
            ->add('keywords', TextType::class, [
                'label' => 'Mots-clés (séparés par des virgules)',
                'required' => false,
            ])
            ->add('sectors', TextType::class, [
                'label' => 'Secteurs cibles',
                'required' => false,
            ])
            ->add('fiche', TextareaType::class, [
                'label' => 'Fiche produit (Markdown)',
                'required' => false,
                'attr' => ['rows' => 20, 'class' => 'markdown-editor'],
            ])
            // Non enregistré : consigne lue par le bouton « Générer avec l'IA ».
            ->add('aiInstruction', TextareaType::class, [
                'label' => 'Consigne pour l\'IA (facultatif)',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'maxlength' => ProductSheetGenerator::MAX_INSTRUCTION_LENGTH,
                    'placeholder' => 'Ce qui ne va pas ou ce qu\'il faut ajouter. Exemple : « trop commercial », « préciser que l\'hébergement est possible chez nous »…',
                ],
            ])
        ;

        if ($options['mode'] !== 'list') {
            $builder->add('submit', SubmitType::class, [
                'label' => 'Valider',
                'attr' => ['class' => 'btn btn-success'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Product::class,
            'mode' => 'list',
        ]);
    }
}
