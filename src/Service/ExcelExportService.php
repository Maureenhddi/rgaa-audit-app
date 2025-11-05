<?php

namespace App\Service;

use App\Entity\Audit;
use App\Repository\AuditResultRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

class ExcelExportService
{
    public function __construct(
        private AuditResultRepository $resultRepository,
        private RgaaThemeService $rgaaThemeService,
        private IssuePriorityService $priorityService,
        private string $projectDir
    ) {
    }

    /**
     * Generate Excel export for an audit
     */
    public function generateExcel(Audit $audit): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Audit RGAA');

        // Get all results
        $results = $this->resultRepository->findGroupedBySeverity($audit);

        // Group by RGAA theme (same logic as CSV)
        $groupedByTheme = $this->groupResultsByTheme($results);

        // Calculate priority scores
        $this->calculatePriorityScores($groupedByTheme);

        $currentRow = 1;

        // HEADER SECTION with logo
        $currentRow = $this->addHeader($sheet, $audit, $currentRow);

        // SUMMARY SECTION
        $currentRow = $this->addSummary($sheet, $audit, $currentRow);

        // PRIORITY STATISTICS
        $currentRow = $this->addPriorityStatistics($sheet, $groupedByTheme, $currentRow);

        // DATA TABLE
        $currentRow = $this->addDataTable($sheet, $groupedByTheme, $currentRow);

