<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests\Symfony;

use n5s\HttpCli\Response as HttpCliResponse;
use n5s\HttpCli\Symfony\CliResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\JsonException;
use Symfony\Component\HttpClient\Exception\RedirectionException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\Process\Process;

class CliResponseTest extends TestCase
{
    // Status Code Tests
    public function testGetStatusCode(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(200),
            [
                'url' => 'http://example.com',
            ]
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testGetStatusCodeVariousValues(): void
    {
        $codes = [200, 201, 204, 301, 302, 400, 401, 403, 404, 500, 502, 503];

        foreach ($codes as $code) {
            $response = new CliResponse(
                $this->createHttpCliResponse($code),
                []
            );
            $this->assertEquals($code, $response->getStatusCode());
        }
    }

    // Headers Tests
    public function testGetHeaders(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(200, [
                'Content-Type: application/json',
                'X-Custom-Header: CustomValue',
            ]),
            []
        );

        $headers = $response->getHeaders(false);

        $this->assertArrayHasKey('content-type', $headers);
        $this->assertArrayHasKey('x-custom-header', $headers);
        $this->assertEquals(['application/json'], $headers['content-type']);
        $this->assertEquals(['CustomValue'], $headers['x-custom-header']);
    }

    public function testGetHeadersMultipleValuesForSameHeader(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(200, [
                'Set-Cookie: cookie1=value1',
                'Set-Cookie: cookie2=value2',
            ]),
            []
        );

        $headers = $response->getHeaders(false);

