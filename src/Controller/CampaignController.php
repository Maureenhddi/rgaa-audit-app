<?php

namespace App\Controller;

use App\Entity\AuditCampaign;
use App\Entity\Project;
use App\Form\CampaignType;
use App\Repository\AuditCampaignRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/campaign')]
class CampaignController extends AbstractController
{
    #[Route('/new/{projectId}', name: 'app_campaign_new', methods: ['GET', 'POST'], requirements: ['projectId' => '\d+'])]
    public function new(
        int $projectId,
        Request $request,
        ProjectRepository $projectRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $this->getUser();
        $project = $projectRepository->findOneByIdAndUser($projectId, $user);

        if (!$project) {
            throw $this->createNotFoundException('Projet non trouvé');
        }

        $campaign = new AuditCampaign();
        $campaign->setProject($project);

        $form = $this->createForm(CampaignType::class, $campaign);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($campaign);
            $entityManager->flush();

            $this->addFlash('success', 'La campagne d\'audit a été créée avec succès !');
            return $this->redirectToRoute('app_campaign_show', ['id' => $campaign->getId()]);
        }

        return $this->render('campaign/form.html.twig', [
            'form' => $form->createView(),
            'campaign' => null,
            'project' => $project,
            'is_edit' => false,
        ]);
    }

    #[Route('/{id}', name: 'app_campaign_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        int $id,
        AuditCampaignRepository $campaignRepository
    ): Response {
        $user = $this->getUser();
        $campaign = $campaignRepository->findOneByIdAndUser($id, $user);

        if (!$campaign) {
            throw $this->createNotFoundException('Campagne non trouvée');
        }

        return $this->render('campaign/show.html.twig', [
            'campaign' => $campaign,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_campaign_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        int $id,
        Request $request,
        AuditCampaignRepository $campaignRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $this->getUser();
        $campaign = $campaignRepository->findOneByIdAndUser($id, $user);

        if (!$campaign) {
            throw $this->createNotFoundException('Campagne non trouvée');
        }

        $form = $this->createForm(CampaignType::class, $campaign);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $campaign->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('success', 'La campagne a été modifiée avec succès !');
            return $this->redirectToRoute('app_campaign_show', ['id' => $campaign->getId()]);
        }

        return $this->render('campaign/form.html.twig', [
            'form' => $form->createView(),
            'campaign' => $campaign,
            'project' => $campaign->getProject(),
            'is_edit' => true,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_campaign_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(
        int $id,
        Request $request,
        AuditCampaignRepository $campaignRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $this->getUser();
        $campaign = $campaignRepository->findOneByIdAndUser($id, $user);

        if (!$campaign) {
            throw $this->createNotFoundException('Campagne non trouvée');
        }

        $projectId = $campaign->getProject()->getId();

        // Verify CSRF token
        if (!$this->isCsrfTokenValid('delete' . $campaign->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_campaign_show', ['id' => $campaign->getId()]);
        }

        // Check if campaign has audits
        if ($campaign->getPageAudits()->count() > 0) {
            $this->addFlash('warning', 'Cette campagne contient ' . $campaign->getPageAudits()->count() . ' audit(s). Tous les audits seront supprimés.');
        }

        $entityManager->remove($campaign);
        $entityManager->flush();

        $this->addFlash('success', 'La campagne a été supprimée avec succès !');
        return $this->redirectToRoute('app_project_show', ['id' => $projectId]);
    }

    #[Route('/{id}/archive', name: 'app_campaign_archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archive(
        int $id,
        Request $request,
        AuditCampaignRepository $campaignRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $this->getUser();
        $campaign = $campaignRepository->findOneByIdAndUser($id, $user);

        if (!$campaign) {
            throw $this->createNotFoundException('Campagne non trouvée');
        }

        // Verify CSRF token
        if (!$this->isCsrfTokenValid('archive' . $campaign->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_campaign_show', ['id' => $campaign->getId()]);
        }

        if ($campaign->isArchived()) {
            // Unarchive
            $campaign->setStatus('completed');
            $this->addFlash('success', 'La campagne a été désarchivée avec succès !');
        } else {
            // Archive
            $campaign->setStatus('archived');
            $this->addFlash('success', 'La campagne a été archivée avec succès !');
        }

        $campaign->setUpdatedAt(new \DateTimeImmutable());
        $entityManager->flush();

        return $this->redirectToRoute('app_campaign_show', ['id' => $campaign->getId()]);
    }

    #[Route('/{id}/complete', name: 'app_campaign_complete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function complete(
        int $id,
        Request $request,
        AuditCampaignRepository $campaignRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $this->getUser();
        $campaign = $campaignRepository->findOneByIdAndUser($id, $user);

        if (!$campaign) {
            throw $this->createNotFoundException('Campagne non trouvée');
        }

        // Verify CSRF token
        if (!$this->isCsrfTokenValid('complete' . $campaign->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_campaign_show', ['id' => $campaign->getId()]);
        }

        // Recalculate statistics
        $campaign->recalculateStatistics();
        $campaign->setStatus('completed');
        $campaign->setEndDate(new \DateTimeImmutable());
        $campaign->setUpdatedAt(new \DateTimeImmutable());

        $entityManager->flush();

        $this->addFlash('success', 'La campagne a été marquée comme terminée !');
        return $this->redirectToRoute('app_campaign_show', ['id' => $campaign->getId()]);
    }

    #[Route('/', name: 'app_campaign_list', methods: ['GET'])]
    public function list(
        Request $request,
        AuditCampaignRepository $campaignRepository
    ): Response {
        $user = $this->getUser();

        // Get filters from query parameters
        $search = $request->query->get('search');
        $status = $request->query->get('status', 'all');
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 10;

        // Get filtered campaigns with pagination
        $campaigns = $campaignRepository->findByUserWithFilters($user, $search, $status, $page, $limit);

        // Get total count for pagination
        $totalCampaigns = $campaignRepository->countByUserWithFilters($user, $search, $status);
        $totalPages = ceil($totalCampaigns / $limit);

        return $this->render('campaign/list.html.twig', [
            'campaigns' => $campaigns,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_campaigns' => $totalCampaigns,
            'search' => $search,
            'status' => $status,
        ]);
    }
}
