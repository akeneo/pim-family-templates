<?php

declare(strict_types=1);

namespace Akeneo\Test\TemplateGenerator\Command;

use Akeneo\TemplateGenerator\Command\GenerateTemplateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class GenerateTemplateCommandTest extends TestCase
{
    private const FAMILIES_PATH = __DIR__ . '/families.xlsx';
    private const EXPECTED_TEMPLATE_PATH = __DIR__ . '/expected_template.json';
    private const OUTPUT_TEMPLATE_NAME = 'output.json';

    protected function setUp(): void
    {
        $this->removeOutputTemplate();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->removeOutputTemplate();
        parent::tearDown();
    }

    public function test_it_generates_template(): void
    {
        $sut = new CommandTester(new GenerateTemplateCommand());

        $sut->execute(['file' => self::FAMILIES_PATH]);

        $this->assertFileExists(self::OUTPUT_TEMPLATE_NAME);
        $this->assertJsonFileEqualsJsonFile(self::EXPECTED_TEMPLATE_PATH, self::OUTPUT_TEMPLATE_NAME);
    }

    private function removeOutputTemplate(): void
    {
        if (is_file(self::OUTPUT_TEMPLATE_NAME)) {
            unlink(self::OUTPUT_TEMPLATE_NAME);
        }
    }
}
