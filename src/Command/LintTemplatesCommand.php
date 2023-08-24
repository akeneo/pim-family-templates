<?php

namespace Akeneo\PimFamilyTemplates\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\AllValidator;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Required;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;

class LintTemplatesCommand extends Command
{
    protected static $defaultName = 'templates:generate';

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

        $violations = $this->lintIndustries($industries);

        return 0 === $violations->count() ? Command::SUCCESS : Command::FAILURE;
    }

    private function readIndustries(string $templatesDirectory): array
    {
        $industriesFilePath = sprintf('%s/%s', $templatesDirectory, 'industries');

        return $this->readJsonFile($industriesFilePath);
    }

    private function lintIndustries(array $industries): ConstraintViolationListInterface
    {
        $validator = Validation::createValidator();
        $violations = [];

        foreach ($industries as $industry) {
            $violations[] = $validator->validate($industry, new Collection([
                'code' => [
                    new Required(),
                    new NotBlank(),
                ],
                'labels' => [
                    new Required(),
                    new Collection([
                        'en_US' => [
                            new Required(),
                            new NotBlank(),
                        ]
                    ]),
                ],
                'family_templates' => [
                    new Count(min: 1),
                    new All(new NotBlank()),
                ]
            ]));
        }

        return $validator->validate($industries, new Collection());
    }

    private function readJsonFile(string $filePath)
    {
        $json = file_get_contents($filePath);
        return json_decode(json: $json, flags: JSON_OBJECT_AS_ARRAY);
    }
}
