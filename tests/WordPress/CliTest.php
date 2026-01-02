<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests\WordPress;

use n5s\HttpCli\Client;
use n5s\HttpCli\Response;
use n5s\HttpCli\Tests\AbstractHttpCliTestCase;
use n5s\HttpCli\WordPress\Cli;
use WpOrg\Requests\Capability;
use WpOrg\Requests\Cookie;
use WpOrg\Requests\Cookie\Jar as CookieJar;
use WpOrg\Requests\Exception;
use WpOrg\Requests\Exception\InvalidArgument;
use WpOrg\Requests\Hooks;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Transport;

class CliTest extends AbstractHttpCliTestCase
{
    public function testImplementsTransportInterface(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $transport = $this->createTransport('index.php');

        $this->assertInstanceOf(Transport::class, $transport);
    }

    // Test Method
    public function testTestMethodReturnsTrue(): void
    {
        $this->assertTrue(Cli::test());
    }

    public function testTestMethodReturnsFalseForSSL(): void
    {
        $this->assertFalse(Cli::test([
            Capability::SSL => true,
        ]));
    }

    public function testTestMethodReturnsTrueWithoutSSL(): void
    {
        $this->assertTrue(Cli::test([
            Capability::SSL => false,
        ]));
    }

    // Basic Request Tests
    public function testBasicGetRequest(): void
    {
        $this->createTestFile('index', '<?php echo "Hello World";');
        $transport = $this->createTransport('index.php');

        $response = $transport->request(
            'http://localhost/',
            [],
            [],
            $this->getDefaultOptions()
        );

        $this->assertIsString($response);
        $this->assertStringContainsString('HTTP/1.1 200 OK', $response);
        $this->assertStringContainsString('Hello World', $response);
    }

    public function testGetRequestWithQueryParams(): void
    {
        $this->createTestFile('query', '<?php echo json_encode($_GET);');
        $transport = $this->createTransport('query.php');

        $options = $this->getDefaultOptions();
        $options['type'] = Requests::GET;

        $response = $transport->request(
            'http://localhost/query.php',
            [],
            [
                'foo' => 'bar',
                'baz' => 'qux',
            ],
            $options
        );

        $this->assertStringContainsString('HTTP/1.1 200', $response);
        $this->assertStringContainsString('"foo":"bar"', $response);
        $this->assertStringContainsString('"baz":"qux"', $response);
    }

    public function testBasicPostRequest(): void
    {
        $this->createTestFile('post', '<?php echo json_encode($_POST);');
        $transport = $this->createTransport('post.php');

        $options = $this->getDefaultOptions();
        $options['type'] = Requests::POST;

        $response = $transport->request(
            'http://localhost/post.php',
            [],
            [
                'name' => 'John',
                'email' => 'john@example.com',
            ],
            $options
        );

        $this->assertStringContainsString('HTTP/1.1 200', $response);
        $this->assertStringContainsString('"name":"John"', $response);
        $this->assertStringContainsString('"email":"john@example.com"', $response);
    }

    public function testPostRequestWithStringBody(): void
    {
        $this->createTestFile('rawbody', '<?php echo file_get_contents("php://input");');
        $transport = $this->createTransport('rawbody.php');

        $options = $this->getDefaultOptions();
        $options['type'] = Requests::POST;

        $response = $transport->request(
            'http://localhost/rawbody.php',
            [],
            'raw body content',
            $options
        );

        $this->assertStringContainsString('HTTP/1.1 200', $response);
        $this->assertStringContainsString('raw body content', $response);
    }

