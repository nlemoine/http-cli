<?php

declare(strict_types=1);

namespace n5s\HttpCli\Runtime;

/**
 * Custom stream wrapper for simulating php://input in CLI environment.
 *
 * This class implements the PHP stream wrapper protocol to make raw POST data
 * available via file_get_contents('php://input') in scripts executed through
 * Client. It also properly delegates other php:// paths to real streams.
 *
 * Key features:
 * - Read-once semantics for php://input (can only be read once per request)
 * - Proper delegation for php://output, php://stdout, php://stderr
 * - In-memory storage for php://memory and php://temp
 * - Thread-safe using static storage per invocation
 *
 * @see https://www.php.net/manual/en/class.streamwrapper.php
 */
final class InputStream
{
    /**
     * Stream context (required by PHP stream wrapper interface).
     *
     * @var resource|null
     */
    public $context = null;

    /**
     * Current position in the input stream.
     */
    private int $position = 0;

    /**
     * Storage for raw input data (set before stream is opened).
     */
    private static string $rawInput = '';

    /**
     * Global flag indicating if the stream has been opened and read from.
     * Once any read happens, subsequent stream opens should return empty (read-once semantics).
     */
    private static bool $hasBeenConsumed = false;

    /**
     * Track if this specific stream instance has been used for reading.
     * Allows seeking within a single handle even if the global consumed flag is set.
     */
    private bool $instanceHasRead = false;

    /**
     * Track which path this stream instance is for.
     */
    private string $path = '';

    /**
     * In-memory buffer for php://memory and php://temp.
     */
    private string $memoryBuffer = '';

    /**
     * Real file handle for php://stdout, php://stderr, php://output.
     *
     * @var resource|null
     */
    private $realHandle = null;

    /**
     * Set the raw input data that will be available via php://input.
     *
     * This must be called before any script attempts to read from php://input.
     *
     * @param string $data The raw POST data
     */
    public static function setRawInput(string $data): void
    {
        self::$rawInput = $data;
        self::$hasBeenConsumed = false;
    }

    /**
     * Reset the stream state (for testing purposes).
     */
    public static function reset(): void
    {
        self::$rawInput = '';
        self::$hasBeenConsumed = false;
    }

    /**
     * Open the stream.
     *
     * @param string $path The path being opened (e.g., "php://input")
     * @param string $mode The mode used to open the file
     * @param int $options Bitmask of options
     * @param string|null $openedPath Full path that was actually opened
     * @return bool True on success
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->path = $path;
        $this->position = 0;

        // Handle different php:// paths
        return match ($path) {
            'php://input' => true, // Handled by our custom logic
            'php://output', 'php://stdout' => $this->openRealStream('php://stdout', 'w'),
            'php://stderr' => $this->openRealStream('php://stderr', 'w'),
            'php://memory', 'php://temp' => true, // Use in-memory buffer
            default => str_starts_with($path, 'php://temp/') || str_starts_with($path, 'php://fd/'),
        };
    }

    /**
     * Read from the stream.
     *
     * @param int $count Number of bytes to read
     * @return string The data read, or empty string if already consumed
     */
    public function stream_read(int $count): string
    {
        if ($this->path === 'php://input') {
            return $this->readInput($count);
        }

        if ($this->path === 'php://memory' || str_starts_with($this->path, 'php://temp')) {
            return $this->readMemory($count);
        }

        return '';
    }

    /**
     * Write to the stream.
     *
     * @param string $data Data to write
     * @return int Number of bytes written
     */
    public function stream_write(string $data): int
    {
        if ($this->path === 'php://input') {
            // php://input is read-only
            return 0;
        }

        if ($this->realHandle !== null) {
            $written = @fwrite($this->realHandle, $data);
            return $written !== false ? $written : 0;
        }

        if ($this->path === 'php://memory' || str_starts_with($this->path, 'php://temp')) {
            // Write to in-memory buffer
            $this->memoryBuffer = substr($this->memoryBuffer, 0, $this->position)
                . $data
                . substr($this->memoryBuffer, $this->position + strlen($data));
            $this->position += strlen($data);
            return strlen($data);
        }

        return 0;
    }

    /**
     * Check if at end of stream.
     *
     * @return bool True if at end
     */
    public function stream_eof(): bool
    {
        if ($this->path === 'php://input') {
            // If this instance has read, check position-based EOF
            if ($this->instanceHasRead) {
                return $this->position >= strlen(self::$rawInput);
            }
            // If consumed by another instance and this one hasn't read, it's EOF
            return self::$hasBeenConsumed || $this->position >= strlen(self::$rawInput);
        }

        if ($this->path === 'php://memory' || str_starts_with($this->path, 'php://temp')) {
            return $this->position >= strlen($this->memoryBuffer);
        }

        if ($this->realHandle !== null) {
            return feof($this->realHandle);
        }

        return true;
    }

    /**
     * Get stream position.
     *
     * @return int Current position
     */
    public function stream_tell(): int
    {
        return $this->position;
    }

    /**
     * Seek to a position in the stream.
     *
     * @param int $offset The offset to seek to
     * @param int $whence SEEK_SET, SEEK_CUR, or SEEK_END
     * @return bool True on success
     */
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($this->path === 'php://input') {
            return $this->seekInput($offset, $whence);
        }

