<?php

namespace Akeneo\PimFamilyTemplates\Command;

use JiraRestApi\Board\BoardService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CalculateMetricsCommand extends Command
{
    protected static $defaultName = 'metrics:calculate';

    private const SERVICES_BOARD_ID = 74;

    public function __construct(
        private readonly BoardService $boardService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Calculate metrics on Family Template usage')
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $kickoffDates = $this->getKickoffDates();
        var_dump($kickoffDates);

        // Filter out client which have a kickoff date older than 6 months

        /*
         * @TODO Fetch client instance name from BigQuery
         * We can use this query https://console.cloud.google.com/bigquery?sq=419145096702:b5de7fb99539441ab7a1ca29815e9c6a
         */

        // Fetch client which used the feature from Datadog
        // Calculate % of client which used the feature

        return Command::SUCCESS;
    }

    /**
     * @return array<string, \DateTimeImmutable>
     */
    private function getKickoffDates(): array
    {
        $issues = $this->boardService->getBoardIssues(self::SERVICES_BOARD_ID, [
            'jql' => '"SF Project ID[Short text]" is not empty and "Project Kick Off Date[Date]" is not empty',
            'maxResults' => 1000,
        ]);

        $kickoffDates = [];
        foreach ($issues as $issue) {
            $sFProjectId = $issue->fields->customFields['customfield_13563'];
            $rawKickOffDate = $issue->fields->customFields['customfield_13564'];
            $kickoffDates[$sFProjectId] = new \DateTimeImmutable($rawKickOffDate);
        }

        return $kickoffDates;
    }
}
