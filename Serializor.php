<?php

declare(strict_types=1);

/**
 * Backward compatibility shim for \Serializor.
 *
 * @deprecated Use \Serializor\Serializor instead. This alias will be removed in a future version.
 */
if (!class_exists('Serializor', false)) {
    class_alias(\Serializor\Serializor::class, 'Serializor');
}
