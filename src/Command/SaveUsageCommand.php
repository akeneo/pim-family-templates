<?php

namespace Akeneo\PimFamilyTemplates\Command;

use Google\Cloud\BigQuery\BigQueryClient;
use GuzzleHttp\ClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SaveUsageCommand extends Command
{
    private const DATADOG_LOGS_LIMIT = 1000;
    const BIGQUERY_DATASET = 'raccoons';
    const BIGQUERY_TABLE = 'create_family_from_template_usage';

    protected static $defaultName = 'usage:save';

    public function __construct(
        private readonly ClientInterface $datadogClient,
        private readonly BigQueryClient $bigQueryClient,
    ) {
        parent::__construct();
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $mostRecentUsage = $this->fetchMostRecentUsageFromBigQuery();
        $newUsages = $this->fetchNewUsagesFromDatadog($mostRecentUsage);
        $this->saveNewUsagesToBigQuery($newUsages);

        return Command::SUCCESS;
    }


    private function fetchMostRecentUsageFromBigQuery(): array|null
    {
        $sql = <<<SQL
SELECT * FROM `akecld-saas-dev.raccoons.create_family_from_template_usage` ORDER BY created_at DESC LIMIT 1;
SQL;

        $query = $this->bigQueryClient->query($sql);
        $results = $this->bigQueryClient->runQuery($query);
        $results->waitUntilComplete();

        $results->rows()->rewind();

        return $results->rows()->current();
    }

    private function fetchNewUsagesFromDatadog(array|null $mostRecentUsage): array
    {
        $searchQuery = <<<DATADOG
"New family created from template" @channel:app @context.akeneo_context:"Family template" tags:(akecld-prd-pim-saas-prod OR akecld-prd-pim-saas-desa OR akecld-prd-pim-saas-sipa)
DATADOG;

        $search = [
            'filter' => [
                'query' => $searchQuery,
                'from' => 'now-15d',
            ],
            'page' => [
                'limit' => self::DATADOG_LOGS_LIMIT,
            ],
        ];

        $response = $this->datadogClient->request(
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

        $usages = [];
        foreach ($logs as $log) {
            if (!$this->isNewLog($mostRecentUsage, $log)) {
                continue;
            }

            $templateCode = $log['attributes']['attributes']['context']['template_code'];
            $createdAt = (new \DateTimeImmutable($log['attributes']['attributes']['datetime'], new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $tenantId = $log['attributes']['attributes']['tenant_id'];
            $gcpProjectId = null;
            foreach ($log['attributes']['tags'] as $tag) {
                if (str_starts_with($tag, 'project_id:')) {
                    $gcpProjectId = explode(':', $tag)[1];
                    break;
                }
            }

            $usages[] = [
                'template_code' => $templateCode,
                'created_at' => $createdAt,
                'tenant_id' => $tenantId,
                'gcp_project_id' => $gcpProjectId,
            ];
        }

        return $usages;
    }

    private function isNewLog(?array $mostRecentUsage, array $currentLog): bool
    {
        if (null === $mostRecentUsage) {
            return true;
        }

        $currentLogCreatedAt = new \DateTimeImmutable($currentLog['attributes']['attributes']['datetime'], new \DateTimeZone('UTC'));

        return $mostRecentUsage['created_at'] < $currentLogCreatedAt;
    }

    private function saveNewUsagesToBigQuery(array $usages): void
    {
        if (empty($usages)) {
            return;
        }

        $filePath = $this->createTemporaryFile();

        $readStream = fopen($filePath, 'w');
        foreach ($usages as $usage) {
            fputcsv($readStream, $usage);
        }
        fclose($readStream);

        $loadStream = fopen($filePath, 'r');

        $loadJob = $this->bigQueryClient
            ->dataset(self::BIGQUERY_DATASET)
            ->table(self::BIGQUERY_TABLE)
            ->load($loadStream)
            ->sourceFormat('CSV')
            ->writeDisposition('WRITE_APPEND');
        $jobResult = $this->bigQueryClient->runJob($loadJob);
        $jobResult->waitUntilComplete();

        if (is_resource($loadStream)) {
            fclose($loadStream);
        }
        $this->removeTemporaryFile($filePath);
        if (isset($jobResult->info()['status']['errorResult'])) {
            throw new \RuntimeException(sprintf('Load job has failed: %s', $jobResult->info()['status']['errorResult']['message']));
        }
    }

    private function createTemporaryFile(): string
    {
        return sprintf('%s/%s.csv', sys_get_temp_dir(), uniqid());
    }

    private function removeTemporaryFile(string $filePath): void
    {
        unlink($filePath);
    }
}
