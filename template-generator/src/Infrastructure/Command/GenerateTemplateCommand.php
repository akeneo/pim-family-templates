<?php

declare(strict_types=1);

namespace Akeneo\TemplateGenerator\Infrastructure\Command;

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
        $industries = $this->getSheetContent($reader, 'Industries');

        return array_map(fn(array $industry) => [
            'code' => $industry['code'],
            'label' => [
                'en_US' => $industry['label-en_US'],
            ],
            'family_templates' => explode(',', $industry['Families per industry'])
        ], $industries);
    }

    private function getFamilyTemplates(ReaderInterface $reader, array $industries): array
    {
        $familyTemplates = [];
        foreach ($industries as $industry) {
            foreach ($industry['family_templates'] as $familyTemplate) {
                $familyTemplates[] = $this->getFamilyTemplate($reader, $familyTemplate);
            }
        }

        return $familyTemplates;
    }

    private function getFamilyTemplate(ReaderInterface $reader, string $familyTemplateCode): array
    {
        $familyTemplate = $this->getSheetContent($reader, $familyTemplateCode);

        $attributes = array_map(fn (array $attribute) => [
            'code' => $attribute['code'],
            'label' => [
                'en_US' => $attribute['label-en_US'],
            ],
            'type' => $attribute['type'],
            'scopable' => $attribute['scopable'] === 1,
            'localizable' => $attribute['localizable'] === 1,
            'group' => $attribute['group'],
            'metric_family' => $attribute['metric_family'],
            'unique' => $attribute['unique'] === 1,
        ], $familyTemplate);

        return [
            'code' => $familyTemplateCode,
            'attributes' => $attributes,
        ];
    }

    private function getAttributeOptions(ReaderInterface $reader): array
    {
        $attributeOptions = $this->getSheetContent($reader, 'attribute_options');

        return array_map(fn (array $attributeOption) => [
            'code' => $attributeOption['code'],
            'label' => [
                'en_US' => $attributeOption['label-en_US'],
            ],
            'attribute' => $attributeOption['attribute']
        ], $attributeOptions);
    }
}
