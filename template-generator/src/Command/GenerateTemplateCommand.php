<?php

declare(strict_types=1);

namespace Akeneo\TemplateGenerator\Command;

use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\SheetInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateTemplateCommand extends Command
{
    protected static $defaultName = 'template-generator:template:generate';

    protected function configure(): void
    {
        $this
            ->setDescription('Generate JSON template file from XLSX template file')
            ->addArgument('source_file', InputArgument::REQUIRED, 'Source file path')
            ->addArgument('output_directory', InputArgument::REQUIRED, 'Output file path')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sourceFile = $input->getArgument('source_file');

        $reader = $this->openFile($sourceFile);

        $industries = $this->readIndustries($reader);
        $familyTemplates = $this->readFamilyTemplates($reader, $industries);
        $attributeOptions = $this->readAttributeOptions($reader);

        $outputDirectory = $input->getArgument('output_directory');

        $this->ensureDirectoryExists($outputDirectory);
        $this->writeIndustriesJson($outputDirectory, $industries);
        $this->writeFamilyTemplatesJson($outputDirectory, $familyTemplates);
        $this->writeAttributeOptionsJson($outputDirectory, $attributeOptions);

        return Command::SUCCESS;
    }

    private function getSheetContent(ReaderInterface $reader, string $sheetName): array
    {
        $content = [];
        $sheet = $this->getSheet($reader, $sheetName);
        $rows = $sheet->getRowIterator();

        $rows->rewind();
        $headers = $rows->current()->toArray();
        $rows->next();
        while ($rows->valid()) {
            $content[] = array_combine($headers, $rows->current()->toArray());

            $rows->next();
        }

        return $content;
    }

    private function getSheet(ReaderInterface $reader, string $sheetName): SheetInterface
    {
        /** @var SheetInterface $sheet */
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getName() === $sheetName) {
                return $sheet;
            }
        }

        throw new \Exception(sprintf('Cannot find the sheet name %s', $sheetName));
    }

    private function openFile(string $filePath): ReaderInterface
    {
        $reader = new XlsxReader();
        $reader->open($filePath);

        return $reader;
    }

    private function readIndustries(ReaderInterface $reader): array
    {
        $rawIndustries = $this->getSheetContent($reader, 'Industries');

        $industries = array_map(static fn (array $industry) => [
            'code' => $industry['code'],
            'labels' => [
                'en_US' => $industry['label-en_US'],
            ],
            'family_templates' => explode(',', $industry['Families per industry']),
        ], $rawIndustries);

        return array_combine(array_column($industries, 'code'), $industries);
    }

    private function readFamilyTemplates(ReaderInterface $reader, array $industries): array
    {
        $familyTemplates = [];
        foreach ($industries as $industry) {
            foreach ($industry['family_templates'] as $familyTemplateCode) {
                $familyTemplates[$familyTemplateCode] = $this->readFamilyTemplate($reader, $familyTemplateCode);
            }
        }

        return $familyTemplates;
    }

    private function readFamilyTemplate(ReaderInterface $reader, string $familyTemplateCode): array
    {
        $familyTemplate = $this->getSheetContent($reader, $familyTemplateCode);

        $attributes = array_map(function (array $rawAttribute) {
            $attribute = [
                'code' => $rawAttribute['code'],
                'labels' => [
                    'en_US' => $rawAttribute['label-en_US'],
                ],
                'type' => $rawAttribute['type'],
                'scopable' => 1 === $rawAttribute['scopable'],
                'localizable' => 1 === $rawAttribute['localizable'],
                'group' => $rawAttribute['group'],
                'unique' => 1 === $rawAttribute['unique'],
            ];

            if ('' !== $rawAttribute['metric_family']) {
                $attribute['metric_family'] = $rawAttribute['metric_family'];
            }

            return $attribute;
        }, $familyTemplate);

        return [
            'code' => $familyTemplateCode,
            'description' => $this->readFamilyTemplateDescription($reader, $familyTemplateCode),
            'attributes' => $attributes,
        ];
    }

    private function readFamilyTemplateDescription(ReaderInterface $reader, string $familyTemplateCode): array
    {
        $rawDescriptions = $this->getSheetContent($reader, 'families_descriptions');

        $rawDescriptions = array_filter(
            $rawDescriptions,
            static fn (array $description) => $description['family'] === $familyTemplateCode,
        );

        if (0 === count($rawDescriptions)) {
            return [];
        }

        return [
            'en_US' => current($rawDescriptions)['description-en_US'],
        ];
    }

    private function readAttributeOptions(ReaderInterface $reader): array
    {
        $rawAttributeOptions = $this->getSheetContent($reader, 'attribute_options');

        $attributeOptions = array_map(static fn (array $attributeOption) => [
            'code' => $attributeOption['code'],
            'labels' => [
                'en_US' => $attributeOption['label-en_US'],
            ],
            'attribute' => $attributeOption['attribute'],
        ], $rawAttributeOptions);

        return array_combine(array_column($attributeOptions, 'code'), $attributeOptions);
    }

    private function writeIndustriesJson(string $outputDirectory, array $industries): void
    {
        $industriesJsonFilePath = $this->getFilePath($outputDirectory, 'industries');
        $this->writeJsonFile($industriesJsonFilePath, $industries);
    }

    private function writeFamilyTemplatesJson(string $outputDirectory, array $familyTemplates): void
    {
        $familiesOutputDirectory = sprintf('%s/%s', $outputDirectory, 'families');
        $this->ensureDirectoryExists($familiesOutputDirectory);
        foreach ($familyTemplates as $code => $familyTemplate) {
            $familyJsonFilePath = $this->getFilePath($familiesOutputDirectory, $code);
            $this->writeJsonFile($familyJsonFilePath, $familyTemplate);
        }
    }

    private function writeAttributeOptionsJson(string $outputDirectory, array $attributeOptions): void
    {
        $industriesJsonFilePath = $this->getFilePath($outputDirectory, 'attribute_options');
        $this->writeJsonFile($industriesJsonFilePath, $attributeOptions);
    }

    private function getFilePath(string $outputDirectory, string $fileNameWithoutExtension): string
    {
        return sprintf('%s/%s.json', $outputDirectory, $fileNameWithoutExtension);
    }

    private function writeJsonFile(string $filePath, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT);
        file_put_contents($filePath, $json);
    }

    private function ensureDirectoryExists(string $outputDirectory): void
    {
        if (!is_dir($outputDirectory)) {
            mkdir($outputDirectory);
        }
    }
}
