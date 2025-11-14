<?php

namespace App\Command;

use App\Repository\AuditRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:recalculate-audit-stats',
    description: 'Recalculate statistics for all audits',
)]
class RecalculateAuditStatsCommand extends Command
{
    public function __construct(
        private AuditRepository $auditRepository,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Recalculating audit statistics');

        $audits = $this->auditRepository->findAll();
        $io->progressStart(count($audits));

        $updated = 0;

        foreach ($audits as $audit) {
            // Count by severity
            $criticalCount = 0;
            $majorCount = 0;
            $minorCount = 0;
            $totalIssues = 0;

            foreach ($audit->getAuditResults() as $result) {
                $severity = $result->getSeverity();
                $totalIssues++;

                if ($severity === 'critical') {
                    $criticalCount++;
                } elseif ($severity === 'major') {
                    $majorCount++;
                } else {
                    $minorCount++;
                }
            }

            // Update counts
            $audit->setCriticalCount($criticalCount);
            $audit->setMajorCount($majorCount);
            $audit->setMinorCount($minorCount);
            $audit->setTotalIssues($totalIssues);

            $updated++;
            $io->progressAdvance();

            // Flush every 20 audits to avoid memory issues
            if ($updated % 20 === 0) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();
        $io->progressFinish();

        $io->success("Successfully recalculated statistics for {$updated} audits.");

        return Command::SUCCESS;
    }
}
