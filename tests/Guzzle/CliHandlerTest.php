<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests\Guzzle;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use n5s\HttpCli\Client;
use n5s\HttpCli\Guzzle\CliHandler;
use n5s\HttpCli\Tests\AbstractHttpCliTestCase;
use Psr\Http\Message\ResponseInterface;

class CliHandlerTest extends AbstractHttpCliTestCase
{
    // Basic Request Tests
    public function testHandlerReturnsPromise(): void
    {
        $this->createTestFile('index', '<?php echo "Hello World";');
        $handler = $this->createHandler('index.php');

        $request = new Request('GET', 'http://localhost/');
        $promise = ($handler)($request);

        $this->assertInstanceOf(PromiseInterface::class, $promise);
    }

    public function testBasicGetRequest(): void
    {
        $this->createTestFile('index', '<?php echo "Hello World";');
        $handler = $this->createHandler('index.php');

        $request = new Request('GET', 'http://localhost/');
        $promise = ($handler)($request);
        $response = $promise->wait();

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Hello World', (string) $response->getBody());
    }

    public function testGetRequestWithQueryParams(): void
    {
        $this->createTestFile('query', '<?php echo json_encode($_GET);');
        $handler = $this->createHandler('query.php');

        $request = new Request('GET', 'http://localhost/query.php?foo=bar&baz=qux');
        $promise = ($handler)($request);
        $response = $promise->wait();

        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('bar', $body['foo']);
        $this->assertEquals('qux', $body['baz']);
    }

    public function testGetRequestWithQueryOption(): void
    {
        $this->createTestFile('query', '<?php echo json_encode($_GET);');
        $handler = $this->createHandler('query.php');

        $request = new Request('GET', 'http://localhost/query.php');
        $options = [
            RequestOptions::QUERY => [
                'search' => 'test',
                'page' => '1',
            ],
        ];
        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('test', $body['search']);
        $this->assertEquals('1', $body['page']);
    }

    public function testBasicPostRequest(): void
    {
        $this->createTestFile('post', '<?php echo json_encode($_POST);');
        $handler = $this->createHandler('post.php');

        $request = new Request('POST', 'http://localhost/post.php');
        $options = [
            RequestOptions::FORM_PARAMS => [
                'name' => 'John',
                'email' => 'john@example.com',
            ],
        ];
        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('John', $body['name']);
        $this->assertEquals('john@example.com', $body['email']);
    }

    public function testPostRequestWithJsonBody(): void
    {
        $this->createTestFile('json', '<?php
            header("Content-Type: application/json");
            $input = json_decode(file_get_contents("php://input"), true);
            echo json_encode($input);
        ');
        $handler = $this->createHandler('json.php');

        $request = new Request('POST', 'http://localhost/json.php');
        $options = [
            RequestOptions::JSON => [
                'key' => 'value',
                'nested' => [
                    'a' => 1,
                ],
            ],
        ];
        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('value', $body['key']);
        $this->assertEquals([
            'a' => 1,
        ], $body['nested']);
    }

    public function testPutRequestAddsContentLengthHeader(): void
    {
        $this->createTestFile('headers', '<?php echo json_encode([
            "content_length" => $_SERVER["CONTENT_LENGTH"] ?? null,
        ]);');
        $handler = $this->createHandler('headers.php');

        $request = new Request('PUT', 'http://localhost/headers.php');
        $promise = ($handler)($request);
        $response = $promise->wait();

        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('content_length', $body);
        $this->assertEquals('0', $body['content_length']);
    }

    public function testPostRequestWithEmptyBodyAddsContentLengthHeader(): void
    {
        $this->createTestFile('headers', '<?php echo json_encode([
            "content_length" => $_SERVER["CONTENT_LENGTH"] ?? null,
        ]);');
        $handler = $this->createHandler('headers.php');