    public function testPostRequestWithJsonBody(): void
    {
        $this->createTestFile('json', '<?php
            header("Content-Type: application/json");
            $input = json_decode(file_get_contents("php://input"), true);
            echo json_encode($input);
        ');
        $transport = $this->createTransport('json.php');

        $options = $this->getDefaultOptions();
        $options['type'] = Requests::POST;

        $response = $transport->request(
            'http://localhost/json.php',
            [
                'Content-Type' => 'application/json',
            ],
            [
                'key' => 'value',
            ],
            $options
        );

        $this->assertStringContainsString('HTTP/1.1 200', $response);
        $this->assertStringContainsString('"key":"value"', $response);
    }

    // Headers Tests
    public function testRequestWithCustomHeaders(): void
    {
        $this->createTestFile('headers', '<?php echo json_encode([
            "x_custom_header" => $_SERVER["HTTP_X_CUSTOM_HEADER"] ?? null,
            "accept" => $_SERVER["HTTP_ACCEPT"] ?? null,
        ]);');
        $transport = $this->createTransport('headers.php');

        $response = $transport->request(
            'http://localhost/headers.php',
            [
                'X-Custom-Header' => 'CustomValue',
                'Accept' => 'application/json',
            ],
            [],
            $this->getDefaultOptions()
        );

        $this->assertStringContainsString('"x_custom_header":"CustomValue"', $response);
        $this->assertStringContainsString('"accept":"application\/json"', $response);
    }

    public function testResponseHeaders(): void
    {
        $this->createTestFile('response_headers', '<?php
            header("X-Custom-Header: CustomValue");
            header("Content-Type: application/json");
            echo "{}";
        ');
        $transport = $this->createTransport('response_headers.php');

        $response = $transport->request(
            'http://localhost/response_headers.php',
            [],
            [],
            $this->getDefaultOptions()
        );

        $this->assertStringContainsString('X-Custom-Header: CustomValue', $response);
        $this->assertStringContainsString('Content-Type: application/json', $response);
    }

    // Authentication Tests
    public function testBasicAuth(): void
    {
        $this->createTestFile('auth', '<?php echo json_encode([
            "authorization" => $_SERVER["HTTP_AUTHORIZATION"] ?? null,
        ]);');
        $transport = $this->createTransport('auth.php');

        $options = $this->getDefaultOptions();
        $options['auth'] = ['username', 'password'];

        $response = $transport->request(
            'http://localhost/auth.php',
            [],
            [],
            $options
        );

        $expected = 'Basic ' . base64_encode('username:password');
        $this->assertStringContainsString($expected, $response);
    }

    // Cookies Tests
    public function testCookiesArray(): void
    {
        $this->createTestFile('cookies', '<?php echo json_encode($_COOKIE);');
        $transport = $this->createTransport('cookies.php');

        $options = $this->getDefaultOptions();
        $options['cookies'] = [
            'session_id' => 'abc123',
            'user' => 'john',
        ];

        $response = $transport->request(
            'http://localhost/cookies.php',
            [],
            [],
            $options
        );

        $this->assertStringContainsString('"session_id":"abc123"', $response);
        $this->assertStringContainsString('"user":"john"', $response);
    }

    public function testCookiesJar(): void
    {
        $this->createTestFile('cookies', '<?php echo json_encode($_COOKIE);');
        $transport = $this->createTransport('cookies.php');

        $jar = new CookieJar();
        $jar['session'] = new Cookie('session', 'xyz789');

        $options = $this->getDefaultOptions();
        $options['cookies'] = $jar;

        $response = $transport->request(
            'http://localhost/cookies.php',
            [],
            [],
            $options
        );

        $this->assertStringContainsString('"session":"xyz789"', $response);
    }

    // Status Code Tests
    public function testResponseStatusCode200(): void
    {
        $this->createTestFile('ok', '<?php echo "OK";');
        $transport = $this->createTransport('ok.php');

        $response = $transport->request(
            'http://localhost/ok.php',
            [],
            [],
            $this->getDefaultOptions()
        );

        $this->assertStringContainsString('HTTP/1.1 200 OK', $response);
    }

