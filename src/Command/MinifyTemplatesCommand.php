<?php

declare(strict_types=1);

namespace Akeneo\PimFamilyTemplates\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MinifyTemplatesCommand extends Command
{
    protected static $defaultName = 'templates:minify';

    protected function configure(): void
    {
        $this
            ->setDescription('Minify JSON template files')
            ->addArgument('templates_directory', InputArgument::REQUIRED, 'Template files directory to minify')
            ->addArgument('output_file', InputArgument::REQUIRED, 'Output file path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $templatesDirectory = $input->getArgument('templates_directory');

        $industries = $this->readIndustries($templatesDirectory);
        $familyTemplates = $this->readFamilyTemplates($templatesDirectory);
        $attributeOptions = $this->readAttributeOptions($templatesDirectory);

        $familyTemplates = $this->addAttributeOptionsToFamilyTemplates($familyTemplates, $attributeOptions);
        $outputFile = $input->getArgument('output_file');
        file_put_contents($outputFile, json_encode([
            'industries' => $industries,
            'family_templates' => $familyTemplates,
        ]));

        return Command::SUCCESS;
    }

    private function readIndustries(string $templatesDirectory): array
    {
        $industriesFilePath = $this->getFilePath($templatesDirectory, 'industries');

        return $this->readJsonFile($industriesFilePath);
    }

    private function readFamilyTemplates(string $templatesDirectory): array
    {
        $familyTemplatesDirectory = sprintf('%s/%s', $templatesDirectory, 'families');
        $familyTemplateFiles = array_filter(scandir($familyTemplatesDirectory), static fn (string $file) => !in_array($file, ['.', '..']));

        $familyTemplates = [];

        foreach ($familyTemplateFiles as $familyTemplateFile) {
            $familyTemplateFilePath = sprintf('%s/%s', $familyTemplatesDirectory, $familyTemplateFile);
            $familyTemplate = $this->readJsonFile($familyTemplateFilePath);
            $familyTemplates[$familyTemplate['code']] = $familyTemplate;
        }

        return $familyTemplates;
    }

    private function readAttributeOptions(string $templatesDirectory): array
    {
        $attributeOptionsFilePath = $this->getFilePath($templatesDirectory, 'attribute_options');

        return $this->readJsonFile($attributeOptionsFilePath);
    }

    private function getFilePath(string $templatesDirectory, string $fileNameWithoutExtension): string
    {
        return sprintf('%s/%s.json', $templatesDirectory, $fileNameWithoutExtension);
    }

    private function readJsonFile(string $filePath): array
    {
        $json = file_get_contents($filePath);

        return json_decode(json: $json, flags: JSON_OBJECT_AS_ARRAY);
    }

    private function addAttributeOptionsToFamilyTemplates(array $familyTemplates, array $attributeOptions): array
    {
        return array_map(fn (array $familyTemplate) => $this->addAttributeOptionsToFamilyTemplate($familyTemplate, $attributeOptions), $familyTemplates);
    }

    private function addAttributeOptionsToFamilyTemplate(array $familyTemplate, array $attributeOptions): array
    {
        $attributeCodes = array_map(static fn ($attribute) => $attribute['code'], $familyTemplate['attributes']);

        $familyTemplate['attribute_options'] = array_filter(
            $attributeOptions,
            static fn (array $attributeOption) => in_array($attributeOption['attribute'], $attributeCodes),
        );

        return $familyTemplate;
    }
}
