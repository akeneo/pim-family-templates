<?php

namespace Akeneo\PimFamilyTemplates\Command;

use GuzzleHttp\ClientInterface;
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
    private const DATADOG_LOGS_LIMIT = 1000;

    protected static $defaultName = 'metrics:calculate';

    public function __construct(
        private readonly BoardService $jiraBoardService,
        private readonly ClientInterface $client,
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
        $customers = $this->findSaasCustomersOlderLessThan6MonthsFromJira();
        $output->writeln(sprintf('Fetched %d SaaS customers which have kickoff date older less than 6 months from Jira', count($customers)));

        $projectIds = array_keys($customers);
        $customersInstances = $this->findCustomersInstancesFromBigQuery($projectIds);
        $output->writeln(sprintf('Fetched %d (out of %d) customers instances from BigQuery', count($customersInstances), count($customers)));

        $customersWhoUsedFeature = $this->findCustomersWhoUsedFeatureFromDatadog($customersInstances);
        $output->writeln(sprintf('Found %d (out of %d) customers who used the feature from Datadog', count($customersWhoUsedFeature), count($customers)));

        $metric = count($customersWhoUsedFeature) / count($customers) * 100;
        $output->writeln(sprintf('Metric 1: %f percents of new customers (6 months) has used Family Template feature during the last two weeks', $metric));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, array{sf_project_id: string, kick_off_date: \DateTimeImmutable}>
     */
    private function findSaasCustomersOlderLessThan6MonthsFromJira(): array
    {
        $jql = '"SF Project ID[Short text]" is not empty AND "Project Kick Off Date[Date]" > startOfDay(-6M)';

        $issues = $this->jiraBoardService->getBoardIssues(self::SERVICES_JIRA_BOARD_ID, [
            'jql' => $jql,
            'maxResults' => self::SERVICES_JIRA_MAX_RESULTS,
        ]);

        if (self::SERVICES_JIRA_MAX_RESULTS <= count($issues)) {
            throw new \RuntimeException('Max results reached on Jira API. Increase it or implement pagination.');
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

    private function findCustomersInstancesFromBigQuery(array $projectIds): array
    {
        $sql = <<<SQL
SELECT
  sf_project.project_record_id as sf_project_id,
  ARRAY_AGG(JSON_OBJECT(['type', 'tenant_id'], [papo_product.environment, papo_product.instance_name])) as instances
FROM
  `ake-actionable-product-data.pim_customers_data.sf_project` sf_project
JOIN `ake-actionable-product-data.pim_customers_data.papo_product` papo_product ON sf_project.partners_portal_project_id = papo_product.project_id
WHERE
  sf_project.pim_version = 'SaaS' AND sf_project.project_record_id IN (%s)
GROUP BY sf_project.project_record_id;
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

        if (null === $rows) {
            throw new \RuntimeException(sprintf('Unable to fetch customer instances from BigQuery. Error output: %s', $process->getErrorOutput()));
        }

        $customersInstances = [];
        foreach ($rows as $row) {
            $customersInstances[$row['sf_project_id']] = array_map(
                static fn (string $rawInstances) => json_decode(json: $rawInstances, associative: true, flags: JSON_OBJECT_AS_ARRAY),
                $row['instances'],
            );
        }

        return $customersInstances;
    }

    private function findCustomersWhoUsedFeatureFromDatadog(array $customersInstances): array
    {
        $search = [
            'filter' => [
                'query' => '"New family created from template" @channel:app @context.akeneo_context:"Family template" tags:akecld-prd-pim-saas-prod',
                'from' => 'now-15d',
            ],
            'page' => [
                'limit' => self::DATADOG_LOGS_LIMIT,
            ],
        ];

        $response = $this->client->request(
            'POST',
            '/api/v2/logs/events/search',
            [
                'body' => json_encode($search),
            ],
        );

        $logs = json_decode($response->getBody()->getContents(), true)['data'];

        if (self::DATADOG_LOGS_LIMIT <= count($logs)) {
            throw new \RuntimeException('Logs limit reached on Datadog API. Increase it or implement pagination.');
        }

        $tenantIdsWhoUsedFeature = [];
        foreach ($logs as $log) {
            $tenantId = $log['attributes']['attributes']['tenant_id'] ?? null;
            if (null === $tenantId) {
                continue;
            }
            if (in_array($tenantId, $tenantIdsWhoUsedFeature)) {
                continue;
            }

            $tenantIdsWhoUsedFeature[] = $tenantId;
        }

        $customersWhoUsedFeature = [];
        foreach ($customersInstances as $projectId => $customerInstances) {
            foreach ($customerInstances as $customerInstance) {
                if (in_array($projectId, $customersWhoUsedFeature)) {
                    break;
                }
                if (in_array($customerInstance['tenant_id'], $tenantIdsWhoUsedFeature)) {
                    $customersWhoUsedFeature[] = $projectId;
                }
            }
        }

        return $customersWhoUsedFeature;
    }
}
