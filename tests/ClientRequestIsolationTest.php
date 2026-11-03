<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use n5s\HttpCli\Client;
use n5s\HttpCli\InheritEnvGlobalsHandler;
use n5s\HttpCli\RequestOptions;
use n5s\HttpCli\Runtime\InputStream;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Nothing from the request that the calling process is itself handling may reach the executed script.
 */
final class ClientRequestIsolationTest extends AbstractHttpCliTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestFile('input', '<?php echo file_get_contents("php://input");');

        // Give the calling process a request body of its own, as under PHP-FPM
        InputStream::register();
        InputStream::setRawInput('parent request body');
    }

    protected function tearDown(): void
    {
        InputStream::unregister();
        InputStream::reset();

        parent::tearDown();
    }

    #[DataProvider('requestsWithoutRawBodyProvider')]
    public function testRequestBodyOfTheCallingProcessDoesNotReachTheScript(string $method, RequestOptions $options): void
    {
        $response = (new Client($this->testDocumentRoot))->request($method, 'http://localhost/input.php', $options);

        $this->assertStringNotContainsString('parent request body', $response->getContent());
    }

    /**
     * @return iterable<string, array{string, RequestOptions}>
     */
    public static function requestsWithoutRawBodyProvider(): iterable
    {
        yield 'GET without body' => ['GET', RequestOptions::empty()];
        yield 'POST with form parameters' => ['POST', new RequestOptions(formParams: [
            'field' => 'value',
        ])];
        yield 'POST with multipart fields' => ['POST', new RequestOptions(multipart: [[
            'name' => 'field',
            'contents' => 'value',
        ]])];
    }

    public function testRequestVariablesOfTheCallingProcessAreNotInherited(): void
    {
        $this->createTestFile('server', '<?php
            $keys = ["HTTP_AUTHORIZATION", "HTTP_COOKIE", "PHP_AUTH_USER", "PHP_AUTH_PW", "HTTPS", "REMOTE_USER", "REDIRECT_HTTP_AUTHORIZATION", "N5S_TEST_APP_ENV"];
            echo json_encode([
                "server" => array_map(fn (string $key) => $_SERVER[$key] ?? null, array_combine($keys, $keys)),
                "env" => $_ENV["HTTP_X_FORWARDED_FOR"] ?? null,
            ]);
        ');

        // What $_SERVER and $_ENV hold when the calling process is itself handling a request, under PHP-FPM for example
        $parentServer = [
            'HTTP_AUTHORIZATION' => 'Basic cGFyZW50OnNlY3JldA==',
            'HTTP_COOKIE' => 'parent_session=abc',
            'PHP_AUTH_USER' => 'parent',
            'PHP_AUTH_PW' => 'secret',
            'HTTPS' => 'on',
            'REMOTE_USER' => 'parent',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Basic cGFyZW50OnNlY3JldA==',
            'N5S_TEST_APP_ENV' => 'testing',
        ];
        $previousServer = $_SERVER;
        $previousEnv = $_ENV;
        $_SERVER = $parentServer + $_SERVER;
        $_ENV['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';

        try {
            $response = (new Client($this->testDocumentRoot, globalsHandler: new InheritEnvGlobalsHandler()))
                ->request('GET', 'http://localhost/server.php');
        } finally {
            $_SERVER = $previousServer;
            $_ENV = $previousEnv;
        }

        $this->assertSame([
            'server' => [
                'HTTP_AUTHORIZATION' => null,
                'HTTP_COOKIE' => null,
                'PHP_AUTH_USER' => null,
                'PHP_AUTH_PW' => null,
                'HTTPS' => null,
                'REMOTE_USER' => null,
                'REDIRECT_HTTP_AUTHORIZATION' => null,
                // Environment variables are still inherited
                'N5S_TEST_APP_ENV' => 'testing',
            ],
            'env' => null,
        ], json_decode($response->getContent(), true));
    }

    public function testRawBodyIsStillPassed(): void
    {
        $response = (new Client($this->testDocumentRoot))->request('POST', 'http://localhost/input.php', new RequestOptions(body: 'own body'));

        $this->assertSame('own body', $response->getContent());
    }
}
