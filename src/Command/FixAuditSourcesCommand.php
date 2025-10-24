<?php

namespace App\Command;

use App\Repository\AuditResultRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fix-audit-sources',
    description: 'Fix audit result sources based on errorType for existing data'
)]
class FixAuditSourcesCommand extends Command
{
    public function __construct(
        private AuditResultRepository $auditResultRepository,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Fixing Audit Result Sources');

        // Get all audit results
        $results = $this->auditResultRepository->findAll();
        $total = count($results);

        if ($total === 0) {
            $io->warning('No audit results found in database.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d audit results to process.', $total));

        $updated = 0;
        $axeCore = 0;
        $htmlCs = 0;
        $playwright = 0;

        $io->progressStart($total);

        foreach ($results as $result) {
            $errorType = $result->getErrorType() ?? '';
            $currentSource = $result->getSource();
            $newSource = null;

            // Determine correct source based on errorType
            if (stripos($errorType, 'Axe-core') !== false) {
                $newSource = 'axe-core';
                $axeCore++;
            } elseif (stripos($errorType, 'HTML_CodeSniffer') !== false) {
                $newSource = 'html_codesniffer';
                $htmlCs++;
            } elseif ($currentSource === 'playwright') {
                // Already correct
                $playwright++;
            } else {
                // Set to playwright by default
                $newSource = 'playwright';
                $playwright++;
            }

            // Update if different
            if ($newSource && $currentSource !== $newSource) {
                $result->setSource($newSource);
                $this->entityManager->persist($result);
                $updated++;
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        // Flush all changes at once
        if ($updated > 0) {
            $this->entityManager->flush();
            $io->success(sprintf('Successfully updated %d audit results!', $updated));
        } else {
            $io->info('No updates needed. All sources are already correct.');
        }

        // Show statistics
        $io->section('Statistics');
        $io->table(
            ['Source', 'Count'],
            [
                ['playwright', $playwright],
                ['axe-core', $axeCore],
                ['html_codesniffer', $htmlCs],
                ['TOTAL', $total],
            ]
        );

        return Command::SUCCESS;
    }
}
