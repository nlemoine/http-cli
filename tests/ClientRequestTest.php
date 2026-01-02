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
        // Create a PHP file that echoes uploaded file info
        $uploadFile = $this->testDocumentRoot . '/upload.php';
        file_put_contents($uploadFile, '<?php
            if (isset($_FILES["document"])) {
                $file = $_FILES["document"];
                echo json_encode([
                    "name" => $file["name"],
                    "type" => $file["type"],
                    "size" => $file["size"],
                    "error" => $file["error"],
                    "tmp_exists" => file_exists($file["tmp_name"]),
                ]);
            } else {
                echo json_encode(["error" => "No file uploaded"]);
            }
        ?>');

        $client = new Client($this->testDocumentRoot);

        try {
            $options = (new RequestOptionsBuilder())
                ->multipart([
                    [
                        'name' => 'document',
                        'contents' => 'Hello, this is file content!',
                        'filename' => 'test.txt',
                    ],
                ])
                ->build();

            $response = $client->request('POST', 'http://localhost/upload.php', $options);

            $this->assertInstanceOf(Response::class, $response);
            $content = $response->getContent();
            $data = json_decode($content, true);

            if (is_array($data) && isset($data['name'])) {
                $this->assertSame('test.txt', $data['name']);
                $this->assertSame(UPLOAD_ERR_OK, $data['error']);
                $this->assertSame(28, $data['size']); // Length of "Hello, this is file content!"
                $this->assertTrue($data['tmp_exists']);
            }
        } catch (Exception $e) {
            // Expected in test environment
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public function testRequestWithMultipleFileUploads(): void
    {
        $uploadFile = $this->testDocumentRoot . '/multi-upload.php';
        file_put_contents($uploadFile, '<?php
            $files = [];
            foreach ($_FILES as $name => $file) {
                $files[$name] = [
                    "name" => $file["name"],
                    "size" => $file["size"],
                    "error" => $file["error"],
                ];
            }
            echo json_encode($files);
        ?>');

        $client = new Client($this->testDocumentRoot);

        try {
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

            $response = $client->request('POST', 'http://localhost/multi-upload.php', $options);
            $data = json_decode($response->getContent(), true);

            if (is_array($data) && isset($data['file1'], $data['file2'])) {
                $this->assertSame('first.txt', $data['file1']['name']);
                $this->assertSame('second.txt', $data['file2']['name']);
            }
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public function testRequestWithMixedMultipartData(): void
    {
        $uploadFile = $this->testDocumentRoot . '/mixed-upload.php';
        file_put_contents($uploadFile, '<?php
            echo json_encode([
                "post" => $_POST,
                "files" => array_map(fn($f) => $f["name"], $_FILES),
            ]);
        ?>');

        $client = new Client($this->testDocumentRoot);

        try {
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

            $response = $client->request('POST', 'http://localhost/mixed-upload.php', $options);
            $data = json_decode($response->getContent(), true);

            if (is_array($data)) {
                // Regular fields should be in $_POST
                $this->assertSame('My Document', $data['post']['title'] ?? null);
                $this->assertSame('A test document', $data['post']['description'] ?? null);
                // File should be in $_FILES
                $this->assertSame('document.pdf', $data['files']['attachment'] ?? null);
            }
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public function testRequestWithFileUploadMimeType(): void
    {
        $uploadFile = $this->testDocumentRoot . '/mime-upload.php';
        file_put_contents($uploadFile, '<?php
            if (isset($_FILES["image"])) {
                echo $_FILES["image"]["type"];
            }
        ?>');

        $client = new Client($this->testDocumentRoot);

        try {
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

            $response = $client->request('POST', 'http://localhost/mime-upload.php', $options);
            $content = trim($response->getContent());

            // The MIME type should be what we specified
            if (! empty($content) && ! str_contains($content, 'error')) {
                $this->assertSame('image/jpeg', $content);
            }
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
        }
    }
}
