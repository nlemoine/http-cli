<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use n5s\HttpCli\Client;
use n5s\HttpCli\GlobalsHandler;
use n5s\HttpCli\RequestOptions;
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
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function requestOrderProvider(): iterable
    {
        yield 'request_order=GP' => ['-d request_order=GP', [
            'key' => 'post',
            'from_get' => '1',
        ]];
        yield 'request_order=GPC' => ['-d request_order=GPC', [
            'key' => 'cookie',
            'from_get' => '1',
        ]];
        yield 'request_order=PG' => ['-d request_order=PG', [
            'key' => 'get',
            'from_get' => '1',
        ]];
        // Set but empty: PHP builds an empty $_REQUEST, it does not fall back to variables_order
        yield 'empty request_order' => ['-d request_order= -d variables_order=EGPCS', []];
        // Not set at all (-n loads no php.ini): PHP falls back to variables_order
        yield 'request_order not set, variables_order=EGPCS' => ['-n -d variables_order=EGPCS', [
            'key' => 'cookie',
            'from_get' => '1',
        ]];
        yield 'request_order not set, variables_order=GPCS without JIT' => ['-n -d variables_order=GPCS -d auto_globals_jit=0', [
            'key' => 'cookie',
            'from_get' => '1',
        ]];
    }

    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('requestOrderProvider')]
    public function testRequestFollowsTheIniOfTheScript(string $iniOptions, array $expected): void
    {
        $this->createTestFile('index', '<?php echo json_encode($_REQUEST);');

        $response = $this->client($iniOptions)->request('POST', 'http://localhost/?key=get&from_get=1', RequestOptions::create()
            ->formParams([
                'key' => 'post',
            ])
            ->cookies([
                'key' => 'cookie',
            ])
            ->build());

        $this->assertSame($expected, json_decode($response->getContent(), true));
    }

    public function testRequestMergesNestedAndNumericKeysLikePhp(): void
    {
        $this->createTestFile('index', '<?php echo json_encode($_REQUEST);');

        $response = $this->client('-d request_order=GPC')->request('GET', 'http://localhost/?a[x]=1&0=q', RequestOptions::create()
            ->cookies([
                'a' => [
                    'y' => '2',
                ],
                0 => 'c',
            ])
            ->build());

        $this->assertSame([
            'a' => [
                'x' => '1',
                'y' => '2',
            ],
            0 => 'c',
        ], json_decode($response->getContent(), true));
    }

    public function testRequestReflectsGlobalsHandlerChangesToGet(): void
    {
        $this->createTestFile('index', '<?php echo json_encode($_REQUEST);');

        $handler = new class() implements GlobalsHandler {
            public function handle(array &$globals): void
            {
                $globals['_GET']['added'] = 'by handler';
            }
        };

        $response = $this->client('-d request_order=GP', $handler)->request('GET', 'http://localhost/?a=1');

        $this->assertSame([
            'a' => '1',
            'added' => 'by handler',
        ], json_decode($response->getContent(), true));
    }

    public function testRequestSetByAGlobalsHandlerIsKept(): void
    {
        $this->createTestFile('index', '<?php echo json_encode($_REQUEST);');

        $handler = new class() implements GlobalsHandler {
            public function handle(array &$globals): void
            {
                $globals['_REQUEST'] = [
                    'only' => 'this',
                ];
            }
        };

        $response = $this->client('-d request_order=GP', $handler)->request('GET', 'http://localhost/?a=1');

        $this->assertSame([
            'only' => 'this',
        ], json_decode($response->getContent(), true));
    }

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>, array<mixed>}>
     */
    public static function requestHandlerProvider(): iterable
    {
        yield 'removed: built by the script' => [static function (array $globals): array {
            unset($globals['_REQUEST']);

            return $globals;
        }, [
            'a' => '1',
        ]];
        yield 'null: built by the script' => [static function (array $globals): array {
            $globals['_REQUEST'] = null;

            return $globals;
        }, [
            'a' => '1',
        ]];
        yield 'emptied: kept' => [static function (array $globals): array {
            $globals['_REQUEST'] = [];

            return $globals;
        }, []];
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     * @param array<mixed> $expected
     */
    #[DataProvider('requestHandlerProvider')]
    public function testRequestChangedByAGlobalsHandler(\Closure $change, array $expected): void
    {
        $this->createTestFile('index', '<?php echo json_encode($_REQUEST);');

        $handler = new class($change) implements GlobalsHandler {
            public function __construct(
                private readonly \Closure $change
            ) {
            }

            public function handle(array &$globals): void
            {
                $globals = ($this->change)($globals);
            }
        };

        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $response = $this->client('-d request_order=GP', $handler)->request('GET', 'http://localhost/?a=1');
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame($expected, json_decode($response->getContent(), true));
    }

    private function client(string $iniOptions, ?GlobalsHandler $handler = null): Client
    {
        return new Client($this->testDocumentRoot, 'index.php', $handler, $this->phpWithIni($iniOptions));
    }
}
