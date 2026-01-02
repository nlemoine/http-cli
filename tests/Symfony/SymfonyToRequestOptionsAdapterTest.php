<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests\Symfony;

use n5s\HttpCli\RequestOptions;
use n5s\HttpCli\Symfony\SymfonyToRequestOptionsAdapter;
use n5s\HttpCli\UnsupportedFeatureException;
use PHPUnit\Framework\TestCase;

class SymfonyToRequestOptionsAdapterTest extends TestCase
{
    private SymfonyToRequestOptionsAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new SymfonyToRequestOptionsAdapter();
    }

    // Basic Tests
    public function testGetSourceLibrary(): void
    {
        $this->assertEquals('symfony/http-client', $this->adapter->getSourceLibrary());
    }

    public function testSupportsOption(): void
    {
        $this->assertTrue($this->adapter->supportsOption('timeout'));
        $this->assertTrue($this->adapter->supportsOption('headers'));
        $this->assertTrue($this->adapter->supportsOption('query'));
        $this->assertTrue($this->adapter->supportsOption('body'));
        $this->assertTrue($this->adapter->supportsOption('json'));
        $this->assertTrue($this->adapter->supportsOption('auth_basic'));
        $this->assertTrue($this->adapter->supportsOption('auth_bearer'));
        $this->assertFalse($this->adapter->supportsOption('unknown_option'));
        $this->assertFalse($this->adapter->supportsOption('user_data')); // Explicitly unsupported
    }

    public function testGetSupportedOptions(): void
    {
        $supported = $this->adapter->getSupportedOptions();

        $this->assertContains('timeout', $supported);
        $this->assertContains('headers', $supported);
        $this->assertContains('query', $supported);
        $this->assertContains('body', $supported);
        $this->assertContains('json', $supported);
        $this->assertContains('auth_basic', $supported);
        $this->assertContains('auth_bearer', $supported);
        $this->assertNotContains('user_data', $supported); // Not in supported list
    }

    public function testTransformEmptyOptions(): void
    {
        $result = $this->adapter->transform([]);

        $this->assertInstanceOf(RequestOptions::class, $result);
    }

    // Timeout Tests
    public function testTransformTimeout(): void
    {
        $result = $this->adapter->transform([
            'timeout' => 30,
        ]);

        $this->assertEquals(30.0, $result->timeout);
    }

    public function testTransformTimeoutFloat(): void
    {
        $result = $this->adapter->transform([
            'timeout' => 5.5,
        ]);

        $this->assertEquals(5.5, $result->timeout);
    }

    public function testTransformTimeoutNull(): void
    {
        $result = $this->adapter->transform([
            'timeout' => null,
        ]);

        $this->assertNull($result->timeout);
    }

    // Headers Tests
    public function testTransformHeaders(): void
    {
        $result = $this->adapter->transform([
            'headers' => [
                'X-Custom-Header' => 'CustomValue',
                'Accept' => 'application/json',
            ],
        ]);

        $this->assertEquals('CustomValue', $result->headers['X-Custom-Header']);
        $this->assertEquals('application/json', $result->headers['Accept']);
    }

    public function testTransformHeadersEmpty(): void
    {
        $result = $this->adapter->transform([
            'headers' => [],
        ]);

        $this->assertEmpty($result->headers);
    }

    // Query Tests
    public function testTransformQueryArray(): void
    {
        $result = $this->adapter->transform([
            'query' => [
                'foo' => 'bar',
                'baz' => 'qux',
            ],
        ]);

        $this->assertEquals([
            'foo' => 'bar',
            'baz' => 'qux',
        ], $result->query);
    }

    public function testTransformQueryEmpty(): void
    {
        $result = $this->adapter->transform([
            'query' => [],
        ]);

        $this->assertEmpty($result->query);
    }

    // Body Tests
    public function testTransformBody(): void
    {
        $result = $this->adapter->transform([
            'body' => 'raw body content',
        ]);

        $this->assertEquals('raw body content', $result->body);
    }

    public function testTransformBodyEmpty(): void
    {
        $result = $this->adapter->transform([
            'body' => '',
        ]);

        $this->assertNull($result->body);
    }

    public function testTransformBodyNull(): void
    {
        $result = $this->adapter->transform([
            'body' => null,
        ]);

        $this->assertNull($result->body);
    }

    // JSON Tests
    public function testTransformJson(): void
    {
        $data = [
            'key' => 'value',
            'nested' => [
                'a' => 1,
            ],
        ];
        $result = $this->adapter->transform([
            'json' => $data,
        ]);

        $this->assertEquals($data, $result->json);
    }

    public function testTransformJsonNull(): void
    {
        $result = $this->adapter->transform([
            'json' => null,
        ]);

        $this->assertNull($result->json);
    }

    // Authentication Tests
    public function testTransformAuthBasicArray(): void
    {
        $result = $this->adapter->transform([
            'auth_basic' => ['username', 'password'],
        ]);

        $this->assertEquals(['username', 'password'], $result->basicAuth);
    }

    public function testTransformAuthBasicString(): void
    {
        $result = $this->adapter->transform([
            'auth_basic' => 'username:password',
        ]);

        $this->assertEquals(['username', 'password'], $result->basicAuth);
    }

    public function testTransformAuthBasicStringWithColonInPassword(): void
    {
        $result = $this->adapter->transform([
            'auth_basic' => 'user:pass:word',
        ]);

        $this->assertEquals(['user', 'pass:word'], $result->basicAuth);
    }

    public function testTransformAuthBearer(): void
    {
        $result = $this->adapter->transform([
            'auth_bearer' => 'my-token-123',
        ]);

        $this->assertEquals('my-token-123', $result->bearerToken);
    }

    public function testTransformAuthBearerNull(): void
    {
        $result = $this->adapter->transform([
            'auth_bearer' => null,
        ]);

        $this->assertNull($result->bearerToken);
    }

    // SSL/Proxy Options (Ignored in CLI context)
    public function testTransformVerifyOptionsAreIgnored(): void
    {
        // Verify options are ignored in CLI context (no network connection)
        $result = $this->adapter->transform([
            'verify_peer' => false,
            'verify_host' => false,
        ]);

        // Should not throw - options are ignored
        $this->assertInstanceOf(RequestOptions::class, $result);
    }

    public function testTransformCafileIsIgnored(): void
    {
        // Cafile is ignored in CLI context (no network connection)
        $result = $this->adapter->transform([
            'cafile' => '/path/to/ca-bundle.crt',
        ]);

        // Should not throw - option is ignored
        $this->assertInstanceOf(RequestOptions::class, $result);
    }

    public function testTransformProxyIsIgnored(): void
    {
        // Proxy is ignored in CLI context (no network connection)
        $result = $this->adapter->transform([
            'proxy' => 'http://proxy.example.com:8080',
        ]);

        // Should not throw - option is ignored
        $this->assertInstanceOf(RequestOptions::class, $result);
    }

    // Redirects Tests
    public function testTransformMaxRedirects(): void
    {
        $result = $this->adapter->transform([
            'max_redirects' => 10,
        ]);

        $this->assertEquals(10, $result->maxRedirects);
    }

    public function testTransformMaxRedirectsZero(): void
    {
        $result = $this->adapter->transform([
            'max_redirects' => 0,
        ]);

        $this->assertEquals(0, $result->maxRedirects);
    }

    // Ignored Options Tests
    public function testIgnoredOptionsDoNotThrow(): void
    {
        // These options are recognized but ignored
        $result = $this->adapter->transform([
            'user_data' => [
                'custom' => 'data',
            ],
            'http_version' => '2.0',
            'base_uri' => 'https://example.com',
            'buffer' => true,
            'on_progress' => fn () => null,
            'resolve' => [
                'example.com' => '127.0.0.1',
            ],
            'no_proxy' => 'localhost',
            'max_duration' => 60,
            'bindto' => '0.0.0.0',
        ]);

        $this->assertInstanceOf(RequestOptions::class, $result);
    }

    // Unknown Options Tests
    public function testUnknownOptionThrowsException(): void
    {
        $this->expectException(UnsupportedFeatureException::class);

        $this->adapter->transform([
            'completely_unknown_option' => 'value',
        ]);
    }

    // Complex Options Tests
    public function testTransformComplexOptions(): void
    {
        $result = $this->adapter->transform([
            'timeout' => 30,
            'headers' => [
                'X-Custom' => 'Value',
                'Accept' => 'application/json',
            ],
            'query' => [
                'page' => '1',
                'limit' => '10',
            ],
            'auth_bearer' => 'token123',
            'max_redirects' => 5,
        ]);

        $this->assertEquals(30.0, $result->timeout);
        $this->assertEquals('Value', $result->headers['X-Custom']);
        $this->assertEquals('application/json', $result->headers['Accept']);
        $this->assertEquals([
            'page' => '1',
            'limit' => '10',
        ], $result->query);
        $this->assertEquals('token123', $result->bearerToken);
        $this->assertEquals(5, $result->maxRedirects);
    }

    public function testTransformMixedSupportedAndIgnoredOptions(): void
    {
        $result = $this->adapter->transform([
            'timeout' => 15,
            'user_data' => [
                'ignored' => 'data',
            ], // Ignored
            'headers' => [
                'X-Test' => 'test',
            ],
            'buffer' => false, // Ignored
        ]);

        $this->assertEquals(15.0, $result->timeout);
        $this->assertEquals('test', $result->headers['X-Test']);
    }
}
