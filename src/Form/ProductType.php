<?php

namespace App\Form;

use App\Entity\Product;
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
