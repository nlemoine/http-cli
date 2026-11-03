<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use n5s\HttpCli\Client;
use n5s\HttpCli\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Resolution of the script to execute: it must be a file inside the document root, and the path passed to the PHP
 * binary must never be read as an option.
 */
final class ClientScriptResolutionTest extends AbstractHttpCliTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestFile('index', '<?php echo "index";');

        // Sibling of the document root, which is "<tempDir>/public"
        file_put_contents($this->tempDir . '/outside.php', '<?php echo "outside";');
    }

    public function testPathStartingWithDashRunsTheFileInsteadOfBeingReadAsAnOption(): void
    {
        $this->createTestFile('-v', '<?php echo "dash file";');

        $response = (new Client($this->testDocumentRoot))->request('GET', 'http://localhost/-v.php');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('dash file', $response->getContent());
    }

    public function testPathStartingWithDashToAMissingFileReturns404(): void
    {
        $response = (new Client($this->testDocumentRoot))->request('GET', 'http://localhost/-h.php');

        $this->assertNotFound($response);
    }

    #[DataProvider('pathsEscapingTheDocumentRootProvider')]
    public function testPathsEscapingTheDocumentRootReturn404(string $url): void
    {
        $response = (new Client($this->testDocumentRoot))->request('GET', $url);

        $this->assertNotFound($response);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsEscapingTheDocumentRootProvider(): iterable
    {
        yield 'parent segment' => ['http://localhost/../outside.php'];
        yield 'parent segment after a subdirectory' => ['http://localhost/sub/../../outside.php'];
        yield 'dot then parent segment' => ['http://localhost/./../outside.php'];
        yield 'percent-encoded parent segment' => ['http://localhost/%2e%2e/outside.php'];
    }

    public function testParentSegmentThatStaysInsideTheDocumentRootIsNormalized(): void
    {
        $this->createDirectory('sub');

        $response = (new Client($this->testDocumentRoot))->request('GET', 'http://localhost/sub/../index.php');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('index', $response->getContent());
    }

    public function testScriptInSubdirectoryGetsNormalizedScriptVariables(): void
    {
        $this->createDirectory('sub');
        file_put_contents(
            $this->testDocumentRoot . '/sub/page.php',
            '<?php echo $_SERVER["SCRIPT_NAME"], "|", $_SERVER["PHP_SELF"], "|", $_SERVER["SCRIPT_FILENAME"];'
        );

        $response = (new Client($this->testDocumentRoot))->request('GET', 'http://localhost/sub/./page.php');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            sprintf('/sub/page.php|/sub/page.php|%s/sub/page.php', $this->testDocumentRoot),
            $response->getContent()
        );
    }

    public function testSymlinkPointingOutsideTheDocumentRootReturns404(): void
    {
        symlink($this->tempDir . '/outside.php', $this->testDocumentRoot . '/link.php');

        $response = (new Client($this->testDocumentRoot))->request('GET', 'http://localhost/link.php');

        $this->assertNotFound($response);
    }

    public function testSymlinkPointingInsideTheDocumentRootIsExecuted(): void
    {
        symlink($this->testDocumentRoot . '/index.php', $this->testDocumentRoot . '/alias.php');

        $response = (new Client($this->testDocumentRoot))->request('GET', 'http://localhost/alias.php');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('index', $response->getContent());
    }

    public function testMissingScriptReturns404WithoutStartingAProcess(): void
    {
        $response = (new Client($this->testDocumentRoot))->request('GET', 'http://localhost/missing.php');

        $this->assertNotFound($response);
        $this->assertFalse($response->getProcess()->isStarted());
    }

    public function testDirectoryNamedLikeAScriptReturns404(): void
    {
        $this->createDirectory('folder.php');

        $response = (new Client($this->testDocumentRoot))->request('GET', 'http://localhost/folder.php');

        $this->assertNotFound($response);
    }

    public function testPathContainingANullByteReturns404(): void
    {
        $response = (new Client($this->testDocumentRoot))->request('GET', "http://localhost/index\0.php");

        $this->assertNotFound($response);
    }

    #[DataProvider('configuredFilesOutsideTheDocumentRootProvider')]
    public function testConfiguredFileOutsideTheDocumentRootReturns404(string $file): void
    {
        $file = str_replace('{tempDir}', $this->tempDir, $file);

        $response = (new Client($this->testDocumentRoot, $file))->request('GET', 'http://localhost/');

        $this->assertNotFound($response);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function configuredFilesOutsideTheDocumentRootProvider(): iterable
    {
        yield 'relative path' => ['../outside.php'];
        yield 'absolute path' => ['{tempDir}/outside.php'];
    }

    public function testMissingConfiguredFileReturns404(): void
    {
        $response = (new Client($this->testDocumentRoot, 'missing.php'))->request('GET', 'http://localhost/');

        $this->assertNotFound($response);
    }

    public function testConfiguredFileGivenAsAnAbsolutePathInsideTheDocumentRootIsExecuted(): void
    {
        $file = $this->createTestFile('front', '<?php echo $_SERVER["SCRIPT_NAME"], "|", $_SERVER["SCRIPT_FILENAME"];');

        $response = (new Client($this->testDocumentRoot, $file))->request('GET', 'http://localhost/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(sprintf('/front.php|%s/front.php', $this->testDocumentRoot), $response->getContent());
    }

    public function testNonExistentDocumentRootThrows(): void
    {
        $this->expectException(RuntimeException::class);

        (new Client($this->tempDir . '/missing-root'))->request('GET', 'http://localhost/');
    }

    private function assertNotFound(Response $response): void
    {
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame("File not found.\n", $response->getContent());
    }
}
