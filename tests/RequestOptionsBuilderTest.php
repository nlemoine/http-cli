<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use n5s\HttpCli\RequestOptions;
use n5s\HttpCli\RequestOptionsBuilder;
use PHPUnit\Framework\TestCase;

class RequestOptionsBuilderTest extends TestCase
{
    // Basic Builder Pattern
    public function testBuilderReturnsRequestOptions(): void
    {
        $options = (new RequestOptionsBuilder())->build();

        $this->assertInstanceOf(RequestOptions::class, $options);
    }

    public function testBuilderMethodsReturnSelf(): void
    {
        $builder = new RequestOptionsBuilder();

        $this->assertSame($builder, $builder->timeout(30));
        $this->assertSame($builder, $builder->header('X-Test', 'value'));
        $this->assertSame($builder, $builder->headers([
            'Accept' => 'json',
        ]));
        $this->assertSame($builder, $builder->query([
            'page' => 1,
        ]));
        $this->assertSame($builder, $builder->body('content'));
        $this->assertSame($builder, $builder->json([
            'key' => 'value',
        ]));
        $this->assertSame($builder, $builder->formParams([
            'field' => 'value',
        ]));
        $this->assertSame($builder, $builder->multipart([[
            'name' => 'f',
            'contents' => 'd',
        ]]));
        $this->assertSame($builder, $builder->basicAuth('user', 'pass'));
        $this->assertSame($builder, $builder->bearerToken('token'));
        $this->assertSame($builder, $builder->maxRedirects(5));
        $this->assertSame($builder, $builder->userAgent('Agent/1.0'));
        $this->assertSame($builder, $builder->cookie('name', 'value'));
        $this->assertSame($builder, $builder->cookies([
            'a' => 'b',
        ]));
        $this->assertSame($builder, $builder->session([
            'user' => 1,
        ]));
        $this->assertSame($builder, $builder->extra('key', 'value'));
        $this->assertSame($builder, $builder->extras([
            'k' => 'v',
        ]));
    }

    // Timeout
    public function testTimeout(): void
    {
        $options = (new RequestOptionsBuilder())
            ->timeout(30.5)
            ->build();

        $this->assertEquals(30.5, $options->timeout);
    }

    // Headers
    public function testSingleHeader(): void
    {
        $options = (new RequestOptionsBuilder())
            ->header('X-Custom', 'value')
            ->build();

        $this->assertEquals([
            'X-Custom' => 'value',
        ], $options->headers);
    }

    public function testMultipleHeaders(): void
    {
        $options = (new RequestOptionsBuilder())
            ->header('X-First', 'one')
            ->header('X-Second', 'two')
            ->build();

        $this->assertEquals([
            'X-First' => 'one',
            'X-Second' => 'two',
        ], $options->headers);
    }

