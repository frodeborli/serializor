<?php

declare(strict_types=1);

namespace Serializor\SecretGenerators;

use function hash;

final class WindowsSecretGenerator implements SecretGenerator
{
    use CanExecuteCommand;

    /** @throws SecretGenerationException If no suitable secret could be generated */
    public function generate(): string
    {
        $cpuId = $this->executeCommand('wmic cpu get ProcessorId')
            ?: throw new SecretGenerationException('CPU ID could not be read');

        $macAddress = $this->executeCommand('getmac')
            ?: $this->executeCommand('wmic nic where (NetEnabled=true) get MACAddress')
            ?: throw new SecretGenerationException('MAC Address could not be read');

        $machineGuid = $this->executeCommand('reg query HKEY_LOCAL_MACHINE\\SOFTWARE\\Microsoft\\Cryptography /v MachineGuid')
            ?: throw new SecretGenerationException('Machine GUID could not be read');

        return hash('sha256', $cpuId . $macAddress . $machineGuid);
    }
}
