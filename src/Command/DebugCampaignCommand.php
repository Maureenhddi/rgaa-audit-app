<?php

namespace App\Command;

use App\Repository\AuditCampaignRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:debug:campaign',
    description: 'Debug campaign data'
)]
class DebugCampaignCommand extends Command
{
    public function __construct(
        private AuditCampaignRepository $campaignRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('id', null, InputOption::VALUE_REQUIRED, 'Campaign ID', 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $campaignId = $input->getOption('id');

        $campaign = $this->campaignRepository->find($campaignId);

        if (!$campaign) {
            $io->error('Campaign not found');
            return Command::FAILURE;
        }

        $io->title('Campaign Debug Info');

        $io->section('Basic Info');
        $io->table(
            ['Property', 'Value'],
            [
                ['ID', $campaign->getId()],
                ['Name', $campaign->getName()],
                ['Status', $campaign->getStatus()],
            ]
        );

        $io->section('Audits');
        $io->writeln('Page Audits Count: ' . $campaign->getPageAudits()->count());

        foreach ($campaign->getPageAudits() as $audit) {
            $io->writeln(sprintf(
                '  - Audit #%d: %s (Status: %s)',
                $audit->getId(),
                $audit->getUrl(),
                $audit->getStatus()->value ?? 'unknown'
            ));
        }

        $io->section('Methods Check');
        $io->writeln('hasCompletedAudits(): ' . ($campaign->hasCompletedAudits() ? 'TRUE' : 'FALSE'));
        $io->writeln('areAllPagesAudited(): ' . ($campaign->areAllPagesAudited() ? 'TRUE' : 'FALSE'));

        $io->section('Action Plans');
        $io->writeln('Action Plans Count: ' . $campaign->getActionPlans()->count());

        foreach ($campaign->getActionPlans() as $plan) {
            $io->writeln(sprintf(
                '  - Plan #%d: %s',
                $plan->getId(),
                $plan->getName()
            ));
        }

        return Command::SUCCESS;
    }
}
