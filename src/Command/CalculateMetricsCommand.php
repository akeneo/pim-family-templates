<?php

namespace Akeneo\PimFamilyTemplates\Command;

use JiraRestApi\Board\BoardService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class CalculateMetricsCommand extends Command
{
    private const SERVICES_JIRA_BOARD_ID = 74;
    private const SERVICES_JIRA_MAX_RESULTS = 500;
    private const SF_PROJECT_ID_JIRA_CUSTOM_FIELD = 'customfield_13563';
    private const KICK_OFF_DATE_JIRA_CUSTOM_FIELD = 'customfield_13564';
    private const GCP_LOCATION = 'eu';
    private const GCP_PROJECT_ID = 'akecld-prd-pim-saas-prod';

    protected static $defaultName = 'metrics:calculate';

    public function __construct(
        private readonly BoardService $jiraBoardService,
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
        $customers = $this->findCustomersOlderLessThan6MonthsFromJira();
        $output->writeln(sprintf('Fetched %d customers which have kickoff date older less than 6 months from Jira', count($customers)));

        $projectIds = array_keys($customers);
        $customerNamesAndInstances = $this->findCustomerNamesAndInstancesFromBigQuery($projectIds);
        $output->writeln(sprintf('Fetched %d (out of %d) customer names and instances from BigQuery', count($customerNamesAndInstances), count($customers)));

        // Fetch client which used the feature from Datadog
        // Calculate % of client which used the feature

        return Command::SUCCESS;
    }

    /**
     * @return array<string, array{sf_project_id: string, kick_off_date: \DateTimeImmutable}>
     */
    private function findCustomersOlderLessThan6MonthsFromJira(): array
    {
        $jql = '"Account ID[Short text]" is not empty AND "SF Project ID[Short text]" is not empty AND "Project Kick Off Date[Date]" > startOfDay(-6M)';

        $issues = $this->jiraBoardService->getBoardIssues(self::SERVICES_JIRA_BOARD_ID, [
            'jql' => $jql,
            'maxResults' => self::SERVICES_JIRA_MAX_RESULTS,
        ]);

        if (self::SERVICES_JIRA_MAX_RESULTS <= count($issues)) {
            throw new \RuntimeException('Max results reached. Increase it or implement pagination.');
        }

        $customers = [];
        foreach ($issues as $issue) {
            $sFProjectId = $issue->fields->customFields[self::SF_PROJECT_ID_JIRA_CUSTOM_FIELD];
            $kickOffDate = new \DateTimeImmutable($issue->fields->customFields[self::KICK_OFF_DATE_JIRA_CUSTOM_FIELD]);

            $customers[$sFProjectId] = [
                'sf_project_id' => $sFProjectId,
                'kick_off_date' => $kickOffDate,
            ];
        }

        return $customers;
    }

    private function findCustomerNamesAndInstancesFromBigQuery(array $projectIds): array
    {
        $sql = <<<SQL
SELECT sf_project.Id as sf_project_id, sf_project.Customer_Account_Name__c as customer_name, JSON_OBJECT(ARRAY_AGG(papo_product.environment), ARRAY_AGG(papo_product.instance_fqdn_prefix)) as instances
FROM `ake-actionable-product-data.source_salesforce.Project__c` sf_project
JOIN `ake-actionable-product-data.source_portal.akeneo_pp_product` papo_product ON CAST(papo_product.project_id AS STRING) = sf_project.PapoID__c
WHERE sf_project.PIMType__c IN ('Cloud Serenity Mode', 'Growth Edition') AND papo_product.discr = 'pim_saas_instance' AND sf_project.Id IN (%s)
GROUP BY sf_project.Id, sf_project.Customer_Account_Name__c;
SQL;
        $sqlProjectIds = join(', ', array_map(static fn (string $projectId) => sprintf("'%s'", $projectId), $projectIds));

        $process = new Process([
            'bq',
            sprintf('--location=%s', self::GCP_LOCATION),
            sprintf('--project_id=%s', self::GCP_PROJECT_ID),
            'query',
            '--format=json',
            '--use_legacy_sql=false',
            sprintf($sql, $sqlProjectIds),
        ]);
        $process->run();

        $rows = json_decode($process->getOutput(), true);

        if (false === $rows) {
            throw new \RuntimeException(sprintf('Unable to fetch customer instances from BigQuery : %s.', $process->getErrorOutput()));
        }

        $customerNamesAndInstances = [];
        foreach ($rows as $row) {
            $customerNamesAndInstances[$row['sf_project_id']] = [
                'name' => $row['customer_name'],
                'instances' => $row['instances'],
            ];
        }

        return $customerNamesAndInstances;
    }
}
