<?php

namespace Akeneo\Test\PimFamilyTemplates\Command\LintTemplatesCommand;

use Akeneo\PimFamilyTemplates\Command\LintTemplatesCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class LintTemplatesCommandTest extends TestCase
{
    private const VALID_TEMPLATES_DIRECTORY = __DIR__ . '/valid_templates';
    private const INVALID_INDUSTRIES_TEMPLATES_DIRECTORY = __DIR__ . '/invalid_industries_templates';
    private const INVALID_FAMILIES_TEMPLATES_DIRECTORY = __DIR__ . '/invalid_families_templates';
    private const INVALID_ATTRIBUTES_TEMPLATES_DIRECTORY = __DIR__ . '/invalid_attributes_templates';
    private const INVALID_ATTRIBUTES_OPTIONS_TEMPLATES_DIRECTORY = __DIR__ . '/invalid_attribute_options_templates';

    public static function provideInvalidTemplates(): iterable
    {
        yield 'Invalid industries' => [
            self::INVALID_INDUSTRIES_TEMPLATES_DIRECTORY,
            [
                '[industries][missing_code_industry][code] This field is missing.',
                '[industries][mismatching_code_industry][code] This value should match with key.',
                '[industries][missing_labels_industry][labels] This field is missing.',
                '[industries][missing_en_US_label_industry][labels][en_US] This field is missing.',
                '[industries][too_long_en_US_label_industry][labels][en_US] This value is too long. It should have 255 characters or less.',
                '[industries][empty_en_US_label_industry][labels][en_US] This value should not be blank.',
                '[industries][missing_family_templates_industry][family_templates] This field is missing.',
                '[industries][empty_family_templates_industry][family_templates] This collection should contain 1 element or more.',
                '[industries][duplicated_code_industry][code] This value should be unique.',
                '[industries][duplicated_family_templates_industry][family_templates] This collection should contain only unique elements.',
                '[industries][unknown_family_template_industry][family_templates][1] This value should match with a family template file name.',
                '[industries][extra_fields_industry][cc] This field was not expected.',
            ],
        ];
        yield 'Invalid families' => [
            self::INVALID_FAMILIES_TEMPLATES_DIRECTORY,
            [
                '[families][empty_en_US_description_family][description][en_US] This value should not be blank.',
                '[families][too_long_en_US_description_family][description][en_US] This value is too long. It should have 255 characters or less.',
                '[families][empty_en_US_label_family][labels][en_US] This value should not be blank.',
                '[families][too_long_en_US_label_family][labels][en_US] This value is too long. It should have 255 characters or less.',
                '[families][extra_fields_family][cc] This field was not expected.',
                '[families][mismatching_code_family][code] This value should match with file name.',
                '[families][missing_attribute_as_label_family][attribute_as_label] This field is missing.',
                '[families][missing_attribute_as_main_media_family][attribute_as_main_media] This field is missing.',
                '[families][missing_attributes_family][attributes] This field is missing.',
                '[families][missing_attributes_family][attribute_as_main_media] This value is not a valid media attribute code.',
                '[families][missing_attributes_family][attribute_as_label] This value is not a valid attribute code.',
                '[families][missing_code_family][code] This field is missing.',
                '[families][missing_description_family][description] This field is missing.',
                '[families][missing_en_US_description_family][description][en_US] This field is missing.',
                '[families][missing_en_US_label_family][labels][en_US] This field is missing.',
                '[families][missing_labels_family][labels] This field is missing.',
                '[families][unknown_family] This value should be referenced in an industry.',
            ]
        ];
        yield 'Invalid attributes' => [
            self::INVALID_ATTRIBUTES_TEMPLATES_DIRECTORY,
            [
                '[families][empty_attributes_family][attributes] This collection should contain 1 element or more.',
                '[families][empty_attributes_family][attribute_as_main_media] This value is not a valid media attribute code.',
                '[families][empty_attributes_family][attribute_as_label] This value is not a valid attribute code.',
                '[families][missing_attribute_code_family][attributes][0][code] This field is missing.',
                '[families][missing_attribute_labels_family][attributes][0][labels] This field is missing.',
                '[families][missing_attribute_en_US_label_family][attributes][0][labels][en_US] This field is missing.',
                '[families][too_long_attribute_en_US_label_family][attributes][0][labels][en_US] This value is too long. It should have 100 characters or less.',
                '[families][missing_attribute_type_family][attributes][1][type] This field is missing.',
                '[families][missing_attribute_scopable_family][attributes][0][scopable] This field is missing.',
                '[families][missing_attribute_localizable_family][attributes][0][localizable] This field is missing.',
                '[families][missing_attribute_unique_family][attributes][0][unique] This field is missing.',
                '[families][empty_attribute_code_family][attributes][0][code] This value should not be blank.',
                '[families][empty_attribute_en_US_label_family][attributes][0][labels][en_US] This value should not be blank.',
                '[families][wrong_attribute_type_family][attributes][1][type] This value is not a valid attribute type.',
                '[families][wrong_attribute_scopable_family][attributes][0][scopable] This value should be of type bool.',
                '[families][wrong_attribute_localizable_family][attributes][0][localizable] This value should be of type bool.',
                '[families][wrong_attribute_code_family][attributes][1][code] This value is not valid.',
                '[families][wrong_attribute_code_family][attributes][2][code] This value is not valid.',
                '[families][wrong_attribute_code_family][attributes][3][code] This value is not valid.',
                '[families][wrong_attribute_code_family][attributes][4][code] This value is not valid.',
                '[families][wrong_attribute_identifier_family][attributes][0][unique] Attribute identifier should be unique.',
                '[families][wrong_attribute_unique_family][attributes][0][unique] This value should be of type bool.',
                '[families][missing_attribute_metric_family_family][attributes][1][metric_family] This field is missing.',
                '[families][missing_attribute_metric_family_family][attributes][1][unit] This field is missing.',
                '[families][empty_attribute_metric_family_family][attributes][1][metric_family] This value should not be blank.',
                '[families][empty_attribute_metric_family_family][attributes][1][unit] This value should not be blank.',
                '[families][missing_identifier_attribute_family][attributes] This collection should contain 1 attribute identifier or more.',
                '[families][missing_attribute_decimals_allowed_family][attributes][1][decimals_allowed] This field is missing.',
                '[families][missing_attribute_negative_allowed_family][attributes][1][negative_allowed] This field is missing.',
                '[families][wrong_attribute_validation_rule_family][attributes][3][validation_rule] This value is not a valid validation rule. Please use one of the following : url,email.',
                '[families][missing_attribute_validation_rule_family][attributes][1][validation_rule] This field is missing.',
            ]
        ];
        yield 'Invalid attribute options' => [
            self::INVALID_ATTRIBUTES_OPTIONS_TEMPLATES_DIRECTORY,
            [
                '[attribute_options][missing_code_option][code] This field is missing.',
                '[attribute_options][missing_labels_option][labels] This field is missing.',
                '[attribute_options][missing_en_US_label_option][labels][en_US] This field is missing.',
                '[attribute_options][missing_attribute_option][attribute] This field is missing.',
                '[attribute_options][empty_en_US_label_option][labels][en_US] This value should not be blank.',
                '[attribute_options][too_long_en_US_label_option][labels][en_US] This value is too long. It should have 255 characters or less.',
                '[attribute_options][unknown_attribute_option][attribute] This value is not referenced in any family.',
                '[attribute_options][mismatching_code_option][code] This value should match with key.',
                '[attribute_options][color2][code] This value should be unique.',
            ]
        ];
    }

    public function test_it_says_nothing_when_it_lints_valid_templates(): void
    {
        $sut = new CommandTester(new LintTemplatesCommand());
        $sut->execute(
            ['templates_directory' => self::VALID_TEMPLATES_DIRECTORY],
        );

        $this->assertSame(Command::SUCCESS, $sut->getStatusCode());
        $this->assertEmpty($sut->getDisplay());
    }

    /**
     * @dataProvider provideInvalidTemplates
     */
    public function test_it_says_whats_wrong_when_it_lints_invalid_templates(string $templatesDirectory, array $expectedErrors): void
    {
        $sut = new CommandTester(new LintTemplatesCommand());
        $sut->execute(
            ['templates_directory' => $templatesDirectory],
        );

        $this->assertSame(Command::FAILURE, $sut->getStatusCode());

        $actualErrors = array_filter(
            preg_split('/\r\n|\n|\r/', $sut->getDisplay()),
            static fn (string $line) => '' !== $line,
        );

        foreach ($expectedErrors as $expectedError) {
            $this->assertContains($expectedError, $actualErrors);
        }

        $unexpectedErrors = array_diff($actualErrors, $expectedErrors);
        $this->assertCount(
            count($expectedErrors),
            $actualErrors,
            sprintf('More errors has been raised than expected : %s', join(', ', $unexpectedErrors)),
        );
    }
}
