<?php

namespace Serializor;

/**
 * @internal
 */
class ClosureStream
{
    const STREAM_PROTO = 'serializor';

    const STAT_BASE = [
        0 => 51,
        1 => 4405873,
        2 => 33204,
        3 => 1,
        4 => 1011,
        5 => 1011,
        6 => 0,
        7 => 2145,
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
        'size' => 2145,
        'atime' => 1725454294,
        'mtime' => 1725454294,
        'ctime' => 1725454294,
        'blksize' => 4096,
        'blocks' => 8,
    ];

    protected static $isRegistered = false;

    protected $content;

    protected $length;

    protected $pointer = 0;

    function stream_open($path, $mode, $options, &$opened_path)
    {
        $this->content = "<?php\n" . substr($path, strlen(static::STREAM_PROTO . '://')) . ";";
        $this->length = strlen($this->content);
        return true;
    }

    public function stream_read($count)
    {
        $value = substr($this->content, $this->pointer, $count);
        $this->pointer += $count;
        return $value;
    }

    public function stream_eof()
    {
        return $this->pointer >= $this->length;
    }

    public function stream_set_option($option, $arg1, $arg2)
    {
        return false;
    }

    public function stream_stat()
    {
        $stat = self::STAT_BASE;
        $stat[7] = $stat['size'] = $this->length;
        return $stat;
    }

    public function url_stat($path, $flags)
    {
        $stat = self::STAT_BASE;
        $stat[7] = $stat['size'] = $this->length;
        return $stat;
    }

    public function stream_seek($offset, $whence = SEEK_SET)
    {
        $crt = $this->pointer;

        switch ($whence) {
            case SEEK_SET:
                $this->pointer = $offset;
                break;
            case SEEK_CUR:
                $this->pointer += $offset;
                break;
            case SEEK_END:
                $this->pointer = $this->length + $offset;
                break;
        }

        if ($this->pointer < 0 || $this->pointer >= $this->length) {
            $this->pointer = $crt;
            return false;
        }

        return true;
    }

    public function stream_tell()
    {
        return $this->pointer;
    }

    public static function register()
    {
        if (!static::$isRegistered) {
            static::$isRegistered = stream_wrapper_register(static::STREAM_PROTO, __CLASS__);
        }
    }

 }