        // Auto-size columns
        foreach (range('A', 'Q') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Freeze header row
        $sheet->freezePane('A10');

        // Add auto-filter
        $lastRow = $currentRow - 1;
        $sheet->setAutoFilter("A9:Q{$lastRow}");

        // Save to temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'excel_');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * Add header with logo and audit info
     */
    private function addHeader($sheet, Audit $audit, int $startRow): int
    {
        $row = $startRow;

        // Row 1: Logo + Title on same row
        $logoPath = $this->projectDir . '/public/images/logo-itroom.png';
        if (file_exists($logoPath)) {
            $drawing = new Drawing();
            $drawing->setName('IT Room Logo');
            $drawing->setDescription('IT Room');
            $drawing->setPath($logoPath);
            $drawing->setHeight(60);
            $drawing->setCoordinates('A' . $row);
            $drawing->setOffsetX(10);
            $drawing->setOffsetY(5);
            $drawing->setWorksheet($sheet);
        }

        // Title - elegant, simple
        $sheet->mergeCells("C{$row}:Q{$row}");
        $sheet->setCellValue("C{$row}", "Rapport d'Audit d'Accessibilité RGAA");
        $sheet->getStyle("C{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 20, 'color' => ['rgb' => '2C3E50']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(60);
        $row++;

        // Spacer row with subtle line
        $sheet->mergeCells("A{$row}:Q{$row}");
        $sheet->getStyle("A{$row}:Q{$row}")->applyFromArray([
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']]],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(5);
        $row++;

        // Row 2: URL (clean, no background)
        $sheet->mergeCells("A{$row}:Q{$row}");
        $sheet->setCellValue("A{$row}", $audit->getUrl());
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '016DAE']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(20);
        $row++;

        // Row 3: Date (subtle)
        $sheet->mergeCells("A{$row}:Q{$row}");
        $sheet->setCellValue("A{$row}", "Audit réalisé le " . $audit->getCreatedAt()->format('d/m/Y à H:i'));
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['size' => 9, 'color' => ['rgb' => '7F8C8D']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(18);
        $row++;

        $row++; // Empty row
        return $row;
    }

    /**
     * Add summary section - Modern card design
     */
    private function addSummary($sheet, Audit $audit, int $startRow): int
    {
        $row = $startRow;

        // Section title - subtle
        $sheet->mergeCells("A{$row}:Q{$row}");
        $sheet->setCellValue("A{$row}", "Vue d'ensemble");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '2C3E50']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $row++;

        // Stats cards - modern boxes with soft colors
        // Card 1: Total
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", "Total des problèmes");
        $sheet->mergeCells("A" . ($row + 1) . ":D" . ($row + 1));
        $sheet->setCellValue("A" . ($row + 1), $audit->getTotalIssues());
        $sheet->getStyle("A{$row}:D" . ($row + 1))->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8F9FA']],
            'borders' => [
                'outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => 'DEE2E6']],
            ],
        ]);
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '6C757D']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle("A" . ($row + 1))->applyFromArray([
            'font' => ['bold' => true, 'size' => 24, 'color' => ['rgb' => '2C3E50']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Card 2: Critiques (red accent)
        $sheet->mergeCells("F{$row}:I{$row}");
        $sheet->setCellValue("F{$row}", "Critiques");
        $sheet->mergeCells("F" . ($row + 1) . ":I" . ($row + 1));
        $sheet->setCellValue("F" . ($row + 1), $audit->getCriticalCount());
        $sheet->getStyle("F{$row}:I" . ($row + 1))->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF5F5']],
            'borders' => [
                'outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => 'E53E3E']],
                'left' => ['borderStyle' => Border::BORDER_THICK, 'color' => ['rgb' => 'E53E3E']],
            ],
        ]);
        $sheet->getStyle("F{$row}")->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => 'C53030']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle("F" . ($row + 1))->applyFromArray([
            'font' => ['bold' => true, 'size' => 24, 'color' => ['rgb' => 'E53E3E']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Card 3: Majeurs (orange accent)
        $sheet->mergeCells("K{$row}:N{$row}");
        $sheet->setCellValue("K{$row}", "Majeurs");
        $sheet->mergeCells("K" . ($row + 1) . ":N" . ($row + 1));
        $sheet->setCellValue("K" . ($row + 1), $audit->getMajorCount());
        $sheet->getStyle("K{$row}:N" . ($row + 1))->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFAF0']],
            'borders' => [
                'outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => 'DD6B20']],
                'left' => ['borderStyle' => Border::BORDER_THICK, 'color' => ['rgb' => 'DD6B20']],
            ],
        ]);
        $sheet->getStyle("K{$row}")->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => 'C05621']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle("K" . ($row + 1))->applyFromArray([
            'font' => ['bold' => true, 'size' => 24, 'color' => ['rgb' => 'DD6B20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Card 4: Mineurs (yellow accent)
        $sheet->mergeCells("P{$row}:Q{$row}");
        $sheet->setCellValue("P{$row}", "Mineurs");
        $sheet->mergeCells("P" . ($row + 1) . ":Q" . ($row + 1));
        $sheet->setCellValue("P" . ($row + 1), $audit->getMinorCount());
        $sheet->getStyle("P{$row}:Q" . ($row + 1))->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFF0']],
            'borders' => [
                'outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => 'D69E2E']],
                'left' => ['borderStyle' => Border::BORDER_THICK, 'color' => ['rgb' => 'D69E2E']],
            ],
        ]);
        $sheet->getStyle("P{$row}")->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => 'B7791F']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle("P" . ($row + 1))->applyFromArray([
            'font' => ['bold' => true, 'size' => 24, 'color' => ['rgb' => 'D69E2E']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $sheet->getRowDimension($row)->setRowHeight(20);
        $sheet->getRowDimension($row + 1)->setRowHeight(35);

        $row += 2;
        $row++; // Empty row
        return $row;
    }

    /**
     * Add priority statistics - Simplified version
     */
    private function addPriorityStatistics($sheet, array $groupedByTheme, int $startRow): int
    {
        $row = $startRow;

        // Count by priority
        $priorityStats = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        foreach ($groupedByTheme as $theme) {
            foreach ($theme['criteria'] as $criterionData) {
                foreach (['critical', 'major', 'minor'] as $severity) {
                    foreach ($criterionData['results'][$severity] as $group) {
                        $score = $group['priorityScore'];
                        if ($score >= 80) $priorityStats[1]++;
                        elseif ($score >= 60) $priorityStats[2]++;
                        elseif ($score >= 40) $priorityStats[3]++;
                        else $priorityStats[4]++;
                    }
                }
            }
        }

        // Section title - subtle
        $sheet->mergeCells("A{$row}:Q{$row}");
        $sheet->setCellValue("A{$row}", "Priorités de correction");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '2C3E50']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $row++;

        // Simple priority list
        $priorities = [
            1 => ['label' => 'Très urgent', 'count' => $priorityStats[1], 'color' => 'E53E3E'],
            2 => ['label' => 'Urgent', 'count' => $priorityStats[2], 'color' => 'DD6B20'],
            3 => ['label' => 'Moyen', 'count' => $priorityStats[3], 'color' => '3182CE'],
            4 => ['label' => 'Faible', 'count' => $priorityStats[4], 'color' => '718096'],
        ];

        foreach ($priorities as $num => $data) {
            $sheet->setCellValue("A{$row}", "P{$num}");
            $sheet->setCellValue("B{$row}", $data['label']);
            $sheet->setCellValue("C{$row}", $data['count']);

            // Styled priority indicator
            $sheet->getStyle("A{$row}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $data['color']]],
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $sheet->getColumnDimension('A')->setWidth(5);

            $sheet->getStyle("B{$row}")->applyFromArray([
                'font' => ['size' => 10, 'color' => ['rgb' => '2C3E50']],
            ]);

            $sheet->getStyle("C{$row}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => $data['color']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $sheet->getColumnDimension('C')->setWidth(8);

            $row++;
        }

        $row++; // Empty row
        return $row;
    }

    /**
     * Add data table with all issues
     */
    private function addDataTable($sheet, array $groupedByTheme, int $startRow): int
    {
        $row = $startRow;

        // Section title
        $sheet->mergeCells("A{$row}:Q{$row}");
        $sheet->setCellValue("A{$row}", "Détail des problèmes");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '2C3E50']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $row++;

        // Table headers - Modern clean design
        $headers = [
            'A' => 'Thème',
            'B' => 'N°',
            'C' => 'Critère',
            'D' => 'Description Critère',
            'E' => 'Type d\'erreur',
            'F' => 'Sév.',
            'G' => 'Prio',
            'H' => 'Niveau Priorité',
            'I' => 'Nb',
            'J' => 'Source',
            'K' => 'Description',
            'L' => 'Impact',
            'M' => 'Recommandation',
            'N' => 'WCAG',
            'O' => 'Correction',
            'P' => 'Sélecteur',
            'Q' => 'Contexte'
        ];

        foreach ($headers as $col => $header) {
            $sheet->setCellValue("{$col}{$row}", $header);
        }

        $sheet->getStyle("A{$row}:Q{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '2C3E50']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F7FAFC']],
            'borders' => [
                'bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '2C3E50']],
            ],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(25);
        $row++;

        // Data rows
        foreach ($groupedByTheme as $theme) {
            foreach ($theme['criteria'] as $criterionKey => $criterionData) {
                foreach (['critical', 'major', 'minor'] as $severity) {
                    foreach ($criterionData['results'][$severity] as $group) {
                        $priorityLevel = $this->getPriorityLevel($group['priorityScore']);
                        $firstOccurrence = $group['occurrences'][0] ?? null;

                        $sheet->setCellValue("A{$row}", $theme['theme_name']);
                        $sheet->setCellValue("B{$row}", $theme['theme_number']);
                        $sheet->setCellValue("C{$row}", $criterionData['criterion'] ?? 'N/A');
                        $sheet->setCellValue("D{$row}", $criterionData['criterion_description'] ?? '');
                        $sheet->setCellValue("E{$row}", $group['errorType'] ?? '');
                        $sheet->setCellValue("F{$row}", strtoupper($severity));
                        $sheet->setCellValue("G{$row}", $group['priorityScore']);
                        $sheet->setCellValue("H{$row}", $priorityLevel);
                        $sheet->setCellValue("I{$row}", count($group['occurrences']));
                        $sheet->setCellValue("J{$row}", $this->formatSource($group['source']));
                        $sheet->setCellValue("K{$row}", $group['description'] ?? '');
                        $sheet->setCellValue("L{$row}", $group['impactUser'] ?? '');
                        $sheet->setCellValue("M{$row}", $group['recommendation'] ?? '');
                        $sheet->setCellValue("N{$row}", $group['wcagCriteria'] ?? '');
                        $sheet->setCellValue("O{$row}", $group['codeFix'] ?? '');
                        $sheet->setCellValue("P{$row}", $firstOccurrence ? $firstOccurrence->getSelector() : '');
                        $sheet->setCellValue("Q{$row}", $firstOccurrence ? $firstOccurrence->getContext() : '');

                        // Clean modern row styling - alternating background
                        $bgColor = ($row % 2 == 0) ? 'FFFFFF' : 'F9FAFB';
                        $sheet->getStyle("A{$row}:Q{$row}")->applyFromArray([
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgColor]],
                            'borders' => [
                                'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']],
                            ],
                        ]);

                        // Severity badge - subtle colored dot
                        $severityColor = match($severity) {
                            'critical' => 'E53E3E',
                            'major' => 'DD6B20',
                            'minor' => 'D69E2E',
                            default => 'A0AEC0'
                        };
                        $sheet->getStyle("F{$row}")->applyFromArray([
                            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => $severityColor]],
                            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                        ]);

                        // Priority score - colored number
                        $priorityColor = $this->getPriorityColorModern($group['priorityScore']);
                        $sheet->getStyle("G{$row}")->applyFromArray([
                            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => $priorityColor]],
                            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                        ]);

                        // Priority level - small text
                        $sheet->getStyle("H{$row}")->applyFromArray([
                            'font' => ['size' => 9, 'color' => ['rgb' => '718096']],
                            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
                        ]);

                        // Wrap text for long columns
                        $sheet->getStyle("K{$row}")->getAlignment()->setWrapText(true);
                        $sheet->getStyle("L{$row}")->getAlignment()->setWrapText(true);
                        $sheet->getStyle("M{$row}")->getAlignment()->setWrapText(true);

                        $row++;
                    }
                }
            }
        }

        return $row;
    }

    /**
     * Group results by theme (same logic as CSV)
     */
    private function groupResultsByTheme(array $results): array
    {
        $groupedByTheme = [];
        $processedIds = [];

        foreach ($results as $result) {
            $resultId = $result->getId();
            if (in_array($resultId, $processedIds)) {
                continue;
            }
            $processedIds[] = $resultId;

            $severity = $result->getSeverity();
            $themeNum = (int) $this->rgaaThemeService->getThemeFromResult($result);
            $theme = $this->rgaaThemeService->getTheme($themeNum);
            $criterion = $this->rgaaThemeService->getCriteriaFromResult($result);
            $criterionKey = $criterion ?? 'non-categorise';

            if (!isset($groupedByTheme[$themeNum])) {
                $groupedByTheme[$themeNum] = [
                    'theme_number' => $themeNum,
                    'theme_name' => $theme['name'],
                    'theme_icon' => $theme['icon'],
                    'theme_color' => $theme['color'],
                    'criteria' => [],
                    'total_count' => 0
                ];
            }

            if (!isset($groupedByTheme[$themeNum]['criteria'][$criterionKey])) {
                $groupedByTheme[$themeNum]['criteria'][$criterionKey] = [
                    'criterion' => $criterion,
                    'criterion_description' => $criterion ? $this->rgaaThemeService->getCriterionDescription($criterion) : '',
                    'results' => [
                        'critical' => [],
                        'major' => [],
                        'minor' => []
                    ],
                    'total_count' => 0
                ];
            }

            $errorType = $result->getErrorType();
            $found = false;
            foreach ($groupedByTheme[$themeNum]['criteria'][$criterionKey]['results'][$severity] as &$group) {
                if ($group['errorType'] === $errorType && $group['source'] === $result->getSource()) {
                    $group['occurrences'][] = $result;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $groupedByTheme[$themeNum]['criteria'][$criterionKey]['results'][$severity][] = [
                    'errorType' => $errorType,
                    'source' => $result->getSource(),
                    'recommendation' => $result->getRecommendation(),
                    'codeFix' => $result->getCodeFix(),
                    'impactUser' => $result->getImpactUser(),
                    'wcagCriteria' => $result->getWcagCriteria(),
                    'rgaaCriteria' => $result->getRgaaCriteria(),
                    'description' => $result->getDescription(),
                    'occurrences' => [$result],
                    'priorityScore' => 0
                ];
            }

            $groupedByTheme[$themeNum]['criteria'][$criterionKey]['total_count']++;
            $groupedByTheme[$themeNum]['total_count']++;
        }

        return $groupedByTheme;
    }

    /**
     * Calculate priority scores for all groups
     */
    private function calculatePriorityScores(array &$groupedByTheme): void
    {
        foreach ($groupedByTheme as &$theme) {
            foreach ($theme['criteria'] as &$criterionData) {
                foreach (['critical', 'major', 'minor'] as $severity) {
                    foreach ($criterionData['results'][$severity] as &$group) {
                        $group['priorityScore'] = $this->priorityService->calculatePriorityScore(
                            $severity,
                            count($group['occurrences']),
                            $group['impactUser'],
                            $group['wcagCriteria']
                        );
                    }
                }
            }
        }
    }

    /**
     * Get priority level text
     */
    private function getPriorityLevel(int $score): string
    {
        if ($score >= 80) {
            return 'Priorité 1 - Très urgent';
        } elseif ($score >= 60) {
            return 'Priorité 2 - Urgent';
        } elseif ($score >= 40) {
            return 'Priorité 3 - Moyen';
        } else {
            return 'Priorité 4 - Faible';
        }
    }

    /**
     * Get priority color - Modern palette
     */
    private function getPriorityColorModern(int $score): string
    {
        if ($score >= 80) {
            return 'E53E3E'; // Modern red
        } elseif ($score >= 60) {
            return 'DD6B20'; // Modern orange
        } elseif ($score >= 40) {
            return '3182CE'; // Modern blue
        } else {
            return '718096'; // Modern gray
        }
    }

    /**
     * Format source name
     */
    private function formatSource(string $source): string
    {
        return match($source) {
            'playwright' => 'Playwright',
            'axe-core' => 'Axe-core',
            'a11ylint' => 'A11yLint',
            'gemini-vision', 'gemini-image-analysis' => 'Gemini AI Vision',
            'ia_context' => 'Gemini AI Context',
            default => ucfirst($source)
        };
    }

    /**
     * Get filename for Excel export
     */
    public function getFilename(Audit $audit): string
    {
        $date = $audit->getCreatedAt()->format('Y-m-d');
        $urlSlug = preg_replace('/[^a-z0-9]+/i', '-', parse_url($audit->getUrl(), PHP_URL_HOST) ?? 'audit');
        return "audit-rgaa-{$urlSlug}-{$date}.xlsx";
    }
}
