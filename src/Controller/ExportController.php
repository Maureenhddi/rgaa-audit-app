<?php

namespace App\Controller;

use App\Entity\Audit;
use App\Service\PdfExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
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
}