        $this->assertArrayHasKey('set-cookie', $headers);
        $this->assertCount(2, $headers['set-cookie']);
        $this->assertEquals(['cookie1=value1', 'cookie2=value2'], $headers['set-cookie']);
    }

    public function testGetHeadersEmpty(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(200, []),
            []
        );

        $headers = $response->getHeaders(false);
        $this->assertEmpty($headers);
    }

    public function testGetHeadersThrowsOnClientError(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(404, ['Content-Type: text/html'], 'Not Found'),
            []
        );

        $this->expectException(ClientException::class);
        $response->getHeaders(true);
    }

    public function testGetHeadersNoThrow(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(404, ['Content-Type: text/html']),
            []
        );

        $headers = $response->getHeaders(false);
        $this->assertArrayHasKey('content-type', $headers);
    }

    // Content Tests
    public function testGetContent(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(200, [], 'Hello World'),
            []
        );

        $this->assertEquals('Hello World', $response->getContent(false));
    }

    public function testGetContentEmpty(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(204, [], ''),
            []
        );

        $this->assertEquals('', $response->getContent(false));
    }

    public function testGetContentThrowsOnServerError(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(500, [], 'Internal Server Error'),
            []
        );

        $this->expectException(ServerException::class);
        $response->getContent(true);
    }

    public function testGetContentNoThrow(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(500, [], 'Error content'),
            []
        );

        $this->assertEquals('Error content', $response->getContent(false));
    }

    // toArray Tests
    public function testToArray(): void
    {
        $jsonData = [
            'key' => 'value',
            'nested' => [
                'a' => 1,
                'b' => 2,
            ],
        ];
        $response = new CliResponse(
            $this->createHttpCliResponse(200, [], json_encode($jsonData)),
            [
                'url' => 'http://example.com/api',
            ]
        );

        $result = $response->toArray(false);
        $this->assertEquals($jsonData, $result);
    }

    public function testToArrayCachesResult(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(200, [], '{"cached": true}'),
            []
        );

        $first = $response->toArray(false);
        $second = $response->toArray(false);

        $this->assertSame($first, $second);
    }

    public function testToArrayThrowsOnEmptyBody(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(200, [], ''),
            [
                'url' => 'http://example.com',
            ]
        );

        $this->expectException(JsonException::class);
        $this->expectExceptionMessage('Response body is empty');
        $response->toArray(false);
    }

    public function testToArrayThrowsOnInvalidJson(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(200, [], 'not valid json'),
            [
                'url' => 'http://example.com',
            ]
        );

        $this->expectException(JsonException::class);
        $response->toArray(false);
    }

    public function testToArrayThrowsOnNonArrayJson(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(200, [], '"just a string"'),
            [
                'url' => 'http://example.com',
            ]
        );

        $this->expectException(JsonException::class);
        $this->expectExceptionMessage('JSON content was expected to decode to an array');
        $response->toArray(false);
    }

    public function testToArrayThrowsOnClientError(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(400, [], '{"error": "bad request"}'),
            []
        );

        $this->expectException(ClientException::class);
        $response->toArray(true);
    }

    // Cancel Tests
    public function testCancel(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(200),
            []
        );

        $response->cancel();

        $this->assertTrue($response->getInfo('canceled'));
    }

    // getInfo Tests
    public function testGetInfoReturnsAllInfo(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(200, ['Content-Type: application/json']),
            [
                'url' => 'http://example.com',
                'method' => 'GET',
            ]
        );

        $info = $response->getInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('http_code', $info);
        $this->assertArrayHasKey('url', $info);
        $this->assertArrayHasKey('start_time', $info);
        $this->assertArrayHasKey('response_headers', $info);
        $this->assertEquals(200, $info['http_code']);
        $this->assertEquals('http://example.com', $info['url']);
    }

    public function testGetInfoSpecificKey(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(201),
            [
                'url' => 'http://test.com',
            ]
        );

        $this->assertEquals(201, $response->getInfo('http_code'));
        $this->assertEquals('http://test.com', $response->getInfo('url'));
    }

    public function testGetInfoUnknownKey(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(200),
            []
        );

        $this->assertNull($response->getInfo('unknown_key'));
    }

    public function testGetInfoContentType(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(200, ['Content-Type: text/html; charset=utf-8']),
            []
        );

        $contentType = $response->getInfo('content_type');
        $this->assertEquals('text/html; charset=utf-8', $contentType);
    }

    // Exception Tests
    public function testRedirectionExceptionOn3xx(): void
    {
        $response = new CliResponse(
            $this->createHttpCliResponse(302, ['Location: http://example.com/new']),
            []
        );

        $this->expectException(RedirectionException::class);
        $response->getContent(true);
    }

    public function testClientExceptionOn4xx(): void
    {
        $codes = [400, 401, 403, 404, 422];

        foreach ($codes as $code) {
            $response = new CliResponse(
                $this->createHttpCliResponse($code),
                []
            );

            try {
                $response->getContent(true);
                $this->fail("Expected ClientException for status code {$code}");
            } catch (ClientException $e) {
                $this->assertEquals($code, $e->getResponse()->getStatusCode());
            }
        }
    }

    public function testServerExceptionOn5xx(): void
    {
        $codes = [500, 502, 503, 504];

        foreach ($codes as $code) {
            $response = new CliResponse(
                $this->createHttpCliResponse($code),
                []
            );

            try {
                $response->getContent(true);
                $this->fail("Expected ServerException for status code {$code}");
            } catch (ServerException $e) {
                $this->assertEquals($code, $e->getResponse()->getStatusCode());
            }
        }
    }

    public function testNoExceptionOn2xx(): void
    {
        $codes = [200, 201, 202, 204];

        foreach ($codes as $code) {
            $response = new CliResponse(
                $this->createHttpCliResponse($code, [], 'OK'),
                []
            );

            // Should not throw
            $content = $response->getContent(true);
            $this->assertEquals('OK', $content);
        }
    }

    private function createStubProcess(): Process
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        return $process;
    }

    private function createHttpCliResponse(
        int $statusCode = 200,
        array $headers = [],
        string $content = ''
    ): HttpCliResponse {
        return new HttpCliResponse(
            $statusCode,
            $headers,
            $content,
            $this->createStubProcess()
        );
    }
}
