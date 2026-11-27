<?php

namespace App\Form;

use App\Entity\Competence;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Competence>
 */
class CompetenceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('submit', SubmitType::class, [
                'label' => 'Valider',
                'attr' => ['class' => 'btn btn-success no-print me-1'],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'Catégorie',
                'choices' => array_flip(Competence::CATEGORIES),
            ])
            ->add('label', TextareaType::class, [
                'label' => 'Libellé',
                'attr' => ['rows' => 3],
                'help' => 'Une seule compétence ou exclusion par élément. Les retours à la ligne sont remplacés par des espaces dans le texte transmis à l\'IA.',
            ])
            ->add('position', IntegerType::class, [
                'label' => 'Ordre',
                'help' => 'Ordre d\'apparition dans sa catégorie (croissant).',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Active (transmise à l\'IA)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Competence::class,
            'mode' => 'update',
        ]);
    }
}
