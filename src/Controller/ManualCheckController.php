<?php

namespace App\Controller;

use App\Entity\Audit;
use App\Entity\ManualCheck;
use App\Repository\ManualCheckRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/manual-check')]
class ManualCheckController extends AbstractController
{
    #[Route('/save/{id}', name: 'app_manual_check_save', methods: ['POST'])]
    public function save(
        Audit $audit,
        Request $request,
        ManualCheckRepository $manualCheckRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse
    {
        $this->denyAccessUnlessGranted('edit', $audit);

        $data = json_decode($request->getContent(), true);

        if (!isset($data['criteriaNumber']) || !isset($data['status'])) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $criteriaNumber = $data['criteriaNumber'];
        $status = $data['status'];
        $notes = $data['notes'] ?? null;

        // Validate status
        if (!in_array($status, ['not_checked', 'conform', 'non_conform'])) {
            return new JsonResponse(['error' => 'Invalid status'], 400);
        }

        // Find or create manual check
        $manualCheck = $manualCheckRepository->findByAuditAndCriteria($audit, $criteriaNumber);

        if (!$manualCheck) {
            $manualCheck = new ManualCheck();
            $manualCheck->setAudit($audit);
            $manualCheck->setCriteriaNumber($criteriaNumber);
        }

        $manualCheck->setStatus($status);
        $manualCheck->setNotes($notes);

        $entityManager->persist($manualCheck);
        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'criteriaNumber' => $criteriaNumber,
            'status' => $status,
            'notes' => $notes
        ]);
    }

    #[Route('/get/{id}', name: 'app_manual_check_get', methods: ['GET'])]
    public function get(
        Audit $audit,
        ManualCheckRepository $manualCheckRepository
    ): JsonResponse
    {
        $this->denyAccessUnlessGranted('view', $audit);

        $checks = $manualCheckRepository->findByAudit($audit);

        $data = [];
        foreach ($checks as $check) {
            $data[$check->getCriteriaNumber()] = [
                'status' => $check->getStatus(),
                'notes' => $check->getNotes()
            ];
        }

        return new JsonResponse($data);
    }

    #[Route('/statistics/{id}', name: 'app_manual_check_statistics', methods: ['GET'])]
    public function statistics(
        Audit $audit,
        ManualCheckRepository $manualCheckRepository
    ): JsonResponse
    {
        $this->denyAccessUnlessGranted('view', $audit);

        $stats = $manualCheckRepository->getStatistics($audit);

        return new JsonResponse($stats);
    }
}
