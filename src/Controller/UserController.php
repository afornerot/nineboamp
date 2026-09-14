<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use Bnine\FilesBundle\Service\FileService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class UserController extends AbstractController
{
    use CrudDeleteTrait;
    use LayoutRenderTrait;

    #[Route('/admin/user', name: 'app_admin_user')]
    public function list(UserRepository $userRepository): Response
    {
        $users = $userRepository->findAll();

        return $this->renderLayout('user/list.html.twig', 'Liste des Utilisateurs', [
            'routesubmit' => 'app_admin_user_submit',
            'routeupdate' => 'app_admin_user_update',
            'users' => $users,
        ]);
    }

    #[Route('/admin/user/submit', name: 'app_admin_user_submit')]
    public function submit(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $em): Response
    {
        $user = new User();

        $form = $this->createForm(UserType::class, $user, ['mode' => 'submit', 'modeAuth' => $this->getParameter('modeAuth')]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user = $form->getData();
            $password = $user->getPassword();
            if ('SQL' !== $this->getParameter('modeAuth')) {
                $password = Uuid::uuid4()->toString();
            }
            if (null === $password) {
                throw new \DomainException('Password is required on user creation.');
            }

            $hashedPassword = $passwordHasher->hashPassword(
                $user,
                $password
            );
            $user->setPassword($hashedPassword);

            $em->persist($user);
            $em->flush();

            return $this->redirectToRoute('app_admin_user');
        }

        return $this->renderLayout('user/edit.html.twig', 'Création Utilisateur', [
            'routecancel' => 'app_admin_user',
            'routedelete' => 'app_admin_user_delete',
            'mode' => 'submit',
            'form' => $form,
        ]);
    }

    #[Route('/admin/user/update/{id}', name: 'app_admin_user_update')]
    public function update(int $id, Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $em, FileService $fileService): Response
    {
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->redirectToRoute('app_admin_user');
        }
        $hashedPassword = $user->getPassword();

        $fileService->init('avatar', '0');

        $form = $this->createForm(UserType::class, $user, ['mode' => 'update', 'modeAuth' => $this->getParameter('modeAuth')]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user = $form->getData();
            if ($user->getPassword()) {
                $hashedPassword = $passwordHasher->hashPassword(
                    $user,
                    $user->getPassword()
                );
            }
            $user->setPassword($hashedPassword);
            $em->flush();

            return $this->redirectToRoute('app_admin_user');
        }

        return $this->renderLayout('user/edit.html.twig', 'Modification Utilisateur = '.$user->getUsername(), [
            'routecancel' => 'app_admin_user',
            'routedelete' => 'app_admin_user_delete',
            'mode' => 'update',
            'form' => $form,
        ]);
    }

    #[Route('/admin/user/delete/{id}', name: 'app_admin_user_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->redirectToRoute('app_admin_user');
        }

        return $this->deleteEntity($request, $user, $id, 'delete-user', 'utilisateur', $em, [
            'list' => 'app_admin_user',
            'update' => 'app_admin_user_update',
            'conflictMessage' => 'Suppression impossible : cet utilisateur est vraisemblablement lié à d\'autres données.',
        ]);
    }

    #[Route('/user', name: 'app_user_profil')]
    public function profil(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $em, FileService $fileService): Response
    {
        $user = $em->getRepository(User::class)->find($this->getUser());
        if (!$user) {
            return $this->redirectToRoute('app_home');
        }
        $hashedPassword = $user->getPassword();

        $fileService->init('avatar', '0');

        $form = $this->createForm(UserType::class, $user, ['mode' => 'profil', 'modeAuth' => $this->getParameter('modeAuth')]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user = $form->getData();
            if ($user->getPassword()) {
                $hashedPassword = $passwordHasher->hashPassword(
                    $user,
                    $user->getPassword()
                );
            }
            $user->setPassword($hashedPassword);

            $em->flush();

            return $this->redirectToRoute('app_home');
        }

        return $this->render('user/edit.html.twig', [
            'usemenu' => true,
            'usesidebar' => false,
            'title' => 'Profil = '.$user->getUsername(),
            'routecancel' => 'app_home',
            'routedelete' => '',
            'mode' => 'profil',
            'form' => $form,
        ]);
    }
}
