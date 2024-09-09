<?php

declare(strict_types=1);

namespace Serializor\SecretGenerators;

use function hash;
use function shell_exec;

final class MacSecretGenerator implements SecretGenerator
{
    /** @throws SecretGenerationException If no suitable secret could be generated */
    public function generate(): string
    {
        $cpuId = shell_exec('sysctl -n machdep.cpu.brand_string')
            ?: throw new SecretGenerationException('CPU ID could not be read');

        $macAddress = shell_exec('ifconfig en0 | grep ether | awk \'{print $2}\'')
            ?: throw new SecretGenerationException('MAC Address could not be read');

        $ioPlatformUuid = shell_exec('ioreg -rd1 -c IOPlatformExpertDevice | grep IOPlatformUUID | awk \'{print $3}\' | sed \'s/\"//g\'')
            ?: throw new SecretGenerationException('IOPlatformUUID could not be read');

        return hash('sha256', $cpuId . $macAddress . $ioPlatformUuid);
    }
}
