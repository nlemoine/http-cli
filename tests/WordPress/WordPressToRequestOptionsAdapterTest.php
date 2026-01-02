<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests\WordPress;

use n5s\HttpCli\RequestOptions;
use n5s\HttpCli\UnsupportedFeatureException;
use n5s\HttpCli\WordPress\WordPressToRequestOptionsAdapter;
use PHPUnit\Framework\TestCase;
use WpOrg\Requests\Cookie;
use WpOrg\Requests\Cookie\Jar as CookieJar;

class WordPressToRequestOptionsAdapterTest extends TestCase
{
    private WordPressToRequestOptionsAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new WordPressToRequestOptionsAdapter();
    }

    // Basic Tests
    public function testGetSourceLibrary(): void
    {
        $this->assertEquals('WordPress Requests', $this->adapter->getSourceLibrary());
    }

    public function testSupportsOption(): void
    {
        $this->assertTrue($this->adapter->supportsOption('timeout'));
        $this->assertTrue($this->adapter->supportsOption('useragent'));
        $this->assertTrue($this->adapter->supportsOption('auth'));
        $this->assertTrue($this->adapter->supportsOption('cookies'));

        // Ignored options (no network connection in CLI context)
        $this->assertFalse($this->adapter->supportsOption('connect_timeout'));
        $this->assertFalse($this->adapter->supportsOption('proxy'));
        $this->assertFalse($this->adapter->supportsOption('verify'));
        $this->assertFalse($this->adapter->supportsOption('hooks'));
        $this->assertFalse($this->adapter->supportsOption('transport'));
        $this->assertFalse($this->adapter->supportsOption('blocking'));
    }

    public function testGetSupportedOptions(): void
    {
        $supported = $this->adapter->getSupportedOptions();

        $this->assertIsArray($supported);
        $this->assertContains('timeout', $supported);
        $this->assertContains('useragent', $supported);
        $this->assertContains('auth', $supported);
        $this->assertNotContains('connect_timeout', $supported);
        $this->assertNotContains('hooks', $supported);
    }

    // Transform Empty Options
    public function testTransformEmptyOptions(): void
    {
        $result = $this->adapter->transform([]);

        $this->assertNull($result->timeout);
        $this->assertNull($result->userAgent);
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

    public function testTransformConnectTimeoutIsIgnored(): void
    {
        // connect_timeout is ignored in CLI context (no network connection)
        $result = $this->adapter->transform([
            'connect_timeout' => 15,
        ]);

        // Should not throw - option is ignored
        $this->assertInstanceOf(RequestOptions::class, $result);
    }

    // User Agent Tests
    public function testTransformUserAgent(): void
    {
        $result = $this->adapter->transform([
            'useragent' => 'WordPress/6.0',
        ]);

        $this->assertEquals('WordPress/6.0', $result->userAgent);
    }

    public function testTransformUserAgentNull(): void
    {
        $result = $this->adapter->transform([
            'useragent' => null,
        ]);

        $this->assertNull($result->userAgent);
    }

    // Redirects Tests
    public function testTransformRedirects(): void
    {
        $result = $this->adapter->transform([
            'redirects' => 5,
        ]);

        $this->assertEquals(5, $result->maxRedirects);
    }

    public function testTransformRedirectsWithFollowRedirectsFalse(): void
    {
        $result = $this->adapter->transform([
            'redirects' => 10,
            'follow_redirects' => false,
        ]);

        $this->assertEquals(0, $result->maxRedirects);
    }

    public function testTransformRedirectsWithFollowRedirectsTrue(): void
    {
        $result = $this->adapter->transform([
            'redirects' => 10,
            'follow_redirects' => true,
        ]);

        $this->assertEquals(10, $result->maxRedirects);
    }

    // Authentication Tests
    public function testTransformAuthArray(): void
    {
        $result = $this->adapter->transform([
            'auth' => ['username', 'password'],
        ]);

        $this->assertEquals(['username', 'password'], $result->basicAuth);
    }

    public function testTransformAuthFalse(): void
    {
        $result = $this->adapter->transform([
            'auth' => false,
        ]);

        $this->assertNull($result->basicAuth);
    }

    public function testTransformAuthInvalidArray(): void
    {
        $result = $this->adapter->transform([
            'auth' => ['only_username'],
        ]);

        $this->assertNull($result->basicAuth);
    }

    // Proxy Tests (Ignored in CLI context)
    public function testTransformProxyIsIgnored(): void
    {
        // Proxy is ignored in CLI context (no network connection)
        $result = $this->adapter->transform([
            'proxy' => 'http://proxy.example.com:8080',
        ]);

        // Should not throw - option is ignored
        $this->assertInstanceOf(RequestOptions::class, $result);
    }

    // Cookies Tests
    public function testTransformCookiesArray(): void
    {
        $result = $this->adapter->transform([
            'cookies' => [
                'session_id' => 'abc123',
                'user' => 'john',
            ],
        ]);

        $this->assertEquals([
            'session_id' => 'abc123',
            'user' => 'john',
        ], $result->cookies);
    }

    public function testTransformCookiesJar(): void
    {
        $jar = new CookieJar();
        $jar['session'] = new Cookie('session', 'xyz789');
        $jar['token'] = new Cookie('token', 'abc123');

        $result = $this->adapter->transform([
            'cookies' => $jar,
        ]);

        $this->assertEquals([
            'session' => 'xyz789',
            'token' => 'abc123',
        ], $result->cookies);
    }

    public function testTransformCookiesEmptyJar(): void
    {
        $jar = new CookieJar();

        $result = $this->adapter->transform([
            'cookies' => $jar,
        ]);

        $this->assertEmpty($result->cookies);
    }

    public function testTransformCookiesFalse(): void
    {
        $result = $this->adapter->transform([
            'cookies' => false,
        ]);

        $this->assertEmpty($result->cookies);
    }

    // SSL Verification Tests (Ignored in CLI context)
    public function testTransformVerifyIsIgnored(): void
    {
        // Verify is ignored in CLI context (no network connection)
        $result = $this->adapter->transform([
            'verify' => false,
            'verifyname' => false,
        ]);

        // Should not throw - options are ignored
        $this->assertInstanceOf(RequestOptions::class, $result);
    }

    // Ignored Options Tests
    public function testIgnoredOptionsDoNotThrow(): void
    {
        $result = $this->adapter->transform([
            'hooks' => null,
            'transport' => null,
            'blocking' => true,
            'type' => 'GET',
            'filename' => false,
            'max_bytes' => false,
            'idn' => true,
            'protocol_version' => 1.1,
            'redirected' => 0,
            'data_format' => 'body',
        ]);

        // Should not throw, just ignore
        $this->assertInstanceOf(RequestOptions::class, $result);
    }

    // Unsupported Options Tests
    public function testUnknownOptionThrowsException(): void
    {
        $this->expectException(UnsupportedFeatureException::class);

        $this->adapter->transform([
            'unknown_option' => 'value',
        ]);
    }

    // Complex Options Tests
    public function testTransformComplexOptions(): void
    {
        $result = $this->adapter->transform([
            'timeout' => 30,
            'useragent' => 'WordPress/6.0',
            'redirects' => 5,
            'follow_redirects' => true,
            'auth' => ['user', 'pass'],
            'cookies' => [
                'session' => 'abc',
            ],
        ]);

        $this->assertEquals(30.0, $result->timeout);
        $this->assertEquals('WordPress/6.0', $result->userAgent);
        $this->assertEquals(5, $result->maxRedirects);
        $this->assertEquals(['user', 'pass'], $result->basicAuth);
        $this->assertEquals([
            'session' => 'abc',
        ], $result->cookies);
    }

    public function testTransformMixedSupportedAndIgnoredOptions(): void
    {
        $result = $this->adapter->transform([
            'timeout' => 15,
            'hooks' => null, // ignored
            'blocking' => true, // ignored
            'useragent' => 'Test/1.0',
        ]);

        $this->assertEquals(15.0, $result->timeout);
        $this->assertEquals('Test/1.0', $result->userAgent);
    }
}
