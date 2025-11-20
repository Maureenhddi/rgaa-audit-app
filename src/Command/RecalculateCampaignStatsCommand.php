<?php

namespace App\Command;

use App\Repository\AuditCampaignRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:campaign:recalculate-stats',
    description: 'Recalculate statistics for all campaigns or a specific campaign'
)]
class RecalculateCampaignStatsCommand extends Command
{
    public function __construct(
        private AuditCampaignRepository $campaignRepository,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('campaign-id', null, InputOption::VALUE_OPTIONAL, 'ID of a specific campaign to recalculate');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $campaignId = $input->getOption('campaign-id');

        if ($campaignId) {
            $campaign = $this->campaignRepository->find($campaignId);
            if (!$campaign) {
                $io->error(sprintf('Campaign with ID %d not found', $campaignId));
                return Command::FAILURE;
            }
            $campaigns = [$campaign];
        } else {
            $campaigns = $this->campaignRepository->findAll();
        }

        $io->title('Recalculating Campaign Statistics');

        $count = 0;
        foreach ($campaigns as $campaign) {
            $io->writeln(sprintf('Processing campaign #%d: %s', $campaign->getId(), $campaign->getName()));

            $beforePages = $campaign->getTotalPages();
            $beforeConformity = $campaign->getAvgConformityRate();
            $beforeIssues = $campaign->getTotalIssues();

            $campaign->recalculateStatistics();

            $afterPages = $campaign->getTotalPages();
            $afterConformity = $campaign->getAvgConformityRate();
            $afterIssues = $campaign->getTotalIssues();

            $io->table(
                ['Metric', 'Before', 'After'],
                [
                    ['Total Pages', $beforePages ?? 0, $afterPages ?? 0],
                    ['Avg Conformity', $beforeConformity ? $beforeConformity . '%' : '-', $afterConformity ? $afterConformity . '%' : '-'],
                    ['Total Issues', $beforeIssues ?? 0, $afterIssues ?? 0],
                ]
            );

            $count++;
        }

        $this->entityManager->flush();

        $io->success(sprintf('Successfully recalculated statistics for %d campaign(s)', $count));

        return Command::SUCCESS;
    }
}
