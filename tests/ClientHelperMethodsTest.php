<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use n5s\HttpCli\Client;
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

    /**
     * Test the parseHeadersFromOutput private method using reflection
     */
    public function testParseHeadersFromOutputMethod(): void
    {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('parseHeadersFromOutput');

        // Test output with header marker
        $headerData = [
            'status' => 201,
            'headers' => ['Content-Type: application/json', 'X-Custom: test'],
        ];
        $serializedData = base64_encode(serialize($headerData));
        $output = "Response content\n<!--HTTP_CLI_HEADERS:{$serializedData}-->";

        $result = $method->invoke($this->client, $output);

        $this->assertIsArray($result);
        $this->assertCount(4, $result);

        [$statusCode, $headers, $cleanContent, $session] = $result;

        $this->assertEquals(201, $statusCode);
        $this->assertEquals(['Content-Type: application/json', 'X-Custom: test'], $headers);
        $this->assertEquals("Response content\n", $cleanContent);
        $this->assertIsArray($session);
    }

    public function testParseHeadersFromOutputWithoutMarker(): void
    {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('parseHeadersFromOutput');

        $output = 'Plain response content without headers';

        $result = $method->invoke($this->client, $output);

        [$statusCode, $headers, $cleanContent, $session] = $result;

        // Should return defaults when no header marker found
        $this->assertEquals(200, $statusCode);
        $this->assertEquals([], $headers);
        $this->assertEquals($output, $cleanContent);
        $this->assertEquals([], $session);
    }

    public function testParseHeadersFromOutputWithMalformedMarker(): void
    {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('parseHeadersFromOutput');

        $output = "Content\n<!--HTTP_CLI_HEADERS:invalid_base64-->";

        $result = $method->invoke($this->client, $output);

        [$statusCode, $headers, $cleanContent, $session] = $result;

        // Should fall back to defaults when parsing fails
        $this->assertEquals(200, $statusCode);
        $this->assertEquals([], $headers);
        $this->assertEquals($output, $cleanContent);
        $this->assertEquals([], $session);
    }

    // Edge Cases for parseHeadersFromOutput

    public function testParseHeadersFromOutputWithInvalidSerializedData(): void
    {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('parseHeadersFromOutput');

        // Valid base64 but invalid serialized data that will throw during unserialize
        $invalidSerialized = base64_encode('not a valid serialized string');
        $output = "Content\n<!--HTTP_CLI_HEADERS:{$invalidSerialized}-->";

        $result = $method->invoke($this->client, $output);

        [$statusCode, $headers, $cleanContent, $session] = $result;

        // Should fall back to defaults when unserialize fails
        $this->assertEquals(200, $statusCode);
        $this->assertEquals([], $headers);
        $this->assertEquals($output, $cleanContent);
        $this->assertEquals([], $session);
    }

    public function testParseHeadersFromOutputWithMissingStatusKey(): void
    {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('parseHeadersFromOutput');

        // Valid serialized data but missing required 'status' key
        $headerData = [
            'headers' => ['Content-Type: text/html'],
        ];
        $serializedData = base64_encode(serialize($headerData));
        $output = "Content\n<!--HTTP_CLI_HEADERS:{$serializedData}-->";

        $result = $method->invoke($this->client, $output);

        [$statusCode, $headers, $cleanContent, $session] = $result;

        // Should fall back to defaults when validation fails
        $this->assertEquals(200, $statusCode);
        $this->assertEquals([], $headers);
        $this->assertEquals($output, $cleanContent);
        $this->assertEquals([], $session);
    }

    public function testParseHeadersFromOutputWithMissingHeadersKey(): void
    {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('parseHeadersFromOutput');

        // Valid serialized data but missing required 'headers' key
        $headerData = [
            'status' => 200,
        ];
        $serializedData = base64_encode(serialize($headerData));
        $output = "Content\n<!--HTTP_CLI_HEADERS:{$serializedData}-->";

        $result = $method->invoke($this->client, $output);

        [$statusCode, $headers, $cleanContent, $session] = $result;

        // Should fall back to defaults when validation fails
        $this->assertEquals(200, $statusCode);
        $this->assertEquals([], $headers);
        $this->assertEquals($output, $cleanContent);
        $this->assertEquals([], $session);
    }

    public function testParseHeadersFromOutputWithNonNumericStatus(): void
    {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('parseHeadersFromOutput');

        // Valid serialized data but status is not numeric
        $headerData = [
            'status' => 'invalid',
            'headers' => [],
        ];
        $serializedData = base64_encode(serialize($headerData));
        $output = "Content\n<!--HTTP_CLI_HEADERS:{$serializedData}-->";

        $result = $method->invoke($this->client, $output);

        [$statusCode, $headers, $cleanContent, $session] = $result;

        // Should fall back to defaults when status is not numeric
        $this->assertEquals(200, $statusCode);
        $this->assertEquals([], $headers);
        $this->assertEquals($output, $cleanContent);
        $this->assertEquals([], $session);
    }

    public function testParseHeadersFromOutputWithNonArrayData(): void
    {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('parseHeadersFromOutput');

        // Valid serialized data but it's a string, not array
        $serializedData = base64_encode(serialize('just a string'));
        $output = "Content\n<!--HTTP_CLI_HEADERS:{$serializedData}-->";

        $result = $method->invoke($this->client, $output);

        [$statusCode, $headers, $cleanContent, $session] = $result;

        // Should fall back to defaults when data is not an array
        $this->assertEquals(200, $statusCode);
        $this->assertEquals([], $headers);
        $this->assertEquals($output, $cleanContent);
        $this->assertEquals([], $session);
    }

    public function testParseHeadersFromOutputWithSessionData(): void
    {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('parseHeadersFromOutput');

        $headerData = [
            'status' => 200,
            'headers' => ['Content-Type: text/html'],
            'session' => [
                'user_id' => 123,
                'role' => 'admin',
            ],
        ];
        $serializedData = base64_encode(serialize($headerData));
        $output = "Content<!--HTTP_CLI_HEADERS:{$serializedData}-->";

        $result = $method->invoke($this->client, $output);

        [$statusCode, $headers, $cleanContent, $session] = $result;

        $this->assertEquals(200, $statusCode);
        $this->assertEquals(['Content-Type: text/html'], $headers);
        $this->assertEquals('Content', $cleanContent);
        $this->assertEquals([
            'user_id' => 123,
            'role' => 'admin',
        ], $session);
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
