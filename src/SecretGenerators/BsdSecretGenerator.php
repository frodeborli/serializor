<?php

declare(strict_types=1);

namespace Serializor\SecretGenerators;

use function hash;

final class BsdSecretGenerator implements SecretGenerator
{
    use CanExecuteCommand;

    /** @throws SecretGenerationException If no suitable secret could be generated */
    public function generate(): string
    {
        $cpuId = $this->executeCommand('dmesg | grep -m 1 \'CPU:\'')
            ?: $this->executeCommand('sysctl -n hw.model')
            ?: throw new SecretGenerationException('CPU ID could not be read');

        $macAddress = $this->executeCommand('ifconfig | grep -E \'ether\' | awk \'{print $2}\'')
            ?: throw new SecretGenerationException('MAC Address could not be read');

        $machineGuid = $this->executeCommand('kenv -q smbios.system.uuid')
            ?: $this->executeCommand('sysctl -n hw.uuid')
            ?: throw new SecretGenerationException('Machine GUID could not be read');

        return hash('sha256', $cpuId . $macAddress . $machineGuid);
    }
}
