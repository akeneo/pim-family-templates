<?php

declare(strict_types=1);

namespace Akeneo\Test\PimFamilyTemplates\Command\GenerateTemplatesCommand;

use Akeneo\PimFamilyTemplates\Command\GenerateTemplatesCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class GenerateTemplatesCommandTest extends TestCase
{
    private const SOURCE_FILE_PATH = __DIR__ . '/source_file.xlsx';
    private const OUTPUT_DIRECTORY = __DIR__ . '/actual_output';
    private const EXPECTED_OUTPUT_DIRECTORY = __DIR__ . '/expected_output';

    protected function setUp(): void
    {
        $this->cleanDirectory(self::OUTPUT_DIRECTORY);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->cleanDirectory(self::OUTPUT_DIRECTORY);
        parent::tearDown();
    }

    public function test_it_generates_template_files(): void
    {
        $sut = new CommandTester(new GenerateTemplatesCommand());

        $sut->execute(['source_file' => self::SOURCE_FILE_PATH, 'output_directory' => self::OUTPUT_DIRECTORY]);

        $this->assertDirectoryEquals(self::EXPECTED_OUTPUT_DIRECTORY, self::OUTPUT_DIRECTORY);
    }

    private function assertDirectoryEquals(string $expectedDirectoryPath, string $actualDirectoryPath): void
    {
        $this->assertDirectoryExists($actualDirectoryPath);

        $expectedFiles = array_filter(
            scandir($expectedDirectoryPath),
            static fn (string $expectedFile) => !in_array($expectedFile, ['.', '..'])
        );

        foreach ($expectedFiles as $expectedFile) {
            $actualFilePath = sprintf('%s/%s', $actualDirectoryPath, $expectedFile);
            $expectedFilePath = sprintf('%s/%s', $expectedDirectoryPath, $expectedFile);

            if (is_dir($expectedFilePath)) {
                $this->assertDirectoryEquals($actualFilePath, $expectedFilePath);
                continue;
            }

            $this->assertFileExists($actualFilePath);
            $this->assertJsonFileEqualsJsonFile($expectedFilePath, $actualFilePath);
        }
    }

    private function cleanDirectory(string $directoryPath): void
    {
        if (!is_dir($directoryPath)) {
            return;
        }

        $subFiles = array_filter(
            scandir($directoryPath),
            static fn (string $expectedFile) => !in_array($expectedFile, ['.', '..'])
        );

        foreach ($subFiles as $subFile) {
            $subFilePath = sprintf('%s/%s', $directoryPath, $subFile);
            if (is_dir($subFilePath)) {
                $this->cleanDirectory($subFilePath);
                continue;
            }
            unlink($subFilePath);
        }

        rmdir($directoryPath);
    }
}
