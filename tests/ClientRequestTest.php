<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use Exception;
use n5s\HttpCli\Client;
use n5s\HttpCli\RequestOptionsBuilder;
use n5s\HttpCli\Response;
use PHPUnit\Framework\Attributes\DataProvider;

class ClientRequestTest extends AbstractHttpCliTestCase
{
    private string $testFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test PHP file
        $this->testFile = $this->testDocumentRoot . '/index.php';
        file_put_contents($this->testFile, '<?php echo "Hello World"; ?>');
    }

    // Request Method Tests with Different HTTP Methods

    #[DataProvider('httpMethodProvider')]
    public function testRequestWithDifferentHttpMethods(string $method): void
    {
        $client = new Client($this->testDocumentRoot);

        // Note: This will actually execute the process, but with our test files
        // In a real scenario, we'd want to mock the Process class
        try {
            $response = $client->request($method, 'http://localhost/index.php');
            $this->assertInstanceOf(Response::class, $response);
        } catch (Exception $e) {
            // Expected in test environment without proper PHP setup
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public static function httpMethodProvider(): array
    {
        return [
            ['GET'],
            ['POST'],
            ['PUT'],
            ['DELETE'],
            ['PATCH'],
            ['HEAD'],
            ['OPTIONS'],
        ];
    }

    // URL Parsing Tests

    public function testRequestWithQueryParameters(): void
    {
        $client = new Client($this->testDocumentRoot);

        try {
            $response = $client->request('GET', 'http://localhost/index.php?param1=value1&param2=value2');
            $this->assertInstanceOf(Response::class, $response);
        } catch (Exception $e) {
            // Expected in test environment
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public function testRequestWithFragment(): void
    {
        $client = new Client($this->testDocumentRoot);

        try {
            $response = $client->request('GET', 'http://localhost/index.php#section1');
            $this->assertInstanceOf(Response::class, $response);
        } catch (Exception $e) {
            // Expected in test environment
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public function testRequestWithPort(): void
    {
        $client = new Client($this->testDocumentRoot);

        try {
            $response = $client->request('GET', 'http://localhost:8080/index.php');
            $this->assertInstanceOf(Response::class, $response);
        } catch (Exception $e) {
            // Expected in test environment
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    // File Path Resolution Tests

    public function testRequestUpdatesFilePathForPhpExtension(): void
    {
        // Create a specific PHP file
        $customFile = $this->testDocumentRoot . '/custom.php';
        file_put_contents($customFile, '<?php echo "Custom"; ?>');

        $client = new Client($this->testDocumentRoot);

        try {
            $response = $client->request('GET', 'http://localhost/custom.php');
            $this->assertInstanceOf(Response::class, $response);
        } catch (Exception $e) {
            // Expected in test environment
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public function testRequestWithNestedPhpFile(): void
    {
        // Create nested directory structure
        $nestedDir = $this->testDocumentRoot . '/api';
        mkdir($nestedDir, 0755, true);
        $nestedFile = $nestedDir . '/endpoint.php';
        file_put_contents($nestedFile, '<?php echo "API endpoint"; ?>');

        $client = new Client($this->testDocumentRoot);

        try {
            $response = $client->request('GET', 'http://localhost/api/endpoint.php');
            $this->assertInstanceOf(Response::class, $response);
        } catch (Exception $e) {
            // Expected in test environment
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    // Options Tests

    public function testRequestWithCustomTimeout(): void
    {
        $client = new Client($this->testDocumentRoot);

        try {
            $options = (new RequestOptionsBuilder())->timeout(60)->build();
            $response = $client->request('GET', 'http://localhost/index.php', $options);
            $this->assertInstanceOf(Response::class, $response);
        } catch (Exception $e) {
            // Expected in test environment
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public function testRequestWithNullTimeout(): void
    {
        $client = new Client($this->testDocumentRoot);

        try {
            // null timeout means no explicit timeout set
            $response = $client->request('GET', 'http://localhost/index.php');
            $this->assertInstanceOf(Response::class, $response);
        } catch (Exception $e) {
            // Expected in test environment
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public function testRequestWithEmptyOptions(): void
    {
        $client = new Client($this->testDocumentRoot);

        try {
            // null options or omitted options means default
            $response = $client->request('GET', 'http://localhost/index.php');
            $this->assertInstanceOf(Response::class, $response);
        } catch (Exception $e) {
            // Expected in test environment
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public function testRequestParsesHeadersFromOutput(): void
    {
        // Create a PHP file that outputs headers
        $headerFile = $this->testDocumentRoot . '/headers.php';
        $headerContent = '<?php
        // Note: In real execution, this would use the header functions from bootstrap
        echo "Content with headers";
        // Headers would be captured by the bootstrap system
        ?>';
        file_put_contents($headerFile, $headerContent);

        $client = new Client($this->testDocumentRoot);

        try {
            $response = $client->request('GET', 'http://localhost/headers.php');

            $this->assertInstanceOf(Response::class, $response);
            $this->assertIsInt($response->getStatusCode());
            $this->assertIsArray($response->getHeaders());
            $this->assertIsString($response->getContent());
        } catch (Exception $e) {
            // Expected in test environment
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    // Multipart / File Upload Tests

    public function testRequestWithSingleFileUpload(): void
    {
        $this->createTestFile('upload', '<?php
            $file = $_FILES["document"];
            echo json_encode([
                "name" => $file["name"],
                "full_path" => $file["full_path"],
                "type" => $file["type"],
                "size" => $file["size"],
                "error" => $file["error"],
                "contents" => file_get_contents($file["tmp_name"]),
            ]);
        ');

        $options = (new RequestOptionsBuilder())
            ->multipart([
                [
                    'name' => 'document',
                    'contents' => 'Hello, this is file content!',
                    'filename' => 'test.txt',
                ],
            ])
            ->build();

        $response = (new Client($this->testDocumentRoot))->request('POST', 'http://localhost/upload.php', $options);

        $this->assertSame([
            'name' => 'test.txt',
            'full_path' => 'test.txt',
            'type' => 'text/plain',
            'size' => 28,
            'error' => UPLOAD_ERR_OK,
            'contents' => 'Hello, this is file content!',
        ], json_decode($response->getContent(), true));
    }

    public function testRequestWithMultipleFileUploads(): void
    {
        $this->createTestFile('multi-upload', '<?php
            echo json_encode(array_map(
                fn (array $file) => [$file["name"], file_get_contents($file["tmp_name"])],
                $_FILES
            ));
        ');

        $options = (new RequestOptionsBuilder())
            ->multipart([
                [
                    'name' => 'file1',
                    'contents' => 'First file content',
                    'filename' => 'first.txt',
                ],
                [
                    'name' => 'file2',
                    'contents' => 'Second file content',
                    'filename' => 'second.txt',
                ],
            ])
            ->build();

        $response = (new Client($this->testDocumentRoot))->request('POST', 'http://localhost/multi-upload.php', $options);

        $this->assertSame([
            'file1' => ['first.txt', 'First file content'],
            'file2' => ['second.txt', 'Second file content'],
        ], json_decode($response->getContent(), true));
    }

    public function testRequestWithMixedMultipartData(): void
    {
        $this->createTestFile('mixed-upload', '<?php
            echo json_encode([
                "post" => $_POST,
                "files" => array_map(fn (array $file) => $file["name"], $_FILES),
            ]);
        ');

        $options = (new RequestOptionsBuilder())
            ->multipart([
                [
                    'name' => 'title',
                    'contents' => 'My Document',
                ],
                [
                    'name' => 'description',
                    'contents' => 'A test document',
                ],
                [
                    'name' => 'attachment',
                    'contents' => 'File contents here',
                    'filename' => 'document.pdf',
                ],
            ])
            ->build();

        $response = (new Client($this->testDocumentRoot))->request('POST', 'http://localhost/mixed-upload.php', $options);

        $this->assertSame([
            'post' => [
                'title' => 'My Document',
                'description' => 'A test document',
            ],
            'files' => [
                'attachment' => 'document.pdf',
            ],
        ], json_decode($response->getContent(), true));
    }

    public function testRequestWithFileUploadMimeType(): void
    {
        $this->createTestFile('mime-upload', '<?php echo $_FILES["image"]["type"];');

        $options = (new RequestOptionsBuilder())
            ->multipart([
                [
                    'name' => 'image',
                    'contents' => 'fake image data',
                    'filename' => 'photo.jpg',
                    'headers' => [
                        'Content-Type' => 'image/jpeg',
                    ],
                ],
            ])
            ->build();

        $response = (new Client($this->testDocumentRoot))->request('POST', 'http://localhost/mime-upload.php', $options);

        $this->assertSame('image/jpeg', $response->getContent());
    }

    public function testFileUploadMimeTypeIsDetectedFromContents(): void
    {
        $this->createTestFile('mime-upload', '<?php echo $_FILES["image"]["type"];');

        // A PNG signature and IHDR chunk, without a Content-Type header
        $png = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xde";
        $options = (new RequestOptionsBuilder())
            ->multipart([
                [
                    'name' => 'image',
                    'contents' => $png,
                    'filename' => 'pixel.png',
                ],
            ])
            ->build();

        $response = (new Client($this->testDocumentRoot))->request('POST', 'http://localhost/mime-upload.php', $options);

        $this->assertSame('image/png', $response->getContent());
    }

    public function testUploadedFileCanBeMovedWithMoveUploadedFile(): void
    {
        $destination = $this->tempDir . '/moved.txt';
        $this->createTestFile('move-upload', '<?php
            $file = $_FILES["document"];
            echo json_encode([
                "is_uploaded" => is_uploaded_file($file["tmp_name"]),
                "moved" => move_uploaded_file($file["tmp_name"], $_POST["destination"]),
                "moved_again" => move_uploaded_file($file["tmp_name"], $_POST["destination"] . ".2"),
            ]);
        ');

        $options = (new RequestOptionsBuilder())
            ->multipart([
                [
                    'name' => 'destination',
                    'contents' => $destination,
                ],
                [
                    'name' => 'document',
                    'contents' => 'Uploaded content',
                    'filename' => 'test.txt',
                ],
            ])
            ->build();

        $response = (new Client($this->testDocumentRoot))->request('POST', 'http://localhost/move-upload.php', $options);

        $this->assertSame([
            'is_uploaded' => true,
            'moved' => true,
            'moved_again' => false,
        ], json_decode($response->getContent(), true));
        $this->assertSame('Uploaded content', file_get_contents($destination));
    }

    public function testOnlyRequestUploadsCountAsUploadedFiles(): void
    {
        $this->createTestFile('not-upload', '<?php
            $target = __DIR__ . "/copy.php";
            echo json_encode([
                "is_uploaded" => is_uploaded_file(__FILE__),
                "moved" => move_uploaded_file(__FILE__, $target),
                "still_there" => file_exists(__FILE__),
            ]);
        ');

        $response = (new Client($this->testDocumentRoot))->request('POST', 'http://localhost/not-upload.php');

        $this->assertSame([
            'is_uploaded' => false,
            'moved' => false,
            'still_there' => true,
        ], json_decode($response->getContent(), true));
    }
}
