<?php

namespace Akeneo\PimFamilyTemplates\Command;

use Google\Cloud\BigQuery\BigQueryClient;
use JiraRestApi\Board\BoardService;
use JiraRestApi\Issue\AgileIssue;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class CalculateMetricsCommand extends Command
{
    private const SERVICES_JIRA_BOARD_ID = 74;
    private const SERVICES_JIRA_MAX_RESULTS = 500;
    private const SF_PROJECT_ID_JIRA_CUSTOM_FIELD = 'customfield_13563';
    private const GCP_LOCATION = 'eu';
    private const GCP_PROD_PROJECT_ID = 'akecld-prd-pim-saas-prod';

    protected static $defaultName = 'metrics:calculate';

    public function __construct(
        private readonly BoardService $jiraBoardService,
        private readonly BigQueryClient $bigQueryClient,
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
        $projectIds = $this->findProjectIdsOlderLessThan6MonthsFromJira();
        $output->writeln(sprintf('Fetched %d project ids which have kickoff date older less than 6 months from Jira', count($projectIds)));

        $tenantIdsByProjectIds = $this->findTenantIdsByProjectIdsFromBigQuery($projectIds);
        $output->writeln(sprintf('Fetched %d (out of %d) project ids which have tenant id from BigQuery', count($tenantIdsByProjectIds), count($projectIds)));

        $projectIdsWhoUsedFeature = $this->findProjectIdsWhoUsedFeatureFromBigQuery($tenantIdsByProjectIds);
        $output->writeln(sprintf('Found %d (out of %d) project ids which used the feature from BigQuery', count($projectIdsWhoUsedFeature), count($projectIds)));

        $metric = count($projectIdsWhoUsedFeature) / count($projectIds) * 100;
        $output->writeln(sprintf('Metric 1: %f percents of new customers (6 months) has used Family Template feature', $metric));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, array{sf_project_id: string, kick_off_date: \DateTimeImmutable}>
     */
    private function findProjectIdsOlderLessThan6MonthsFromJira(): array
    {
        $jql = '"SF Project ID[Short text]" is not empty AND "Project Kick Off Date[Date]" > startOfDay(-6M)';

        $issues = $this->jiraBoardService->getBoardIssues(self::SERVICES_JIRA_BOARD_ID, [
            'jql' => $jql,
            'maxResults' => self::SERVICES_JIRA_MAX_RESULTS,
        ]);

        if (self::SERVICES_JIRA_MAX_RESULTS <= $issues->count()) {
            throw new \RuntimeException('Max results reached on Jira API. Increase it or implement pagination.');
        }

        return array_map(
            static fn (AgileIssue $issue) => $issue->fields->customFields[self::SF_PROJECT_ID_JIRA_CUSTOM_FIELD],
            $issues->getArrayCopy(),
        );
    }

    private function findTenantIdsByProjectIdsFromBigQuery(array $projectIds): array
    {
        $sql = <<<SQL
SELECT
  sf_project.project_record_id as sf_project_id,
  ARRAY_AGG(papo_product.instance_name IGNORE NULLS) as tenant_ids
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
            sprintf('--project_id=%s', self::GCP_PROD_PROJECT_ID),
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

        $tenantIdsByProjectIds = [];
        foreach ($rows as $row) {
            $tenantIdsByProjectIds[$row['sf_project_id']] = $row['tenant_ids'];
        }

        return $tenantIdsByProjectIds;
    }

    private function findProjectIdsWhoUsedFeatureFromBigQuery(array $tenantIdsByProjectIds): array
    {
        $sql = <<<SQL
SELECT DISTINCT(tenant_id)
FROM `akecld-saas-dev.raccoons.create_family_from_template_usage`
WHERE tenant_id IN UNNEST(?) AND gcp_project_id = ?;
SQL;

        $tenantIds = array_reduce(
            $tenantIdsByProjectIds,
            static fn (array $accumulator, array $tenantIds) => [...$accumulator, ...$tenantIds],
            [],
        );

        $query = $this->bigQueryClient->query($sql)->parameters([$tenantIds, self::GCP_PROD_PROJECT_ID]);
        $results = $this->bigQueryClient->runQuery($query);
        $results->waitUntilComplete();

        $tenantsIdsWhoUsedFeature = array_reduce(
            iterator_to_array($results),
            static fn ($accumulator, $result) => [...$accumulator, $result['tenant_id']],
            [],
        );

        return array_keys(array_filter(
            $tenantIdsByProjectIds,
            static fn (array $tenantIds) => 0 < count(array_intersect($tenantsIdsWhoUsedFeature, $tenantIds)),
        ));
    }
}