    public function testHeadersArray(): void
    {
        $options = (new RequestOptionsBuilder())
            ->headers([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->build();

        $this->assertEquals([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $options->headers);
    }

    public function testHeadersReplacesPreviousHeaders(): void
    {
        // headers() replaces all headers, doesn't merge
        $options = (new RequestOptionsBuilder())
            ->header('X-First', 'one')
            ->headers([
                'X-Second' => 'two',
                'X-Third' => 'three',
            ])
            ->header('X-Fourth', 'four')
            ->build();

        // X-First is lost because headers() replaced all
        $this->assertEquals([
            'X-Second' => 'two',
            'X-Third' => 'three',
            'X-Fourth' => 'four',
        ], $options->headers);
    }

    public function testHeaderOverwrite(): void
    {
        $options = (new RequestOptionsBuilder())
            ->header('X-Test', 'original')
            ->header('X-Test', 'overwritten')
            ->build();

        $this->assertEquals([
            'X-Test' => 'overwritten',
        ], $options->headers);
    }

    // Query
    public function testQuery(): void
    {
        $options = (new RequestOptionsBuilder())
            ->query([
                'page' => 1,
                'limit' => 10,
            ])
            ->build();

        $this->assertEquals([
            'page' => 1,
            'limit' => 10,
        ], $options->query);
    }

    public function testQueryReplacesPreviousQuery(): void
    {
        // query() replaces all query params, doesn't merge
        $options = (new RequestOptionsBuilder())
            ->query([
                'page' => 1,
            ])
            ->query([
                'limit' => 10,
            ])
            ->build();

        // page is lost because query() replaced all
        $this->assertEquals([
            'limit' => 10,
        ], $options->query);
    }

    public function testQueryParam(): void
    {
        $options = (new RequestOptionsBuilder())
            ->queryParam('page', '1')
            ->queryParam('sort', 'name')
            ->build();

        $this->assertEquals([
            'page' => '1',
            'sort' => 'name',
        ], $options->query);
    }

    // Body Options
    public function testBody(): void
    {
        $options = (new RequestOptionsBuilder())
            ->body('raw content')
            ->build();

        $this->assertEquals('raw content', $options->body);
    }

    public function testJson(): void
    {
        $options = (new RequestOptionsBuilder())
            ->json([
                'key' => 'value',
                'nested' => [
                    'a' => 1,
                ],
            ])
            ->build();

        $this->assertEquals([
            'key' => 'value',
            'nested' => [
                'a' => 1,
            ],
        ], $options->json);
    }

    public function testFormParams(): void
    {
        $options = (new RequestOptionsBuilder())
            ->formParams([
                'username' => 'john',
                'password' => 'secret',
            ])
            ->build();

        $this->assertEquals([
            'username' => 'john',
            'password' => 'secret',
        ], $options->formParams);
    }

    public function testMultipart(): void
    {
        $multipart = [
            [
                'name' => 'file',
                'contents' => 'file content',
                'filename' => 'test.txt',
            ],
            [
                'name' => 'field',
                'contents' => 'value',
            ],
        ];

        $options = (new RequestOptionsBuilder())
            ->multipart($multipart)
            ->build();

        $this->assertEquals($multipart, $options->multipart);
    }

    // Authentication
    public function testBasicAuth(): void
    {
        $options = (new RequestOptionsBuilder())
            ->basicAuth('username', 'password')
            ->build();

        $this->assertEquals(['username', 'password'], $options->basicAuth);
    }

    public function testBearerToken(): void
    {
        $options = (new RequestOptionsBuilder())
            ->bearerToken('my-jwt-token')
            ->build();

        $this->assertEquals('my-jwt-token', $options->bearerToken);
    }

    // Redirects
    public function testMaxRedirects(): void
    {
        $options = (new RequestOptionsBuilder())
            ->maxRedirects(10)
            ->build();

        $this->assertEquals(10, $options->maxRedirects);
    }

    public function testMaxRedirectsZero(): void
    {
        $options = (new RequestOptionsBuilder())
            ->maxRedirects(0)
            ->build();

        $this->assertEquals(0, $options->maxRedirects);
    }

    // User Agent
    public function testUserAgent(): void
    {
        $options = (new RequestOptionsBuilder())
            ->userAgent('MyApp/1.0 (PHP)')
            ->build();

        $this->assertEquals('MyApp/1.0 (PHP)', $options->userAgent);
    }

    // Cookies
    public function testSingleCookie(): void
    {
        $options = (new RequestOptionsBuilder())
            ->cookie('session_id', 'abc123')
            ->build();

        $this->assertEquals([
            'session_id' => 'abc123',
        ], $options->cookies);
    }

    public function testMultipleCookies(): void
    {
        $options = (new RequestOptionsBuilder())
            ->cookie('session', 'abc')
            ->cookie('user', 'john')
            ->build();

        $this->assertEquals([
            'session' => 'abc',
            'user' => 'john',
        ], $options->cookies);
    }

    public function testCookiesArray(): void
    {
        $options = (new RequestOptionsBuilder())
            ->cookies([
                'a' => '1',
                'b' => '2',
            ])
            ->build();

        $this->assertEquals([
            'a' => '1',
            'b' => '2',
        ], $options->cookies);
    }

    public function testCookiesReplacesPreviousCookies(): void
    {
        // cookies() replaces all cookies, doesn't merge
        $options = (new RequestOptionsBuilder())
            ->cookie('first', '1')
            ->cookies([
                'second' => '2',
                'third' => '3',
            ])
            ->cookie('fourth', '4')
            ->build();

        // first is lost because cookies() replaced all
        $this->assertEquals([
            'second' => '2',
            'third' => '3',
            'fourth' => '4',
        ], $options->cookies);
    }

    // Session
    public function testSession(): void
    {
        $options = (new RequestOptionsBuilder())
            ->session([
                'user_id' => 42,
                'role' => 'admin',
            ])
            ->build();

        $this->assertEquals([
            'user_id' => 42,
            'role' => 'admin',
        ], $options->session);
    }

    public function testSessionReplacesPreviousSession(): void
    {
        // session() replaces all session data, doesn't merge
        $options = (new RequestOptionsBuilder())
            ->session([
                'user_id' => 42,
            ])
            ->session([
                'role' => 'admin',
            ])
            ->build();

        // user_id is lost because session() replaced all
        $this->assertEquals([
            'role' => 'admin',
        ], $options->session);
    }

    // Extras
    public function testSingleExtra(): void
    {
        $options = (new RequestOptionsBuilder())
            ->extra('custom_option', 'custom_value')
            ->build();

        $this->assertEquals([
            'custom_option' => 'custom_value',
        ], $options->extras);
    }

    public function testMultipleExtras(): void
    {
        $options = (new RequestOptionsBuilder())
            ->extra('first', 1)
            ->extra('second', 2)
            ->build();

        $this->assertEquals([
            'first' => 1,
            'second' => 2,
        ], $options->extras);
    }

    public function testExtrasArray(): void
    {
        $options = (new RequestOptionsBuilder())
            ->extras([
                'a' => 1,
                'b' => 2,
            ])
            ->build();

        $this->assertEquals([
            'a' => 1,
            'b' => 2,
        ], $options->extras);
    }

    public function testExtrasMerge(): void
    {
        $options = (new RequestOptionsBuilder())
            ->extra('first', 1)
            ->extras([
                'second' => 2,
                'third' => 3,
            ])
            ->extra('fourth', 4)
            ->build();

        $this->assertEquals([
            'first' => 1,
            'second' => 2,
            'third' => 3,
            'fourth' => 4,
        ], $options->extras);
    }

    // Chaining
    public function testFullChaining(): void
    {
        $options = (new RequestOptionsBuilder())
            ->timeout(30)
            ->header('Accept', 'application/json')
            ->header('X-Custom', 'value')
            ->query([
                'page' => 1,
            ])
            ->basicAuth('user', 'pass')
            ->maxRedirects(5)
            ->userAgent('Test/1.0')
            ->cookie('session', 'abc')
            ->session([
                'user_id' => 42,
            ])
            ->extra('debug', true)
            ->build();

        $this->assertEquals(30, $options->timeout);
        $this->assertEquals([
            'Accept' => 'application/json',
            'X-Custom' => 'value',
        ], $options->headers);
        $this->assertEquals([
            'page' => 1,
        ], $options->query);
        $this->assertEquals(['user', 'pass'], $options->basicAuth);
        $this->assertEquals(5, $options->maxRedirects);
        $this->assertEquals('Test/1.0', $options->userAgent);
        $this->assertEquals([
            'session' => 'abc',
        ], $options->cookies);
        $this->assertEquals([
            'user_id' => 42,
        ], $options->session);
        $this->assertEquals([
            'debug' => true,
        ], $options->extras);
    }

    // Builder Reuse
    public function testBuilderCanBeReused(): void
    {
        $builder = (new RequestOptionsBuilder())
            ->timeout(30)
            ->header('Accept', 'application/json');

        $options1 = $builder->build();
        $options2 = $builder->userAgent('Different/1.0')->build();

        // Both should have the base options
        $this->assertEquals(30, $options1->timeout);
        $this->assertEquals(30, $options2->timeout);
        $this->assertEquals([
            'Accept' => 'application/json',
        ], $options1->headers);
        $this->assertEquals([
            'Accept' => 'application/json',
        ], $options2->headers);

        // Only options2 should have the user agent
        $this->assertNull($options1->userAgent);
        $this->assertEquals('Different/1.0', $options2->userAgent);
    }
}
