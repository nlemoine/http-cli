<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use n5s\HttpCli\Client;
use n5s\HttpCli\GlobalsHandler;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the script sees in its superglobals must not depend on the ini of the
 * PHP process that runs it. variables_order changes how PHP builds $_ENV and
 * $_REQUEST, and auto_globals_jit changes when.
 */
final class ClientSuperglobalsTest extends AbstractHttpCliTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function iniProvider(): iterable
    {
        // The value of the php.ini files PHP ships
        yield 'GPCS' => ['-d variables_order=GPCS'];
        // PHP's built-in value, used when no php.ini is loaded (official Docker images)
        yield 'EGPCS' => ['-d variables_order=EGPCS'];
        yield 'EGPCS without JIT' => ['-d variables_order=EGPCS -d auto_globals_jit=0'];
    }

    #[DataProvider('iniProvider')]
    public function testEnvHoldsOnlyWhatTheClientSends(string $iniOptions): void
    {
        $this->createTestFile('index', '<?php echo json_encode($_ENV);');

        $response = $this->client($iniOptions)->request('GET', 'http://localhost/');

        // Not the environment of the PHP process (PATH, HOME...), whatever variables_order says
        $this->assertSame([], json_decode($response->getContent(), true));
    }

    #[DataProvider('iniProvider')]
    public function testEnvFromAGlobalsHandlerReachesTheScript(string $iniOptions): void
    {
        $this->createTestFile('index', '<?php echo json_encode($_ENV);');

        $handler = new class() implements GlobalsHandler {
            public function handle(array &$globals): void
            {
                $globals['_ENV']['APP_ENV'] = 'test';
            }
        };

        $response = $this->client($iniOptions, $handler)->request('GET', 'http://localhost/');

        $this->assertSame([
            'APP_ENV' => 'test',
        ], json_decode($response->getContent(), true));
    }

    #[DataProvider('iniProvider')]
    public function testEnvSurvivesFilterInput(string $iniOptions): void
    {
        // filter_input() makes PHP build $_ENV at runtime, after the bootstrap ran
        $this->createTestFile('index', '<?php filter_input(INPUT_ENV, "APP_ENV"); echo json_encode($GLOBALS["_ENV"]);');

        $handler = new class() implements GlobalsHandler {
            public function handle(array &$globals): void
            {
                $globals['_ENV']['APP_ENV'] = 'test';
            }
        };

        $response = $this->client($iniOptions, $handler)->request('GET', 'http://localhost/');

        $this->assertSame([
            'APP_ENV' => 'test',
        ], json_decode($response->getContent(), true));
    }

    /**
     * A client whose PHP binary is a wrapper adding the given ini options.
     */
    private function client(string $iniOptions, ?GlobalsHandler $handler = null): Client
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('The PHP wrapper is a shell script');
        }

        $wrapper = $this->tempDir . '/php';
        file_put_contents($wrapper, sprintf("#!/bin/sh\nexec %s %s \"\$@\"\n", escapeshellarg(PHP_BINARY), $iniOptions));
        chmod($wrapper, 0755);

        return new Client($this->testDocumentRoot, 'index.php', $handler, $wrapper);
    }
}
