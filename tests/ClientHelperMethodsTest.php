<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use n5s\HttpCli\Client;
use n5s\HttpCli\GlobalsHandler;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

class ClientHelperMethodsTest extends AbstractHttpCliTestCase
{
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test PHP file
        $testFile = $this->testDocumentRoot . '/index.php';
        file_put_contents($testFile, '<?php echo "Test"; ?>');

        $this->client = new Client($this->testDocumentRoot);
    }

    // Private Method Testing using Reflection

    /**
     * Test the getGlobals private method using reflection
     */
    public function testGetGlobalsMethod(): void
    {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('getGlobals');

        // Create a request with various parameters
        $request = Request::create(
            'http://localhost/test.php?param1=value1&param2=value2',
            'POST',
            [
                'post_param' => 'post_value',
            ],
            [
                'cookie_name' => 'cookie_value',
            ],
            [],
            [
                'HTTP_ACCEPT' => 'text/html',
                'HTTP_USER_AGENT' => 'Test Agent',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'CONTENT_LENGTH' => '20',
            ]
        );

        $globals = $method->invoke($this->client, $request);

        // Test structure
        $this->assertIsArray($globals);
        $this->assertArrayHasKey('_ENV', $globals);
        $this->assertArrayHasKey('_GET', $globals);
        $this->assertArrayHasKey('_POST', $globals);
        $this->assertArrayHasKey('_COOKIE', $globals);
        $this->assertArrayHasKey('_FILES', $globals);
        $this->assertArrayHasKey('_SESSION', $globals);
        $this->assertArrayHasKey('_SERVER', $globals);
        $this->assertArrayHasKey('_REQUEST', $globals);

        // Test GET parameters
        $this->assertEquals([
            'param1' => 'value1',
            'param2' => 'value2',
        ], $globals['_GET']);

        // Test POST parameters
        $this->assertEquals([
            'post_param' => 'post_value',
        ], $globals['_POST']);

        // Test COOKIE parameters
        $this->assertEquals([
            'cookie_name' => 'cookie_value',
        ], $globals['_COOKIE']);

        // Test SERVER parameters from headers
        $this->assertEquals('text/html', $globals['_SERVER']['HTTP_ACCEPT']);
        $this->assertEquals('Test Agent', $globals['_SERVER']['HTTP_USER_AGENT']);
        $this->assertEquals('application/x-www-form-urlencoded', $globals['_SERVER']['CONTENT_TYPE']);
        $this->assertEquals('20', $globals['_SERVER']['CONTENT_LENGTH']);

        // Test QUERY_STRING generation
        $this->assertStringContainsString('param1=value1', $globals['_SERVER']['QUERY_STRING']);
        $this->assertStringContainsString('param2=value2', $globals['_SERVER']['QUERY_STRING']);

        // Test _REQUEST merging
        $this->assertArrayHasKey('param1', $globals['_REQUEST']);
        $this->assertArrayHasKey('post_param', $globals['_REQUEST']);
        // Note: cookie_name may or may not be in _REQUEST depending on request_order ini setting
        // By default PHP uses "GP" (GET and POST only), so cookies are not included
        // This matches Symfony's behavior exactly
    }

    public function testGetGlobalsWithSpecialHeaders(): void
    {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('getGlobals');

        $request = Request::create(
            'http://localhost/test.php',
            'GET',
            [],
            [],
            [],
            [
                'HTTP_CONTENT_MD5' => 'md5hash',
                'HTTP_AUTHORIZATION' => 'Bearer token123',
                'HTTP_X_CUSTOM_HEADER' => 'custom-value',
            ]
        );

        $globals = $method->invoke($this->client, $request);

        // Special headers should be prefixed with HTTP_
        $this->assertEquals('Bearer token123', $globals['_SERVER']['HTTP_AUTHORIZATION']);
        $this->assertEquals('custom-value', $globals['_SERVER']['HTTP_X_CUSTOM_HEADER']);

        // CONTENT_MD5 is actually treated as regular header with HTTP_ prefix
        $this->assertArrayHasKey('HTTP_CONTENT_MD5', $globals['_SERVER']);
    }

    public function testGetGlobalsRequestOrderHandling(): void
    {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('getGlobals');

        // Create request with overlapping parameter names
        $request = Request::create(
            'http://localhost/test.php?name=get_value',
            'POST',
            [
                'name' => 'post_value',
            ],
            [
                'name' => 'cookie_value',
            ]
        );

        $globals = $method->invoke($this->client, $request);

        // The _REQUEST array should contain merged values
        // The exact behavior depends on request_order setting
        $this->assertArrayHasKey('name', $globals['_REQUEST']);

        // Verify individual arrays are correct
        $this->assertEquals('get_value', $globals['_GET']['name']);
        $this->assertEquals('post_value', $globals['_POST']['name']);
        $this->assertEquals('cookie_value', $globals['_COOKIE']['name']);
    }

    /**
     * Test the getPhpExecutable private method using reflection
     */
    public function testGetPhpExecutableMethod(): void
    {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('getPhpExecutable');

        $phpExecutable = $method->invoke($this->client);

        $this->assertIsString($phpExecutable);
        $this->assertNotEmpty($phpExecutable);
        $this->assertStringContainsString('php', strtolower($phpExecutable));

        // Test memoization - should return same value on second call
        $phpExecutable2 = $method->invoke($this->client);
        $this->assertEquals($phpExecutable, $phpExecutable2);
    }

    // Test GlobalsHandler

    public function testGlobalsHandlerModifiesGlobals(): void
    {
        $handler = new class() implements GlobalsHandler {
            public function handle(array &$globals): void
            {
                $globals['_SERVER']['MY_CUSTOM_VAR'] = 'test_value';
                $globals['_ENV']['MY_CUSTOM_VAR'] = 'test_value';
            }
        };
        $client = new Client($this->testDocumentRoot, 'index.php', $handler);

        $reflection = new ReflectionClass($client);
        $method = $reflection->getMethod('getGlobals');

        $request = Request::create('http://localhost/test.php');
        $globals = $method->invoke($client, $request);

        $this->assertEquals('test_value', $globals['_SERVER']['MY_CUSTOM_VAR']);
        $this->assertEquals('test_value', $globals['_ENV']['MY_CUSTOM_VAR']);
    }

    public function testGlobalsHandlerInChildProcess(): void
    {
        $this->createTestFile('globals_handler', '<?php
            echo json_encode([
                "server" => $_SERVER["MY_CUSTOM_VAR"] ?? null,
            ]);
        ?>');

        $handler = new class() implements GlobalsHandler {
            public function handle(array &$globals): void
            {
                $globals['_SERVER']['MY_CUSTOM_VAR'] = 'test_value';
            }
        };
        $client = new Client($this->testDocumentRoot, 'globals_handler.php', $handler);

        $response = $client->request('GET', 'http://localhost/globals_handler.php');
        $data = json_decode($response->getContent(), true);

        $this->assertEquals('test_value', $data['server']);
    }

    public function testNullGlobalsHandlerIsNoOp(): void
    {
        $client = new Client($this->testDocumentRoot, 'index.php', null);

        $reflection = new ReflectionClass($client);
        $method = $reflection->getMethod('getGlobals');

        $request = Request::create('http://localhost/test.php');
        $globals = $method->invoke($client, $request);

        // Should still produce valid globals without a handler
        $this->assertArrayHasKey('_SERVER', $globals);
        $this->assertArrayHasKey('_ENV', $globals);
    }

    // Test custom PHP executable

    public function testConstructorWithCustomPhpExecutable(): void
    {
        $customPhp = PHP_BINARY; // Use the current PHP binary
        $client = new Client($this->testDocumentRoot, 'index.php', null, $customPhp);

        $reflection = new ReflectionClass($client);
        $method = $reflection->getMethod('getPhpExecutable');

        $result = $method->invoke($client);
        $this->assertEquals($customPhp, $result);
    }
}
