<?php

declare(strict_types=1);

namespace Tests\Unit\SecretGenerators;

use Serializor\SecretGenerators\CanExecuteCommand;

use function restore_error_handler;
use function set_error_handler;

use const E_WARNING;

test('executes a command and returns its result', function (): void {
    $class = createClassThatCanExecuteCommand();

    $actual = $class('echo Hello');

    expect($actual)->toBe('Hello');
})
    ->coversClass(CanExecuteCommand::class);

test('returns empty string when error occurred', function (): void {
    $class = createClassThatCanExecuteCommand();
    set_error_handler(fn(): bool => true, E_WARNING);

    $actual = $class(['a', false]);

    restore_error_handler();
    expect($actual)->toBe('');
})
    ->coversClass(CanExecuteCommand::class);

function createClassThatCanExecuteCommand(): object
{
    return new class {
        use CanExecuteCommand;

        public function __invoke(string|array $command): string
        {
            return $this->executeCommand($command);
        }
    };
}
