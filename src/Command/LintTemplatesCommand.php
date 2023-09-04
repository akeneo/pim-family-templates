<?php

namespace Akeneo\PimFamilyTemplates\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\EqualTo;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Optional;
use Symfony\Component\Validator\Constraints\Required;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Constraints\Unique;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class LintTemplatesCommand extends Command
{
    private const ATTRIBUTE_TYPES = [
        'pim_catalog_boolean',
        'pim_catalog_date',
        'pim_catalog_file',
        'pim_catalog_identifier',
        'pim_catalog_image',
        'pim_catalog_metric',
        'pim_catalog_number',
        'pim_catalog_multiselect',
        'pim_catalog_simpleselect',
        'pim_catalog_price_collection',
        'pim_catalog_textarea',
        'pim_catalog_text',
        'akeneo_reference_entity',
        'akeneo_reference_entity_collection',
        'pim_catalog_asset_collection',
        'pim_catalog_table',
    ];

    protected static $defaultName = 'templates:lint';
    private ValidatorInterface $validator;

    public function __construct()
    {
        $this->validator = Validation::createValidator();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Lint JSON template files')
            ->addArgument('templates_directory', InputArgument::REQUIRED, 'Template files directory to minify')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $templateDirectory = $input->getArgument('templates_directory');
        $industries = $this->readIndustries($templateDirectory);
        $families = $this->readFamilies($templateDirectory);
        $attributeOptions = $this->readAttributeOptions($templateDirectory);
        $familyCodesInIndustries = $this->getFamilyCodesInIndustries($industries);
        $familyTemplateFileNames = array_keys($families);
        $attributeCodesInFamilies = $this->getAttributeCodesInFamilies($families);

        $industriesViolations = $this->lintIndustries($industries, $familyTemplateFileNames);
        $familiesViolations = $this->lintFamilies($families, $familyCodesInIndustries);
        $attributeOptionsViolations = $this->lintAttributeOptions($attributeOptions, $attributeCodesInFamilies);

        $flattenViolations = [
            ...$this->flatViolations('industries', $industriesViolations),
            ...$this->flatViolations('families', $familiesViolations),
            ...$this->flatViolations('attribute_options', $attributeOptionsViolations),
        ];

        $this->displayViolations($output, $flattenViolations);

        return 0 === count($flattenViolations) ? Command::SUCCESS : Command::FAILURE;
    }

    private function readIndustries(string $templatesDirectory): array
    {
        $industriesFilePath = sprintf('%s/%s', $templatesDirectory, 'industries.json');

        return $this->readJsonFile($industriesFilePath);
    }

    private function readFamilies(string $templateDirectory): array
    {
        $families = [];

        $familiesDirectory = sprintf('%s/%s', $templateDirectory, 'families');
        $familyFileNames = array_filter(
            scandir($familiesDirectory),
            static fn (string $file) => !in_array($file, ['.', '..']),
        );

        foreach ($familyFileNames as $familyFileName) {
            $familyFilePath = sprintf('%s/%s', $familiesDirectory, $familyFileName);
            $familyCode = str_replace('.json', '', $familyFileName);
            $families[$familyCode] = $this->readJsonFile($familyFilePath);
        }

        return $families;
    }

    private function readAttributeOptions(string $templatesDirectory): array
    {
        $attributeOptionsFilePath = sprintf('%s/%s', $templatesDirectory, 'attribute_options.json');

        return $this->readJsonFile($attributeOptionsFilePath);
    }

    private function getFamilyCodesInIndustries(array $industries): array
    {
        return array_reduce(
            $industries,
            static fn (array $familyCodes, array $industry) => [...$familyCodes, ...($industry['family_templates'] ?? [])],
            [],
        );
    }

    private function getAttributeCodesInFamilies(array $families): array
    {
        return array_reduce(
            $families,
            static fn (array $attributesCodes, array $family) => [...$attributesCodes, ...array_column($family['attributes'] ?? [], 'code')],
            [],
        );
    }

    private function lintIndustries(array $industries, array $familyTemplateFileNames): array
    {
        $violations = [];

        $lintedIndustryCodes = [];
        foreach ($industries as $key => $industry) {
            $violations[$key] = $this->validator->validate($industry, new Collection([
                'code' => new EqualTo(
                    value: $key,
                    message: 'This value should match with key.',
                ),
                'labels' => new Collection([
                    'en_US' => [
                        new NotBlank(),
                        new Length(max: 255),
                    ],
                ]),
                'family_templates' => [
                    new Count(min: 1),
                    new All([
                        new NotBlank(),
                        new Choice(
                            choices: $familyTemplateFileNames,
                            message: 'This value should match with a family template file name.',
                        ),
                    ]),
                    new Unique(),
                ],
            ]));

            if (!array_key_exists('code', $industry) || '' === $industry['code']) {
                continue;
            }

            if (in_array($industry['code'], $lintedIndustryCodes)) {
                $violations[$key]->add(new ConstraintViolation(
                    'This value should be unique.',
                    null,
                    [],
                    null,
                    '[code]',
                    null,
                ));
            }

            $lintedIndustryCodes[] = $industry['code'];
        }

        return $violations;
    }

    private function lintFamilies(array $families, array $familyCodesInIndustries): array
    {
        $violations = [];

        foreach ($families as $fileName => $family) {
            $violations[$fileName] = $this->validator->validate($family, new Collection([
                'code' => new EqualTo(
                    value: $fileName,
                    message: 'This value should match with file name.',
                ),
                'labels' => new Collection([
                    'en_US' => [
                        new NotBlank(),
                        new Length(max: 255),
                    ],
                ]),
                'description' => new Collection([
                    'en_US' => [
                        new NotBlank(),
                        new Length(max: 255),
                    ],
                ]),
                'attributes' => [
                    new Count(min: 1),
                    new All(new Collection([
                        'code' => new NotBlank(),
                        'labels' => new Collection([
                            'en_US' => [
                                new NotBlank(),
                                new Length(max: 255),
                            ],
                        ]),
                        'type' => new Choice(
                            choices: self::ATTRIBUTE_TYPES,
                            message: 'This value is not a valid attribute type.',
                        ),
                        'scopable' => [
                            new Type('bool'),
                            new Required(),
                        ],
                        'localizable' => [
                            new Type('bool'),
                            new Required(),
                        ],
                        'group' => new NotBlank(),
                        'unique' => [
                            new Type('bool'),
                            new Required(),
                        ],
                        'metric_family' => new Optional(),
                    ])),
                ],
            ]));

            if (!in_array($fileName, $familyCodesInIndustries)) {
                $violations[$fileName]->add(new ConstraintViolation(
                    'This value should be referenced in an industry.',
                    null,
                    [],
                    null,
                    '',
                    null,
                ));
            }

            if (array_key_exists('attributes', $family) && !empty($family['attributes'])) {
                $hasAttributeIdentifier = false;

                foreach ($family['attributes'] as $index => $attribute) {
                    if (!array_key_exists('type', $attribute)) {
                        continue;
                    }

                    $hasAttributeIdentifier = $hasAttributeIdentifier || 'pim_catalog_identifier' === $attribute['type'];

                    if ('pim_catalog_metric' === $attribute['type']) {
                        $propertyPath = sprintf('[attributes][%d][metric_family]', $index);
                        switch (true) {
                            case !array_key_exists('metric_family', $attribute):
                                $violations[$fileName]->add(new ConstraintViolation(
                                    'This field is missing.',
                                    null,
                                    [],
                                    null,
                                    $propertyPath,
                                    null,
                                ));
                                break;
                            case empty($attribute['metric_family']):
                                $violations[$fileName]->add(new ConstraintViolation(
                                    'This value should not be blank.',
                                    null,
                                    [],
                                    null,
                                    $propertyPath,
                                    null,
                                ));
                                break;
                        }
                    }
                }

                if (!$hasAttributeIdentifier) {
                    $violations[$fileName]->add(new ConstraintViolation(
                        'This collection should contain 1 attribute identifier or more.',
                        null,
                        [],
                        null,
                        '[attributes]',
                        null,
                    ));
                }
            }
        }

        return $violations;
    }

    private function lintAttributeOptions(array $attributeOptions, array $attributeCodesInFamilies): array
    {
        $violations = [];

        $lintedAttributeOptionCodes = [];
        foreach ($attributeOptions as $key => $attributeOption) {
            $violations[$key] = $this->validator->validate($attributeOption, new Collection([
                'code' => new EqualTo(
                    value: $key,
                    message: 'This value should match with key.',
                ),
                'labels' => new Collection([
                    'en_US' => [
                        new NotBlank(),
                        new Length(max: 255),
                    ],
                ]),
                'attribute' => new Choice(
                    choices: $attributeCodesInFamilies,
                    message: 'This value is not referenced in any family.',
                ),
            ]));

            if (!array_key_exists('code', $attributeOption) || empty($attributeOption['code'])) {
                continue;
            }

            if (in_array($attributeOption['code'], $lintedAttributeOptionCodes)) {
                $violations[$key]->add(new ConstraintViolation(
                    'This value should be unique.',
                    null,
                    [],
                    null,
                    '[code]',
                    null,
                ));
            }

            $lintedAttributeOptionCodes[] = $attributeOption['code'];
        }

        return $violations;
    }

    private function flatViolations(string $rootPath, array $violationsIndexedByCodes): array
    {
        $flattenViolations = [];

        foreach ($violationsIndexedByCodes as $code => $violations) {
            foreach ($violations as $violation) {
                $flattenPath = sprintf('[%s][%s]%s', $rootPath, $code, $violation->getPropertyPath());
                $flattenViolations[$flattenPath] = $violation->getMessage();
            }
        }

        return $flattenViolations;
    }

    private function displayViolations(OutputInterface $output, array $violations): void
    {
        foreach ($violations as $path => $message) {
            $output->writeln(sprintf('<error>%s %s</error>', $path, $message));
        }
    }

    private function readJsonFile(string $filePath)
    {
        $json = file_get_contents($filePath);

        return json_decode(json: $json, flags: JSON_OBJECT_AS_ARRAY);
    }
}