        if ($this->path === 'php://memory' || str_starts_with($this->path, 'php://temp')) {
            return $this->seekMemory($offset, $whence);
        }

        return false;
    }

    /**
     * Get stream statistics.
     *
     * @return array<string, mixed> Stream statistics
     */
    public function stream_stat(): array
    {
        $size = match (true) {
            $this->path === 'php://input' => strlen(self::$rawInput),
            $this->path === 'php://memory', str_starts_with($this->path, 'php://temp') => strlen($this->memoryBuffer),
            default => 0,
        };

        return [
            'dev' => 0,
            'ino' => 0,
            'mode' => 33206, // 0100666 (regular file, rw-rw-rw-)
            'nlink' => 0,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => $size,
            'atime' => 0,
            'mtime' => 0,
            'ctime' => 0,
            'blksize' => -1,
            'blocks' => -1,
        ];
    }

    /**
     * Cast stream to resource.
     * Not supported for this stream type.
     *
     * @param int $castAs STREAM_CAST_FOR_SELECT or STREAM_CAST_AS_STREAM
     * @return resource|false The real resource if available, false otherwise
     */
    public function stream_cast(int $castAs)
    {
        if ($this->realHandle !== null) {
            return $this->realHandle;
        }
        return false;
    }

    /**
     * Flush the stream.
     *
     * @return bool True on success
     */
    public function stream_flush(): bool
    {
        if ($this->realHandle !== null) {
            return fflush($this->realHandle);
        }
        return true;
    }

    /**
     * Truncate the stream.
     *
     * @param int $newSize New size
     * @return bool True on success
     */
    public function stream_truncate(int $newSize): bool
    {
        if ($this->path === 'php://memory' || str_starts_with($this->path, 'php://temp')) {
            if ($newSize < strlen($this->memoryBuffer)) {
                $this->memoryBuffer = substr($this->memoryBuffer, 0, $newSize);
            } else {
                $this->memoryBuffer = str_pad($this->memoryBuffer, $newSize, "\0");
            }
            return true;
        }
        return false;
    }

    /**
     * Set stream options.
     *
     * @param int $option Option to set
     * @param int $arg1 First argument
     * @param int $arg2 Second argument
     * @return bool Always false (no options supported)
     */
    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }

    /**
     * Close the stream.
     */
    public function stream_close(): void
    {
        if ($this->realHandle !== null) {
            fclose($this->realHandle);
            $this->realHandle = null;
        }
    }

    /**
     * Register this class as the handler for php://input.
     *
     * @return bool True on success
     */
    public static function register(): bool
    {
        // Unregister if already registered (for testing/re-registration)
        if (in_array('php', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('php');
        }

        return stream_wrapper_register('php', self::class, STREAM_IS_URL);
    }

    /**
     * Unregister the stream wrapper (restore default php:// handler).
     *
     * @return bool True on success
     */
    public static function unregister(): bool
    {
        if (! in_array('php', stream_get_wrappers(), true)) {
            return false;
        }

        stream_wrapper_unregister('php');

        // Restore the original PHP stream wrapper
        return stream_wrapper_restore('php');
    }

    /**
     * Open a real stream for delegation.
     */
    private function openRealStream(string $path, string $mode): bool
    {
        // Temporarily restore original wrapper to open real stream
        stream_wrapper_restore('php');
        $handle = @fopen($path, $mode);
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class, STREAM_IS_URL);

        if ($handle === false) {
            return false;
        }

        $this->realHandle = $handle;

        return true;
    }

    /**
     * Read from php://input.
     */
    private function readInput(int $count): string
    {
        // php://input can only be read once (globally across all stream instances)
        // But allow reads from the same instance that already started reading
        if (self::$hasBeenConsumed && ! $this->instanceHasRead) {
            return '';
        }

        $length = strlen(self::$rawInput);
        if ($this->position >= $length) {
            return '';
        }

        $data = substr(self::$rawInput, $this->position, $count);
        $this->position += strlen($data);

        // Mark this instance as having read data
        $this->instanceHasRead = true;

        // Mark globally as consumed so new handles will get empty
        self::$hasBeenConsumed = true;

        return $data;
    }

    /**
     * Read from in-memory buffer.
     */
    private function readMemory(int $count): string
    {
        $length = strlen($this->memoryBuffer);
        if ($this->position >= $length) {
            return '';
        }

        $data = substr($this->memoryBuffer, $this->position, $count);
        $this->position += strlen($data);

        return $data;
    }

    /**
     * Seek in php://input.
     */
    private function seekInput(int $offset, int $whence): bool
    {
        // php://input doesn't support seeking if this instance hasn't read yet
        // and data has been consumed by another instance
        if (self::$hasBeenConsumed && ! $this->instanceHasRead) {
            return false;
        }

        $length = strlen(self::$rawInput);

        $newPosition = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $length + $offset,
            default => -1,
        };

        if ($newPosition < 0 || $newPosition > $length) {
            return false;
        }

        $this->position = $newPosition;
        return true;
    }

    /**
     * Seek in memory buffer.
     */
    private function seekMemory(int $offset, int $whence): bool
    {
        $length = strlen($this->memoryBuffer);

        $newPosition = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $length + $offset,
            default => -1,
        };

        if ($newPosition < 0) {
            return false;
        }

        $this->position = $newPosition;
        return true;
    }
}