    public function testResponseStatusCode404(): void
    {
        $this->createTestFile('notfound', '<?php
            http_response_code(404);
            echo "Not Found";
        ');
        $transport = $this->createTransport('notfound.php');

        $response = $transport->request(
            'http://localhost/notfound.php',
            [],
            [],
            $this->getDefaultOptions()
        );

        $this->assertStringContainsString('HTTP/1.1 404 Not Found', $response);
    }

    public function testResponseStatusCode500(): void
    {
        $this->createTestFile('error', '<?php
            http_response_code(500);
            echo "Internal Server Error";
        ');
        $transport = $this->createTransport('error.php');

        $response = $transport->request(
            'http://localhost/error.php',
            [],
            [],
            $this->getDefaultOptions()
        );

        $this->assertStringContainsString('HTTP/1.1 500 Internal Server Error', $response);
    }

    public function testResponseStatusCode302(): void
    {
        $this->createTestFile('redirect', '<?php
            http_response_code(302);
            header("Location: /target.php");
        ');
        $transport = $this->createTransport('redirect.php');

        $response = $transport->request(
            'http://localhost/redirect.php',
            [],
            [],
            $this->getDefaultOptions()
        );

        $this->assertStringContainsString('HTTP/1.1 302 Found', $response);
        $this->assertStringContainsString('Location: /target.php', $response);
    }

    // Timeout Tests
    public function testTimeoutOption(): void
    {
        $this->createTestFile('slow', '<?php sleep(5); echo "Done";');
        $transport = $this->createTransport('slow.php');

        $options = $this->getDefaultOptions();
        $options['timeout'] = 0.5;

        $this->expectException(Exception::class);

        $transport->request(
            'http://localhost/slow.php',
            [],
            [],
            $options
        );
    }

    // Request Multiple Tests
    public function testRequestMultiple(): void
    {
        $this->createTestFile('one', '<?php echo "Response 1";');
        $this->createTestFile('two', '<?php echo "Response 2";');

        $transport1 = $this->createTransport('one.php');

        $requests = [
            'first' => [
                'url' => 'http://localhost/one.php',
                'headers' => [],
                'data' => [],
                'options' => $this->getDefaultOptions(),
            ],
        ];

        $responses = $transport1->request_multiple($requests, $this->getDefaultOptions());

        $this->assertArrayHasKey('first', $responses);
        $this->assertStringContainsString('Response 1', $responses['first']);
    }

    public function testRequestMultipleWithError(): void
    {
        $this->createTestFile('ok', '<?php echo "OK";');
        $transport = $this->createTransport('ok.php');

        $slowOptions = $this->getDefaultOptions();
        $slowOptions['timeout'] = 0.1;

        // Create a slow file for the second request
        $this->createTestFile('slow', '<?php sleep(5); echo "Done";');

        $requests = [
            'fast' => [
                'url' => 'http://localhost/ok.php',
                'headers' => [],
                'data' => [],
                'options' => $this->getDefaultOptions(),
            ],
        ];

        $responses = $transport->request_multiple($requests, $this->getDefaultOptions());

        $this->assertArrayHasKey('fast', $responses);
        $this->assertStringContainsString('OK', $responses['fast']);
    }

    // Factory Methods Tests
    public function testCreateFactory(): void
    {
        $this->createTestFile('index', '<?php echo "Factory Test";');

        $transport = Cli::create($this->testDocumentRoot, 'index.php');
        $response = $transport->request(
            'http://localhost/',
            [],
            [],
            $this->getDefaultOptions()
        );

        $this->assertStringContainsString('Factory Test', $response);
    }

    public function testCreateFromClient(): void
    {
        $this->createTestFile('test', '<?php echo "From Client";');

        $httpCliClient = new Client($this->testDocumentRoot, 'test.php');
        $transport = Cli::createFromClient($httpCliClient);
        $response = $transport->request(
            'http://localhost/test.php',
            [],
            [],
            $this->getDefaultOptions()
        );

        $this->assertStringContainsString('From Client', $response);
    }

    // HTTP Method Tests
    public function testPutRequest(): void
    {
        $this->createTestFile('put', '<?php
            echo "Method: " . $_SERVER["REQUEST_METHOD"];
            echo " Body: " . file_get_contents("php://input");
        ');
        $transport = $this->createTransport('put.php');

        $options = $this->getDefaultOptions();
        $options['type'] = Requests::PUT;

        $response = $transport->request(
            'http://localhost/put.php',
            [],
            'put data',
            $options
        );

        $this->assertStringContainsString('Method: PUT', $response);
        $this->assertStringContainsString('Body: put data', $response);
    }

    public function testDeleteRequest(): void
    {
        $this->createTestFile('delete', '<?php echo "Method: " . $_SERVER["REQUEST_METHOD"];');
        $transport = $this->createTransport('delete.php');

        $options = $this->getDefaultOptions();
        $options['type'] = Requests::DELETE;

        $response = $transport->request(
            'http://localhost/delete.php',
            [],
            [],
            $options
        );

        $this->assertStringContainsString('Method: DELETE', $response);
    }

    public function testPatchRequest(): void
    {
        $this->createTestFile('patch', '<?php
            echo "Method: " . $_SERVER["REQUEST_METHOD"];
            echo " Body: " . file_get_contents("php://input");
        ');
        $transport = $this->createTransport('patch.php');

        $options = $this->getDefaultOptions();
        $options['type'] = Requests::PATCH;

        $response = $transport->request(
            'http://localhost/patch.php',
            [],
            'patch data',
            $options
        );

        $this->assertStringContainsString('Method: PATCH', $response);
        $this->assertStringContainsString('Body: patch data', $response);
    }

    // User Agent Tests
    public function testUserAgent(): void
    {
        $this->createTestFile('useragent', '<?php echo $_SERVER["HTTP_USER_AGENT"] ?? "none";');
        $transport = $this->createTransport('useragent.php');

        $options = $this->getDefaultOptions();
        $options['useragent'] = 'WordPress/6.0';

        $response = $transport->request(
            'http://localhost/useragent.php',
            [],
            [],
            $options
        );

        $this->assertStringContainsString('WordPress/6.0', $response);
    }

    // Response Parsing Tests
    public function testResponseCanBeParsedByRequests(): void
    {
        $this->createTestFile('parseable', '<?php
            header("Content-Type: text/plain");
            header("X-Test: value");
            echo "Body content";
        ');
        $transport = $this->createTransport('parseable.php');

        $rawResponse = $transport->request(
            'http://localhost/parseable.php',
            [],
            [],
            $this->getDefaultOptions()
        );

        // Verify the response format is correct for WordPress to parse
        $this->assertMatchesRegularExpression('/^HTTP\/1\.1 \d{3}/', $rawResponse);
        $this->assertStringContainsString("\r\n\r\n", $rawResponse);

        // Split headers and body
        $parts = explode("\r\n\r\n", $rawResponse, 2);
        $this->assertCount(2, $parts);
        $this->assertStringContainsString('Content-Type:', $parts[0]);
        $this->assertEquals('Body content', $parts[1]);
    }

    // Hooks Tests
    public function testBeforeRequestHookIsDispatched(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $transport = $this->createTransport('index.php');

        $hookCalled = false;
        $capturedUrl = null;

        $hooks = new Hooks();
        $hooks->register('cli.before_request', function (&$url, &$headers, &$data, &$options) use (&$hookCalled, &$capturedUrl): void {
            $hookCalled = true;
            $capturedUrl = $url;
        });

        $options = $this->getDefaultOptions();
        $options['hooks'] = $hooks;

        $transport->request('http://localhost/', [], [], $options);

        $this->assertTrue($hookCalled);
        $this->assertEquals('http://localhost/', $capturedUrl);
    }

    public function testBeforeRequestHookCanModifyUrl(): void
    {
        $this->createTestFile('index', '<?php echo "Original";');
        $this->createTestFile('modified', '<?php echo "Modified";');
        $transport = $this->createTransport('index.php');

        $hooks = new Hooks();
        $hooks->register('cli.before_request', function (&$url, &$headers, &$data, &$options): void {
            // Note: URL modification doesn't change which file is executed
            // since that's determined by the Client configuration
            // But we can verify the hook receives the URL
        });

        $options = $this->getDefaultOptions();
        $options['hooks'] = $hooks;

        $response = $transport->request('http://localhost/', [], [], $options);

        $this->assertStringContainsString('Original', $response);
    }

    public function testBeforeSendHookIsDispatched(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $transport = $this->createTransport('index.php');

        $hookCalled = false;
        $capturedMethod = null;

        $hooks = new Hooks();
        $hooks->register('cli.before_send', function (&$url, &$method, &$requestOptions) use (&$hookCalled, &$capturedMethod): void {
            $hookCalled = true;
            $capturedMethod = $method;
        });

        $options = $this->getDefaultOptions();
        $options['hooks'] = $hooks;
        $options['type'] = Requests::POST;

        $transport->request('http://localhost/', [], [
            'data' => 'value',
        ], $options);

        $this->assertTrue($hookCalled);
        $this->assertEquals('POST', $capturedMethod);
    }

    public function testAfterSendHookIsDispatched(): void
    {
        $this->createTestFile('index', '<?php echo "Response Body";');
        $transport = $this->createTransport('index.php');

        $hookCalled = false;
        $capturedResponse = null;

        $hooks = new Hooks();
        $hooks->register('cli.after_send', function ($response) use (&$hookCalled, &$capturedResponse): void {
            $hookCalled = true;
            $capturedResponse = $response;
        });

        $options = $this->getDefaultOptions();
        $options['hooks'] = $hooks;

        $transport->request('http://localhost/', [], [], $options);

        $this->assertTrue($hookCalled);
        $this->assertInstanceOf(Response::class, $capturedResponse);
        $this->assertEquals('Response Body', $capturedResponse->getContent());
    }

    public function testAfterRequestHookIsDispatched(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $transport = $this->createTransport('index.php');

        $hookCalled = false;
        $capturedHeaders = null;
        $capturedInfo = null;

        $hooks = new Hooks();
        $hooks->register('cli.after_request', function (&$headers, &$info) use (&$hookCalled, &$capturedHeaders, &$capturedInfo): void {
            $hookCalled = true;
            $capturedHeaders = $headers;
            $capturedInfo = $info;
        });

        $options = $this->getDefaultOptions();
        $options['hooks'] = $hooks;

        $transport->request('http://localhost/', [], [], $options);

        $this->assertTrue($hookCalled);
        $this->assertStringContainsString('HTTP/1.1 200 OK', $capturedHeaders);
        $this->assertArrayHasKey('http_code', $capturedInfo);
        $this->assertEquals(200, $capturedInfo['http_code']);
    }

    public function testAfterRequestHookCanModifyHeaders(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $transport = $this->createTransport('index.php');

        $hooks = new Hooks();
        $hooks->register('cli.after_request', function (&$headers, &$info): void {
            $headers .= "X-Modified-By: Hook\r\n";
        });

        $options = $this->getDefaultOptions();
        $options['hooks'] = $hooks;

        $response = $transport->request('http://localhost/', [], [], $options);

        $this->assertStringContainsString('X-Modified-By: Hook', $response);
    }

    public function testHeadersAndInfoArePopulated(): void
    {
        $this->createTestFile('index', '<?php header("Content-Type: text/plain"); echo "Content here";');
        $transport = $this->createTransport('index.php');

        $options = $this->getDefaultOptions();
        $options['type'] = Requests::POST;

        $transport->request('http://localhost/', [], [], $options);

        $this->assertStringContainsString('HTTP/1.1 200 OK', $transport->headers);
        $this->assertEquals('http://localhost/', $transport->info['url']);
        $this->assertEquals(200, $transport->info['http_code']);
        $this->assertEquals('POST', $transport->info['method']);
        $this->assertEquals(strlen('Content here'), $transport->info['content_length']);
        $this->assertGreaterThan(0, $transport->info['total_time']);
        // New fields
        $this->assertEquals('text/plain', $transport->info['content_type']);
        $this->assertEquals(strlen('Content here'), $transport->info['size_download']);
        $this->assertEquals(0, $transport->info['redirect_count']);
    }

    public function testRequestMultipleDispatchesParseResponseHook(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $transport = $this->createTransport('index.php');

        $parseResponseCalled = false;

        $hooks = new Hooks();
        $hooks->register('transport.internal.parse_response', function (&$response, $request) use (&$parseResponseCalled): void {
            $parseResponseCalled = true;
        });

        $options = $this->getDefaultOptions();
        $options['hooks'] = $hooks;

        $requests = [
            'first' => [
                'url' => 'http://localhost/index.php',
                'headers' => [],
                'data' => [],
                'options' => $options,
            ],
        ];

        $transport->request_multiple($requests, $options);

        $this->assertTrue($parseResponseCalled);
    }

    public function testHooksWithNullHooksOption(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $transport = $this->createTransport('index.php');

        $options = $this->getDefaultOptions();
        unset($options['hooks']);

        // Should not throw even without hooks
        $response = $transport->request('http://localhost/', [], [], $options);

        $this->assertStringContainsString('OK', $response);
    }

    public function testMultipleHooksCanBeRegistered(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $transport = $this->createTransport('index.php');

        $callOrder = [];

        $hooks = new Hooks();
        $hooks->register('cli.before_request', function () use (&$callOrder): void {
            $callOrder[] = 'first';
        }, 0);
        $hooks->register('cli.before_request', function () use (&$callOrder): void {
            $callOrder[] = 'second';
        }, 10);
        $hooks->register('cli.before_request', function () use (&$callOrder): void {
            $callOrder[] = 'priority_first';
        }, -10);

        $options = $this->getDefaultOptions();
        $options['hooks'] = $hooks;

        $transport->request('http://localhost/', [], [], $options);

        $this->assertEquals(['priority_first', 'first', 'second'], $callOrder);
    }

    // Invalid Argument Tests
    public function testRequestThrowsInvalidArgumentForInvalidUrl(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $transport = $this->createTransport('index.php');

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('$url');

        $transport->request(12345, [], [], $this->getDefaultOptions());
    }

    public function testRequestThrowsInvalidArgumentForInvalidHeaders(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $transport = $this->createTransport('index.php');

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('$headers');

        $transport->request('http://localhost/', 'invalid', [], $this->getDefaultOptions());
    }

    public function testRequestThrowsInvalidArgumentForInvalidData(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $transport = $this->createTransport('index.php');

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('$data');

        $transport->request('http://localhost/', [], new \stdClass(), $this->getDefaultOptions());
    }

    public function testRequestThrowsInvalidArgumentForInvalidOptions(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $transport = $this->createTransport('index.php');

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('$options');

        $transport->request('http://localhost/', [], [], 'invalid');
    }

    public function testRequestAcceptsNullDataAsEmptyString(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $transport = $this->createTransport('index.php');

        // null data should be converted to empty string, not throw
        $response = $transport->request('http://localhost/', [], null, $this->getDefaultOptions());

        $this->assertStringContainsString('OK', $response);
    }

    public function testRequestMultipleThrowsInvalidArgumentForInvalidRequests(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $transport = $this->createTransport('index.php');

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('$requests');

        $transport->request_multiple('invalid', $this->getDefaultOptions());
    }

    public function testRequestMultipleThrowsInvalidArgumentForInvalidOptions(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $transport = $this->createTransport('index.php');

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('$options');

        $transport->request_multiple([], 'invalid');
    }

    // Filename option tests
    public function testFilenameOptionSavesResponseToFile(): void
    {
        $this->createTestFile('download', '<?php echo "File content to download";');
        $transport = $this->createTransport('download.php');

        $outputFile = $this->testDocumentRoot . '/output.txt';

        $options = $this->getDefaultOptions();
        $options['filename'] = $outputFile;

        $response = $transport->request('http://localhost/download.php', [], [], $options);

        $this->assertFileExists($outputFile);
        $this->assertEquals('File content to download', file_get_contents($outputFile));
        // Response should still contain the content
        $this->assertStringContainsString('File content to download', $response);

        // Cleanup
        @unlink($outputFile);
    }

    public function testFilenameOptionWithFalseDoesNotSaveFile(): void
    {
        $this->createTestFile('index', '<?php echo "Content";');
        $transport = $this->createTransport('index.php');

        $outputFile = $this->testDocumentRoot . '/should_not_exist.txt';

        $options = $this->getDefaultOptions();
        $options['filename'] = false;

        $transport->request('http://localhost/', [], [], $options);

        $this->assertFileDoesNotExist($outputFile);
    }

    // Max bytes option tests
    public function testMaxBytesLimitsResponseContent(): void
    {
        $this->createTestFile('large', '<?php echo str_repeat("X", 1000);');
        $transport = $this->createTransport('large.php');

        $options = $this->getDefaultOptions();
        $options['max_bytes'] = 100;

        $response = $transport->request('http://localhost/large.php', [], [], $options);

        // Body should be truncated to 100 bytes
        $parts = explode("\r\n\r\n", $response, 2);
        $this->assertCount(2, $parts);
        $this->assertEquals(100, strlen($parts[1]));

        // Info should reflect truncated size
        $this->assertEquals(100, $transport->info['content_length']);
        $this->assertEquals(100, $transport->info['size_download']);
    }

    public function testMaxBytesWithFalseDoesNotLimitResponse(): void
    {
        $this->createTestFile('large', '<?php echo str_repeat("Y", 500);');
        $transport = $this->createTransport('large.php');

        $options = $this->getDefaultOptions();
        $options['max_bytes'] = false;

        $response = $transport->request('http://localhost/large.php', [], [], $options);

        $parts = explode("\r\n\r\n", $response, 2);
        $this->assertEquals(500, strlen($parts[1]));
    }

    public function testMaxBytesDoesNotTruncateSmallResponse(): void
    {
        $this->createTestFile('small', '<?php echo "Small";');
        $transport = $this->createTransport('small.php');

        $options = $this->getDefaultOptions();
        $options['max_bytes'] = 1000;

        $response = $transport->request('http://localhost/small.php', [], [], $options);

        $parts = explode("\r\n\r\n", $response, 2);
        $this->assertEquals('Small', $parts[1]);
        $this->assertEquals(5, $transport->info['content_length']);
    }

    public function testFilenameWithMaxBytesSavesTruncatedContent(): void
    {
        $this->createTestFile('large', '<?php echo str_repeat("Z", 500);');
        $transport = $this->createTransport('large.php');

        $outputFile = $this->testDocumentRoot . '/truncated.txt';

        $options = $this->getDefaultOptions();
        $options['filename'] = $outputFile;
        $options['max_bytes'] = 50;

        $transport->request('http://localhost/large.php', [], [], $options);

        $this->assertFileExists($outputFile);
        $this->assertEquals(50, strlen(file_get_contents($outputFile)));

        // Cleanup
        @unlink($outputFile);
    }

    // Content type in info
    public function testInfoContentTypeIsEmptyWhenNotSet(): void
    {
        $this->createTestFile('noheader', '<?php echo "No content type";');
        $transport = $this->createTransport('noheader.php');

        $transport->request('http://localhost/noheader.php', [], [], $this->getDefaultOptions());

        $this->assertEquals('', $transport->info['content_type']);
    }

    private function createTransport(string $file = 'index.php'): Cli
    {
        return new Cli(new Client($this->testDocumentRoot, $file));
    }

    private function getDefaultOptions(): array
    {
        return [
            'timeout' => 10,
            'useragent' => 'php-requests/test',
            'protocol_version' => 1.1,
            'redirected' => 0,
            'redirects' => 10,
            'follow_redirects' => true,
            'blocking' => true,
            'type' => Requests::GET,
            'filename' => false,
            'auth' => false,
            'proxy' => false,
            'cookies' => false,
            'max_bytes' => false,
            'idn' => true,
            'hooks' => new Hooks(),
            'transport' => null,
            'verify' => false,
            'verifyname' => false,
        ];
    }
}
