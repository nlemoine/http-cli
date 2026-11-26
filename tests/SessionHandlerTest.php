<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use n5s\HttpCli\Client;
use n5s\HttpCli\RequestOptions;
use n5s\HttpCli\Runtime\SessionHandler;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;

final class SessionHandlerTest extends AbstractHttpCliTestCase
{
    public function testImplementsTheSessionIdInterfaces(): void
    {
        // PHP 8.6 warns when they are missing, PHP 9 will require them
        $handler = new SessionHandler([]);

        $this->assertInstanceOf(SessionIdInterface::class, $handler);
        $this->assertInstanceOf(SessionUpdateTimestampHandlerInterface::class, $handler);
    }

    public function testCreatesValidSessionIds(): void
    {
        $handler = new SessionHandler([]);

        $id = $handler->create_sid();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        $this->assertNotSame($id, $handler->create_sid());
        $this->assertTrue($handler->validateId($id));
        $this->assertTrue($handler->updateTimestamp($id, ''));
    }

    public function testSessionWorksWithDisplayErrorsOn(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('The PHP wrapper is a shell script');
        }

        // Any warning PHP emits while the bootstrap installs the handler would be printed before the script output
        $wrapper = $this->tempDir . '/php';
        file_put_contents($wrapper, sprintf("#!/bin/sh\nexec %s -d display_errors=1 -d error_reporting=-1 \"\$@\"\n", escapeshellarg(PHP_BINARY)));
        chmod($wrapper, 0755);

        $this->createTestFile('index', '<?php session_start(); $_SESSION["count"]++; echo $_SESSION["count"];');

        $response = (new Client($this->testDocumentRoot, phpExecutable: $wrapper))->request(
            'GET',
            'http://localhost/',
            RequestOptions::create()->session([
                'count' => 1,
            ])->build()
        );

        $this->assertSame('2', $response->getContent());
        $this->assertSame([
            'count' => 2,
        ], $response->getSession());
    }
}
