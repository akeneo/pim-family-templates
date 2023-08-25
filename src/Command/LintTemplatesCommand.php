<?php

namespace Akeneo\PimFamilyTemplates\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Required;
use Symfony\Component\Validator\Constraints\Unique;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validation;

class LintTemplatesCommand extends Command
{
    protected static $defaultName = 'templates:lint';

    protected function configure(): void
    {
        $this
            ->setDescription('Lint JSON template files')
            ->addArgument('templates_directory', InputArgument::REQUIRED, 'Template files directory to minify')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $templateDirectories = $input->getArgument('templates_directory');
        $industries = $this->readIndustries($templateDirectories);

        $industriesViolations = $this->lintIndustries($industries);

        $flattenViolations = [
            ...$this->flatViolations('industries', $industriesViolations),
        ];

        $this->displayViolations($output, $flattenViolations);

        return 0 === count($flattenViolations) ? Command::SUCCESS : Command::FAILURE;
    }

    private function readIndustries(string $templatesDirectory): array
    {
        $industriesFilePath = sprintf('%s/%s', $templatesDirectory, 'industries.json');

        return $this->readJsonFile($industriesFilePath);
    }

    private function lintIndustries(array $industries): array
    {
        $validator = Validation::createValidator();
        $violations = [];

        $lintedIndustryCodes = [];

        foreach ($industries as $code => $industry) {
            $violations[$code] = $validator->validate($industry, new Collection([
                'code' => [
                    new NotBlank(),
                ],
                'labels' => [
                    new Collection([
                        'en_US' => [
                            new Required(),
                            new NotBlank(),
                        ],
                    ]),
                ],
                'family_templates' => [
                    new Count(min: 1),
                    new All(new NotBlank()),
                    new Unique(),
                ],
            ]));

            if (!array_key_exists('code', $industry) || '' === $industry['code']) {
                continue;
            }

            if ($code !== $industry['code']) {
                $violations[$code]->add(new ConstraintViolation(
                    'This value should match with key.',
                    null,
                    [],
                    null,
                    '[code]',
                    null,
                ));
            }

            if (in_array($industry['code'], $lintedIndustryCodes)) {
                $violations[$code]->add(new ConstraintViolation(
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
