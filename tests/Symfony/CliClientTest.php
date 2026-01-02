<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests\Symfony;

use n5s\HttpCli\Client;
use n5s\HttpCli\Symfony\CliClient;
use n5s\HttpCli\Tests\AbstractHttpCliTestCase;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class CliClientTest extends AbstractHttpCliTestCase
{
    public function testImplementsHttpClientInterface(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $client = $this->createClient('index.php');

        $this->assertInstanceOf(HttpClientInterface::class, $client);
    }

    public function testRequestReturnsResponseInterface(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $client = $this->createClient('index.php');

        $response = $client->request('GET', 'http://localhost/');

        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    // Basic Request Tests
    public function testBasicGetRequest(): void
    {
        $this->createTestFile('index', '<?php echo "Hello World";');
        $client = $this->createClient('index.php');

        $response = $client->request('GET', 'http://localhost/');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Hello World', $response->getContent());
    }

    public function testGetRequestWithQueryParams(): void
    {
        $this->createTestFile('query', '<?php echo json_encode($_GET);');
        $client = $this->createClient('query.php');

        $response = $client->request('GET', 'http://localhost/query.php?foo=bar&baz=qux');

        $body = $response->toArray();
        $this->assertEquals('bar', $body['foo']);
        $this->assertEquals('qux', $body['baz']);
    }

    public function testGetRequestWithQueryOption(): void
    {
        $this->createTestFile('query', '<?php echo json_encode($_GET);');
        $client = $this->createClient('query.php');

        $response = $client->request('GET', 'http://localhost/query.php', [
            'query' => [
                'search' => 'test',
                'page' => '1',
            ],
        ]);

        $body = $response->toArray();
        $this->assertEquals('test', $body['search']);
        $this->assertEquals('1', $body['page']);
    }

    public function testBasicPostRequest(): void
    {
        $this->createTestFile('post', '<?php echo json_encode($_POST);');
        $client = $this->createClient('post.php');

        $response = $client->request('POST', 'http://localhost/post.php', [
            'body' => http_build_query([
                'name' => 'John',
                'email' => 'john@example.com',
            ]),
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);

        $body = $response->toArray();
        $this->assertEquals('John', $body['name']);
        $this->assertEquals('john@example.com', $body['email']);
    }

    public function testPostRequestWithJsonBody(): void
    {
        $this->createTestFile('json', '<?php
            header("Content-Type: application/json");
            $input = json_decode(file_get_contents("php://input"), true);
            echo json_encode($input);
        ');
        $client = $this->createClient('json.php');

        $response = $client->request('POST', 'http://localhost/json.php', [
            'json' => [
                'key' => 'value',
                'nested' => [
                    'a' => 1,
                ],
            ],
        ]);

        $body = $response->toArray();
        $this->assertEquals('value', $body['key']);
        $this->assertEquals([
            'a' => 1,
        ], $body['nested']);
    }

    // Headers Tests
    public function testRequestWithCustomHeaders(): void
    {
        $this->createTestFile('headers', '<?php echo json_encode([
            "x_custom_header" => $_SERVER["HTTP_X_CUSTOM_HEADER"] ?? null,
            "accept" => $_SERVER["HTTP_ACCEPT"] ?? null,
        ]);');
        $client = $this->createClient('headers.php');

        $response = $client->request('GET', 'http://localhost/headers.php', [
            'headers' => [
                'X-Custom-Header' => 'CustomValue',
                'Accept' => 'application/json',
            ],
        ]);

        $body = $response->toArray();
        $this->assertEquals('CustomValue', $body['x_custom_header']);
        $this->assertEquals('application/json', $body['accept']);
    }

    public function testResponseHeaders(): void
    {
        $this->createTestFile('response_headers', '<?php
            header("X-Custom-Header: CustomValue");
            header("Content-Type: application/json");
            echo "{}";
        ');
        $client = $this->createClient('response_headers.php');

        $response = $client->request('GET', 'http://localhost/response_headers.php');
        $headers = $response->getHeaders();

        $this->assertArrayHasKey('x-custom-header', $headers);
        $this->assertEquals(['CustomValue'], $headers['x-custom-header']);
    }

    // Authentication Tests
    public function testBasicAuthArray(): void
    {
        $this->createTestFile('auth', '<?php echo json_encode([
            "authorization" => $_SERVER["HTTP_AUTHORIZATION"] ?? null,
        ]);');
        $client = $this->createClient('auth.php');

        $response = $client->request('GET', 'http://localhost/auth.php', [
            'auth_basic' => ['username', 'password'],
        ]);

        $body = $response->toArray();
        $expected = 'Basic ' . base64_encode('username:password');
        $this->assertEquals($expected, $body['authorization']);
    }

    public function testBasicAuthString(): void
    {
        $this->createTestFile('auth', '<?php echo json_encode([
            "authorization" => $_SERVER["HTTP_AUTHORIZATION"] ?? null,
        ]);');
        $client = $this->createClient('auth.php');

        $response = $client->request('GET', 'http://localhost/auth.php', [
            'auth_basic' => 'user:pass',
        ]);

        $body = $response->toArray();
        $expected = 'Basic ' . base64_encode('user:pass');
        $this->assertEquals($expected, $body['authorization']);
    }

    public function testBearerAuth(): void
    {
        $this->createTestFile('auth', '<?php echo json_encode([
            "authorization" => $_SERVER["HTTP_AUTHORIZATION"] ?? null,
        ]);');
        $client = $this->createClient('auth.php');

        $response = $client->request('GET', 'http://localhost/auth.php', [
            'auth_bearer' => 'my-token-123',
        ]);

        $body = $response->toArray();
        $this->assertEquals('Bearer my-token-123', $body['authorization']);
    }

    // Cookies Tests
    public function testCookiesAreSent(): void
    {
        $this->createTestFile('cookies', '<?php echo json_encode($_COOKIE);');
        $client = $this->createClient('cookies.php');

        $response = $client->request('GET', 'http://localhost/cookies.php', [
            'headers' => [
                'Cookie' => 'session_id=abc123; user=john',
            ],
        ]);

        $body = $response->toArray();
        $this->assertEquals('abc123', $body['session_id']);
        $this->assertEquals('john', $body['user']);
    }

    // Response Status Tests
    public function testResponseStatusCode404(): void
    {
        $this->createTestFile('notfound', '<?php
            http_response_code(404);
            echo "Not Found";
        ');
        $client = $this->createClient('notfound.php');

        $response = $client->request('GET', 'http://localhost/notfound.php');

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertEquals('Not Found', $response->getContent(false));
    }

    public function testResponseStatusCode404Throws(): void
    {
        $this->createTestFile('notfound', '<?php
            http_response_code(404);
            echo "Not Found";
        ');
        $client = $this->createClient('notfound.php');

        $response = $client->request('GET', 'http://localhost/notfound.php');

        $this->expectException(ClientException::class);
        $response->getContent(true);
    }

    public function testResponseStatusCode500(): void
    {
        $this->createTestFile('error', '<?php
            http_response_code(500);
            echo "Internal Server Error";
        ');
        $client = $this->createClient('error.php');

        $response = $client->request('GET', 'http://localhost/error.php');

        $this->assertEquals(500, $response->getStatusCode());
    }

    public function testResponseStatusCode500Throws(): void
    {
        $this->createTestFile('error', '<?php
            http_response_code(500);
            echo "Error";
        ');
        $client = $this->createClient('error.php');

        $response = $client->request('GET', 'http://localhost/error.php');

        $this->expectException(ServerException::class);
        $response->getContent(true);
    }

    public function testRedirectStatusCode(): void
    {
        $this->createTestFile('redirect', '<?php
            http_response_code(302);
            header("Location: /target.php");
        ');
        $client = $this->createClient('redirect.php');

        $response = $client->request('GET', 'http://localhost/redirect.php');

        $this->assertEquals(302, $response->getStatusCode());
        $headers = $response->getHeaders(false);
        $this->assertArrayHasKey('location', $headers);
    }

    // Timeout Tests
    public function testTimeoutOption(): void
    {
        $this->createTestFile('slow', '<?php sleep(5); echo "Done";');
        $client = $this->createClient('slow.php');

        $this->expectException(TransportExceptionInterface::class);

        $client->request('GET', 'http://localhost/slow.php', [
            'timeout' => 0.5,
        ]);
    }

    // toArray Tests
    public function testToArrayParsesJson(): void
    {
        $this->createTestFile('json', '<?php
            header("Content-Type: application/json");
            echo json_encode(["status" => "success", "data" => [1, 2, 3]]);
        ');
        $client = $this->createClient('json.php');

        $response = $client->request('GET', 'http://localhost/json.php');
        $data = $response->toArray();

        $this->assertEquals('success', $data['status']);
        $this->assertEquals([1, 2, 3], $data['data']);
    }

    // getInfo Tests
    public function testGetInfoReturnsData(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $client = $this->createClient('index.php');

        $response = $client->request('GET', 'http://localhost/index.php');
        $info = $response->getInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('http_code', $info);
        $this->assertEquals(200, $info['http_code']);
    }

    // withOptions Tests
    public function testWithOptionsReturnsNewInstance(): void
    {
        $this->createTestFile('index', '<?php echo "OK";');
        $client = $this->createClient('index.php');

        $newClient = $client->withOptions([
            'timeout' => 30,
        ]);

        $this->assertNotSame($client, $newClient);
        $this->assertInstanceOf(CliClient::class, $newClient);
    }

    // Factory Methods Tests
    public function testCreateFactory(): void
    {
        $this->createTestFile('index', '<?php echo "Factory Test";');

        $client = CliClient::create($this->testDocumentRoot);
        $response = $client->request('GET', 'http://localhost/');

        $this->assertEquals('Factory Test', $response->getContent());
    }

    public function testCreateFromClient(): void
    {
        $this->createTestFile('test', '<?php echo "From Client";');

        $httpCliClient = new Client($this->testDocumentRoot, 'test.php');
        $client = CliClient::createFromClient($httpCliClient);
        $response = $client->request('GET', 'http://localhost/test.php');

        $this->assertEquals('From Client', $response->getContent());
    }

    // Stream Tests
    public function testStreamSingleResponse(): void
    {
        $this->createTestFile('stream', '<?php echo "Stream content";');
        $client = $this->createClient('stream.php');

        $response = $client->request('GET', 'http://localhost/stream.php');
        $stream = $client->stream($response);

        $chunks = [];
        foreach ($stream as $r => $chunk) {
            $this->assertSame($response, $r);
            $chunks[] = $chunk->getContent();
        }

        $this->assertCount(1, $chunks);
        $this->assertEquals('Stream content', $chunks[0]);
    }

    public function testStreamMultipleResponses(): void
    {
        $this->createTestFile('stream1', '<?php echo "Stream 1";');
        $this->createTestFile('stream2', '<?php echo "Stream 2";');

        $client1 = $this->createClient('stream1.php');
        $client2 = $this->createClient('stream2.php');

        $response1 = $client1->request('GET', 'http://localhost/stream1.php');
        $response2 = $client2->request('GET', 'http://localhost/stream2.php');

        $stream = $client1->stream([$response1, $response2]);

        $contents = [];
        foreach ($stream as $chunk) {
            $contents[] = $chunk->getContent();
        }

        $this->assertCount(2, $contents);
        $this->assertContains('Stream 1', $contents);
        $this->assertContains('Stream 2', $contents);
    }

    public function testChunkProperties(): void
    {
        $this->createTestFile('chunk', '<?php echo "Chunk test";');
        $client = $this->createClient('chunk.php');

        $response = $client->request('GET', 'http://localhost/chunk.php');
        $stream = $client->stream($response);

        foreach ($stream as $chunk) {
            $this->assertFalse($chunk->isTimeout());
            $this->assertTrue($chunk->isFirst());
            $this->assertTrue($chunk->isLast());
            $this->assertEquals(0, $chunk->getOffset());
            $this->assertNull($chunk->getError());
            $this->assertEmpty($chunk->getInformationalStatus());
        }
    }

    private function createClient(string $file = 'index.php'): CliClient
    {
        return new CliClient(new Client($this->testDocumentRoot, $file));
    }
}
