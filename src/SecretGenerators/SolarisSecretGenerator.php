<?php

declare(strict_types=1);

namespace Serializor\SecretGenerators;

use function hash;

final class SolarisSecretGenerator implements SecretGenerator
{
    use CanExecuteCommand;

    /** @throws SecretGenerationException If no suitable secret could be generated */
    public function generate(): string
    {
        $cpuId = $this->executeCommand('psrinfo -pv | grep \'The physical processor\' | awk \'{print $5}\'')
            ?: $this->executeCommand('prtdiag | grep \'Processor\'')
            ?: throw new SecretGenerationException('CPU ID could not be read');

        $macAddress = $this->executeCommand('ifconfig -a | grep ether | awk \'{print $2}\'')
            ?: throw new SecretGenerationException('MAC Address could not be read');

        $machineGuid = $this->executeCommand('hostid')
            ?: $this->executeCommand('/usr/bin/sneep')
            ?: throw new SecretGenerationException('Machine GUID could not be read');

        return hash('sha256', $cpuId . $macAddress . $machineGuid);
    }
}
