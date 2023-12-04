<?php

namespace Akeneo\PimFamilyTemplates\Command;

use Google\Cloud\BigQuery\BigQueryClient;
use GuzzleHttp\ClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SaveUsagesCommand extends Command
{
    private const DATADOG_LOGS_LIMIT = 1000;
    private const BIGQUERY_DATASET = 'raccoons';
    private const BIGQUERY_TABLE = 'create_family_from_template_usage';

    protected static $defaultName = 'usages:save';

    public function __construct(
        private readonly ClientInterface $datadogClient,
        private readonly BigQueryClient $bigQueryClient,
    ) {
        parent::__construct();
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $latestSavedCreatedAt = $this->fetchLatestSavedCreatedAtFromBigQuery();
        $newUsages = $this->fetchNewUsagesFromDatadog($latestSavedCreatedAt);
        $this->saveNewUsagesToBigQuery($newUsages);

        $output->writeln(sprintf('%d new usages has been saved', count($newUsages)));

        return Command::SUCCESS;
    }

    private function fetchLatestSavedCreatedAtFromBigQuery(): \DateTime|null
    {
        $sql = <<<SQL
SELECT * FROM `akecld-saas-dev.raccoons.create_family_from_template_usage` ORDER BY created_at DESC LIMIT 1;
SQL;

        $query = $this->bigQueryClient->query($sql);
        $results = $this->bigQueryClient->runQuery($query);
        $results->waitUntilComplete();

        $results->rows()->rewind();

        return $results->rows()->current()['created_at'] ?? null;
    }

    private function fetchNewUsagesFromDatadog(\DateTime|null $latestSavedCreatedAt): array
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
            $createdAt = new \DateTime($log['attributes']['attributes']['datetime'], new \DateTimeZone('UTC'));

            if (!$this->isNewLog($latestSavedCreatedAt, $createdAt)) {
                continue;
            }

            $templateCode = $log['attributes']['attributes']['context']['template_code'];
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
                'created_at' => $createdAt->format('Y-m-d H:i:s.u'),
                'tenant_id' => $tenantId,
                'gcp_project_id' => $gcpProjectId,
            ];
        }

        return $usages;
    }

    private function isNewLog(?\DateTime $latestSavedCreatedAt, \DateTime $createdAt): bool
    {
        if (null === $latestSavedCreatedAt) {
            return true;
        }

        return $latestSavedCreatedAt < $createdAt;
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
