<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use PHPUnit\Framework\TestCase;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Abstract base class for HTTP CLI Client tests that need temporary file fixtures.
 *
 * Provides common setup/teardown for temporary directories and helper methods
 * for creating test files.
 */
abstract class AbstractHttpCliTestCase extends TestCase
{
    /**
     * Spatie temporary directory instance.
     */
    protected TemporaryDirectory $temporaryDirectory;

    /**
     * Temporary directory root path for test fixtures.
     */
    protected string $tempDir;

    /**
     * Document root directory for PHP test files.
     */
    protected string $testDocumentRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = (new TemporaryDirectory())
            ->name('http-cli-test-' . uniqid())
            ->create();

        $this->tempDir = $this->temporaryDirectory->path();
        $this->testDocumentRoot = $this->temporaryDirectory->path('public');

        if (! is_dir($this->testDocumentRoot)) {
            mkdir($this->testDocumentRoot, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->temporaryDirectory->delete();
    }

    /**
     * Create a PHP test file in the document root.
     *
     * @param string $name File name without .php extension
     * @param string $content PHP content to write
     * @return string Full path to the created file
     */
    protected function createTestFile(string $name, string $content): string
    {
        $filePath = $this->testDocumentRoot . '/' . $name . '.php';
        file_put_contents($filePath, $content);

        return $filePath;
    }

    /**
     * Create a subdirectory in the document root.
     *
     * @param string $path Relative path from document root
     * @return string Full path to the created directory
     */
    protected function createDirectory(string $path): string
    {
        $fullPath = $this->testDocumentRoot . '/' . ltrim($path, '/');
        if (! is_dir($fullPath)) {
            mkdir($fullPath, 0755, true);
        }

        return $fullPath;
    }

    /**
     * Path of a PHP binary that runs the real one with the given ini options,
     * to pass as the client's phpExecutable. What the script sees must not
     * depend on the php.ini of the machine running the tests.
     */
    protected function phpWithIni(string $iniOptions): string
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('The PHP wrapper is a shell script');
        }

        $wrapper = $this->tempDir . '/php-' . md5($iniOptions);
        file_put_contents($wrapper, sprintf("#!/bin/sh\nexec %s %s \"\$@\"\n", escapeshellarg(PHP_BINARY), $iniOptions));
        chmod($wrapper, 0755);

        return $wrapper;
    }
}
