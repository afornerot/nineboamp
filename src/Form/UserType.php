<?php

namespace App\Form;

use App\Entity\Groupe;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * @extends AbstractType<User>
 */
class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
        ->add('submit', SubmitType::class, [
            'label' => 'Valider',
            'attr' => ['class' => 'btn btn-success no-print me-1'],
        ])

        ->add('username', TextType::class, [
            'label' => 'Login',
            'disabled' => 'SQL' !== $options['modeAuth'],
            'attr' => 'SQL' !== $options['modeAuth'] ? ['style' => 'background: #e9ecef;'] : [],
        ])

        ->add('lastname', TextType::class, [
            'label' => 'Nom',
            'required' => false,
            'disabled' => 'SQL' !== $options['modeAuth'],
            'attr' => 'SQL' !== $options['modeAuth'] ? ['style' => 'background: #e9ecef;'] : [],
        ])

        ->add('firstname', TextType::class, [
            'label' => 'Prénom',
            'required' => false,
            'disabled' => 'SQL' !== $options['modeAuth'],
            'attr' => 'SQL' !== $options['modeAuth'] ? ['style' => 'background: #e9ecef;'] : [],
        ])

        ->add('avatar', HiddenType::class)

        ->add('email', EmailType::class, [
            'label' => 'Email',
        ])

        ->add('groupes', EntityType::class, [
            'label' => 'Groupes',
            'class' => Groupe::class,
            'choice_label' => 'name',
            'multiple' => true,
            'required' => false,
            'attr' => ['class' => 'select2'],
        ]);

        if ('profil' != $options['mode']) {
            $builder
            ->add('roles', ChoiceType::class, [
                'choices' => ['ROLE_ADMIN' => 'ROLE_ADMIN', 'ROLE_MASTER' => 'ROLE_MASTER', 'ROLE_USER' => 'ROLE_USER'],
                'multiple' => true,
                'expanded' => true,
            ]);
        }

        if ('SQL' === $options['modeAuth']) {
            $builder
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'required' => ('submit' == $options['mode'] ? true : false),
                'options' => ['always_empty' => true],
                'first_options' => ['label' => 'Mot de Passe', 'attr' => ['class' => 'form-control', 'style' => 'margin-bottom:15px', 'autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Confirmer Mot de Passe', 'attr' => ['class' => 'form-control', 'style' => 'margin-bottom:15px']],
                'constraints' => [
                    new Regex(
                        pattern: '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\W).{8,}$/',
                        message: 'Le mot de passe doit contenir au moins 8 caractères, une lettre majuscule, une lettre minuscule et un caractère spécial.',
                    ),
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => static::class,
            'mode' => 'submit',
            'modeAuth' => 'SQL',
        ]);
    }
}
