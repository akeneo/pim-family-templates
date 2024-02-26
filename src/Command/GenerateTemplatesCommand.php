<?php

declare(strict_types=1);

namespace Akeneo\PimFamilyTemplates\Command;

use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\SheetInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateTemplatesCommand extends Command
{
    protected static $defaultName = 'templates:generate';

    protected function configure(): void
    {
        $this
            ->setDescription('Generate JSON template files from XLSX template file')
            ->addArgument('source_file', InputArgument::REQUIRED, 'Source file path')
            ->addArgument('output_directory', InputArgument::REQUIRED, 'Output directory')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sourceFile = $input->getArgument('source_file');

        $reader = $this->openFile($sourceFile);

        $industries = $this->readIndustries($reader);
        $familyTemplates = $this->readFamilyTemplates($reader, $industries);
        $attributeOptions = $this->readAttributeOptions($reader);
        $attributeGroups = $this->readAttributeGroups($reader);

        $outputDirectory = $input->getArgument('output_directory');

        $this->ensureDirectoryExists($outputDirectory);
        $this->writeIndustriesJson($outputDirectory, $industries);
        $this->writeFamilyTemplatesJson($outputDirectory, $familyTemplates);
        $this->writeAttributeOptionsJson($outputDirectory, $attributeOptions);
        $this->writeAttributeGroupsJson($outputDirectory, $attributeGroups);

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
            $rowContent = array_map(
                static fn (mixed $value) => is_int($value) ? strval($value) : $value,
                $rows->current()->toArray(),
            );

            $content[] = array_combine($headers, $rowContent);

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
        $familyTemplateLabelsAndDescription = $this->readFamilyTemplateLabelsAndDescription($reader);

        $familyTemplates = [];
        foreach ($industries as $industry) {
            foreach ($industry['family_templates'] as $familyTemplateCode) {
                $familyTemplates[$familyTemplateCode] = $this->readFamilyTemplate($reader, $familyTemplateCode, $familyTemplateLabelsAndDescription);
            }
        }

        return $familyTemplates;
    }

    private function readFamilyTemplateLabelsAndDescription(ReaderInterface $reader): array
    {
        $rawDescriptions = $this->getSheetContent($reader, 'families_descriptions');

        return array_reduce(
            $rawDescriptions,
            function (array $descriptions, array $rawDescription) {
                $descriptions[$rawDescription['code']] = [
                    'labels' => [
                        'en_US' => $rawDescription['label-en_US'],
                    ],
                    'description' => [
                        'en_US' => $rawDescription['description-en_US'],
                    ],
                    'attribute_as_main_media' => $rawDescription['attribute_as_main_media'],
                    'attribute_as_label' => $rawDescription['attribute_as_label'],
                ];

                return $descriptions;
            },
            [],
        );
    }

    private function readFamilyTemplate(ReaderInterface $reader, string $familyTemplateCode, array $familyTemplateLabelsAndDescription): array
    {
        $familyTemplate = $this->getSheetContent($reader, $familyTemplateCode);

        $attributes = array_map(function (array $rawAttribute) {
            $attribute = [
                'code' => $rawAttribute['code'],
                'labels' => [
                    'en_US' => $rawAttribute['label-en_US'],
                ],
                'type' => $rawAttribute['type'],
                'scopable' => '1' === $rawAttribute['scopable'],
                'localizable' => '1' === $rawAttribute['localizable'],
                'unique' => '1' === $rawAttribute['unique'],
            ];

            if (!empty($rawAttribute['metric_family'])) {
                $attribute['metric_family'] = $rawAttribute['metric_family'];
            }

            if (!empty($rawAttribute['unit'])) {
                $attribute['unit'] = $rawAttribute['unit'];
            }

            if ('' !== $rawAttribute['decimals_allowed']) {
                $attribute['decimals_allowed'] = '1' === $rawAttribute['decimals_allowed'];
            }

            if ('' !== $rawAttribute['negative_allowed']) {
                $attribute['negative_allowed'] = '1' === $rawAttribute['negative_allowed'];
            }

            return $attribute;
        }, $familyTemplate);

        return [
            'code' => $familyTemplateCode,
            ...$familyTemplateLabelsAndDescription[$familyTemplateCode],
            'attributes' => $attributes,
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

    private function readAttributeGroups(ReaderInterface $reader): array
    {
        $rawAttributeGroups = $this->getSheetContent($reader, 'attribute_groups');
        $attributeGroups = array_map(static fn (array $attributeGroup) => [
            'code' => $attributeGroup['code'],
            'labels' => [
                'en_US' => $attributeGroup['label-en_US'],
            ],
        ], $rawAttributeGroups);

        return array_combine(array_column($attributeGroups, 'code'), $attributeGroups);
    }

    private function writeIndustriesJson(string $outputDirectory, array $industries): void
    {
        $industriesJsonFilePath = sprintf('%s/%s.json', $outputDirectory, 'industries');
        $this->writeJsonFile($industriesJsonFilePath, $industries);
    }

    private function writeFamilyTemplatesJson(string $outputDirectory, array $familyTemplates): void
    {
        $familiesOutputDirectory = sprintf('%s/%s', $outputDirectory, 'families');
        $this->ensureDirectoryExists($familiesOutputDirectory);
        foreach ($familyTemplates as $code => $familyTemplate) {
            $familyJsonFilePath = sprintf('%s/%s.json', $familiesOutputDirectory, $code);
            $this->writeJsonFile($familyJsonFilePath, $familyTemplate);
        }
    }

    private function writeAttributeOptionsJson(string $outputDirectory, array $attributeOptions): void
    {
        $attributeOptionsJsonFilePath = sprintf('%s/%s.json', $outputDirectory, 'attribute_options');
        $this->writeJsonFile($attributeOptionsJsonFilePath, $attributeOptions);
    }

    private function writeAttributeGroupsJson(string $outputDirectory, array $attributeGroups): void
    {
        $attributeGroupsJsonFilePath = sprintf('%s/%s.json', $outputDirectory, 'attribute_groups');
        $this->writeJsonFile($attributeGroupsJsonFilePath, $attributeGroups);
    }

    private function writeJsonFile(string $filePath, array $data): void
    {
        $json = json_encode($data);
        file_put_contents($filePath, $json);
    }

    private function ensureDirectoryExists(string $outputDirectory): void
    {
        if (!is_dir($outputDirectory)) {
            mkdir($outputDirectory);
        }
    }
}
