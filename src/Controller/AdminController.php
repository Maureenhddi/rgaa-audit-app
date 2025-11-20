<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class AdminController extends AbstractController
{
    #[Route('/users', name: 'app_admin_users')]
    public function listUsers(UserRepository $userRepository): Response
    {
        $users = $userRepository->findAll();

        return $this->render('admin/users.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/users/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function newUser(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager
    ): Response {
        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $isAdmin = $request->request->get('is_admin') === '1';

            // Validation
            if (empty($name) || empty($email) || empty($password)) {
                $this->addFlash('error', 'Tous les champs sont requis.');
                return $this->redirectToRoute('app_admin_user_new');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'Email invalide.');
                return $this->redirectToRoute('app_admin_user_new');
            }

            // Check if email already exists
            $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existingUser) {
                $this->addFlash('error', 'Un utilisateur avec cet email existe déjà.');
                return $this->redirectToRoute('app_admin_user_new');
            }

            $user = new User();
            $user->setName($name);
            $user->setEmail($email);
            $user->setPassword($passwordHasher->hashPassword($user, $password));

            if ($isAdmin) {
                $user->setRoles(['ROLE_USER', 'ROLE_SUPER_ADMIN']);
            }

            $entityManager->persist($user);
            $entityManager->flush();

            $this->addFlash('success', 'Utilisateur créé avec succès !');
            return $this->redirectToRoute('app_admin_users');
        }

        return $this->render('admin/user_form.html.twig', [
            'user' => null,
        ]);
    }

    #[Route('/users/{id}/edit', name: 'app_admin_user_edit', methods: ['GET', 'POST'])]
    public function editUser(
        int $id,
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $userRepository->find($id);

        if (!$user) {
            throw $this->createNotFoundException('Utilisateur non trouvé');
        }

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $isAdmin = $request->request->get('is_admin') === '1';

            if (empty($name) || empty($email)) {
                $this->addFlash('error', 'Le nom et l\'email sont requis.');
                return $this->redirectToRoute('app_admin_user_edit', ['id' => $id]);
            }

            $user->setName($name);
            $user->setEmail($email);

            if (!empty($password)) {
                $user->setPassword($passwordHasher->hashPassword($user, $password));
            }

            if ($isAdmin) {
                $user->setRoles(['ROLE_USER', 'ROLE_SUPER_ADMIN']);
            } else {
                $user->setRoles(['ROLE_USER']);
            }

            $entityManager->flush();

            $this->addFlash('success', 'Utilisateur modifié avec succès !');
            return $this->redirectToRoute('app_admin_users');
        }

        return $this->render('admin/user_form.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/users/{id}/delete', name: 'app_admin_user_delete', methods: ['POST'])]
    public function deleteUser(
        int $id,
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $userRepository->find($id);

        if (!$user) {
            throw $this->createNotFoundException('Utilisateur non trouvé');
        }

        // Prevent deleting yourself
        if ($user->getId() === $this->getUser()->getId()) {
            $this->addFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');
            return $this->redirectToRoute('app_admin_users');
        }

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete_user_' . $user->getId(), $token)) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_admin_users');
        }

        $entityManager->remove($user);
        $entityManager->flush();

        $this->addFlash('success', 'Utilisateur supprimé avec succès !');
        return $this->redirectToRoute('app_admin_users');
    }
}
