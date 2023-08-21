<?php

declare(strict_types=1);

namespace Akeneo\TemplateGenerator\Command;

use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\SheetInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateTemplateCommand extends Command
{
    protected static $defaultName = 'pim-family-template:create';

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Generate json template files from the xlsx template file')
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the xlsx file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $reader = $this->getReader();
        $reader->open($input->getArgument('file'));
        $industries = $this->getIndustries($reader);
        $familyTemplates = $this->getFamilyTemplates($reader, $industries);
        $attributeOptions = $this->getAttributeOptions($reader);

        file_put_contents('output.json', json_encode([
            'industries' => $industries,
            'family_templates' => $familyTemplates,
            'attribute_options' => $attributeOptions
        ]));

        return 1;
    }

    private function getSheetContent(ReaderInterface $reader, string $sheetName): array
    {
        $content = [];
        $sheet = $this->getSheet($reader, $sheetName);
        $rows = $sheet->getRowIterator();

        $rows->rewind();
        $headers = $rows->current()->toArray();
        $rows->next();
        while ($rows->valid() ){
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

    private function getReader(): ReaderInterface
    {
        $options = new XlsxOptions();
        $options->SHOULD_FORMAT_DATES = $normalizedOptions['shouldFormatDates'] ?? $options->SHOULD_FORMAT_DATES;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = $normalizedOptions['shouldPreserveEmptyRows'] ?? $options->SHOULD_PRESERVE_EMPTY_ROWS;

        return new XlsxReader($options);
    }

    private function getIndustries(ReaderInterface $reader): array
    {
        $rawIndustries = $this->getSheetContent($reader, 'Industries');

        $industries = array_map(static fn(array $industry) => [
            'code' => $industry['code'],
            'labels' => [
                'en_US' => $industry['label-en_US'],
            ],
            'family_templates' => explode(',', $industry['Families per industry'])
        ], $rawIndustries);

        return array_combine(array_column($industries, 'code'), $industries);
    }

    private function getFamilyTemplates(ReaderInterface $reader, array $industries): array
    {
        $familyTemplates = [];
        foreach ($industries as $industry) {
            foreach ($industry['family_templates'] as $familyTemplateCode) {
                $familyTemplates[$familyTemplateCode] = $this->getFamilyTemplate($reader, $familyTemplateCode);
            }
        }

        return $familyTemplates;
    }

    private function getFamilyTemplate(ReaderInterface $reader, string $familyTemplateCode): array
    {
        $familyTemplate = $this->getSheetContent($reader, $familyTemplateCode);

        $attributes = array_map(function (array $rawAttribute) {
            $attribute = [
                'code' => $rawAttribute['code'],
                'labels' => [
                    'en_US' => $rawAttribute['label-en_US'],
                ],
                'type' => $rawAttribute['type'],
                'scopable' => $rawAttribute['scopable'] === 1,
                'localizable' => $rawAttribute['localizable'] === 1,
                'group' => $rawAttribute['group'],
                'unique' => $rawAttribute['unique'] === 1,
            ];

            if ('' !== $rawAttribute['metric_family']) {
                $attribute['metric_family'] = $rawAttribute['metric_family'];
            }

            return $attribute;
        }, $familyTemplate);

        return [
            'code' => $familyTemplateCode,
            'description' => $this->getFamilyTemplateDescription($reader, $familyTemplateCode),
            'attributes' => $attributes,
        ];
    }

    private function getFamilyTemplateDescription(ReaderInterface $reader, string $familyTemplateCode): array
    {
        $rawDescriptions = $this->getSheetContent($reader, 'families_descriptions');

        $rawDescriptions = array_filter(
            $rawDescriptions,
            static fn (array $description) => $description['family'] === $familyTemplateCode,
        );

        return [
            'en_US' => current($rawDescriptions)['description-en_US']
        ];
    }

    private function getAttributeOptions(ReaderInterface $reader): array
    {
        $rawAttributeOptions = $this->getSheetContent($reader, 'attribute_options');

        $attributeOptions = array_map(static fn (array $attributeOption) => [
            'code' => $attributeOption['code'],
            'labels' => [
                'en_US' => $attributeOption['label-en_US'],
            ],
            'attribute' => $attributeOption['attribute']
        ], $rawAttributeOptions);

        return array_combine(array_column($attributeOptions, 'code'), $attributeOptions);
    }
}
