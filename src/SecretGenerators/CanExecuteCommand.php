<?php

declare(strict_types=1);

namespace Serializor\SecretGenerators;

use function fclose;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function trim;

trait CanExecuteCommand
{
    private function executeCommand(string|array $command): string
    {
        $process = proc_open(
            command: $command,
            descriptor_spec: [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w']
            ],
            pipes: $pipes,
            options: [
                'suppress_errors' => true,
            ],
        );

        if ($process === false) {
            return '';
        }

        fclose($pipes[0]);

        $result = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return trim($result);
    }
}
