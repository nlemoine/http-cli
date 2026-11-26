<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use n5s\HttpCli\Runtime\RequestSuperglobal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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

    public function testOrderFallsBackToVariablesOrder(): void
    {
        $requestOrder = (string) \ini_get('request_order');
        $variablesOrder = (string) \ini_get('variables_order');

        $this->assertSame($requestOrder !== '' ? $requestOrder : $variablesOrder, RequestSuperglobal::order());
    }
}