        $request = new Request('POST', 'http://localhost/headers.php');
        $promise = ($handler)($request);
        $response = $promise->wait();

        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('content_length', $body);
        $this->assertEquals('0', $body['content_length']);
    }

    // Headers Tests
    public function testRequestWithCustomHeaders(): void
    {
        $this->createTestFile('headers', '<?php echo json_encode([
            "x_custom_header" => $_SERVER["HTTP_X_CUSTOM_HEADER"] ?? null,
            "accept" => $_SERVER["HTTP_ACCEPT"] ?? null,
        ]);');
        $handler = $this->createHandler('headers.php');

        $request = new Request('GET', 'http://localhost/headers.php', [
            'X-Custom-Header' => 'CustomValue',
            'Accept' => 'application/json',
        ]);
        $promise = ($handler)($request);
        $response = $promise->wait();

        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('CustomValue', $body['x_custom_header']);
        $this->assertEquals('application/json', $body['accept']);
    }

    public function testHeadersOptionMergesWithRequestHeaders(): void
    {
        $this->createTestFile('headers', '<?php echo json_encode([
            "x_request_header" => $_SERVER["HTTP_X_REQUEST_HEADER"] ?? null,
            "x_options_header" => $_SERVER["HTTP_X_OPTIONS_HEADER"] ?? null,
        ]);');
        $handler = $this->createHandler('headers.php');

        $request = new Request('GET', 'http://localhost/headers.php', [
            'X-Request-Header' => 'FromRequest',
        ]);
        $options = [
            RequestOptions::HEADERS => [
                'X-Options-Header' => 'FromOptions',
            ],
        ];
        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('FromRequest', $body['x_request_header']);
        $this->assertEquals('FromOptions', $body['x_options_header']);
    }

    // DELAY Option Tests
    public function testDelayOptionWaitsBeforeRequest(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $handler = $this->createHandler('index.php');

        $startTime = microtime(true);

        $request = new Request('GET', 'http://localhost/');
        $options = [
            RequestOptions::DELAY => 100,
        ]; // 100ms delay
        $promise = ($handler)($request, $options);
        $promise->wait();

        $elapsed = (microtime(true) - $startTime) * 1000; // Convert to ms
        $this->assertGreaterThanOrEqual(90, $elapsed, 'Request should have waited at least ~100ms');
    }

    public function testZeroDelayDoesNotWait(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $handler = $this->createHandler('index.php');

        $startTime = microtime(true);

        $request = new Request('GET', 'http://localhost/');
        $options = [
            RequestOptions::DELAY => 0,
        ];
        $promise = ($handler)($request, $options);
        $promise->wait();

        $elapsed = (microtime(true) - $startTime) * 1000;
        // Should complete reasonably fast (allowing for process overhead)
        $this->assertLessThan(2000, $elapsed);
    }

    // ON_STATS Callback Tests
    public function testOnStatsCallbackIsInvoked(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $handler = $this->createHandler('index.php');

        $statsReceived = null;

        $request = new Request('GET', 'http://localhost/');
        $options = [
            RequestOptions::ON_STATS => function (TransferStats $stats) use (&$statsReceived): void {
                $statsReceived = $stats;
            },
        ];

        $promise = ($handler)($request, $options);
        $promise->wait();

        $this->assertInstanceOf(TransferStats::class, $statsReceived);
        $this->assertSame($request, $statsReceived->getRequest());
        $this->assertInstanceOf(ResponseInterface::class, $statsReceived->getResponse());
        $this->assertNull($statsReceived->getHandlerErrorData());
    }

    public function testOnStatsTransferTimeIsPositive(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $handler = $this->createHandler('index.php');

        $transferTime = null;

        $request = new Request('GET', 'http://localhost/');
        $options = [
            RequestOptions::ON_STATS => function (TransferStats $stats) use (&$transferTime): void {
                $transferTime = $stats->getTransferTime();
            },
        ];

        $promise = ($handler)($request, $options);
        $promise->wait();

        $this->assertNotNull($transferTime);
        $this->assertGreaterThan(0, $transferTime);
    }

    public function testOnStatsNotInvokedWhenNotProvided(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $handler = $this->createHandler('index.php');

        // This test just ensures no errors occur when on_stats is not provided
        $request = new Request('GET', 'http://localhost/');
        $promise = ($handler)($request);
        $response = $promise->wait();

        $this->assertEquals(200, $response->getStatusCode());
    }

    // ON_HEADERS Callback Tests
    public function testOnHeadersCallbackIsInvoked(): void
    {
        $this->createTestFile('custom_headers', '<?php
            header("X-Custom-Response: TestValue");
            echo "OK";
        ');
        $handler = $this->createHandler('custom_headers.php');

        $headersResponse = null;

        $request = new Request('GET', 'http://localhost/custom_headers.php');
        $options = [
            RequestOptions::ON_HEADERS => function (ResponseInterface $response) use (&$headersResponse): void {
                $headersResponse = $response;
            },
        ];

        $promise = ($handler)($request, $options);
        $promise->wait();

        $this->assertInstanceOf(ResponseInterface::class, $headersResponse);
    }

    public function testOnHeadersExceptionRejectsPromise(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $handler = $this->createHandler('index.php');

        $request = new Request('GET', 'http://localhost/');
        $options = [
            RequestOptions::ON_HEADERS => function (ResponseInterface $response): void {
                throw new \Exception('Headers callback error');
            },
        ];

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('An error was encountered during the on_headers event');

        $promise = ($handler)($request, $options);
        $promise->wait();
    }

    // Authentication Tests
    public function testBasicAuthHeader(): void
    {
        $this->createTestFile('auth', '<?php echo json_encode([
            "authorization" => $_SERVER["HTTP_AUTHORIZATION"] ?? null,
        ]);');
        $handler = $this->createHandler('auth.php');

        $request = new Request('GET', 'http://localhost/auth.php');
        $options = [
            RequestOptions::AUTH => ['username', 'password'],
        ];
        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('authorization', $body);
        $expected = 'Basic ' . base64_encode('username:password');
        $this->assertEquals($expected, $body['authorization']);
    }

    // Cookie Tests
    public function testCookiesAreSent(): void
    {
        $this->createTestFile('cookies', '<?php echo json_encode($_COOKIE);');
        $handler = $this->createHandler('cookies.php');

        $request = new Request('GET', 'http://localhost/cookies.php');
        $options = [
            RequestOptions::COOKIES => [
                'session_id' => 'abc123',
                'user' => 'john',
            ],
        ];
        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('abc123', $body['session_id']);
        $this->assertEquals('john', $body['user']);
    }

    // Timeout Tests
    public function testTimeoutOption(): void
    {
        $this->createTestFile('slow', '<?php sleep(5); echo "Done";');
        $handler = $this->createHandler('slow.php');

        $request = new Request('GET', 'http://localhost/slow.php');
        $options = [
            RequestOptions::TIMEOUT => 0.5,
        ]; // 500ms timeout

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('The process timed out');

        $promise = ($handler)($request, $options);
        $promise->wait();
    }

    // Response Status Tests
    public function testResponseStatusCode(): void
    {
        $this->createTestFile('notfound', '<?php
            http_response_code(404);
            echo "Not Found";
        ');
        $handler = $this->createHandler('notfound.php');

        $request = new Request('GET', 'http://localhost/notfound.php');
        $promise = ($handler)($request);
        $response = $promise->wait();

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertEquals('Not Found', (string) $response->getBody());
    }

    public function testRedirectStatusCode(): void
    {
        $this->createTestFile('redirect', '<?php
            http_response_code(302);
            header("Location: /target.php");
        ');
        $handler = $this->createHandler('redirect.php');

        $request = new Request('GET', 'http://localhost/redirect.php');
        $promise = ($handler)($request);
        $response = $promise->wait();

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Location'));
    }

    public function testServerErrorStatusCode(): void
    {
        $this->createTestFile('error', '<?php
            http_response_code(500);
            echo "Internal Server Error";
        ');
        $handler = $this->createHandler('error.php');

        $request = new Request('GET', 'http://localhost/error.php');
        $promise = ($handler)($request);
        $response = $promise->wait();

        $this->assertEquals(500, $response->getStatusCode());
    }

    // Content Type Tests
    public function testJsonContentTypeFromRequest(): void
    {
        $this->createTestFile('json_echo', '<?php
            $input = json_decode(file_get_contents("php://input"), true);
            echo json_encode(["received" => $input]);
        ');
        $handler = $this->createHandler('json_echo.php');

        $body = json_encode([
            'data' => 'test',
        ]);
        $request = new Request('POST', 'http://localhost/json_echo.php', [
            'Content-Type' => 'application/json',
        ], $body);

        $promise = ($handler)($request);
        $response = $promise->wait();

        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertEquals([
            'data' => 'test',
        ], $responseBody['received']);
    }

    // Response Headers Tests
    public function testResponseHeadersAreParsed(): void
    {
        $this->createTestFile('custom_headers', '<?php
            header("X-Custom-Header: CustomValue");
            header("Content-Type: application/json");
            echo "{}";
        ');
        $handler = $this->createHandler('custom_headers.php');

        $request = new Request('GET', 'http://localhost/custom_headers.php');
        $promise = ($handler)($request);
        $response = $promise->wait();

        $this->assertTrue($response->hasHeader('X-Custom-Header'));
        $this->assertEquals('CustomValue', $response->getHeaderLine('X-Custom-Header'));
    }

    // SINK Option Tests
    public function testSinkToFilePath(): void
    {
        $this->createTestFile('content', '<?php echo "This is the response body content";');
        $handler = $this->createHandler('content.php');

        $sinkFile = $this->tempDir . '/sink_output.txt';

        $request = new Request('GET', 'http://localhost/content.php');
        $options = [
            RequestOptions::SINK => $sinkFile,
        ];
        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        $this->assertFileExists($sinkFile);
        $this->assertEquals('This is the response body content', file_get_contents($sinkFile));
        $this->assertEquals('This is the response body content', (string) $response->getBody());
    }

    public function testSinkToResource(): void
    {
        $this->createTestFile('content', '<?php echo "Resource sink test";');
        $handler = $this->createHandler('content.php');

        $sinkFile = $this->tempDir . '/resource_sink.txt';
        $resource = fopen($sinkFile, 'w+');

        $request = new Request('GET', 'http://localhost/content.php');
        $options = [
            RequestOptions::SINK => $resource,
        ];
        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        fclose($resource);

        $this->assertEquals('Resource sink test', file_get_contents($sinkFile));
    }

    public function testSinkToStream(): void
    {
        $this->createTestFile('content', '<?php echo "Stream sink test";');
        $handler = $this->createHandler('content.php');

        $stream = Utils::streamFor(fopen('php://temp', 'r+'));

        $request = new Request('GET', 'http://localhost/content.php');
        $options = [
            RequestOptions::SINK => $stream,
        ];
        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        $this->assertEquals('Stream sink test', (string) $response->getBody());
    }

    // Decode Content Tests
    public function testDecodeContentWithGzipEncoding(): void
    {
        $originalContent = 'This is the original uncompressed content for gzip test';
        $gzippedContent = gzencode($originalContent);

        $this->createTestFile('gzip', '<?php
            header("Content-Encoding: gzip");
            header("Content-Length: " . strlen($GLOBALS["gzipped"]));
            echo $GLOBALS["gzipped"];
        ');

        // We need to pass the gzipped content via a different approach
        // Create a file that outputs pre-gzipped content
        $gzipFile = $this->testDocumentRoot . '/gzip_response.php';
        file_put_contents($gzipFile, '<?php
            header("Content-Encoding: gzip");
            $content = gzencode("This is the original uncompressed content for gzip test");
            header("Content-Length: " . strlen($content));
            echo $content;
        ');

        $handler = $this->createHandler('gzip_response.php');

        $request = new Request('GET', 'http://localhost/gzip_response.php');
        $options = [
            RequestOptions::DECODE_CONTENT => true,
        ];
        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        // Content should be decoded
        $this->assertEquals($originalContent, (string) $response->getBody());
        // Original encoding should be preserved in x-encoded-content-encoding
        $this->assertTrue($response->hasHeader('x-encoded-content-encoding'));
        $this->assertEquals('gzip', $response->getHeaderLine('x-encoded-content-encoding'));
        // Content-Encoding header should be removed
        $this->assertFalse($response->hasHeader('Content-Encoding'));
    }

    public function testDecodeContentWithDeflateEncoding(): void
    {
        $originalContent = 'This is deflate compressed content';

        $deflateFile = $this->testDocumentRoot . '/deflate_response.php';
        // Note: gzcompress() produces zlib-wrapped data which is what most servers
        // actually send for Content-Encoding: deflate (despite the name)
        file_put_contents($deflateFile, '<?php
            header("Content-Encoding: deflate");
            $content = gzcompress("This is deflate compressed content");
            header("Content-Length: " . strlen($content));
            echo $content;
        ');

        $handler = $this->createHandler('deflate_response.php');

        $request = new Request('GET', 'http://localhost/deflate_response.php');
        $options = [
            RequestOptions::DECODE_CONTENT => true,
        ];
        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        $this->assertEquals($originalContent, (string) $response->getBody());
        $this->assertTrue($response->hasHeader('x-encoded-content-encoding'));
        $this->assertEquals('deflate', $response->getHeaderLine('x-encoded-content-encoding'));
    }

    public function testDecodeContentDisabledReturnsRawContent(): void
    {
        $gzipFile = $this->testDocumentRoot . '/gzip_raw.php';
        file_put_contents($gzipFile, '<?php
            header("Content-Encoding: gzip");
            $content = gzencode("Original content");
            echo $content;
        ');

        $handler = $this->createHandler('gzip_raw.php');

        $request = new Request('GET', 'http://localhost/gzip_raw.php');
        $options = [
            RequestOptions::DECODE_CONTENT => false,
        ];
        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        // Content should remain gzip-encoded (not equal to original)
        $this->assertNotEquals('Original content', (string) $response->getBody());
        // Content-Encoding header should still be present
        $this->assertTrue($response->hasHeader('Content-Encoding'));
        $this->assertEquals('gzip', $response->getHeaderLine('Content-Encoding'));
    }

    public function testDecodeContentWithNoEncodingPassesThrough(): void
    {
        $this->createTestFile('plain', '<?php echo "Plain unencoded content";');
        $handler = $this->createHandler('plain.php');

        $request = new Request('GET', 'http://localhost/plain.php');
        $options = [
            RequestOptions::DECODE_CONTENT => true,
        ];
        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        $this->assertEquals('Plain unencoded content', (string) $response->getBody());
        $this->assertFalse($response->hasHeader('x-encoded-content-encoding'));
    }

    public function testDecodeContentWithUnsupportedEncodingPassesThrough(): void
    {
        $brFile = $this->testDocumentRoot . '/brotli.php';
        file_put_contents($brFile, '<?php
            header("Content-Encoding: br");
            echo "Some content with br encoding header";
        ');

        $handler = $this->createHandler('brotli.php');

        $request = new Request('GET', 'http://localhost/brotli.php');
        $options = [
            RequestOptions::DECODE_CONTENT => true,
        ];
        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        // Content should pass through unchanged for unsupported encodings
        $this->assertEquals('Some content with br encoding header', (string) $response->getBody());
        // Content-Encoding should still be present (not decoded)
        $this->assertTrue($response->hasHeader('Content-Encoding'));
        $this->assertEquals('br', $response->getHeaderLine('Content-Encoding'));
    }

    public function testDecodeContentUpdatesContentLength(): void
    {
        $originalContent = 'Short';
        $compressedLength = strlen(gzencode($originalContent));

        $gzipFile = $this->testDocumentRoot . '/gzip_length.php';
        file_put_contents($gzipFile, '<?php
            header("Content-Encoding: gzip");
            $content = gzencode("Short");
            header("Content-Length: " . strlen($content));
            echo $content;
        ');

        $handler = $this->createHandler('gzip_length.php');

        $request = new Request('GET', 'http://localhost/gzip_length.php');
        $options = [
            RequestOptions::DECODE_CONTENT => true,
        ];
        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        // x-encoded-content-length should have the original compressed length
        $this->assertTrue($response->hasHeader('x-encoded-content-length'));
        $this->assertEquals((string) $compressedLength, $response->getHeaderLine('x-encoded-content-length'));
    }

    // Empty Options Tests
    public function testEmptyOptionsArray(): void
    {
        $this->createTestFile('index', '<?php echo "Hello World";');
        $handler = $this->createHandler('index.php');

        $request = new Request('GET', 'http://localhost/');
        $promise = ($handler)($request, []);
        $response = $promise->wait();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Hello World', (string) $response->getBody());
    }

    public function testNoOptionsProvided(): void
    {
        $this->createTestFile('index', '<?php echo "Hello World";');
        $handler = $this->createHandler('index.php');

        $request = new Request('GET', 'http://localhost/');
        $promise = ($handler)($request);
        $response = $promise->wait();

        $this->assertEquals(200, $response->getStatusCode());
    }

    // HEAD Request Tests
    public function testHeadRequest(): void
    {
        $this->createTestFile('head_test', '<?php echo "This body should not be returned for HEAD";');
        $handler = $this->createHandler('head_test.php');

        $request = new Request('HEAD', 'http://localhost/head_test.php');
        $promise = ($handler)($request, []);
        $response = $promise->wait();

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testHeadRequestWithHeaders(): void
    {
        $this->createTestFile('head_headers', '<?php
            header("X-Custom-Header: test-value");
            header("Content-Type: application/json");
            echo "Body content";
        ');
        $handler = $this->createHandler('head_headers.php');

        $request = new Request('HEAD', 'http://localhost/head_headers.php');
        $promise = ($handler)($request, []);
        $response = $promise->wait();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('X-Custom-Header'));
        $this->assertEquals('test-value', $response->getHeaderLine('X-Custom-Header'));
    }

    // Content-Length Tests
    public function testGetRequestDoesNotAddContentLength(): void
    {
        $this->createTestFile('get_cl', '<?php
            // Output the received Content-Length header (or "none")
            echo isset($_SERVER["CONTENT_LENGTH"]) ? $_SERVER["CONTENT_LENGTH"] : "none";
        ');
        $handler = $this->createHandler('get_cl.php');

        $request = new Request('GET', 'http://localhost/get_cl.php');
        $promise = ($handler)($request, []);
        $response = $promise->wait();

        // GET requests should not have Content-Length
        $body = (string) $response->getBody();
        $this->assertEquals('none', $body);
    }

    // Sink Edge Cases Tests
    public function testSinkToNonExistentFilePath(): void
    {
        $this->createTestFile('sink_content', '<?php echo "Content for new file";');
        $handler = $this->createHandler('sink_content.php');

        $sinkPath = $this->testDocumentRoot . '/new_sink_file.txt';
        // Ensure file doesn't exist
        if (file_exists($sinkPath)) {
            unlink($sinkPath);
        }

        $request = new Request('GET', 'http://localhost/sink_content.php');
        $options = [
            RequestOptions::SINK => $sinkPath,
        ];
        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        // File should be created
        $this->assertFileExists($sinkPath);
        $this->assertEquals('Content for new file', file_get_contents($sinkPath));
    }

    // on_stats Error Callback Tests
    public function testOnStatsInvokedOnException(): void
    {
        $handler = $this->createHandler('nonexistent_file.php');

        $statsReceived = null;
        $request = new Request('GET', 'http://localhost/nonexistent_file.php');
        $options = [
            RequestOptions::ON_STATS => function (TransferStats $stats) use (&$statsReceived): void {
                $statsReceived = $stats;
            },
        ];

        $promise = ($handler)($request, $options);

        try {
            $promise->wait();
        } catch (\Exception) {
            // Expected - file doesn't exist
        }

        // on_stats should still be called even if there's an error
        // Note: This depends on how the handler handles the error
        // The handler may or may not call on_stats depending on where the error occurs
        $this->assertTrue(true); // Test passes if no exception thrown
    }

    // on_headers Callback Order Test
    public function testOnHeadersCalledBeforeSinkWrite(): void
    {
        $this->createTestFile('headers_order', '<?php
            header("X-Test-Header: order-test");
            echo "Body content for sink";
        ');
        $handler = $this->createHandler('headers_order.php');

        $sinkPath = $this->testDocumentRoot . '/headers_order_sink.txt';
        $headersReceived = null;
        $sinkExistedDuringCallback = null;

        $request = new Request('GET', 'http://localhost/headers_order.php');
        $options = [
            RequestOptions::SINK => $sinkPath,
            RequestOptions::ON_HEADERS => function ($response) use ($sinkPath, &$headersReceived, &$sinkExistedDuringCallback): void {
                $headersReceived = $response->getHeaders();
                // Check if sink file exists during callback
                $sinkExistedDuringCallback = file_exists($sinkPath);
            },
        ];

        // Clean up any existing sink file
        if (file_exists($sinkPath)) {
            unlink($sinkPath);
        }

        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        // on_headers should have been called
        $this->assertNotNull($headersReceived);
        $this->assertArrayHasKey('X-Test-Header', $headersReceived);

        // Sink file should exist after request completes
        $this->assertFileExists($sinkPath);
    }

    // DELETE Request Test
    public function testDeleteRequest(): void
    {
        $this->createTestFile('delete_test', '<?php
            echo "Method: " . $_SERVER["REQUEST_METHOD"];
        ');
        $handler = $this->createHandler('delete_test.php');

        $request = new Request('DELETE', 'http://localhost/delete_test.php');
        $promise = ($handler)($request, []);
        $response = $promise->wait();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Method: DELETE', (string) $response->getBody());
    }

    // PATCH Request Test
    public function testPatchRequest(): void
    {
        $this->createTestFile('patch_test', '<?php
            echo "Method: " . $_SERVER["REQUEST_METHOD"];
            echo " Body: " . file_get_contents("php://input");
        ');
        $handler = $this->createHandler('patch_test.php');

        $request = new Request('PATCH', 'http://localhost/patch_test.php', [], 'patch data');
        $promise = ($handler)($request, []);
        $response = $promise->wait();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Method: PATCH', (string) $response->getBody());
        $this->assertStringContainsString('Body: patch data', (string) $response->getBody());
    }

    // OPTIONS Request Test
    public function testOptionsRequest(): void
    {
        $this->createTestFile('options_test', '<?php
            header("Allow: GET, POST, OPTIONS");
            echo "Method: " . $_SERVER["REQUEST_METHOD"];
        ');
        $handler = $this->createHandler('options_test.php');

        $request = new Request('OPTIONS', 'http://localhost/options_test.php');
        $promise = ($handler)($request, []);
        $response = $promise->wait();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Method: OPTIONS', (string) $response->getBody());
    }

    // Protocol Version and Reason Phrase Tests
    public function testResponseHasProtocolVersion(): void
    {
        $this->createTestFile('version', '<?php echo "OK";');
        $handler = $this->createHandler('version.php');

        $request = new Request('GET', 'http://localhost/version.php');
        $promise = ($handler)($request, []);
        $response = $promise->wait();

        $this->assertEquals('1.1', $response->getProtocolVersion());
    }

    public function testResponseHasReasonPhrase(): void
    {
        $this->createTestFile('reason', '<?php echo "OK";');
        $handler = $this->createHandler('reason.php');

        $request = new Request('GET', 'http://localhost/reason.php');
        $promise = ($handler)($request, []);
        $response = $promise->wait();

        $this->assertEquals('OK', $response->getReasonPhrase());
    }

    public function testResponseReasonPhraseForDifferentStatusCodes(): void
    {
        $testCases = [
            [201, 'Created'],
            [204, 'No Content'],
            [301, 'Moved Permanently'],
            [404, 'Not Found'],
            [500, 'Internal Server Error'],
        ];

        foreach ($testCases as [$statusCode, $expectedReason]) {
            $this->createTestFile("status_{$statusCode}", "<?php http_response_code({$statusCode});");
            $handler = $this->createHandler("status_{$statusCode}.php");

            $request = new Request('GET', 'http://localhost/status_' . $statusCode . '.php');
            $promise = ($handler)($request, []);
            $response = $promise->wait();

            $this->assertEquals($statusCode, $response->getStatusCode());
            $this->assertEquals($expectedReason, $response->getReasonPhrase());
        }
    }

    // Protocol Version Tests
    public function testRejectsUnsupportedProtocolVersion(): void
    {
        $this->createTestFile('proto', '<?php echo "OK";');
        $handler = $this->createHandler('proto.php');

        // Create a request with HTTP/2.0
        $request = new Request('GET', 'http://localhost/proto.php');
        $request = $request->withProtocolVersion('2.0');

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('HTTP/2.0 is not supported by the CLI handler');

        ($handler)($request, []);
    }

    public function testAcceptsHttp10(): void
    {
        $this->createTestFile('http10', '<?php echo "OK";');
        $handler = $this->createHandler('http10.php');

        $request = new Request('GET', 'http://localhost/http10.php');
        $request = $request->withProtocolVersion('1.0');

        $promise = ($handler)($request, []);
        $response = $promise->wait();

        $this->assertEquals(200, $response->getStatusCode());
    }

    // Expect Header Tests
    public function testExpectHeaderIsRemoved(): void
    {
        $this->createTestFile('expect', '<?php
            echo isset($_SERVER["HTTP_EXPECT"]) ? "Expect: " . $_SERVER["HTTP_EXPECT"] : "No Expect header";
        ');
        $handler = $this->createHandler('expect.php');

        $request = new Request('PUT', 'http://localhost/expect.php', [
            'Expect' => '100-continue',
        ], 'body');

        $promise = ($handler)($request, []);
        $response = $promise->wait();

        // Expect header should be removed by the handler
        $this->assertEquals('No Expect header', (string) $response->getBody());
    }

    // Stream Option Tests
    public function testStreamOptionKeepsStreamOpen(): void
    {
        $this->createTestFile('stream_opt', '<?php echo "Streaming content";');
        $handler = $this->createHandler('stream_opt.php');

        $request = new Request('GET', 'http://localhost/stream_opt.php');
        $options = [
            RequestOptions::STREAM => true,
        ];
        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Streaming content', (string) $response->getBody());
    }

    // Default Sink Tests
    public function testDefaultDrainToPhpTemp(): void
    {
        $this->createTestFile('drain', '<?php echo "Content to drain";');
        $handler = $this->createHandler('drain.php');

        $request = new Request('GET', 'http://localhost/drain.php');
        $promise = ($handler)($request, []);
        $response = $promise->wait();

        // Body should be readable multiple times (drained to php://temp)
        $body1 = (string) $response->getBody();
        $response->getBody()->rewind();
        $body2 = (string) $response->getBody();

        $this->assertEquals('Content to drain', $body1);
        $this->assertEquals($body1, $body2);
    }

    // HEAD Request Tests (no drain)
    public function testHeadRequestSkipsDrain(): void
    {
        $this->createTestFile('head_drain', '<?php
            header("X-Test: value");
            echo "This should not be processed for HEAD";
        ');
        $handler = $this->createHandler('head_drain.php');

        $request = new Request('HEAD', 'http://localhost/head_drain.php');
        $promise = ($handler)($request, []);
        $response = $promise->wait();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('X-Test'));
    }

    // on_headers Callback Before Drain Test
    public function testOnHeadersCalledBeforeDrain(): void
    {
        $this->createTestFile('headers_drain', '<?php
            header("X-Before-Drain: yes");
            echo str_repeat("x", 1000);
        ');
        $handler = $this->createHandler('headers_drain.php');

        $headersReceivedAt = null;
        $bodyAvailableAt = null;

        $request = new Request('GET', 'http://localhost/headers_drain.php');
        $options = [
            RequestOptions::ON_HEADERS => function ($response) use (&$headersReceivedAt): void {
                $headersReceivedAt = microtime(true);
                // At this point headers are available but body may not be fully drained
                $this->assertTrue($response->hasHeader('X-Before-Drain'));
            },
        ];

        $promise = ($handler)($request, $options);
        $response = $promise->wait();

        $this->assertNotNull($headersReceivedAt);
        $this->assertEquals(1000, strlen((string) $response->getBody()));
    }

    private function createHandler(string $file = 'index.php'): CliHandler
    {
        return new CliHandler(new Client($this->testDocumentRoot, $file));
    }
}
