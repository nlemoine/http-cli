<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use n5s\HttpCli\Runtime\HeaderHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Tests for HeaderHandler
 */
class HeaderHandlerTest extends TestCase
{
    private HeaderHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        HeaderHandler::resetInstance();
        $this->handler = new HeaderHandler();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        HeaderHandler::resetInstance();
    }

    public function testStartsWithDefaultResponseCode(): void
    {
        $this->assertSame(200, $this->handler->http_response_code());
    }

    public function testSetsAndGetsResponseCode(): void
    {
        $previous = $this->handler->http_response_code(404);

        $this->assertSame(200, $previous);
        $this->assertSame(404, $this->handler->http_response_code());
    }

    public function testRejectsInvalidResponseCodes(): void
    {
        $result = @$this->handler->http_response_code(99);
        $this->assertFalse($result);

        $result = @$this->handler->http_response_code(600);
        $this->assertFalse($result);
    }

    public function testStartsWithEmptyHeadersList(): void
    {
        $this->assertSame([], $this->handler->headers_list());
    }

    public function testAddsHeaders(): void
    {
        $this->handler->header('Content-Type: application/json');

        $this->assertSame(['Content-Type: application/json'], $this->handler->headers_list());
    }

    public function testReplacesHeadersByDefault(): void
    {
        $this->handler->header('Content-Type: text/html');
        $this->handler->header('Content-Type: application/json');

        $this->assertSame(['Content-Type: application/json'], $this->handler->headers_list());
    }

    public function testAppendsHeadersWhenReplaceIsFalse(): void
    {
        $this->handler->header('Set-Cookie: foo=bar', false);
        $this->handler->header('Set-Cookie: baz=qux', false);

        $this->assertSame([
            'Set-Cookie: foo=bar',
            'Set-Cookie: baz=qux',
        ], $this->handler->headers_list());
    }

    public function testHandlesCaseInsensitiveHeaderReplacement(): void
    {
        $this->handler->header('content-type: text/html');
        $this->handler->header('Content-Type: application/json');

        $this->assertSame(['Content-Type: application/json'], $this->handler->headers_list());
    }

    public function testSetsResponseCodeViaHeaderThirdParameter(): void
    {
        $this->handler->header('X-Custom: value', true, 201);

        $this->assertSame(201, $this->handler->http_response_code());
    }

    public function testParsesHttpStatusLine(): void
    {
        $this->handler->header('HTTP/1.1 404 Not Found');

        $this->assertSame(404, $this->handler->http_response_code());
    }

    public function testSetsRedirectCodeForLocationHeader(): void
    {
        $this->handler->header('Location: https://example.com');

        $this->assertSame(302, $this->handler->http_response_code());
    }

    public function testDoesNotChangeNon200CodeForLocationHeader(): void
    {
        $this->handler->http_response_code(301);
        $this->handler->header('Location: https://example.com');

        $this->assertSame(301, $this->handler->http_response_code());
    }

    public function testRemovesSpecificHeader(): void
    {
        $this->handler->header('Content-Type: application/json');
        $this->handler->header('X-Custom: value');
        $this->handler->header_remove('Content-Type');

        $this->assertSame(['X-Custom: value'], $this->handler->headers_list());
    }

    public function testRemovesAllHeaders(): void
    {
        $this->handler->header('Content-Type: application/json');
        $this->handler->header('X-Custom: value');
        $this->handler->header_remove();

        $this->assertSame([], $this->handler->headers_list());
    }

    public function testRemovesHeadersCaseInsensitively(): void
    {
        $this->handler->header('Content-Type: application/json');
        $this->handler->header_remove('content-type');

        $this->assertSame([], $this->handler->headers_list());
    }

    public function testReportsHeadersNotSentInitially(): void
    {
        $this->assertFalse($this->handler->headers_sent());
    }

    public function testRejectsHeadersWithNewlines(): void
    {
        @$this->handler->header("Content-Type: text/html\r\nX-Injected: bad");

        $this->assertSame([], $this->handler->headers_list());
    }

    public function testRejectsHeadersWithNullBytes(): void
    {
        @$this->handler->header("Content-Type: text/html\0X-Injected: bad");

        $this->assertSame([], $this->handler->headers_list());
    }

    public function testIgnoresHeadersWithoutColon(): void
    {
        $this->handler->header('InvalidHeaderWithoutColon');

        $this->assertSame([], $this->handler->headers_list());
    }

    public function testIgnoresHeadersWithEmptyName(): void
    {
        $this->handler->header(': value');

        $this->assertSame([], $this->handler->headers_list());
    }

    public function testIgnoresHeaderNamesWithSpaces(): void
    {
        $this->handler->header('Invalid Header: value');

        $this->assertSame([], $this->handler->headers_list());
    }

    public function testConvertsToString(): void
    {
        $this->handler->http_response_code(201);
        $this->handler->header('Content-Type: application/json');

        $string = (string) $this->handler;

        $this->assertStringContainsString('HTTP/1.1 201 Created', $string);
        $this->assertStringContainsString('Content-Type: application/json', $string);
    }

    public function testUsesCorrectStatusTexts(): void
    {
        $this->handler->http_response_code(418);
        $string = (string) $this->handler;

        $this->assertStringContainsString("418 I'm a teapot", $string);
    }

    public function testHandlesUnknownStatusCodes(): void
    {
        $this->handler->http_response_code(599);
        $string = (string) $this->handler;

        $this->assertStringContainsString('599 Unknown', $string);
    }

    public function testOutputsHeadersOnce(): void
    {
        $reflection = new ReflectionClass($this->handler);
        $property = $reflection->getProperty('headersOutputted');

        $this->assertFalse($property->getValue($this->handler));

        ob_start();
        $this->handler->outputHeaders();
        ob_end_clean();

        $this->assertTrue($property->getValue($this->handler));
    }

    public function testDoesNotOutputHeadersTwice(): void
    {
        $this->handler->header('X-Test: first');

        ob_start();
        $this->handler->outputHeaders();
        ob_end_clean();

        @$this->handler->header('X-Test: second');

        $this->assertSame(['X-Test: first'], $this->handler->headers_list());
    }

    public function testProvidesStaticInstance(): void
    {
        $instance = HeaderHandler::getInstance();

        $this->assertInstanceOf(HeaderHandler::class, $instance);
    }

    public function testChecksIfInstanceExists(): void
    {
        $this->assertTrue(HeaderHandler::hasInstance());

        HeaderHandler::resetInstance();

        $this->assertFalse(HeaderHandler::hasInstance());
    }

    public function testHandlesEmergencyHeaderOutput(): void
    {
        $reflection = new ReflectionClass($this->handler);
        $property = $reflection->getProperty('headersOutputted');

        $this->assertFalse($property->getValue($this->handler));

        ob_start();
        $this->handler->emergencyHeaderOutput();
        ob_end_clean();

        $this->assertTrue($property->getValue($this->handler));
    }

    public function testBuildsHeaderString(): void
    {
        $this->handler->http_response_code(200);
        $this->handler->header('Content-Type: text/plain');
        $this->handler->header('X-Custom: test');

        $headerString = $this->handler->buildHeaderString();

        $this->assertStringContainsString('HTTP/1.1 200 OK', $headerString);
        $this->assertStringContainsString('Content-Type: text/plain', $headerString);
        $this->assertStringContainsString('X-Custom: test', $headerString);
    }

    public function testProvidesStaticReasonPhrase(): void
    {
        $this->assertSame('OK', HeaderHandler::getReasonPhrase(200));
        $this->assertSame('Not Found', HeaderHandler::getReasonPhrase(404));
        $this->assertSame('Internal Server Error', HeaderHandler::getReasonPhrase(500));
        $this->assertSame('Unknown', HeaderHandler::getReasonPhrase(999));
    }

    public function testHandlesFinalCleanup(): void
    {
        $this->handler->finalCleanup();
        $this->assertTrue(true);
    }

    public function testThrowsWhenGettingInstanceBeforeInit(): void
    {
        HeaderHandler::resetInstance();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Header handler not initialized');

        HeaderHandler::getInstance();
    }
}
