<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use n5s\HttpCli\Client;
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

    public function testRawBodyIsStillPassed(): void
    {
        $response = (new Client($this->testDocumentRoot))->request('POST', 'http://localhost/input.php', new RequestOptions(body: 'own body'));

        $this->assertSame('own body', $response->getContent());
    }
}
