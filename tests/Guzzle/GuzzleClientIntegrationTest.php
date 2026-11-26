<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests\Guzzle;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use n5s\HttpCli\Client;
use n5s\HttpCli\Guzzle\CliHandler;
use n5s\HttpCli\Tests\AbstractHttpCliTestCase;

/**
 * Goes through a real GuzzleHttp\Client, which merges its own configuration
 * (handler, base_uri, protocols, factories, redirect bookkeeping) into the
 * options the handler receives.
 */
final class GuzzleClientIntegrationTest extends AbstractHttpCliTestCase
{
    private GuzzleClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestFile('index', '<?php
            $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
            switch ($path) {
                case "/redirect":
                    http_response_code(302);
                    header("Location: /target");
                    break;
                case "/target":
                    echo "target reached";
                    break;
                case "/json":
                    header("Content-Type: application/json");
                    echo json_encode([
                        "method" => $_SERVER["REQUEST_METHOD"],
                        "body" => json_decode(file_get_contents("php://input"), true),
                    ]);
                    break;
                case "/missing":
                    http_response_code(404);
                    echo "not found";
                    break;
                default:
                    echo "path " . $path;
            }
        ');

        $this->client = new GuzzleClient([
            'handler' => HandlerStack::create(new CliHandler(new Client($this->testDocumentRoot))),
            'base_uri' => 'http://localhost/',
        ]);
    }

    public function testGetRequest(): void
    {
        $response = $this->client->get('/hello');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('path /hello', (string) $response->getBody());
    }

    public function testPostJsonRequest(): void
    {
        $response = $this->client->post('/json', [
            RequestOptions::JSON => [
                'name' => 'John',
            ],
        ]);

        $this->assertSame([
            'method' => 'POST',
            'body' => [
                'name' => 'John',
            ],
        ], json_decode((string) $response->getBody(), true));
    }

    public function testRedirectIsFollowed(): void
    {
        $response = $this->client->get('/redirect');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('target reached', (string) $response->getBody());
    }

    public function testHttpErrorsThrowClientException(): void
    {
        $this->expectException(ClientException::class);

        $this->client->get('/missing');
    }

    public function testHttpErrorsDisabledReturnsResponse(): void
    {
        $response = $this->client->get('/missing', [
            RequestOptions::HTTP_ERRORS => false,
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('not found', (string) $response->getBody());
    }
}
