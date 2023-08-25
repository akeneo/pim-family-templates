<?php

namespace Akeneo\Test\PimFamilyTemplates\Command\LintTemplatesCommand;

use Akeneo\PimFamilyTemplates\Command\LintTemplatesCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class LintTemplatesCommandTest extends TestCase
{
    private const VALID_TEMPLATES_DIRECTORY = __DIR__ . '/valid_templates';
    private const INVALID_FAMILIES_TEMPLATES_DIRECTORY = __DIR__ . '/invalid_industries_templates';

    public static function provideInvalidTemplates(): iterable
    {
        yield 'Invalid industries' => [
            self::INVALID_FAMILIES_TEMPLATES_DIRECTORY,
            [
                '[industries][missing_code_industry][code] This field is missing.',
                '[industries][empty_code_industry][code] This value should not be blank.',
                '[industries][mismatching_code_industry][code] This value should match with key.',
                '[industries][missing_labels_industry][labels] This field is missing.',
                '[industries][missing_en_US_label_industry][labels][en_US] This field is missing.',
                '[industries][empty_en_US_label_industry][labels][en_US] This value should not be blank.',
                '[industries][missing_family_templates_industry][family_templates] This field is missing.',
                '[industries][empty_family_templates_industry][family_templates] This collection should contain 1 element or more.',
                '[industries][duplicated_code_industry][code] This value should be unique.',
                '[industries][duplicated_family_templates_industry][family_templates] This collection should contain only unique elements.',
            ],
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
        foreach ($expectedErrors as $expectedError) {
            $this->assertStringContainsString($expectedError, $sut->getDisplay());
        }
    }
}
