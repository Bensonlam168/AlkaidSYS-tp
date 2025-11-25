<?php

declare (strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use Infrastructure\Validator\JsonSchemaValidatorGenerator;

class TestValidator extends Command
{
    protected function configure()
    {
        // Command name
        $this->setName('test:validator')
            ->setDescription('Test JsonSchemaValidatorGenerator functionality');
    }

    protected function execute(Input $input, Output $output)
    {
        $generator = new JsonSchemaValidatorGenerator();
        $output->writeln('Testing JsonSchemaValidatorGenerator...');

        $schema = [
            'type' => 'object',
            'required' => ['name', 'age'],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 50
                ],
                'age' => [
                    'type' => 'integer',
                    'minimum' => 18,
                    'maximum' => 100
                ],
                'role' => [
                    'type' => 'string',
                    'enum' => ['admin', 'user', 'guest']
                ],
                'email' => [
                    'type' => 'string',
                    'pattern' => '^.+@.+$'
                ]
            ]
        ];

        $output->writeln('Input Schema: ' . json_encode($schema, JSON_PRETTY_PRINT));

        $rules = $generator->generateRules($schema);

        $output->writeln('Generated Rules:');
        foreach ($rules as $field => $rule) {
            $output->writeln("  {$field}: {$rule}");
        }

        // Basic assertions
        if (str_contains($rules['name'], 'require') && str_contains($rules['name'], 'min:2')) {
            $output->writeln('<info>Name rules correct.</info>');
        } else {
            $output->writeln('<error>Name rules incorrect.</error>');
        }

        if (str_contains($rules['age'], 'integer') && str_contains($rules['age'], 'egt:18')) {
            $output->writeln('<info>Age rules correct.</info>');
        } else {
            $output->writeln('<error>Age rules incorrect.</error>');
        }

        $output->writeln('Test complete.');
    }
}
