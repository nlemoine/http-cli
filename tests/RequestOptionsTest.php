<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use InvalidArgumentException;
use n5s\HttpCli\RequestOptions;
use n5s\HttpCli\RequestOptionsBuilder;
use PHPUnit\Framework\TestCase;

class RequestOptionsTest extends TestCase
{
    // Factory Methods
    public function testCreateReturnsBuilder(): void
    {
        $builder = RequestOptions::create();

        $this->assertInstanceOf(RequestOptionsBuilder::class, $builder);
    }

    public function testEmptyReturnsDefaultOptions(): void
    {
        $options = RequestOptions::empty();

        $this->assertInstanceOf(RequestOptions::class, $options);
        $this->assertNull($options->timeout);
        $this->assertEmpty($options->headers);
        $this->assertEmpty($options->query);
        $this->assertNull($options->body);
        $this->assertNull($options->json);
        $this->assertEmpty($options->formParams);
        $this->assertEmpty($options->multipart);
        $this->assertNull($options->basicAuth);
        $this->assertNull($options->bearerToken);
        $this->assertNull($options->maxRedirects);
        $this->assertNull($options->userAgent);
        $this->assertEmpty($options->cookies);
        $this->assertEmpty($options->session);
        $this->assertEmpty($options->extras);
    }

    // Constructor
    public function testConstructorWithAllParameters(): void
    {
        $options = new RequestOptions(
            timeout: 30.0,
            headers: [
                'Content-Type' => 'application/json',
            ],
            query: [
                'page' => 1,
            ],
            body: 'raw body',
            json: null,
            formParams: [],
            multipart: [],
            basicAuth: ['user', 'pass'],
            bearerToken: 'token123',
            maxRedirects: 5,
            userAgent: 'TestAgent/1.0',
            cookies: [
                'session' => 'abc123',
            ],
            session: [
                'user_id' => 42,
            ],
            extras: [
                'custom' => 'value',
            ]
        );

        $this->assertEquals(30.0, $options->timeout);
        $this->assertEquals([
            'Content-Type' => 'application/json',
        ], $options->headers);
        $this->assertEquals([
            'page' => 1,
        ], $options->query);
        $this->assertEquals('raw body', $options->body);
        $this->assertEquals(['user', 'pass'], $options->basicAuth);
        $this->assertEquals('token123', $options->bearerToken);
        $this->assertEquals(5, $options->maxRedirects);
        $this->assertEquals('TestAgent/1.0', $options->userAgent);
        $this->assertEquals([
            'session' => 'abc123',
        ], $options->cookies);
        $this->assertEquals([
            'user_id' => 42,
        ], $options->session);
        $this->assertEquals([
            'custom' => 'value',
        ], $options->extras);
    }

    // Validation: Timeout
    public function testValidationRejectsZeroTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be positive');

