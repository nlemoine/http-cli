<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use n5s\HttpCli\InheritEnvGlobalsHandler;
use PHPUnit\Framework\TestCase;

final class InheritEnvGlobalsHandlerTest extends TestCase
{
    /**
     * @var array<mixed>
     */
    private array $previousServer;

    /**
     * @var array<mixed>
     */
    private array $previousEnv;

    protected function setUp(): void
    {
        $this->previousServer = $_SERVER;
        $this->previousEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->previousServer;
        $_ENV = $this->previousEnv;
    }

    public function testServerEnvironmentVariablesAreInheritedButNotRequestVariables(): void
    {
        $_SERVER['N5S_TEST_APP_ENV'] = 'testing';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic cGFyZW50OnNlY3JldA==';
        $_SERVER['REMOTE_USER'] = 'parent';

        $globals = $this->handle();

        $this->assertSame('testing', $globals['_SERVER']['N5S_TEST_APP_ENV'] ?? null);
        $this->assertArrayNotHasKey('HTTP_AUTHORIZATION', $globals['_SERVER']);
        $this->assertArrayNotHasKey('REMOTE_USER', $globals['_SERVER']);
    }

    public function testEnvEnvironmentVariablesAreInheritedButNotRequestVariables(): void
    {
        $_ENV['N5S_TEST_APP_ENV'] = 'testing';
        $_ENV['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';
        $_ENV['PHP_AUTH_PW'] = 'secret';

        $globals = $this->handle();

        $this->assertSame('testing', $globals['_ENV']['N5S_TEST_APP_ENV'] ?? null);
        $this->assertArrayNotHasKey('HTTP_X_FORWARDED_FOR', $globals['_ENV']);
        $this->assertArrayNotHasKey('PHP_AUTH_PW', $globals['_ENV']);
    }

    public function testRequestValuesTakePrecedenceOverInheritedOnes(): void
    {
        $_SERVER['N5S_TEST_APP_ENV'] = 'inherited';
        $_ENV['N5S_TEST_APP_ENV'] = 'inherited';

        $globals = $this->handle([
            'N5S_TEST_APP_ENV' => 'request',
        ], [
            'N5S_TEST_APP_ENV' => 'request',
        ]);

        $this->assertSame('request', $globals['_SERVER']['N5S_TEST_APP_ENV']);
        $this->assertSame('request', $globals['_ENV']['N5S_TEST_APP_ENV']);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $env
     * @return array<string, mixed>
     */
    private function handle(array $server = [], array $env = []): array
    {
        $globals = [
            '_ENV' => $env,
            '_GET' => [],
            '_POST' => [],
            '_COOKIE' => [],
            '_FILES' => [],
            '_SESSION' => [],
            '_SERVER' => $server,
            '_RAW_INPUT' => '',
            '_REQUEST' => [],
        ];

        (new InheritEnvGlobalsHandler())->handle($globals);

        return $globals;
    }
}
