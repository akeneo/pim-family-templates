<?php

declare(strict_types=1);

namespace Akeneo\Test\TemplateGenerator\Command;

use Akeneo\TemplateGenerator\Command\GenerateTemplateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class GenerateTemplateCommandTest extends TestCase
{
    private const SOURCE_FILE_PATH = __DIR__ . '/source_file.xlsx';
    private const OUTPUT_FILE_PATH = 'actual_output.json';
    private const EXPECTED_OUTPUT_PATH = __DIR__ . '/expected_output.json';

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

        $sut->execute(['source_file' => self::SOURCE_FILE_PATH, 'output_file' => self::OUTPUT_FILE_PATH]);

        $this->assertFileExists(self::OUTPUT_FILE_PATH);
        $this->assertJsonFileEqualsJsonFile(self::EXPECTED_OUTPUT_PATH, self::OUTPUT_FILE_PATH);
    }

    private function removeOutputTemplate(): void
    {
        if (is_file(self::OUTPUT_FILE_PATH)) {
            unlink(self::OUTPUT_FILE_PATH);
        }
    }
}
