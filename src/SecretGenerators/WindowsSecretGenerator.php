<?php

declare(strict_types=1);

namespace Serializor\SecretGenerators;

use function hash;
use function shell_exec;

final class WindowsSecretGenerator implements SecretGenerator
{
    /** @throws SecretGenerationException If no suitable secret could be generated */
    public function generate(): string
    {
        $cpuId = shell_exec('wmic cpu get ProcessorId')
            ?: throw new SecretGenerationException('CPU ID could not be read');

        $macAddress = shell_exec('getmac')
            ?: shell_exec('wmic nic where (NetEnabled=true) get MACAddress')
            ?: throw new SecretGenerationException('MAC Address could not be read');

        $machineGuid = shell_exec('reg query HKEY_LOCAL_MACHINE\\SOFTWARE\\Microsoft\\Cryptography /v MachineGuid')
            ?: throw new SecretGenerationException('Machine GUID could not be read');

        return hash('sha256', $cpuId . $macAddress . $machineGuid);
    }
}
