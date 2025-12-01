<?php

namespace App\Controller;

use App\Entity\Audit;
use App\Entity\ManualCheck;
use App\Repository\ManualCheckRepository;
use App\Repository\AuditResultRepository;
use App\Service\RgaaReferenceService;
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
        AuditResultRepository $auditResultRepository,
        RgaaReferenceService $rgaaReferenceService,
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
        if (!in_array($status, ['not_checked', 'conform', 'non_conform', 'not_applicable'])) {
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

        // Recalculate conformity rate after manual check update
        $results = $auditResultRepository->findBy(['audit' => $audit]);

        // Build non-conform details from results (similar to AuditController)
        $nonConformCriteria = [];
        foreach ($results as $result) {
            $rgaaCriteria = $result->getRgaaCriteria();
            if ($rgaaCriteria && !in_array($rgaaCriteria, $nonConformCriteria)) {
                $nonConformCriteria[] = $rgaaCriteria;
            }
        }

        $auditStatistics = [
            'statistics' => [
                'nonConformDetails' => array_map(function($criteria) {
                    return ['criteriaNumber' => $criteria];
                }, $nonConformCriteria)
            ]
        ];
        $criteriaStatus = $rgaaReferenceService->getCriteriaStatus($auditStatistics);

        // Get all manual checks including the one being saved
        $allManualChecks = $manualCheckRepository->findByAudit($audit);
        $manualChecksMap = [];
        foreach ($allManualChecks as $check) {
            $manualChecksMap[$check->getCriteriaNumber()] = $check->getStatus();
        }
        // Add the current one being saved (in case it's new)
        $manualChecksMap[$criteriaNumber] = $status;

        // Get all RGAA criteria
        $allCriteria = $rgaaReferenceService->getAllCriteria();

        // Calculate totals with manual checks priority
        $totalConform = 0;
        $totalNonConform = 0;
        $totalNotApplicable = 0;
        $totalNotTested = 0;

        foreach ($allCriteria as $criterion) {
            $num = $criterion['number'];

            // Priority 1: Manual check overrides everything
            if (isset($manualChecksMap[$num])) {
                $manualStatus = $manualChecksMap[$num];
                if ($manualStatus === 'conform') {
                    $totalConform++;
                } elseif ($manualStatus === 'non_conform') {
                    $totalNonConform++;
                } elseif ($manualStatus === 'not_applicable') {
                    $totalNotApplicable++;
                } else {
                    $totalNotTested++;
                }
            }
            // Priority 2: Automatic test results
            elseif (in_array($num, $criteriaStatus['nonConform'] ?? [])) {
                $totalNonConform++;
            }
            elseif (in_array($num, $criteriaStatus['conform'] ?? [])) {
                $totalConform++;
            }
            else {
                $totalNotTested++;
            }
        }

        // Update audit totals
        $audit->setConformCriteria($totalConform);
        $audit->setNonConformCriteria($totalNonConform);
        $audit->setNotApplicableCriteria($totalNotApplicable);
        $audit->setNotTestedCriteria($totalNotTested);

        // Calculate and update conformity rate
        $totalTested = $totalConform + $totalNonConform;

        if ($totalTested > 0) {
            $conformityRate = ($totalConform / $totalTested) * 100;
            $audit->setConformityRate($conformityRate);
        }

        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'criteriaNumber' => $criteriaNumber,
            'status' => $status,
            'notes' => $notes,
            'conformityRate' => $audit->getConformityRate(),
            'totals' => [
                'conform' => $totalConform,
                'nonConform' => $totalNonConform,
                'notApplicable' => $totalNotApplicable,
                'notTested' => $totalNotTested
            ]
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
