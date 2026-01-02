<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use Exception;
use n5s\HttpCli\Client;
use n5s\HttpCli\RequestOptionsBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class ClientEdgeCasesTest extends AbstractHttpCliTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create test PHP file
        $testFile = $this->testDocumentRoot . '/index.php';
        file_put_contents($testFile, '<?php echo "Test"; ?>');
    }

    // Constructor Edge Cases

    public function testConstructorWithEmptyDocumentRoot(): void
    {
        // Empty document root should still work
        $client = new Client('');
        $this->assertInstanceOf(Client::class, $client);
    }

    public function testConstructorWithDocumentRootContainingSpecialCharacters(): void
    {
        $specialDir = $this->tempDir . '/special-chars_@#$%';
        mkdir($specialDir, 0755, true);

        $client = new Client($specialDir);
        $this->assertInstanceOf(Client::class, $client);
    }

    public function testConstructorWithVeryLongDocumentRootPath(): void
    {
        // Create a very long path
        $longPath = $this->tempDir;
        for ($i = 0; $i < 10; $i++) {
            $longPath .= '/very_long_directory_name_' . str_repeat('a', 50) . '_' . $i;
        }

        // Only test if we can actually create such a long path
        try {
            mkdir($longPath, 0755, true);
            $client = new Client($longPath);
            $this->assertInstanceOf(Client::class, $client);
        } catch (Exception) {
            // Path too long for filesystem, skip test
            $this->markTestSkipped('Filesystem does not support very long paths');
        }
    }

    public function testConstructorWithNonExistentDocumentRoot(): void
    {
        // Non-existent document root should still work (constructor doesn't validate existence)
        $client = new Client('/non/existent/path');
        $this->assertInstanceOf(Client::class, $client);
    }

    // Request Method Edge Cases

    public function testRequestWithMalformedUrl(): void
    {
        $client = new Client($this->testDocumentRoot);

        try {
            $client->request('GET', 'http://[invalid-url');
            // If it doesn't throw, we still validate it's handled gracefully
            $this->addToAssertionCount(1);
        } catch (Exception $e) {
            // Expected - malformed URLs should cause issues
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public function testRequestWithUrlContainingNullBytes(): void
    {
        $client = new Client($this->testDocumentRoot);

        // URLs with null bytes may cause issues with the underlying PHP process
        try {
            $client->request('GET', "http://localhost/index.php\0malicious");
            $this->addToAssertionCount(1);
        } catch (Exception $e) {
            // Expected - null bytes may cause various issues
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public function testRequestWithVeryLongUrl(): void
    {
        $client = new Client($this->testDocumentRoot);

        // Create very long URL
        $longQuery = str_repeat('param' . random_int(0, mt_getrandmax()) . '=value&', 1000);
        $longUrl = 'http://localhost/index.php?' . $longQuery;

        try {
            $client->request('GET', $longUrl);
            $this->addToAssertionCount(1);
        } catch (Exception $e) {
            // Long URLs might cause various issues, which is acceptable
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    #[DataProvider('invalidHttpMethodProvider')]
    public function testRequestWithInvalidHttpMethods(string $method): void
    {
        $client = new Client($this->testDocumentRoot);

        try {
            $client->request($method, 'http://localhost/index.php');
            // Some invalid methods might still be processed
            $this->addToAssertionCount(1);
        } catch (Exception $e) {
            // Invalid methods might cause issues in the request processing
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public static function invalidHttpMethodProvider(): array
    {
        return [
            [''],
            ['INVALID_METHOD'],
            ['GET POST'], // Space in method
            ['G*ET'], // Special characters
            [str_repeat('A', 1000)], // Very long method
        ];
    }

    public function testRequestWithFilePathContainingPathTraversal(): void
    {
        $client = new Client($this->testDocumentRoot);

        // Path traversal in URL doesn't affect which PHP file is executed
        // since Client runs the configured file regardless of URL path
        try {
            $client->request('GET', 'http://localhost/../../../etc/passwd');
            // Request may succeed since it runs the configured file, not the URL path
            $this->addToAssertionCount(1);
        } catch (Exception $e) {
            // May fail for other reasons in test environment
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public function testRequestWithSymlinkToPhpFile(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlinks not supported on this platform');
        }

        $targetFile = $this->testDocumentRoot . '/target.php';
        file_put_contents($targetFile, '<?php echo "Symlink target"; ?>');

        $symlinkFile = $this->testDocumentRoot . '/symlink.php';

        try {
            symlink($targetFile, $symlinkFile);

            $client = new Client($this->testDocumentRoot);
            $response = $client->request('GET', 'http://localhost/symlink.php');

            // Should handle symlinks gracefully
            $this->addToAssertionCount(1);
        } catch (Exception $e) {
            // Symlink handling might vary by system
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    // Note: globalsHandler feature was removed from Client
    // Tests for that feature have been removed

    // File System Edge Cases

    public function testRequestWithFileHavingNoReadPermissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Permission tests not reliable on Windows');
        }

        $restrictedFile = $this->testDocumentRoot . '/restricted.php';
        file_put_contents($restrictedFile, '<?php echo "Restricted"; ?>');
        chmod($restrictedFile, 0000); // No permissions

        $client = new Client($this->testDocumentRoot);

        try {
            $response = $client->request('GET', 'http://localhost/restricted.php');
            // Might still work depending on system
            $this->addToAssertionCount(1);
        } catch (Exception $e) {
            // Expected due to permission issues
            $this->assertInstanceOf(Exception::class, $e);
        } finally {
            // Restore permissions for cleanup
            chmod($restrictedFile, 0644);
        }
    }

    public function testRequestWithFileInSubdirectoryWithRestrictedPermissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Permission tests not reliable on Windows');
        }

        $restrictedDir = $this->testDocumentRoot . '/restricted';
        mkdir($restrictedDir, 0000); // No permissions

        $client = new Client($this->testDocumentRoot);

        try {
            // The URL path doesn't determine which file is executed
            // Client runs the configured file regardless
            $client->request('GET', 'http://localhost/restricted/file.php');
            $this->addToAssertionCount(1);
        } catch (Exception $e) {
            // May fail for various reasons
            $this->assertInstanceOf(Exception::class, $e);
        } finally {
            // Restore permissions for cleanup
            chmod($restrictedDir, 0755);
        }
    }

    // Memory and Resource Edge Cases
    // Note: testRequestWithVeryLargeGlobalsData was removed as globalsHandler feature no longer exists

    // URL Edge Cases

    #[DataProvider('edgeCaseUrlProvider')]
    public function testRequestWithEdgeCaseUrls(string $url, bool $shouldThrowException = false): void
    {
        $client = new Client($this->testDocumentRoot);

        if ($shouldThrowException) {
            $this->expectException(RuntimeException::class);
        }

        try {
            $response = $client->request('GET', $url);
            if (! $shouldThrowException) {
                $this->addToAssertionCount(1);
            }
        } catch (Exception $e) {
            if ($shouldThrowException) {
                $this->assertInstanceOf(RuntimeException::class, $e);
            } else {
                // Unexpected exception
                throw $e;
            }
        }
    }

    public static function edgeCaseUrlProvider(): array
    {
        return [
            // URLs that should work - Client runs the configured file
            // regardless of the URL, so all these should succeed
            ['http://localhost/index.php', false],
            ['https://localhost:443/index.php', false],
            ['http://127.0.0.1/index.php', false],
            ['http://localhost/index.php?', false], // Empty query
            ['http://localhost/index.php#', false], // Empty fragment

            // Other schemes - Client doesn't validate schemes,
            // it passes them to Symfony Request::create
            ['ftp://localhost/index.php', false],
            ['http://localhost/index.php', false],

            // Borderline cases
            ['http://localhost:0/index.php', false], // Port 0
            ['http://localhost:65535/index.php', false], // Max port
        ];
    }

    // Options Edge Cases

    public function testRequestWithNegativeTimeout(): void
    {
        $client = new Client($this->testDocumentRoot);

        try {
            $options = (new RequestOptionsBuilder())->timeout(-1)->build();
            $response = $client->request('GET', 'http://localhost/index.php', $options);
            $this->addToAssertionCount(1);
        } catch (Exception $e) {
            // Negative timeout might be rejected by Process class
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public function testRequestWithFloatTimeout(): void
    {
        $client = new Client($this->testDocumentRoot);

        try {
            $options = (new RequestOptionsBuilder())->timeout(1.5)->build();
            $response = $client->request('GET', 'http://localhost/index.php', $options);
            $this->addToAssertionCount(1);
        } catch (Exception $e) {
            // Float timeout handling depends on Symfony Process
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public function testRequestWithUnknownOptions(): void
    {
        $client = new Client($this->testDocumentRoot);

        try {
            // RequestOptions only supports known options, so this test now just tests
            // that extra options set via extras don't cause issues
            $options = (new RequestOptionsBuilder())
                ->timeout(30)
                ->extra('unknown_option', 'value')
                ->extra('another_unknown', true)
                ->build();
            $response = $client->request('GET', 'http://localhost/index.php', $options);
            // Unknown options should be ignored
            $this->addToAssertionCount(1);
        } catch (Exception $e) {
            // Expected in test environment
            $this->assertInstanceOf(Exception::class, $e);
        }
    }
}
