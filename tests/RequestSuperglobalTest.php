<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use n5s\HttpCli\Runtime\RequestSuperglobal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RequestSuperglobalTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<mixed>}>
     */
    public static function orderProvider(): iterable
    {
        yield 'GP: cookies left out' => ['GP', [
            'key' => 'post',
            'get_only' => 'g',
            'post_only' => 'p',
        ]];
        yield 'GPC: cookies win' => ['GPC', [
            'key' => 'cookie',
            'get_only' => 'g',
            'post_only' => 'p',
            'cookie_only' => 'c',
        ]];
        yield 'CPG: GET wins' => ['CPG', [
            'key' => 'get',
            'cookie_only' => 'c',
            'post_only' => 'p',
            'get_only' => 'g',
        ]];
        yield 'EGPCS: other letters ignored' => ['EGPCS', [
            'key' => 'cookie',
            'get_only' => 'g',
            'post_only' => 'p',
            'cookie_only' => 'c',
        ]];
        yield 'lowercase' => ['gp', [
            'key' => 'post',
            'get_only' => 'g',
            'post_only' => 'p',
        ]];
        yield 'repeated letter: first position counts' => ['GPG', [
            'key' => 'post',
            'get_only' => 'g',
            'post_only' => 'p',
        ]];
        yield 'no source letter' => ['ES', []];
        yield 'empty' => ['', []];
    }

    /**
     * @param array<mixed> $expected
     */
    #[DataProvider('orderProvider')]
    public function testSourcesFollowTheOrder(string $order, array $expected): void
    {
        $request = RequestSuperglobal::build(
            [
                'key' => 'get',
                'get_only' => 'g',
            ],
            [
                'key' => 'post',
                'post_only' => 'p',
            ],
            [
                'key' => 'cookie',
                'cookie_only' => 'c',
            ],
            $order
        );

        $this->assertSame($expected, $request);
    }

    public function testNestedAndNumericKeysMergeLikePhp(): void
    {
        // What PHP itself gives for GET a[x]=1&0=q and cookie a[y]=2; 0=c with request_order=GPC
        $request = RequestSuperglobal::build(
            [
                'a' => [
                    'x' => '1',
                ],
                0 => 'q',
            ],
            [],
            [
                'a' => [
                    'y' => '2',
                ],
                0 => 'c',
            ],
            'GPC'
        );

        $this->assertSame([
            'a' => [
                'x' => '1',
                'y' => '2',
            ],
            0 => 'c',
        ], $request);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function iniOrderProvider(): iterable
    {
        yield 'request_order set' => [['-d', 'request_order=GP', '-d', 'variables_order=EGPCS'], 'GP'];
        // Set but empty: PHP builds an empty $_REQUEST, it does not fall back
        yield 'request_order empty' => [['-d', 'request_order=', '-d', 'variables_order=EGPCS'], ''];
        yield 'request_order not set' => [['-d', 'variables_order=EGPCS'], 'EGPCS'];
    }

    /**
     * @param list<string> $iniOptions
     */
    #[DataProvider('iniOrderProvider')]
    public function testOrderOfTheRunningPhp(array $iniOptions, string $expected): void
    {
        // -n: no php.ini, so request_order is only what the options set
        $process = new Process([
            PHP_BINARY,
            '-n',
            ...$iniOptions,
            '-r',
            'require $argv[1]; echo n5s\HttpCli\Runtime\RequestSuperglobal::order();',
            \dirname(__DIR__) . '/src/Runtime/RequestSuperglobal.php',
        ]);
        $process->mustRun();

        $this->assertSame($expected, $process->getOutput());
    }
}
