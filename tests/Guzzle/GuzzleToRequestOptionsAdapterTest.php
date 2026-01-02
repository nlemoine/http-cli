<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests\Guzzle;

use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions as GuzzleOptions;
use InvalidArgumentException;
use n5s\HttpCli\Guzzle\GuzzleToRequestOptionsAdapter;
use n5s\HttpCli\RequestOptions;
use n5s\HttpCli\UnsupportedFeatureException;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive test suite for GuzzleToRequestOptionsAdapter
 */
final class GuzzleToRequestOptionsAdapterTest extends TestCase
{
    private GuzzleToRequestOptionsAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new GuzzleToRequestOptionsAdapter();
    }

    public function testGetSourceLibrary(): void
    {
        $this->assertSame('Guzzle HTTP', $this->adapter->getSourceLibrary());
    }

    public function testSupportsOption(): void
    {
        $this->assertTrue($this->adapter->supportsOption(GuzzleOptions::TIMEOUT));
        $this->assertTrue($this->adapter->supportsOption(GuzzleOptions::HEADERS));
        $this->assertTrue($this->adapter->supportsOption(GuzzleOptions::ON_HEADERS));
        $this->assertTrue($this->adapter->supportsOption(GuzzleOptions::DEBUG));
        $this->assertFalse($this->adapter->supportsOption('unknown_option'));
    }

    public function testGetSupportedOptions(): void
    {
        $supportedOptions = $this->adapter->getSupportedOptions();

        $this->assertContains(GuzzleOptions::TIMEOUT, $supportedOptions);
        $this->assertContains(GuzzleOptions::HEADERS, $supportedOptions);
        $this->assertContains(GuzzleOptions::ON_HEADERS, $supportedOptions);
        $this->assertContains(GuzzleOptions::DEBUG, $supportedOptions);
        $this->assertGreaterThan(20, count($supportedOptions)); // Should have many options
    }

    public function testTransformEmptyOptions(): void
    {
        $result = $this->adapter->transform([]);
        $this->assertInstanceOf(RequestOptions::class, $result);
        $this->assertNull($result->timeout);
        $this->assertEmpty($result->headers);
    }

    public function testTransformTimeout(): void
    {
        $result = $this->adapter->transform([
            GuzzleOptions::TIMEOUT => 30.5,
        ]);

        $this->assertSame(30.5, $result->timeout);
    }

    public function testTransformTimeoutInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be a non-negative number');

        $this->adapter->transform([
            GuzzleOptions::TIMEOUT => -1,
        ]);
    }

    public function testTransformConnectTimeoutIsIgnored(): void
    {
        // connect_timeout is ignored in CLI context (no network connection)
        $result = $this->adapter->transform([
            GuzzleOptions::CONNECT_TIMEOUT => 15.0,
        ]);

        // Should not throw, just store in extras
        $this->assertArrayHasKey('guzzle_internal_connect_timeout', $result->extras);
    }

    public function testTransformHeaders(): void
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => ['application/json', 'text/plain'],
            'X-Custom' => 'value',
        ];

        $result = $this->adapter->transform([
            GuzzleOptions::HEADERS => $headers,
        ]);

        $expected = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/plain',
            'X-Custom' => 'value',
        ];

        $this->assertSame($expected, $result->headers);
    }

    public function testTransformHeadersInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Option 'headers' must be of type array");

        $this->adapter->transform([
            GuzzleOptions::HEADERS => 'invalid',
        ]);
    }

    public function testTransformQueryArray(): void
    {
        $query = [
            'page' => 1,
            'limit' => 10,
            'search' => 'test',
        ];

        $result = $this->adapter->transform([
            GuzzleOptions::QUERY => $query,
        ]);

        $this->assertSame($query, $result->query);
    }

    public function testTransformQueryString(): void
    {
        $queryString = 'page=1&limit=10&search=test';

        $result = $this->adapter->transform([
            GuzzleOptions::QUERY => $queryString,
        ]);

        $expected = [
            'page' => '1',
            'limit' => '10',
            'search' => 'test',
        ];
        $this->assertSame($expected, $result->query);
    }

    public function testTransformBodyString(): void
    {
        $body = 'Hello, World!';

        $result = $this->adapter->transform([
            GuzzleOptions::BODY => $body,
        ]);

        $this->assertSame($body, $result->body);
    }

    public function testTransformBodyResource(): void
    {
        $stream = fopen('data://text/plain,Hello Resource!', 'r');

        $result = $this->adapter->transform([
            GuzzleOptions::BODY => $stream,
        ]);

        $this->assertSame('Hello Resource!', $result->body);

        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    public function testTransformBodyNull(): void
    {
        $result = $this->adapter->transform([
            GuzzleOptions::BODY => null,
        ]);

        $this->assertNull($result->body);
    }

    public function testTransformBodyStreamInterface(): void
    {
        $stream = Utils::streamFor('Stream content');

        $result = $this->adapter->transform([
            GuzzleOptions::BODY => $stream,
        ]);

        $this->assertSame('Stream content', $result->body);
    }

    public function testTransformBodyCallable(): void
    {
        $callable = fn () => 'callable body';

        $result = $this->adapter->transform([
            GuzzleOptions::BODY => $callable,
        ]);

        $this->assertStringContainsString('guzzle_complex_body', $result->body);
        $this->assertArrayHasKey('guzzle_original_body', $result->extras);
        $this->assertSame($callable, $result->extras['guzzle_original_body']);
    }

    public function testTransformBodyIterator(): void
    {
        $iterator = new \ArrayIterator(['chunk1', 'chunk2']);

        $result = $this->adapter->transform([
            GuzzleOptions::BODY => $iterator,
        ]);

        $this->assertStringContainsString('guzzle_complex_body', $result->body);
        $this->assertArrayHasKey('guzzle_original_body', $result->extras);
        $this->assertSame($iterator, $result->extras['guzzle_original_body']);
    }

    public function testTransformBodyStringable(): void
    {
        $stringable = new class() implements \Stringable {
            public function __toString(): string
            {
                return 'stringable body';
            }
        };

        $result = $this->adapter->transform([
            GuzzleOptions::BODY => $stringable,
        ]);

        $this->assertSame('stringable body', $result->body);
    }

    public function testTransformJson(): void
    {
        $data = [
            'name' => 'John',
            'age' => 30,
        ];

        $result = $this->adapter->transform([
            GuzzleOptions::JSON => $data,
        ]);

        $this->assertSame($data, $result->json);
    }

    public function testTransformFormParams(): void
    {
        $formData = [
            'username' => 'john',
            'password' => 'secret',
        ];

        $result = $this->adapter->transform([
            GuzzleOptions::FORM_PARAMS => $formData,
        ]);

        $this->assertSame($formData, $result->formParams);
    }

    public function testTransformMultipart(): void
    {
        $multipart = [
            [
                'name' => 'field1',
                'contents' => 'value1',
            ],
            [
                'name' => 'file',
                'contents' => 'file contents',
                'filename' => 'test.txt',
                'headers' => [
                    'Content-Type' => 'text/plain',
                ],
            ],
        ];

        $result = $this->adapter->transform([
            GuzzleOptions::MULTIPART => $multipart,
        ]);

        $this->assertSame($multipart, $result->multipart);
    }

    public function testTransformMultipartInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Each multipart element must have 'name' and 'contents' keys");

        $multipart = [[
            'name' => 'field1',
        ]]; // Missing 'contents'
        $this->adapter->transform([
            GuzzleOptions::MULTIPART => $multipart,
        ]);
    }

    public function testTransformBasicAuth(): void
    {
        $auth = ['username', 'password'];

        $result = $this->adapter->transform([
            GuzzleOptions::AUTH => $auth,
        ]);

        $this->assertSame($auth, $result->basicAuth);
        $this->assertNull($result->bearerToken);
    }

    public function testTransformBearerAuth(): void
    {
        $auth = ['user', 'token123', 'bearer'];

        $result = $this->adapter->transform([
            GuzzleOptions::AUTH => $auth,
        ]);

        $this->assertSame('token123', $result->bearerToken);
        $this->assertNull($result->basicAuth);
    }

    public function testTransformAuthInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Auth must be an array with at least [username, password]');

        $this->adapter->transform([
            GuzzleOptions::AUTH => ['only_username'],
        ]);
    }

    public function testTransformAuthUnsupportedType(): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage("Feature 'Auth type 'digest'' is not supported");

        $this->adapter->transform([
            GuzzleOptions::AUTH => ['user', 'pass', 'digest'],
        ]);
    }

    public function testTransformVerifyIsIgnored(): void
    {
        // Verify options are ignored in CLI context (no network connection)
        $result = $this->adapter->transform([
            GuzzleOptions::VERIFY => false,
        ]);

        // Should not throw, just store in extras
        $this->assertArrayHasKey('guzzle_internal_verify', $result->extras);
    }

    public function testTransformCertIsIgnored(): void
    {
        // Cert options are ignored in CLI context (no network connection)
        $result = $this->adapter->transform([
            GuzzleOptions::CERT => '/path/to/cert.pem',
        ]);

        // Should not throw, just store in extras
        $this->assertArrayHasKey('guzzle_internal_cert', $result->extras);
    }

    public function testTransformProxyIsIgnored(): void
    {
        // Proxy options are ignored in CLI context (no network connection)
        $result = $this->adapter->transform([
            GuzzleOptions::PROXY => 'http://proxy.example.com:8080',
        ]);

        // Should not throw, just store in extras
        $this->assertArrayHasKey('guzzle_internal_proxy', $result->extras);
    }

    public function testTransformAllowRedirectsBoolean(): void
    {
        $result = $this->adapter->transform([
            GuzzleOptions::ALLOW_REDIRECTS => true,
        ]);

        $this->assertSame(5, $result->maxRedirects); // Default Guzzle max
    }

    public function testTransformAllowRedirectsFalse(): void
    {
        $result = $this->adapter->transform([
            GuzzleOptions::ALLOW_REDIRECTS => false,
        ]);

        $this->assertSame(0, $result->maxRedirects);
    }

    public function testTransformAllowRedirectsArray(): void
    {
        $redirectConfig = [
            'max' => 3,
            'strict' => true,
        ];

        $result = $this->adapter->transform([
            GuzzleOptions::ALLOW_REDIRECTS => $redirectConfig,
        ]);

        $this->assertSame(3, $result->maxRedirects);
        $this->assertSame($redirectConfig, $result->extras['guzzle_redirect_config']);
    }

    public function testTransformHttpVersionIsInternal(): void
    {
        // VERSION is handled directly by CliHandler, not the adapter
        // It should be accepted silently as an internal option
        $result = $this->adapter->transform([
            GuzzleOptions::VERSION => 2.0,
        ]);

        // No extras should be set - VERSION is internal
        $this->assertArrayNotHasKey('guzzle_version', $result->extras);
    }

    public function testTransformCallbackOptions(): void
    {
        $onHeaders = fn () => null;
        $onStats = fn () => null;
        $progress = fn () => null;

        $result = $this->adapter->transform([
            GuzzleOptions::ON_HEADERS => $onHeaders,
            GuzzleOptions::ON_STATS => $onStats,
            GuzzleOptions::PROGRESS => $progress,
        ]);

        $this->assertSame($onHeaders, $result->extras['guzzle_on_headers']);
        $this->assertSame($onStats, $result->extras['guzzle_on_stats']);
        $this->assertSame($progress, $result->extras['guzzle_progress']);
    }

    public function testTransformInternalOptions(): void
    {
        $result = $this->adapter->transform([
            GuzzleOptions::DEBUG => true,
            GuzzleOptions::DELAY => 1000,
        ]);

        $this->assertTrue($result->extras['guzzle_internal_debug']);
        $this->assertSame(1000, $result->extras['guzzle_internal_delay']);
    }

    public function testTransformCookiesWithBooleanTrue(): void
    {
        // Boolean true means use shared cookie jar - not applicable for CLI
        $result = $this->adapter->transform([
            GuzzleOptions::COOKIES => true,
        ]);

        // Should be ignored (no cookies set)
        $this->assertEmpty($result->cookies);
    }

    public function testTransformCookiesWithBooleanFalse(): void
    {
        // Boolean false means disable cookies
        $result = $this->adapter->transform([
            GuzzleOptions::COOKIES => false,
        ]);

        // Should be ignored (no cookies set)
        $this->assertEmpty($result->cookies);
    }

    public function testTransformCookiesWithArray(): void
    {
        $cookies = [
            'session_id' => 'abc123',
            'user_id' => '456',
        ];

        $result = $this->adapter->transform([
            GuzzleOptions::COOKIES => $cookies,
        ]);

        $this->assertSame($cookies, $result->cookies);
    }

    public function testTransformCookiesWithCookieJarInterface(): void
    {
        // Create stub CookieJarInterface (no expectations, just return values)
        $stubCookie1 = $this->createStub(SetCookie::class);
        $stubCookie1->method('getName')->willReturn('session');
        $stubCookie1->method('getValue')->willReturn('sess123');

        $stubCookie2 = $this->createStub(SetCookie::class);
        $stubCookie2->method('getName')->willReturn('token');
        $stubCookie2->method('getValue')->willReturn('tok456');

        $stubJar = $this->createStub(CookieJarInterface::class);
        $stubJar->method('getIterator')->willReturn(new \ArrayIterator([$stubCookie1, $stubCookie2]));

        $result = $this->adapter->transform([
            GuzzleOptions::COOKIES => $stubJar,
        ]);

        $expected = [
            'session' => 'sess123',
            'token' => 'tok456',
        ];

        $this->assertSame($expected, $result->cookies);
    }

    public function testTransformCookiesWithEmptyCookieJar(): void
    {
        // Create stub empty CookieJarInterface (no expectations, just return values)
        $stubJar = $this->createStub(CookieJarInterface::class);
        $stubJar->method('getIterator')->willReturn(new \ArrayIterator([]));

        $result = $this->adapter->transform([
            GuzzleOptions::COOKIES => $stubJar,
        ]);

        // Empty cookie jar should result in no cookies
        $this->assertEmpty($result->cookies);
    }

    public function testTransformComplexOptions(): void
    {
        $options = [
            GuzzleOptions::TIMEOUT => 30.0,
            GuzzleOptions::HEADERS => [
                'Content-Type' => 'application/json',
            ],
            GuzzleOptions::JSON => [
                'test' => 'data',
            ],
            GuzzleOptions::AUTH => ['user', 'pass'],
            GuzzleOptions::ALLOW_REDIRECTS => [
                'max' => 3,
            ],
            GuzzleOptions::HTTP_ERRORS => false,
            GuzzleOptions::DECODE_CONTENT => true,
        ];

        $result = $this->adapter->transform($options);

        $this->assertSame(30.0, $result->timeout);
        $this->assertSame([
            'Content-Type' => 'application/json',
        ], $result->headers);
        $this->assertSame([
            'test' => 'data',
        ], $result->json);
        $this->assertSame(['user', 'pass'], $result->basicAuth);
        $this->assertSame(3, $result->maxRedirects);
        // HTTP_ERRORS and DECODE_CONTENT are now internal options
        // handled directly by CliHandler, not stored in extras
        $this->assertArrayNotHasKey('guzzle_http_errors', $result->extras);
        $this->assertArrayNotHasKey('guzzle_decode_content', $result->extras);
    }

    public function testTransformMultipleBodyOptionsThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiple body options specified');

        $this->adapter->transform([
            GuzzleOptions::BODY => 'string body',
            GuzzleOptions::JSON => [
                'json' => 'data',
            ],
        ]);
    }

    public function testTransformUnsupportedOptionThrowsException(): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage("Feature 'unknown_option' is not supported by adapter 'Guzzle HTTP'");

        $this->adapter->transform([
            'unknown_option' => 'value',
        ]);
    }

    public function testTransformWithTypeValidationErrors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Option 'timeout' must be of type numeric");

        $this->adapter->transform([
            GuzzleOptions::TIMEOUT => 'not_a_number',
        ]);
    }

    public function testPerformanceWithLargeOptionsArray(): void
    {
        $largeOptions = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeOptions[GuzzleOptions::HEADERS]["X-Header-{$i}"] = "value-{$i}";
        }

        $startTime = microtime(true);
        $result = $this->adapter->transform($largeOptions);
        $endTime = microtime(true);

        $this->assertInstanceOf(RequestOptions::class, $result);
        $this->assertCount(1000, $result->headers);
        $this->assertLessThan(0.1, $endTime - $startTime); // Should complete in under 100ms
    }

    public function testMemoryEfficiencyWithSPLObjectStorage(): void
    {
        $initialMemory = memory_get_usage();

        // Process many options to test SPL usage
        $headers = [];
        $query = [];
        for ($i = 1; $i <= 100; $i++) {
            $headers["X-Header-{$i}"] = "value-{$i}";
            $query["param-{$i}"] = "value-{$i}";
        }

        $options = [
            GuzzleOptions::TIMEOUT => 30,
            GuzzleOptions::HEADERS => $headers,
            GuzzleOptions::QUERY => $query,
            GuzzleOptions::AUTH => ['user', 'pass'],
            GuzzleOptions::VERIFY => true,
        ];

        $result = $this->adapter->transform($options);

        $finalMemory = memory_get_usage();
        $memoryUsed = $finalMemory - $initialMemory;

        $this->assertInstanceOf(RequestOptions::class, $result);
        // Memory usage should be reasonable (less than 1MB for this test)
        $this->assertLessThan(1024 * 1024, $memoryUsed);
    }
}
