<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use n5s\HttpCli\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ResponseTest extends TestCase
{
    private Process $mockProcess;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a stub Process object for testing (no expectations, just return values)
        $this->mockProcess = $this->createStub(Process::class);
        $this->mockProcess->method('getOutput')->willReturn('Test output');
        $this->mockProcess->method('getErrorOutput')->willReturn('');
        $this->mockProcess->method('getExitCode')->willReturn(0);
    }

    // Constructor Tests

    public function testConstructorWithValidParameters(): void
    {
        $response = new Response(
            statusCode: 200,
            headers: ['Content-Type: text/html', 'X-Custom: test'],
            content: 'Hello World',
            process: $this->mockProcess
        );

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testConstructorWithMinimalParameters(): void
    {
        $response = new Response(
            statusCode: 404,
            headers: [],
            content: '',
            process: $this->mockProcess
        );

        $this->assertInstanceOf(Response::class, $response);
    }

    // Getter Tests

    public function testGetStatusCode(): void
    {
        $response = new Response(
            statusCode: 201,
            headers: [],
            content: 'Created',
            process: $this->mockProcess
        );

        $this->assertEquals(201, $response->getStatusCode());
    }

    #[DataProvider('statusCodeProvider')]
    public function testGetStatusCodeWithVariousValues(int $statusCode): void
    {
        $response = new Response(
            statusCode: $statusCode,
            headers: [],
            content: '',
            process: $this->mockProcess
        );

        $this->assertEquals($statusCode, $response->getStatusCode());
    }

    public static function statusCodeProvider(): array
    {
        return [
            [100], // Continue
            [200], // OK
            [201], // Created
            [204], // No Content
            [301], // Moved Permanently
            [302], // Found
            [304], // Not Modified
            [400], // Bad Request
            [401], // Unauthorized
            [403], // Forbidden
            [404], // Not Found
            [405], // Method Not Allowed
            [500], // Internal Server Error
            [501], // Not Implemented
            [502], // Bad Gateway
            [503], // Service Unavailable
        ];
    }

    public function testGetHeaders(): void
    {
        $headers = [
            'Content-Type: application/json',
            'Content-Length: 1024',
            'X-Custom-Header: custom-value',
        ];

        $response = new Response(
            statusCode: 200,
            headers: $headers,
            content: '{"test": true}',
            process: $this->mockProcess
        );

        $this->assertEquals($headers, $response->getHeaders());
    }

    public function testGetHeadersWithEmptyArray(): void
    {
        $response = new Response(
            statusCode: 204,
            headers: [],
            content: '',
            process: $this->mockProcess
        );

        $this->assertEquals([], $response->getHeaders());
    }

    public function testGetContent(): void
    {
        $content = 'This is the response content';

        $response = new Response(
            statusCode: 200,
            headers: [],
            content: $content,
            process: $this->mockProcess
        );

        $this->assertEquals($content, $response->getContent());
    }

    public function testGetContentWithEmptyString(): void
    {
        $response = new Response(
            statusCode: 204,
            headers: [],
            content: '',
            process: $this->mockProcess
        );

        $this->assertEquals('', $response->getContent());
    }

    // Content Type Tests

    #[DataProvider('contentTypeProvider')]
    public function testGetContentWithDifferentContentTypes(string $content, array $headers): void
    {
        $response = new Response(
            statusCode: 200,
            headers: $headers,
            content: $content,
            process: $this->mockProcess
        );

        $this->assertEquals($content, $response->getContent());
        $this->assertEquals($headers, $response->getHeaders());
    }

    public static function contentTypeProvider(): array
    {
        return [
            // HTML content
            [
                '<html><body><h1>Hello World</h1></body></html>',
                ['Content-Type: text/html; charset=utf-8'],
            ],
            // JSON content
            [
                '{"message": "Hello", "status": "success"}',
                ['Content-Type: application/json'],
            ],
            // XML content
            [
                '<?xml version="1.0"?><root><message>Hello</message></root>',
                ['Content-Type: application/xml'],
            ],
            // Plain text
            [
                'Hello World',
                ['Content-Type: text/plain'],
            ],
            // Binary-like content (represented as string)
            [
                "Binary\x00\x01\x02data",
                ['Content-Type: application/octet-stream'],
            ],
        ];
    }

    // Edge Cases

    public function testResponseWithVeryLongContent(): void
    {
        $longContent = str_repeat('A', 1024 * 1024); // 1MB of 'A's

        $response = new Response(
            statusCode: 200,
            headers: ['Content-Length: ' . strlen($longContent)],
            content: $longContent,
            process: $this->mockProcess
        );

        $this->assertEquals($longContent, $response->getContent());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testResponseWithManyHeaders(): void
    {
        $manyHeaders = [];
        for ($i = 0; $i < 100; $i++) {
            $manyHeaders[] = "X-Custom-Header-{$i}: value-{$i}";
        }

        $response = new Response(
            statusCode: 200,
            headers: $manyHeaders,
            content: 'Response with many headers',
            process: $this->mockProcess
        );

        $this->assertEquals($manyHeaders, $response->getHeaders());
        $this->assertCount(100, $response->getHeaders());
    }

    public function testResponseWithSpecialCharactersInContent(): void
    {
        $specialContent = "Content with special characters: àáâãäåæçèéêë\n\r\t\"'\\";

        $response = new Response(
            statusCode: 200,
            headers: ['Content-Type: text/plain; charset=utf-8'],
            content: $specialContent,
            process: $this->mockProcess
        );

        $this->assertEquals($specialContent, $response->getContent());
    }

    public function testResponseWithUnicodeContent(): void
    {
        $unicodeContent = 'Unicode content: 🌟 ★ ☆ 🚀 💻 🎉';

        $response = new Response(
            statusCode: 200,
            headers: ['Content-Type: text/plain; charset=utf-8'],
            content: $unicodeContent,
            process: $this->mockProcess
        );

        $this->assertEquals($unicodeContent, $response->getContent());
    }

    public function testResponseWithNullBytesInContent(): void
    {
        $contentWithNulls = "Content\x00with\x00null\x00bytes";

        $response = new Response(
            statusCode: 200,
            headers: [],
            content: $contentWithNulls,
            process: $this->mockProcess
        );

        $this->assertEquals($contentWithNulls, $response->getContent());
    }

    // Status Code Edge Cases

    public function testResponseWithUncommonStatusCodes(): void
    {
        $uncommonCodes = [
            102, // Processing
            207, // Multi-Status
            226, // IM Used
            418, // I'm a teapot
            422, // Unprocessable Entity
            429, // Too Many Requests
            451, // Unavailable For Legal Reasons
            507, // Insufficient Storage
            508, // Loop Detected
            510, // Not Extended
        ];

        foreach ($uncommonCodes as $code) {
            $response = new Response(
                statusCode: $code,
                headers: [],
                content: "Status code {$code} response",
                process: $this->mockProcess
            );

            $this->assertEquals($code, $response->getStatusCode());
        }
    }

    // Headers Edge Cases

    public function testResponseWithDuplicateHeaderNames(): void
    {
        $headers = [
            'Set-Cookie: session=abc123',
            'Set-Cookie: user=john',
            'Set-Cookie: theme=dark',
        ];

        $response = new Response(
            statusCode: 200,
            headers: $headers,
            content: 'Response with multiple cookies',
            process: $this->mockProcess
        );

        $this->assertEquals($headers, $response->getHeaders());
        $this->assertCount(3, $response->getHeaders());
    }

    public function testResponseWithHeadersContainingSpecialCharacters(): void
    {
        $headers = [
            'X-Special: value with spaces and "quotes"',
            'X-Unicode: café résumé 🌟',
            'X-Numbers: 123-456-789',
            'X-Symbols: !@#$%^&*()_+-=',
        ];

        $response = new Response(
            statusCode: 200,
            headers: $headers,
            content: 'Response with special headers',
            process: $this->mockProcess
        );

        $this->assertEquals($headers, $response->getHeaders());
    }

    public function testResponseWithEmptyHeaderValues(): void
    {
        $headers = [
            'X-Empty-Value: ',
            'X-Another-Empty:',
            'X-Normal: value',
        ];

        $response = new Response(
            statusCode: 200,
            headers: $headers,
            content: 'Response with empty header values',
            process: $this->mockProcess
        );

        $this->assertEquals($headers, $response->getHeaders());
    }

    public function testResponseWithVeryLongHeaderValues(): void
    {
        $longValue = str_repeat('x', 8192); // 8KB header value
        $headers = [
            'X-Long-Header: ' . $longValue,
            'Content-Type: text/plain',
        ];

        $response = new Response(
            statusCode: 200,
            headers: $headers,
            content: 'Response with very long header',
            process: $this->mockProcess
        );

        $this->assertEquals($headers, $response->getHeaders());
    }

    // Integration Tests

    public function testCompleteResponseObject(): void
    {
        $statusCode = 201;
        $headers = [
            'Content-Type: application/json',
            'Location: /api/resource/123',
            'X-Request-ID: abc-def-ghi',
        ];
        $content = '{"id": 123, "message": "Resource created successfully"}';

        $response = new Response(
            statusCode: $statusCode,
            headers: $headers,
            content: $content,
            process: $this->mockProcess
        );

        // Test all getters return correct values
        $this->assertEquals($statusCode, $response->getStatusCode());
        $this->assertEquals($headers, $response->getHeaders());
        $this->assertEquals($content, $response->getContent());

        // Test immutability - original arrays should remain unchanged
        $originalHeaders = $headers;
        $response->getHeaders();
        $this->assertEquals($originalHeaders, $headers);
    }

    public function testResponseObjectIsImmutable(): void
    {
        $headers = ['Content-Type: text/plain'];
        $content = 'Original content';

        $response = new Response(
            statusCode: 200,
            headers: $headers,
            content: $content,
            process: $this->mockProcess
        );

        // Modify original arrays
        $headers[] = 'X-Modified: true';
        $content = 'Modified content';

        // Response should still have original values
        $this->assertEquals(['Content-Type: text/plain'], $response->getHeaders());
        $this->assertEquals('Original content', $response->getContent());
    }

    // Process Integration

    public function testResponseWithDifferentProcessStates(): void
    {
        $processes = [
            $this->createStubProcessWithOutput('Success output', 0),
            $this->createStubProcessWithOutput('Error output', 1),
            $this->createStubProcessWithOutput('', 0), // Empty output
        ];

        foreach ($processes as $process) {
            $response = new Response(
                statusCode: 200,
                headers: [],
                content: 'Test content',
                process: $process
            );

            $this->assertInstanceOf(Response::class, $response);
        }
    }

    private function createStubProcessWithOutput(string $output, int $exitCode): Process
    {
        $stubProcess = $this->createStub(Process::class);
        $stubProcess->method('getOutput')->willReturn($output);
        $stubProcess->method('getExitCode')->willReturn($exitCode);

        return $stubProcess;
    }
}
