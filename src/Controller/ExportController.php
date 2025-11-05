<?php

namespace App\Controller;

use App\Entity\Audit;
use App\Service\PdfExportService;
use App\Service\CsvExportService;
use App\Service\ExcelExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/export')]
class ExportController extends AbstractController
{
    #[Route('/audit/{id}/pdf', name: 'app_export_audit_pdf')]
    public function exportAuditPdf(Audit $audit, PdfExportService $pdfExportService): Response
    {
        $this->denyAccessUnlessGranted('view', $audit);

        try {
            $pdfContent = $pdfExportService->generateReport($audit);
            $filename = $pdfExportService->getFilename($audit);

            $response = new Response($pdfContent);
            $response->headers->set('Content-Type', 'application/pdf');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

            return $response;

        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la génération du PDF : ' . $e->getMessage());
            return $this->redirectToRoute('app_audit_show', ['id' => $audit->getId()]);
        }
    }

    #[Route('/audit/{id}/csv', name: 'app_export_audit_csv')]
    public function exportAuditCsv(Audit $audit, CsvExportService $csvExportService): Response
    {
        $this->denyAccessUnlessGranted('view', $audit);

        try {
            $csvContent = $csvExportService->generateCsv($audit);
            $filename = $csvExportService->getFilename($audit);

            $response = new Response($csvContent);
            $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

            return $response;

        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la génération du CSV : ' . $e->getMessage());
            return $this->redirectToRoute('app_audit_show', ['id' => $audit->getId()]);
        }
    }

    #[Route('/audit/{id}/excel', name: 'app_export_audit_excel')]
    public function exportAuditExcel(Audit $audit, ExcelExportService $excelExportService): Response
    {
        $this->denyAccessUnlessGranted('view', $audit);

        try {
            $tempFile = $excelExportService->generateExcel($audit);
            $filename = $excelExportService->getFilename($audit);

            $response = new BinaryFileResponse($tempFile);
            $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $filename
            );
            $response->deleteFileAfterSend(true);

            return $response;

        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la génération du fichier Excel : ' . $e->getMessage());
            return $this->redirectToRoute('app_audit_show', ['id' => $audit->getId()]);
        }
    }
}
