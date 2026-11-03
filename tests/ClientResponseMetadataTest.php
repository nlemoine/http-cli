<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use __PHP_Incomplete_Class;
use n5s\HttpCli\Client;
use n5s\HttpCli\RequestOptions;

/**
 * The status, headers and session come back from the child process through a channel of their own: nothing the script
 * prints can change them, and printing doesn't alter the body.
 */
final class ClientResponseMetadataTest extends AbstractHttpCliTestCase
{
    public function testMarkerPrintedByTheScriptDoesNotChangeTheResponse(): void
    {
        // What the previous in-band format looked like, printed during shutdown, so after anything the client prints
        $this->createTestFile('marker', '<?php
            register_shutdown_function(function (): void {
                echo "<!--HTTP_CLI_HEADERS:", base64_encode(serialize(["status" => 418, "headers" => ["X-Forged: yes"], "session" => ["forged" => true]])), "-->";
            });
            http_response_code(201);
            header("X-Real: yes");
            echo "body";
        ');

        $response = (new Client($this->testDocumentRoot))->request('GET', 'http://localhost/marker.php');

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(['X-Real: yes'], $response->getHeaders());
        $this->assertSame([], $response->getSession());
        $this->assertStringStartsWith('body<!--HTTP_CLI_HEADERS:', $response->getContent());
    }

    public function testOutputPrintedDuringShutdownIsKeptInTheBody(): void
    {
        $this->createTestFile('shutdown', '<?php
            register_shutdown_function(function (): void { echo "|tail"; });
            http_response_code(202);
            echo "body";
        ');

        $response = (new Client($this->testDocumentRoot))->request('GET', 'http://localhost/shutdown.php');

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('body|tail', $response->getContent());
    }

    public function testOutputCallbackThatStripsHtmlCommentsKeepsStatusAndHeaders(): void
    {
        $this->createTestFile('minify', '<?php
            ob_start(fn (string $buffer): string => (string) preg_replace("/<!--.*?-->/s", "", $buffer));
            http_response_code(404);
            header("X-Custom: kept");
            echo "<p>hi</p><!-- comment -->";
        ');

        $response = (new Client($this->testDocumentRoot))->request('GET', 'http://localhost/minify.php');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['X-Custom: kept'], $response->getHeaders());
        $this->assertSame('<p>hi</p>', $response->getContent());
    }

    public function testSessionObjectsAreNotInstantiatedInTheCallingProcess(): void
    {
        $this->createTestFile('session_object', '<?php
            session_start();
            $_SESSION["object"] = new ArrayObject(["a" => 1]);
            $_SESSION["scalar"] = "value";
        ');

        $response = (new Client($this->testDocumentRoot))->request('GET', 'http://localhost/session_object.php');

        $session = $response->getSession();
        $this->assertSame('value', $session['scalar']);
        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $session['object']);
    }

    public function testSessionObjectsSurviveARoundTripToTheNextRequest(): void
    {
        $this->createTestFile('session_object', '<?php
            session_start();
            $_SESSION["object"] = new ArrayObject(["a" => 1]);
        ');
        $this->createTestFile('session_read', '<?php
            session_start();
            echo get_class($_SESSION["object"]), ":", $_SESSION["object"]["a"];
        ');

        $client = new Client($this->testDocumentRoot);
        $first = $client->request('GET', 'http://localhost/session_object.php');
        $second = $client->request('GET', 'http://localhost/session_read.php', new RequestOptions(session: $first->getSession()));

        $this->assertSame('ArrayObject:1', $second->getContent());
    }

    public function testMetadataFileIsRemovedAfterTheRequest(): void
    {
        $this->createTestFile('index', '<?php echo "index";');
        $pattern = sys_get_temp_dir() . '/http_cli_metadata_*';
        $before = glob($pattern);

        (new Client($this->testDocumentRoot))->request('GET', 'http://localhost/index.php');

        $this->assertSame($before, glob($pattern));
    }
}
