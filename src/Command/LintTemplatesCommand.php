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
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Unique;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class LintTemplatesCommand extends Command
{
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
        $familyCodesInIndustries = $this->getFamilyCodesInIndustries($industries);
        $familyTemplateFileNames = array_keys($families);

        $industriesViolations = $this->lintIndustries($industries, $familyTemplateFileNames);
        $familiesViolations = $this->lintFamilies($families, $familyCodesInIndustries);

        $flattenViolations = [
            ...$this->flatViolations('industries', $industriesViolations),
            ...$this->flatViolations('families', $familiesViolations),
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

    private function getFamilyCodesInIndustries(array $industries): array
    {
        return array_reduce(
            $industries,
            static fn (array $familyCodes, array $industry) => [...$familyCodes, ...($industry['family_templates'] ?? [])],
            [],
        );
    }

    private function lintIndustries(array $industries, array $familyTemplateFileNames): array
    {
        $violations = [];

        $lintedIndustryCodes = [];

        foreach ($industries as $key => $industry) {
            $violations[$key] = $this->validator->validate($industry, new Collection([
                'code' => new NotBlank(),
                'labels' => new Collection([
                    'en_US' => [
                        new NotBlank(),
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

            if ($key !== $industry['code']) {
                $violations[$key]->add(new ConstraintViolation(
                    'This value should match with key.',
                    null,
                    [],
                    null,
                    '[code]',
                    null,
                ));
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
                'code' => new NotBlank(),
                'description' => new Collection([
                    'en_US' => new NotBlank(),
                ]),
                'attributes' => [
                    new Count(min: 1),
                ],
            ]));

            if (array_key_exists('code', $family) && '' !== $family['code'] && $fileName !== $family['code']) {
                $violations[$fileName]->add(new ConstraintViolation(
                    'This value should match with file name.',
                    null,
                    [],
                    null,
                    '[code]',
                    null,
                ));
            }

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
