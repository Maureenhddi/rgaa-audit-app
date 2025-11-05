<?php

namespace App\Controller;

use App\Entity\Project;
use App\Form\ProjectType;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/project')]
class ProjectController extends AbstractController
{
    #[Route('/', name: 'app_project_list', methods: ['GET'])]
    public function list(Request $request, ProjectRepository $projectRepository): Response
    {
        $user = $this->getUser();

        // Get filters from query parameters
        $search = $request->query->get('search');
        $status = $request->query->get('status', 'all');
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 12;

        // Get filtered projects with pagination
        $projects = $projectRepository->findByUserWithFilters($user, $search, $status, $page, $limit);

        // Get total count for pagination
        $totalProjects = $projectRepository->countByUserWithFilters($user, $search, $status);
        $totalPages = ceil($totalProjects / $limit);

        return $this->render('project/list.html.twig', [
            'projects' => $projects,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_projects' => $totalProjects,
            'search' => $search,
            'status' => $status,
        ]);
    }

    #[Route('/new', name: 'app_project_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $project = new Project();
        $form = $this->createForm(ProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $project->setUser($this->getUser());

            $entityManager->persist($project);
            $entityManager->flush();

            $this->addFlash('success', 'Le projet a été créé avec succès !');
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        return $this->render('project/form.html.twig', [
            'form' => $form->createView(),
            'project' => null,
            'is_edit' => false,
        ]);
    }

    #[Route('/{id}', name: 'app_project_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, ProjectRepository $projectRepository): Response
    {
        $user = $this->getUser();
        $project = $projectRepository->findOneByIdAndUser($id, $user);

        if (!$project) {
            throw $this->createNotFoundException('Projet non trouvé');
        }

        return $this->render('project/show.html.twig', [
            'project' => $project,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_project_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request, ProjectRepository $projectRepository, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        $project = $projectRepository->findOneByIdAndUser($id, $user);

        if (!$project) {
            throw $this->createNotFoundException('Projet non trouvé');
        }

        $form = $this->createForm(ProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $project->setUpdatedAt(new \DateTimeImmutable());

            $entityManager->flush();

            $this->addFlash('success', 'Le projet a été modifié avec succès !');
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        return $this->render('project/form.html.twig', [
            'form' => $form->createView(),
            'project' => $project,
            'is_edit' => true,
        ]);
    }

    #[Route('/{id}/archive', name: 'app_project_archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archive(int $id, Request $request, ProjectRepository $projectRepository, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        $project = $projectRepository->findOneByIdAndUser($id, $user);

        if (!$project) {
            throw $this->createNotFoundException('Projet non trouvé');
        }

        // Verify CSRF token
        if (!$this->isCsrfTokenValid('archive' . $project->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        if ($project->isArchived()) {
            // Unarchive
            $project->setArchivedAt(null);
            $project->setStatus('active');
            $this->addFlash('success', 'Le projet a été désarchivé avec succès !');
        } else {
            // Archive
            $project->setArchivedAt(new \DateTimeImmutable());
            $project->setStatus('archived');
            $this->addFlash('success', 'Le projet a été archivé avec succès !');
        }

        $project->setUpdatedAt(new \DateTimeImmutable());
        $entityManager->flush();

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_project_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, ProjectRepository $projectRepository, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        $project = $projectRepository->findOneByIdAndUser($id, $user);

        if (!$project) {
            throw $this->createNotFoundException('Projet non trouvé');
        }

        // Verify CSRF token
        if (!$this->isCsrfTokenValid('delete' . $project->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        // Check if project has audits
        if ($project->getAuditCount() > 0) {
            $this->addFlash('warning', 'Ce projet contient ' . $project->getAuditCount() . ' audit(s). Les audits seront dissociés du projet.');
        }

        $entityManager->remove($project);
        $entityManager->flush();

        $this->addFlash('success', 'Le projet a été supprimé avec succès !');
        return $this->redirectToRoute('app_project_list');
    }
}
