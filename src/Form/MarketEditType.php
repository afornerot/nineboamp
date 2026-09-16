<?php

namespace App\Form;

use App\Entity\Market;
use Bnine\MdEditorBundle\Form\Type\MarkdownType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MarketEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('status', ChoiceType::class, [
                'label' => 'Statut',
                'choices' => Market::STATUSES,
            ])
            ->add('score', IntegerType::class, [
                'label' => 'Score (0-100)',
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'max' => 100,
                ],
            ])
            ->add('comment', MarkdownType::class, [
                'label' => 'Commentaire',
                'required' => false,
                'attr' => ['rows' => 6],
                'label_attr' => ['class' => 'fw-bold'],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Enregistrer',
                'attr' => ['class' => 'btn btn-primary'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Market::class,
        ]);
    }
}
