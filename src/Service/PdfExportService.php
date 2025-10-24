<?php

namespace App\Service;

use App\Entity\Audit;
use App\Repository\AuditResultRepository;
use Knp\Snappy\Pdf;
use Twig\Environment;

class PdfExportService
{
    public function __construct(
        private Environment $twig,
        private AuditResultRepository $resultRepository,
        private ?Pdf $pdf = null
    ) {
    }

    /**
     * Generate PDF report for an audit
     */
    public function generateReport(Audit $audit): string
    {
        // Group results by severity
        $results = $this->resultRepository->findGroupedBySeverity($audit);

        $groupedResults = [
            'critical' => [],
            'major' => [],
            'minor' => []
        ];

        foreach ($results as $result) {
            $severity = $result->getSeverity();
            if (isset($groupedResults[$severity])) {
                $groupedResults[$severity][] = $result;
            }
        }

        // Render HTML template
        $html = $this->twig->render('audit/pdf_report.html.twig', [
            'audit' => $audit,
            'grouped_results' => $groupedResults,
            'generated_at' => new \DateTimeImmutable(),
        ]);

        // If Snappy is configured, generate PDF
        if ($this->pdf !== null) {
            return $this->pdf->getOutputFromHtml($html, [
                'encoding' => 'UTF-8',
                'page-size' => 'A4',
                'margin-top' => 10,
                'margin-right' => 10,
                'margin-bottom' => 10,
                'margin-left' => 10,
            ]);
        }

        // Fallback: return HTML
        return $html;
    }

    /**
     * Get filename for PDF export
     */
    public function getFilename(Audit $audit): string
    {
        $date = $audit->getCreatedAt()->format('Y-m-d');
        $urlSlug = preg_replace('/[^a-z0-9]+/i', '-', parse_url($audit->getUrl(), PHP_URL_HOST) ?? 'audit');
        return "audit-rgaa-{$urlSlug}-{$date}.pdf";
    }
}
