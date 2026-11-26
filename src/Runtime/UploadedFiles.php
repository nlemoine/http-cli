<?php

declare(strict_types=1);

namespace n5s\HttpCli\Runtime;

/**
 * Temporary files of the request's uploads, as the client wrote them.
 *
 * The CLI SAPI has no real uploads, so is_uploaded_file() and
 * move_uploaded_file() would refuse every file. The runtime replaces them with
 * versions that accept these files only, so that a script (or a framework such
 * as Symfony) can handle uploads as under a web server, without letting it
 * move arbitrary files.
 */
final class UploadedFiles
{
    /**
     * @var array<string, true>
     */
    private static array $paths = [];

    /**
     * Record the tmp_name entries of a $_FILES array. A field can hold one
     * path or, for array fields, a nested array of paths.
     *
     * @param array<mixed> $files
     */
    public static function register(array $files): void
    {
        foreach ($files as $file) {
            if (! \is_array($file) || ! isset($file['tmp_name'])) {
                continue;
            }

            $paths = \is_array($file['tmp_name']) ? $file['tmp_name'] : [$file['tmp_name']];
            array_walk_recursive($paths, static function (mixed $path): void {
                if (\is_string($path) && $path !== '') {
                    self::$paths[$path] = true;
                }
            });
        }
    }

    public static function isUploaded(string $path): bool
    {
        return isset(self::$paths[$path]);
    }

    /**
     * Same contract as the native function: false without a warning for a
     * file that is not an upload of this request, false with a warning if the
     * move itself fails.
     */
    public static function move(string $from, string $to): bool
    {
        if (! self::isUploaded($from)) {
            return false;
        }

        if (! rename($from, $to)) {
            return false;
        }

        unset(self::$paths[$from]);

        return true;
    }

    public static function reset(): void
    {
        self::$paths = [];
    }
}