        new RequestOptions(timeout: 0);
    }

    public function testValidationRejectsNegativeTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be positive');

        new RequestOptions(timeout: -1);
    }

    public function testValidationAcceptsPositiveTimeout(): void
    {
        $options = new RequestOptions(timeout: 0.001);

        $this->assertEquals(0.001, $options->timeout);
    }

    // Validation: Body Options
    public function testValidationRejectsMultipleBodyOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only one body option');

        new RequestOptions(body: 'raw', json: [
            'key' => 'value',
        ]);
    }

    public function testValidationRejectsBodyAndFormParams(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only one body option');

        new RequestOptions(body: 'raw', formParams: [
            'key' => 'value',
        ]);
    }

    public function testValidationRejectsJsonAndFormParams(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only one body option');

        new RequestOptions(json: [
            'a' => 1,
        ], formParams: [
            'b' => 2,
        ]);
    }

    public function testValidationRejectsBodyAndMultipart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only one body option');

        new RequestOptions(
            body: 'raw',
            multipart: [[
                'name' => 'file',
                'contents' => 'data',
            ]]
        );
    }

    public function testValidationRejectsThreeBodyOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only one body option');

        new RequestOptions(
            body: 'raw',
            json: [
                'a' => 1,
            ],
            formParams: [
                'b' => 2,
            ]
        );
    }

    public function testValidationAcceptsSingleBodyOption(): void
    {
        $options1 = new RequestOptions(body: 'raw');
        $this->assertEquals('raw', $options1->body);

        $options2 = new RequestOptions(json: [
            'key' => 'value',
        ]);
        $this->assertEquals([
            'key' => 'value',
        ], $options2->json);

        $options3 = new RequestOptions(formParams: [
            'key' => 'value',
        ]);
        $this->assertEquals([
            'key' => 'value',
        ], $options3->formParams);

        $options4 = new RequestOptions(multipart: [[
            'name' => 'file',
            'contents' => 'data',
        ]]);
        $this->assertCount(1, $options4->multipart);
    }

    // Type Enforcement (via PHPDoc)    // Note: basicAuth type is enforced by PHPDoc as array{0: string, 1: string}|null
    // Headers are typed as array<string, string>, so keys are always strings
    // Runtime validation was removed as it's now handled by static analysis

    public function testValidationAcceptsValidBasicAuth(): void
    {
        $options = new RequestOptions(basicAuth: ['username', 'password']);

        $this->assertEquals(['username', 'password'], $options->basicAuth);
    }

    public function testValidationAcceptsStringHeaderNames(): void
    {
        $options = new RequestOptions(headers: [
            'X-Custom' => 'value',
            'Accept' => 'application/json',
        ]);

        $this->assertEquals([
            'X-Custom' => 'value',
            'Accept' => 'application/json',
        ], $options->headers);
    }

    // Validation: Max Redirects
    public function testValidationRejectsNegativeMaxRedirects(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max redirects must be non-negative');

        new RequestOptions(maxRedirects: -1);
    }

    public function testValidationAcceptsZeroMaxRedirects(): void
    {
        $options = new RequestOptions(maxRedirects: 0);

        $this->assertEquals(0, $options->maxRedirects);
    }

    public function testValidationAcceptsPositiveMaxRedirects(): void
    {
        $options = new RequestOptions(maxRedirects: 10);

        $this->assertEquals(10, $options->maxRedirects);
    }

    // toArray()
    public function testToArrayWithEmptyOptions(): void
    {
        $options = RequestOptions::empty();
        $array = $options->toArray();

        $this->assertEmpty($array);
    }

    public function testToArrayWithTimeout(): void
    {
        $options = new RequestOptions(timeout: 30.0);
        $array = $options->toArray();

        $this->assertEquals([
            'timeout' => 30.0,
        ], $array);
    }

    public function testToArrayWithHeaders(): void
    {
        $options = new RequestOptions(headers: [
            'X-Custom' => 'value',
        ]);
        $array = $options->toArray();

        $this->assertEquals([
            'headers' => [
                'X-Custom' => 'value',
            ],
        ], $array);
    }

    public function testToArrayWithQuery(): void
    {
        $options = new RequestOptions(query: [
            'page' => 1,
            'limit' => 10,
        ]);
        $array = $options->toArray();

        $this->assertEquals([
            'query' => [
                'page' => 1,
                'limit' => 10,
            ],
        ], $array);
    }

    public function testToArrayWithBody(): void
    {
        $options = new RequestOptions(body: 'raw content');
        $array = $options->toArray();

        $this->assertEquals([
            'body' => 'raw content',
        ], $array);
    }

    public function testToArrayWithJson(): void
    {
        $options = new RequestOptions(json: [
            'key' => 'value',
        ]);
        $array = $options->toArray();

        $this->assertEquals([
            'json' => [
                'key' => 'value',
            ],
        ], $array);
    }

    public function testToArrayWithFormParams(): void
    {
        $options = new RequestOptions(formParams: [
            'field' => 'value',
        ]);
        $array = $options->toArray();

        $this->assertEquals([
            'form_params' => [
                'field' => 'value',
            ],
        ], $array);
    }

    public function testToArrayWithMultipart(): void
    {
        $multipart = [[
            'name' => 'file',
            'contents' => 'data',
        ]];
        $options = new RequestOptions(multipart: $multipart);
        $array = $options->toArray();

        $this->assertEquals([
            'multipart' => $multipart,
        ], $array);
    }

    public function testToArrayWithBasicAuth(): void
    {
        $options = new RequestOptions(basicAuth: ['user', 'pass']);
        $array = $options->toArray();

        $this->assertEquals([
            'basic_auth' => ['user', 'pass'],
        ], $array);
    }

    public function testToArrayWithBearerToken(): void
    {
        $options = new RequestOptions(bearerToken: 'token123');
        $array = $options->toArray();

        $this->assertEquals([
            'bearer_token' => 'token123',
        ], $array);
    }

    public function testToArrayWithMaxRedirects(): void
    {
        $options = new RequestOptions(maxRedirects: 5);
        $array = $options->toArray();

        $this->assertEquals([
            'max_redirects' => 5,
        ], $array);
    }

    public function testToArrayWithUserAgent(): void
    {
        $options = new RequestOptions(userAgent: 'TestAgent/1.0');
        $array = $options->toArray();

        $this->assertEquals([
            'user_agent' => 'TestAgent/1.0',
        ], $array);
    }

    public function testToArrayWithCookies(): void
    {
        $options = new RequestOptions(cookies: [
            'session' => 'abc',
        ]);
        $array = $options->toArray();

        $this->assertEquals([
            'cookies' => [
                'session' => 'abc',
            ],
        ], $array);
    }

    public function testToArrayWithSession(): void
    {
        $options = new RequestOptions(session: [
            'user_id' => 42,
        ]);
        $array = $options->toArray();

        $this->assertEquals([
            'session' => [
                'user_id' => 42,
            ],
        ], $array);
    }

    public function testToArrayWithExtras(): void
    {
        $options = new RequestOptions(extras: [
            'custom' => 'value',
        ]);
        $array = $options->toArray();

        $this->assertEquals([
            'extras' => [
                'custom' => 'value',
            ],
        ], $array);
    }

    public function testToArrayWithMultipleOptions(): void
    {
        $options = new RequestOptions(
            timeout: 30.0,
            headers: [
                'Accept' => 'application/json',
            ],
            query: [
                'page' => 1,
            ],
            userAgent: 'Test/1.0'
        );
        $array = $options->toArray();

        $this->assertEquals([
            'timeout' => 30.0,
            'headers' => [
                'Accept' => 'application/json',
            ],
            'query' => [
                'page' => 1,
            ],
            'user_agent' => 'Test/1.0',
        ], $array);
    }

    // Immutability
    public function testOptionsAreImmutable(): void
    {
        $options = new RequestOptions(
            timeout: 30.0,
            headers: [
                'X-Test' => 'value',
            ]
        );

        // Properties are readonly, this test verifies they exist and have expected values
        $this->assertEquals(30.0, $options->timeout);
        $this->assertEquals([
            'X-Test' => 'value',
        ], $options->headers);
    }
}
