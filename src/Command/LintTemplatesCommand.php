<?php

namespace Akeneo\PimFamilyTemplates\Command;

use Akeneo\PimFamilyTemplates\Model\AttributeType;
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
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Required;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Constraints\Unique;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class LintTemplatesCommand extends Command
{
    private const VALIDATION_RULES = [
        self::VALIDATION_RULE_URL,
        self::VALIDATION_RULE_EMAIL,
    ];

    private const VALIDATION_RULE_URL = 'url';
    private const VALIDATION_RULE_EMAIL = 'email';

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
        $attributeGroups = $this->readAttributeGroups($templateDirectory);
        $attributeGroupsCodes = array_keys($attributeGroups);
        $familyCodesInIndustries = $this->getFamilyCodesInIndustries($industries);
        $familyTemplateFileNames = array_keys($families);
        $attributeCodesInFamilies = $this->getAttributeCodesInFamilies($families);

        $industriesViolations = $this->lintIndustries($industries, $familyTemplateFileNames);
        $familiesViolations = $this->lintFamilies($families, $familyCodesInIndustries, $attributeGroupsCodes);
        $attributeOptionsViolations = $this->lintAttributeOptions($attributeOptions, $attributeCodesInFamilies);
        $attributeGroupsViolations = $this->lintAttributeGroups($attributeGroups);

        $flattenViolations = [
            ...$this->flatViolations('industries', $industriesViolations),
            ...$this->flatViolations('families', $familiesViolations),
            ...$this->flatViolations('attribute_options', $attributeOptionsViolations),
            ...$this->flatViolations('attribute_groups', $attributeGroupsViolations),
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

    private function readAttributeGroups(string $templatesDirectory): array
    {
        $attributeGroupsFilePath = sprintf('%s/%s', $templatesDirectory, 'attribute_groups.json');

        return $this->readJsonFile($attributeGroupsFilePath);
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
                        new Type('string'),
                        new NotBlank(),
                        new Type('string'),
                        new Length(max: 255),
                    ],
                ]),
                'family_templates' => [
                    new Count(min: 1),
                    new All([
                        new Type('string'),
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

    private function lintFamilies(array $families, array $familyCodesInIndustries, array $attributeGroupsCodes): array
    {
        $violations = [];

        foreach ($families as $fileName => $family) {
            $attributeAsLabelChoices = [];
            $mediaAttributeChoices = [];
            if (!empty($family['attributes'])) {
                foreach ($family['attributes'] as $attribute) {
                    if (isset($attribute['type'])) {
                        if (AttributeType::ATTRIBUTE_TYPE_IMAGE->value === $attribute['type']) {
                            $mediaAttributeChoices[] = $attribute['code'];
                        }
                        if (isset($attribute['code']) && in_array($attribute['type'], [AttributeType::ATTRIBUTE_TYPE_IDENTIFIER->value, AttributeType::ATTRIBUTE_TYPE_TEXT->value])) {
                            $attributeAsLabelChoices[] = $attribute['code'];
                        }
                    }
                }
            }

            $violations[$fileName] = $this->validator->validate($family, new Collection([
                'code' => new EqualTo(
                    value: $fileName,
                    message: 'This value should match with file name.',
                ),
                'labels' => new Collection([
                    'en_US' => [
                        new Type('string'),
                        new NotBlank(),
                        new Length(max: 255),
                    ],
                ]),
                'description' => new Collection([
                    'en_US' => [
                        new Type('string'),
                        new NotBlank(),
                    ],
                ]),
                'attribute_as_main_media' => [
                    new Type('string'),
                    new Choice(
                        choices: $mediaAttributeChoices,
                        message: 'This value is not a valid media attribute code.',
                    ),
                ],
                'attribute_as_label' => [
                    new Type('string'),
                    new Choice(
                        choices: $attributeAsLabelChoices,
                        message: 'This value is not a valid attribute code.',
                    ),
                ],
                'attributes' => [
                    new Count(min: 1),
                    new All(new Collection([
                        'code' => [
                            new Type('string'),
                            new Regex('/^(?!(id|associationTypes|categories|categoryId|completeness|enabled|(?i)\bfamily\b|groups|associations|products|scope|treeId|values|category|parent|label|(.)*_(products|groups)|entity_type|attributes|uuid|identifier)$)/i'),
                            new NotBlank(),
                        ],
                        'labels' => new Collection([
                            'en_US' => [
                                new Type('string'),
                                new NotBlank(),
                                new Length(max: 100),
                            ],
                        ]),
                        'type' => new Choice(
                            choices: AttributeType::getChoices(),
                            message: 'This value is not a valid attribute type.',
                        ),
                        'group' => new Choice(
                            choices: $attributeGroupsCodes,
                            message: 'This attribute group does not exist.',
                        ),
                        'scopable' => [
                            new Type('bool'),
                            new Required(),
                        ],
                        'localizable' => [
                            new Type('bool'),
                            new Required(),
                        ],
                        'unique' => [
                            new Type('bool'),
                            new Required(),
                        ],
                        'metric_family' => [
                            new Optional(),
                        ],
                        'unit' => [
                            new Optional(),
                        ],
                        'decimals_allowed' => [
                            new Optional(),
                        ],
                        'negative_allowed' => [
                            new Optional(),
                        ],
                        'validation_rule' => [
                            new Optional(),
                        ],
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
                $familyAttributeCodes = [];
                foreach ($family['attributes'] as $index => $attribute) {
                    if (empty($attribute['type'])) {
                        continue;
                    }

                    if (!empty($attribute['code'])) {
                        if (!in_array($attribute['code'], $familyAttributeCodes)) {
                            $familyAttributeCodes[] = $attribute['code'];
                        } else {
                            $violations[$fileName]->add(new ConstraintViolation(
                                sprintf('Each attribute code should be unique. Attribute : %s is duplicated.', $attribute['code']),
                                null,
                                [],
                                null,
                                '[attributes]',
                                null,
                            ));
                        }
                    }

                    $hasAttributeIdentifier = $hasAttributeIdentifier || 'pim_catalog_identifier' === $attribute['type'];

                    $propertyPath = sprintf('[attributes][%d]', $index);
                    switch ($attribute['type']) {
                        case AttributeType::ATTRIBUTE_TYPE_IDENTIFIER->value:
                            $this->assertValidAttributeIdentifier($attribute, $propertyPath, $fileName, $violations);
                            break;
                        case AttributeType::ATTRIBUTE_TYPE_METRIC->value:
                            $this->assertValidStringProperty('metric_family', $attribute, $index, $fileName, $violations);
                            $this->assertValidStringProperty('unit', $attribute, $index, $fileName, $violations);
                            $this->assertValidBooleanProperty('decimals_allowed', $attribute, $index, $fileName, $violations);
                            $this->assertValidBooleanProperty('negative_allowed', $attribute, $index, $fileName, $violations);
                            break;
                        case AttributeType::ATTRIBUTE_TYPE_NUMBER->value:
                            $this->assertValidBooleanProperty('decimals_allowed', $attribute, $index, $fileName, $violations);
                            $this->assertValidBooleanProperty('negative_allowed', $attribute, $index, $fileName, $violations);
                            break;
                        case AttributeType::ATTRIBUTE_TYPE_PRICE_COLLECTION->value:
                            $this->assertValidBooleanProperty('decimals_allowed', $attribute, $index, $fileName, $violations);
                            break;
                        case AttributeType::ATTRIBUTE_TYPE_TEXT->value:
                            $this->assertValidAttributeValidationRule($attribute, $index, $fileName, $violations);
                            break;
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
                        new Type('string'),
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

    private function lintAttributeGroups(array $attributeGroups): array
    {
        $violations = [];
        $lintedAttributeGroupCodes = [];

        foreach ($attributeGroups as $key => $attributeGroup) {
            $violations[$key] = $this->validator->validate($attributeGroup, new Collection([
                'code' => new EqualTo(
                    value: $key,
                    message: 'This value should match with key.',
                ),
                'labels' => new Collection([
                    'en_US' => [
                        new Type('string'),
                        new NotBlank(),
                        new Length(max: 100),
                    ],
                ]),
            ]));

            if (!array_key_exists('code', $attributeGroup) || empty($attributeGroup['code'])) {
                continue;
            }

            if (in_array($attributeGroup['code'], $lintedAttributeGroupCodes)) {
                $violations[$key]->add(new ConstraintViolation(
                    'This value should be unique.',
                    null,
                    [],
                    null,
                    '[code]',
                    null,
                ));
            }

            $lintedAttributeGroupCodes[] = $attributeGroup['code'];
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

    private function assertValidStringProperty(string $property, array $attribute, int $index, string $fileName, array $violations): void
    {
        $propertyPath = sprintf('[attributes][%d][%s]', $index, $property);
        switch (true) {
            case !array_key_exists($property, $attribute):
                $violations[$fileName]->add(new ConstraintViolation(
                    'This field is missing.',
                    null,
                    [],
                    null,
                    $propertyPath,
                    null,
                ));
                break;
            case !is_string($attribute[$property]):
                $violations[$fileName]->add(new ConstraintViolation(
                    'This value should be of type string.',
                    null,
                    [],
                    null,
                    $propertyPath,
                    null,
                ));
                break;
            case empty($attribute[$property]):
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

    private function assertValidBooleanProperty(string $property, array $attribute, int $index, string $fileName, array $violations): void
    {
        $propertyPath = sprintf('[attributes][%d][%s]', $index, $property);
        switch (true) {
            case !array_key_exists($property, $attribute):
                $violations[$fileName]->add(new ConstraintViolation(
                    'This field is missing.',
                    null,
                    [],
                    null,
                    $propertyPath,
                    null,
                ));
                break;
            case !is_bool($attribute[$property]):
                $violations[$fileName]->add(new ConstraintViolation(
                    'This value should be of type boolean.',
                    null,
                    [],
                    null,
                    $propertyPath,
                    null,
                ));
                break;
        }
    }

    private function assertValidAttributeValidationRule(array $attribute, int $index, string $fileName, array $violations): void
    {
        $propertyPath = sprintf('[attributes][%d][validation_rule]', $index);
        switch (true) {
            case !array_key_exists('validation_rule', $attribute):
                $violations[$fileName]->add(new ConstraintViolation(
                    'This field is missing.',
                    null,
                    [],
                    null,
                    $propertyPath,
                    null,
                ));
                break;
            case !is_null($attribute['validation_rule']) && !in_array($attribute['validation_rule'], self::VALIDATION_RULES):
                $violations[$fileName]->add(new ConstraintViolation(
                    sprintf('This value is not a valid validation rule. Please use one of the following : %s.', implode(',', self::VALIDATION_RULES)),
                    null,
                    [],
                    null,
                    $propertyPath,
                    null,
                ));
                break;
        }
    }

    private function assertValidAttributeIdentifier(array $attribute, string $propertyPath, string $fileName, array $violations): void
    {
        if (array_key_exists('unique', $attribute) && false === $attribute['unique']) {
            $violations[$fileName]->add(new ConstraintViolation(
                'Attribute identifier should be unique.',
                null,
                [],
                null,
                sprintf('%s[%s]', $propertyPath, 'unique'),
                null,
            ));
        }
    }
}
