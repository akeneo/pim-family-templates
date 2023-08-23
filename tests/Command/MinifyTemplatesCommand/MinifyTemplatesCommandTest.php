<?php

declare(strict_types=1);

namespace Akeneo\Test\TemplateGenerator\Command\MinifyTemplatesCommand;

use Akeneo\TemplateGenerator\Command\MinifyTemplatesCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class MinifyTemplatesCommandTest extends TestCase
{
    private const TEMPLATES_DIR = __DIR__ . '/templates';
    private const OUTPUT_FILE = __DIR__ . '/minified.json';
    private const EXPECTED_OUTPUT_FILE = __DIR__ . '/expected_minified.json';

    protected function setUp(): void
    {
        $this->cleanOutputFile();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->cleanOutputFile();
        parent::tearDown();
    }

    public function test_it_minifies_template_files(): void
    {
        $sut = new CommandTester(new MinifyTemplatesCommand());

        $sut->execute(['templates_directory' => self::TEMPLATES_DIR, 'output_file' => self::OUTPUT_FILE]);

        $this->assertFileExists(self::OUTPUT_FILE);
        $this->assertFileEquals(self::EXPECTED_OUTPUT_FILE, self::OUTPUT_FILE);
    }

    private function cleanOutputFile(): void
    {
        if (!file_exists(self::OUTPUT_FILE)) {
            return;
        }

        unlink(self::OUTPUT_FILE);
    }
}
