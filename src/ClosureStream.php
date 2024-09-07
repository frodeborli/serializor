<?php

declare(strict_types=1);

namespace Serializor;

use function stream_wrapper_register;
use function strlen;
use function substr;

use const PHP_EOL;

/**
 * @internal
 */
final class ClosureStream
{
    public const PROTOCOL = 'serializor';

    /** @var ?resource $context */
    public mixed $context = null;

    private static bool $isRegistered = false;

    private string $content = '';

    private int $length = 0;

    /** @var non-negative-int $pointer */
    private int $pointer = 0;

    public static function register(): void
    {
        if (!static::$isRegistered) {
            static::$isRegistered = stream_wrapper_register(static::PROTOCOL, __CLASS__);
        }
    }

    public function stream_eof(): bool
    {
        return $this->pointer >= $this->length;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->content = '<?php' . PHP_EOL . substr($path, strlen(static::PROTOCOL . '://')) . ';';
        $this->length = strlen($this->content);

        return true;
    }

    /** @param non-negative-int $count */
    public function stream_read(int $count): string
    {
        $value = substr($this->content, $this->pointer, $count);
        $this->pointer += $count;

        return $value;
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return [
            0 => 51,
            1 => 4405873,
            2 => 33204,
            3 => 1,
            4 => 1011,
            5 => 1011,
            6 => 0,
            7 => $this->length,
            8 => 1725454294,
            9 => 1725454294,
            10 => 1725454294,
            11 => 4096,
            12 => 8,
            'dev' => 51,
            'ino' => 4405873,
            'mode' => 33204,
            'nlink' => 1,
            'uid' => 1011,
            'gid' => 1011,
            'rdev' => 0,
            'size' => $this->length,
            'atime' => 1725454294,
            'mtime' => 1725454294,
            'ctime' => 1725454294,
            'blksize' => 4096,
            'blocks' => 8,
        ];
    }
}
