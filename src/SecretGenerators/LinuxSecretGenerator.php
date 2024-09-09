<?php

declare(strict_types=1);

namespace Serializor\SecretGenerators;

use function file_exists;
use function file_get_contents;
use function hash;

final class LinuxSecretGenerator implements SecretGenerator
{
    /** @throws SecretGenerationException If no suitable secret could be generated */
    public function generate(): string
    {
        $cpuId = shell_exec('grep -m 1 \'Serial\' /proc/cpuinfo | awk \'{print $3}\'')
            ?: shell_exec('cat /proc/cpuinfo | grep \'model name\' | head -1 | awk -F\': \' \'{print $2}\'')
            ?: throw new SecretGenerationException('CPU ID could not be read');

        $macAddress = shell_exec('ip link show | awk \'/ether/ {print $2}\' | head -n 1')
            ?: shell_exec('ifconfig -a | grep -Po \'HWaddr \K.*$\' | head -n 1')
            ?: throw new SecretGenerationException('MAC Address could not be read');

        if (!file_exists('/etc/machine-id')) {
            throw new SecretGenerationException('Machine ID could not be read');
        }

        $machineId = file_get_contents('/etc/machine-id')
            ?: throw new SecretGenerationException('Machine ID could not be read');

        return hash('sha256', $cpuId . $macAddress . $machineId);
    }
}
