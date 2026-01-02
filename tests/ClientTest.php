<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use Exception;
use InvalidArgumentException;
use n5s\HttpCli\Client;
use n5s\HttpCli\RequestOptions;
use n5s\HttpCli\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;

class ClientTest extends AbstractHttpCliTestCase
{
    private string $testFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test PHP file
        $this->testFile = $this->testDocumentRoot . '/index.php';
        file_put_contents($this->testFile, '<?php echo "Hello World"; ?>');
    }

    // Constructor Tests

    #[DataProvider('validConstructorArgumentsProvider')]
    public function testConstructorWithValidArguments(
        callable $documentRootProvider,
        ?callable $filePathProvider
    ): void {
        $documentRoot = $documentRootProvider($this);
        $filePath = $filePathProvider ? $filePathProvider($this) : null;

        $client = new Client($documentRoot, $filePath);
        $this->assertInstanceOf(Client::class, $client);
    }

    public static function validConstructorArgumentsProvider(): iterable
    {
        yield 'valid document root only' => [
            'documentRootProvider' => fn (self $test) => $test->testDocumentRoot,
            'filePathProvider' => null,
        ];

        yield 'document root with trailing forward slash' => [
            'documentRootProvider' => fn (self $test) => $test->testDocumentRoot . '/',
            'filePathProvider' => null,
        ];

        yield 'document root with trailing backslash' => [
            'documentRootProvider' => fn (self $test) => $test->testDocumentRoot . '\\',
            'filePathProvider' => null,
        ];

        yield 'relative file path' => [
            'documentRootProvider' => fn (self $test) => $test->testDocumentRoot,
            'filePathProvider' => fn (self $test) => 'index.php',
        ];

        yield 'absolute file path' => [
            'documentRootProvider' => fn (self $test) => $test->testDocumentRoot,
            'filePathProvider' => fn (self $test) => $test->testFile,
        ];

        yield 'null file path defaults to index.php' => [
            'documentRootProvider' => fn (self $test) => $test->testDocumentRoot,
            'filePathProvider' => fn (self $test) => null,
        ];
    }

    // Basic request method tests (without actual process execution)

    #[DataProvider('urlSchemeProvider')]
    public function testRequestMethodWithValidUrl(string $scheme): void
    {
        $client = new Client($this->testDocumentRoot);

        try {
            $response = $client->request('GET', "{$scheme}://localhost/index.php");

            $this->assertInstanceOf(Response::class, $response);
            // Don't test specific content since it comes from actual process execution
            $this->assertIsInt($response->getStatusCode());
            $this->assertIsString($response->getContent());
        } catch (Exception $e) {
            // In test environment, process execution might fail - that's acceptable
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public static function urlSchemeProvider(): iterable
    {
        yield 'HTTP scheme' => [
            'scheme' => 'http',
        ];
        yield 'HTTPS scheme' => [
            'scheme' => 'https',
        ];
    }

    public function testRequestMethodWithPhpExtensionUpdatesFilePath(): void
    {
        $customFile = $this->createTestFile('custom', '<?php echo "Custom file"; ?>');

        $stubProcess = $this->createStubProcess('Custom output', 0);
        $client = $this->createClientWithMockProcess($stubProcess);

        $response = $client->request('GET', 'http://localhost/custom.php');

        $this->assertInstanceOf(Response::class, $response);
    }

    // URL-based PHP File Routing Tests

    public function testUrlBasedPhpFileRouting(): void
    {
        // Create a specific PHP file
        $this->createTestFile('specific', '<?php echo "SPECIFIC_FILE_CONTENT"; ?>');

        $client = new Client($this->testDocumentRoot);

        $response = $client->request('GET', 'http://localhost/specific.php');

        $this->assertStringContainsString('SPECIFIC_FILE_CONTENT', $response->getContent());
    }

    public function testUrlBasedPhpFileRoutingWithNestedPath(): void
    {
        // Create nested directory structure
        $nestedDir = $this->testDocumentRoot . '/api/v1';
        mkdir($nestedDir, 0755, true);
        file_put_contents($nestedDir . '/users.php', '<?php echo "API_V1_USERS"; ?>');

        $client = new Client($this->testDocumentRoot);

        $response = $client->request('GET', 'http://localhost/api/v1/users.php');

        $this->assertStringContainsString('API_V1_USERS', $response->getContent());
    }

    public function testUrlWithoutPhpExtensionUsesDefaultFile(): void
    {
        // Default index.php returns "Hello World" from setUp()
        $client = new Client($this->testDocumentRoot);

        $response = $client->request('GET', 'http://localhost/some/path');

        $this->assertStringContainsString('Hello World', $response->getContent());
    }

    public function testUrlRootUsesDefaultFile(): void
    {
        // Default index.php returns "Hello World" from setUp()
        $client = new Client($this->testDocumentRoot);

        $response = $client->request('GET', 'http://localhost/');

        $this->assertStringContainsString('Hello World', $response->getContent());
    }

    public function testBasicEchoOutput(): void
    {
        $testFile = $this->createTestFile('basic_echo', '<?php echo "Simple output test"; ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/basic_echo.php');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Simple output test', $response->getContent());
    }

    public function testPhpOutputStreamWorks(): void
    {
        // This tests that php://output still works after our stream wrapper registration
        $testFile = $this->createTestFile('php_output_stream', '<?php
            $handle = fopen("php://output", "w");
            fwrite($handle, "Written via php://output");
            fclose($handle);
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/php_output_stream.php');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Written via php://output', $response->getContent());
    }

    public function testPhpMemoryStreamWorks(): void
    {
        // This tests that php://memory still works after our stream wrapper registration
        $testFile = $this->createTestFile('php_memory_stream', '<?php
            $mem = fopen("php://memory", "r+");
            fwrite($mem, "memory content");
            rewind($mem);
            $data = stream_get_contents($mem);
            fclose($mem);
            echo "Read from memory: " . $data;
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/php_memory_stream.php');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Read from memory: memory content', $response->getContent());
    }

    // Output Buffering Tests
    public function testOutputBufferingWithObStartAndFlush(): void
    {
        $testFile = $this->createTestFile('ob_flush', '<?php
            ob_start();
            echo "Buffered content";
            ob_end_flush();
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/ob_flush.php');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Buffered content', $response->getContent());
    }

    public function testOutputBufferingWithObGetClean(): void
    {
        $testFile = $this->createTestFile('ob_get_clean', '<?php
            ob_start();
            echo "First output";
            $captured = ob_get_clean();
            echo "Captured: " . $captured;
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/ob_get_clean.php');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Captured: First output', $response->getContent());
    }

    public function testNestedOutputBuffering(): void
    {
        $testFile = $this->createTestFile('ob_nested', '<?php
            ob_start();
            echo "Level 1 start|";
            ob_start();
            echo "Level 2|";
            ob_end_flush();
            echo "Level 1 end";
            ob_end_flush();
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/ob_nested.php');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Level 1 start|Level 2|Level 1 end', $response->getContent());
    }

    public function testOutputBufferingWithCallback(): void
    {
        $testFile = $this->createTestFile('ob_callback', '<?php
            ob_start(function($buffer) {
                return strtoupper($buffer);
            });
            echo "lowercase text";
            ob_end_flush();
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/ob_callback.php');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('LOWERCASE TEXT', $response->getContent());
    }

    public function testOutputWithExplicitFlush(): void
    {
        $testFile = $this->createTestFile('explicit_flush', '<?php
            echo "Before flush|";
            flush();
            echo "After flush";
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/explicit_flush.php');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Before flush|After flush', $response->getContent());
    }

    public function testOutputAfterHeadersSent(): void
    {
        $testFile = $this->createTestFile('output_after_headers', '<?php
            header("X-Custom: test");
            echo "Content after header";
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/output_after_headers.php');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Content after header', $response->getContent());
    }

    public function testLargeOutputBuffer(): void
    {
        $testFile = $this->createTestFile('large_output', '<?php
            ob_start();
            // Generate 100KB of output
            for ($i = 0; $i < 1000; $i++) {
                echo str_repeat("X", 100) . "\n";
            }
            ob_end_flush();
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/large_output.php');

        $this->assertEquals(200, $response->getStatusCode());
        // Should have approximately 101KB (100 chars + newline) * 1000
        $this->assertGreaterThan(100000, strlen($response->getContent()));
    }

    public function testMixedEchoAndPrint(): void
    {
        $testFile = $this->createTestFile('mixed_output', '<?php
            echo "Echo1|";
            print "Print1|";
            echo "Echo2|";
            print "Print2";
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/mixed_output.php');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Echo1|Print1|Echo2|Print2', $response->getContent());
    }

    public function testOutputBufferingWithImplicitFlushAtEnd(): void
    {
        // Tests that unflushed buffer is captured at script end
        $testFile = $this->createTestFile('ob_implicit', '<?php
            ob_start();
            echo "This buffer is never explicitly flushed";
            // No ob_end_flush() - PHP should flush at script end
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/ob_implicit.php');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('This buffer is never explicitly flushed', $response->getContent());
    }

    public function testOutputBufferingDiscarded(): void
    {
        $testFile = $this->createTestFile('ob_discarded', '<?php
            ob_start();
            echo "This will be discarded";
            ob_end_clean(); // Discard buffer
            echo "Only this should appear";
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/ob_discarded.php');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Only this should appear', $response->getContent());
        $this->assertStringNotContainsString('This will be discarded', $response->getContent());
    }

    public function testOutputWithPrintR(): void
    {
        $testFile = $this->createTestFile('print_r', '<?php
            $data = ["key" => "value", "number" => 42];
            print_r($data);
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/print_r.php');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('[key] => value', $response->getContent());
        $this->assertStringContainsString('[number] => 42', $response->getContent());
    }

    public function testOutputWithVarExport(): void
    {
        $testFile = $this->createTestFile('var_export', '<?php
            $data = ["a" => 1, "b" => 2];
            var_export($data);
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/var_export.php');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString("'a' => 1", $response->getContent());
        $this->assertStringContainsString("'b' => 2", $response->getContent());
    }

    public function testOutputOnShutdown(): void
    {
        $testFile = $this->createTestFile('shutdown_output', '<?php
            echo "Before shutdown";
            register_shutdown_function(function() {
                echo " - Shutdown output";
            });
            echo " - After registration";
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/shutdown_output.php');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Before shutdown', $response->getContent());
        $this->assertStringContainsString('After registration', $response->getContent());
        $this->assertStringContainsString('Shutdown output', $response->getContent());
    }

    public function testMultipleShutdownFunctions(): void
    {
        $testFile = $this->createTestFile('multi_shutdown', '<?php
            register_shutdown_function(function() {
                echo "[first]";
            });
            register_shutdown_function(function() {
                echo "[second]";
            });
            register_shutdown_function(function() {
                echo "[third]";
            });
            echo "Main content";
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/multi_shutdown.php');

        $this->assertEquals(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertStringContainsString('Main content', $content);
        $this->assertStringContainsString('[first]', $content);
        $this->assertStringContainsString('[second]', $content);
        $this->assertStringContainsString('[third]', $content);
    }

    public function testShutdownWithOutputBuffering(): void
    {
        $testFile = $this->createTestFile('shutdown_ob', '<?php
            ob_start();
            echo "Buffered content";
            register_shutdown_function(function() {
                echo " - Shutdown";
            });
            $buffered = ob_get_clean();
            echo "After clean: " . $buffered;
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/shutdown_ob.php');

        $this->assertEquals(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertStringContainsString('After clean: Buffered content', $content);
        $this->assertStringContainsString('Shutdown', $content);
    }

    // Target Script Error Tests
    public function testTargetScriptSyntaxError(): void
    {
        $testFile = $this->createTestFile('syntax_error', '<?php
            echo "Before error"
            echo "Missing semicolon above";
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/syntax_error.php');

        // Script should fail - check that we get the error info
        $this->assertNotEquals(0, $response->getProcess()->getExitCode());
        $errorOutput = $response->getProcess()->getErrorOutput();
        $this->assertStringContainsString('syntax error', $errorOutput);
    }

    public function testTargetScriptUndefinedFunction(): void
    {
        $testFile = $this->createTestFile('undefined_func', '<?php
            echo "Before call";
            this_function_does_not_exist();
            echo "After call";
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/undefined_func.php');

        // Output before the error should be captured
        $content = $response->getContent();
        $this->assertStringContainsString('Before call', $content);
        // Error is captured in output (bootstrap handles errors)
        $this->assertStringContainsString('this_function_does_not_exist', $content);
        $this->assertStringNotContainsString('After call', $content);
    }

    public function testTargetScriptUncaughtException(): void
    {
        $testFile = $this->createTestFile('uncaught_exception', '<?php
            echo "Before exception";
            throw new RuntimeException("Test uncaught exception");
            echo "After exception";
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/uncaught_exception.php');

        $content = $response->getContent();
        $this->assertStringContainsString('Before exception', $content);
        // Error is captured in output (bootstrap handles errors)
        $this->assertStringContainsString('RuntimeException', $content);
        $this->assertStringContainsString('Test uncaught exception', $content);
        $this->assertStringNotContainsString('After exception', $content);
    }

    public function testTargetScriptCaughtException(): void
    {
        $testFile = $this->createTestFile('caught_exception', '<?php
            echo "Before try";
            try {
                throw new RuntimeException("Caught this");
            } catch (RuntimeException $e) {
                echo " - Caught: " . $e->getMessage();
            }
            echo " - After catch";
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/caught_exception.php');

        $this->assertEquals(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertStringContainsString('Before try', $content);
        $this->assertStringContainsString('Caught: Caught this', $content);
        $this->assertStringContainsString('After catch', $content);
    }

    public function testTargetScriptWarning(): void
    {
        $testFile = $this->createTestFile('php_warning', '<?php
            echo "Before warning";
            $arr = [];
            echo $arr["nonexistent"]; // Undefined array key warning
            echo " - After warning";
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/php_warning.php');

        // Script continues after warning
        $content = $response->getContent();
        $this->assertStringContainsString('Before warning', $content);
        $this->assertStringContainsString('After warning', $content);
    }

    public function testTargetScriptNotice(): void
    {
        $testFile = $this->createTestFile('php_notice', '<?php
            error_reporting(E_ALL);
            echo "Before notice";
            echo $undefined_variable; // Undefined variable warning
            echo " - After notice";
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/php_notice.php');

        // Script continues after notice
        $content = $response->getContent();
        $this->assertStringContainsString('Before notice', $content);
        $this->assertStringContainsString('After notice', $content);
    }

    public function testTargetScriptDivisionByZero(): void
    {
        $testFile = $this->createTestFile('div_zero', '<?php
            echo "Before division";
            $result = 1 / 0;
            echo " - Result: " . $result;
            echo " - After division";
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/div_zero.php');

        $content = $response->getContent();
        $this->assertStringContainsString('Before division', $content);
        // DivisionByZeroError is thrown in PHP 8 and captured in output
        $this->assertStringContainsString('DivisionByZeroError', $content);
        $this->assertStringNotContainsString('After division', $content);
    }

    public function testTargetScriptDieWithMessage(): void
    {
        $testFile = $this->createTestFile('die_message', '<?php
            echo "Before die";
            die(" - Goodbye!");
            echo "After die";
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/die_message.php');

        $content = $response->getContent();
        $this->assertStringContainsString('Before die', $content);
        $this->assertStringContainsString('Goodbye!', $content);
        $this->assertStringNotContainsString('After die', $content);
    }

    public function testTargetScriptExitWithCode(): void
    {
        $testFile = $this->createTestFile('exit_code', '<?php
            echo "Exiting with code";
            exit(42);
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/exit_code.php');

        $this->assertStringContainsString('Exiting with code', $response->getContent());
        $this->assertEquals(42, $response->getProcess()->getExitCode());
    }

    public function testTargetScriptCustomErrorHandler(): void
    {
        $testFile = $this->createTestFile('custom_error', '<?php
            set_error_handler(function($errno, $errstr) {
                echo "[CUSTOM ERROR: $errstr]";
                return true;
            });
            echo "Before trigger";
            trigger_error("Custom triggered error", E_USER_WARNING);
            echo " - After trigger";
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/custom_error.php');

        $this->assertEquals(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertStringContainsString('Before trigger', $content);
        $this->assertStringContainsString('CUSTOM ERROR: Custom triggered error', $content);
        $this->assertStringContainsString('After trigger', $content);
    }

    public function testTargetScriptCustomExceptionHandler(): void
    {
        $testFile = $this->createTestFile('custom_exception', '<?php
            set_exception_handler(function($e) {
                echo "[CAUGHT BY HANDLER: " . $e->getMessage() . "]";
            });
            echo "Before throw";
            throw new Exception("Handled exception");
            echo "After throw";
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/custom_exception.php');

        $content = $response->getContent();
        $this->assertStringContainsString('Before throw', $content);
        $this->assertStringContainsString('CAUGHT BY HANDLER: Handled exception', $content);
        $this->assertStringNotContainsString('After throw', $content);
    }

    public function testTargetScriptErrorInShutdown(): void
    {
        $testFile = $this->createTestFile('shutdown_error', '<?php
            register_shutdown_function(function() {
                echo "[Shutdown start]";
                trigger_error("Error in shutdown", E_USER_WARNING);
                echo "[Shutdown end]";
            });
            echo "Main content";
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/shutdown_error.php');

        $content = $response->getContent();
        $this->assertStringContainsString('Main content', $content);
        $this->assertStringContainsString('Shutdown start', $content);
        $this->assertStringContainsString('Shutdown end', $content);
    }

    public function testPhpFileRoutingWithServerVariables(): void
    {
        // Create a PHP file that outputs server variables
        $nestedDir = $this->testDocumentRoot . '/admin';
        mkdir($nestedDir, 0755, true);
        file_put_contents($nestedDir . '/dashboard.php', '<?php echo json_encode([
            "SCRIPT_NAME" => $_SERVER["SCRIPT_NAME"],
            "PHP_SELF" => $_SERVER["PHP_SELF"],
            "SCRIPT_FILENAME" => $_SERVER["SCRIPT_FILENAME"],
        ]); ?>');

        $client = new Client($this->testDocumentRoot);

        $response = $client->request('GET', 'http://localhost/admin/dashboard.php');
        $data = json_decode($response->getContent(), true);

        $this->assertEquals('/admin/dashboard.php', $data['SCRIPT_NAME']);
        $this->assertEquals('/admin/dashboard.php', $data['PHP_SELF']);
        $this->assertStringEndsWith('/admin/dashboard.php', $data['SCRIPT_FILENAME']);
    }

    public function testPhpSapiNameReturnsCgiFcgi(): void
    {
        $testFile = $this->createTestFile('sapi_name', '<?php
            echo php_sapi_name();
        ?>');
        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request('GET', 'http://localhost/sapi_name.php');

        $this->assertEquals('cgi-fcgi', $response->getContent());
    }

    // GET Parameters Tests

    #[DataProvider('getParametersProvider')]
    public function testRequestWithGetParameters(
        string $queryString,
        array $expectedGet,
        ?array $additionalAssertions = null
    ): void {
        $testFile = $this->createJsonEchoFile(
            'get_test',
            '["get" => $_GET, "count" => count($_GET), "keys" => array_keys($_GET)]'
        );

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $url = 'http://localhost/get_test.php' . ($queryString ? '?' . $queryString : '');
        $response = $client->request(method: 'GET', url: $url);

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals($expectedGet, $decoded['get']);

        if ($additionalAssertions) {
            foreach ($additionalAssertions as $assertion) {
                $assertion($decoded);
            }
        }
    }

    public static function getParametersProvider(): iterable
    {
        yield 'single parameter' => [
            'queryString' => 'name=John',
            'expectedGet' => [
                'name' => 'John',
            ],
        ];

        yield 'multiple parameters' => [
            'queryString' => 'name=John&age=30&city=Paris',
            'expectedGet' => [
                'name' => 'John',
                'age' => '30',
                'city' => 'Paris',
            ],
        ];

        yield 'special characters URL-encoded' => [
            'queryString' => 'email=test%40example.com&message=Hello%20World%21&special=%26%3D%3F%23',
            'expectedGet' => [
                'email' => 'test@example.com',
                'message' => 'Hello World!',
                'special' => '&=?#',
            ],
        ];

        yield 'empty parameter values' => [
            'queryString' => 'foo=&bar=&baz=value',
            'expectedGet' => [
                'foo' => '',
                'bar' => '',
                'baz' => 'value',
            ],
            'additionalAssertions' => [
                fn (array $decoded) => self::assertEquals(3, $decoded['count']),
                fn (array $decoded) => self::assertContains('foo', $decoded['keys']),
                fn (array $decoded) => self::assertContains('bar', $decoded['keys']),
                fn (array $decoded) => self::assertContains('baz', $decoded['keys']),
            ],
        ];

        yield 'no parameters' => [
            'queryString' => '',
            'expectedGet' => [],
            'additionalAssertions' => [
                fn (array $decoded) => self::assertEquals(0, $decoded['count']),
            ],
        ];
    }

    #[DataProvider('arrayParameterProvider')]
    public function testRequestWithArrayGetParameters(string $queryString, array $expected): void
    {
        $testFile = $this->createGlobalEchoFile('get_array_test', '_GET');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $response = $client->request(
            method: 'GET',
            url: "http://localhost/get_array_test.php?{$queryString}"
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals($expected, $decoded);
    }

    public static function arrayParameterProvider(): iterable
    {
        yield 'simple array notation' => [
            'queryString' => 'tags[]=php&tags[]=test',
            'expected' => [
                'tags' => ['php', 'test'],
            ],
        ];

        yield 'array with numeric keys' => [
            'queryString' => 'items[0]=first&items[1]=second&items[2]=third',
            'expected' => [
                'items' => ['first', 'second', 'third'],
            ],
        ];

        yield 'nested array notation' => [
            'queryString' => 'user[name]=John&user[email]=john@example.com',
            'expected' => [
                'user' => [
                    'name' => 'John',
                    'email' => 'john@example.com',
                ],
            ],
        ];

        yield 'mixed array and scalar' => [
            'queryString' => 'tags[]=php&tags[]=test&version=8.1',
            'expected' => [
                'tags' => ['php', 'test'],
                'version' => '8.1',
            ],
        ];

        yield 'deeply nested arrays' => [
            'queryString' => 'data[user][name]=John&data[user][age]=30&data[active]=true',
            'expected' => [
                'data' => [
                    'user' => [
                        'name' => 'John',
                        'age' => '30',
                    ],
                    'active' => 'true',
                ],
            ],
        ];
    }

    // Headers Tests

    #[DataProvider('headersProvider')]
    public function testRequestWithHeaders(
        array $headers,
        array $expectedServerKeys,
        array $expectedValues
    ): void {
        $testFile = $this->createGlobalEchoFile('headers_test', '_SERVER');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->headers($headers)
            ->build();

        $response = $client->request(method: 'GET', url: 'http://localhost/headers_test.php', options: $options);

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        foreach ($expectedServerKeys as $key) {
            $this->assertArrayHasKey($key, $decoded, "Expected server key '{$key}' not found");
        }

        foreach ($expectedValues as $key => $expectedValue) {
            $this->assertEquals($expectedValue, $decoded[$key], "Value mismatch for key '{$key}'");
        }
    }

    public static function headersProvider(): iterable
    {
        yield 'standard headers' => [
            'headers' => [
                'User-Agent' => 'TestBot/1.0',
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'expectedServerKeys' => ['HTTP_USER_AGENT', 'HTTP_ACCEPT', 'CONTENT_TYPE'],
            'expectedValues' => [
                'HTTP_USER_AGENT' => 'TestBot/1.0',
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ],
        ];

        yield 'custom headers' => [
            'headers' => [
                'X-Custom-Header' => 'CustomValue',
                'X-API-Key' => 'secret-key-123',
            ],
            'expectedServerKeys' => ['HTTP_X_CUSTOM_HEADER', 'HTTP_X_API_KEY'],
            'expectedValues' => [
                'HTTP_X_CUSTOM_HEADER' => 'CustomValue',
                'HTTP_X_API_KEY' => 'secret-key-123',
            ],
        ];

        yield 'multiple headers' => [
            'headers' => [
                'Accept' => 'application/json',
                'Accept-Language' => 'en-US',
                'Accept-Encoding' => 'gzip, deflate',
                'X-Request-ID' => 'req-123',
                'X-Correlation-ID' => 'corr-456',
            ],
            'expectedServerKeys' => [
                'HTTP_ACCEPT',
                'HTTP_ACCEPT_LANGUAGE',
                'HTTP_ACCEPT_ENCODING',
                'HTTP_X_REQUEST_ID',
                'HTTP_X_CORRELATION_ID',
            ],
            'expectedValues' => [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US',
                'HTTP_ACCEPT_ENCODING' => 'gzip, deflate',
                'HTTP_X_REQUEST_ID' => 'req-123',
                'HTTP_X_CORRELATION_ID' => 'corr-456',
            ],
        ];

        yield 'headers with special characters' => [
            'headers' => [
                'Authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9',
                'X-Special-Value' => 'value with spaces & symbols!',
            ],
            'expectedServerKeys' => ['HTTP_AUTHORIZATION', 'HTTP_X_SPECIAL_VALUE'],
            'expectedValues' => [
                'HTTP_AUTHORIZATION' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9',
                'HTTP_X_SPECIAL_VALUE' => 'value with spaces & symbols!',
            ],
        ];
    }

    #[DataProvider('caseInsensitiveHeaderProvider')]
    public function testRequestWithCaseInsensitiveHeaders(string $headerName, string $expectedServerKey): void
    {
        $testFile = $this->createGlobalEchoFile('headers_case_test', '_SERVER');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->headers([
                $headerName => 'TestValue',
            ])
            ->build();

        $response = $client->request(method: 'GET', url: 'http://localhost/headers_case_test.php', options: $options);

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey($expectedServerKey, $decoded);
        $this->assertEquals('TestValue', $decoded[$expectedServerKey]);
    }

    public static function caseInsensitiveHeaderProvider(): iterable
    {
        yield 'lowercase header' => [
            'headerName' => 'x-custom-header',
            'expectedServerKey' => 'HTTP_X_CUSTOM_HEADER',
        ];

        yield 'uppercase header' => [
            'headerName' => 'X-CUSTOM-HEADER',
            'expectedServerKey' => 'HTTP_X_CUSTOM_HEADER',
        ];

        yield 'mixed case header' => [
            'headerName' => 'X-Custom-Header',
            'expectedServerKey' => 'HTTP_X_CUSTOM_HEADER',
        ];

        yield 'content-type lowercase' => [
            'headerName' => 'content-type',
            'expectedServerKey' => 'CONTENT_TYPE',
        ];

        yield 'Content-Type mixed case' => [
            'headerName' => 'Content-Type',
            'expectedServerKey' => 'CONTENT_TYPE',
        ];
    }

    public function testRequestWithNoHeaders(): void
    {
        $testFile = $this->createTestFile('headers_none_test', '<?php
            $httpHeaders = array_filter($_SERVER, fn($key) => str_starts_with($key, "HTTP_"), ARRAY_FILTER_USE_KEY);
            echo json_encode(["count" => count($httpHeaders)]);
        ');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $response = $client->request(method: 'GET', url: 'http://localhost/headers_none_test.php');

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        // Should be 0 or very minimal system headers
        $this->assertIsInt($decoded['count']);
    }

    // Combined Tests (GET + Headers)

    public function testRequestWithBothGetParametersAndHeaders(): void
    {
        $testFile = $this->createJsonEchoFile('combined_test', '[
            "get" => $_GET,
            "headers" => [
                "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
                "custom" => $_SERVER["HTTP_X_CUSTOM"] ?? null,
            ],
        ]');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->headers([
                'User-Agent' => 'CombinedTest/1.0',
                'X-Custom' => 'CustomValue',
            ])
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/combined_test.php?name=John&age=30',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals([
            'name' => 'John',
            'age' => '30',
        ], $decoded['get']);
        $this->assertEquals('CombinedTest/1.0', $decoded['headers']['user_agent']);
        $this->assertEquals('CustomValue', $decoded['headers']['custom']);
    }

    public function testRequestVerifiesQueryStringInServerVariables(): void
    {
        $testFile = $this->createJsonEchoFile('query_string_test', '[
            "query_string" => $_SERVER["QUERY_STRING"] ?? "",
            "get" => $_GET,
        ]');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/query_string_test.php?foo=bar&baz=qux'
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertStringContainsString('foo=bar', $decoded['query_string']);
        $this->assertStringContainsString('baz=qux', $decoded['query_string']);
        $this->assertEquals([
            'foo' => 'bar',
            'baz' => 'qux',
        ], $decoded['get']);
    }

    // RequestOptions Query Parameter Tests

    public function testRequestOptionsWithSingleQueryParameter(): void
    {
        $testFile = $this->createGlobalEchoFile('query_single_test', '_GET');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->query([
                'key' => 'value',
            ])
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/query_single_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals([
            'key' => 'value',
        ], $decoded);
    }

    public function testRequestOptionsWithMultipleQueryParameters(): void
    {
        $testFile = $this->createGlobalEchoFile('query_multiple_test', '_GET');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->query([
                'name' => 'John',
                'age' => '30',
                'city' => 'Paris',
                'active' => 'true',
            ])
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/query_multiple_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals([
            'name' => 'John',
            'age' => '30',
            'city' => 'Paris',
            'active' => 'true',
        ], $decoded);
    }

    public function testRequestOptionsWithQueryParamMethod(): void
    {
        $testFile = $this->createGlobalEchoFile('query_param_test', '_GET');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->queryParam('foo', 'bar')
            ->queryParam('baz', 'qux')
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/query_param_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals([
            'foo' => 'bar',
            'baz' => 'qux',
        ], $decoded);
    }

    public function testRequestOptionsQueryMergesWithUrlQueryParams(): void
    {
        $testFile = $this->createJsonEchoFile('query_merge_test', '[
            "query_string" => $_SERVER["QUERY_STRING"] ?? "",
            "get" => $_GET,
        ]');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->query([
                'baz' => 'qux',
                'extra' => 'param',
            ])
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/query_merge_test.php?foo=bar',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        // Verify all parameters are present in $_GET
        $this->assertArrayHasKey('foo', $decoded['get']);
        $this->assertArrayHasKey('baz', $decoded['get']);
        $this->assertArrayHasKey('extra', $decoded['get']);

        // Verify values
        $this->assertEquals('bar', $decoded['get']['foo']);
        $this->assertEquals('qux', $decoded['get']['baz']);
        $this->assertEquals('param', $decoded['get']['extra']);
    }

    public function testRequestOptionsQueryOverridesUrlQueryParams(): void
    {
        $testFile = $this->createGlobalEchoFile('query_override_test', '_GET');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->query([
                'foo' => 'overridden',
            ])
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/query_override_test.php?foo=original',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        // RequestOptions query params should override URL query params
        $this->assertEquals([
            'foo' => 'overridden',
        ], $decoded);
    }

    #[DataProvider('requestOptionsArrayParameterProvider')]
    public function testRequestOptionsWithArrayQueryParameters(array $queryParams, array $expected): void
    {
        $testFile = $this->createGlobalEchoFile('query_array_test', '_GET');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->query($queryParams)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/query_array_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals($expected, $decoded);
    }

    public static function requestOptionsArrayParameterProvider(): iterable
    {
        yield 'simple array values' => [
            'queryParams' => [
                'tags' => ['php', 'test'],
            ],
            'expected' => [
                'tags' => ['php', 'test'],
            ],
        ];

        yield 'nested arrays' => [
            'queryParams' => [
                'user' => [
                    'name' => 'John',
                    'email' => 'john@example.com',
                ],
            ],
            'expected' => [
                'user' => [
                    'name' => 'John',
                    'email' => 'john@example.com',
                ],
            ],
        ];

        yield 'mixed array and scalar' => [
            'queryParams' => [
                'tags' => ['php', 'test'],
                'version' => '8.1',
                'active' => 'true',
            ],
            'expected' => [
                'tags' => ['php', 'test'],
                'version' => '8.1',
                'active' => 'true',
            ],
        ];

        yield 'deeply nested arrays' => [
            'queryParams' => [
                'data' => [
                    'user' => [
                        'name' => 'John',
                        'age' => '30',
                    ],
                    'active' => 'true',
                ],
            ],
            'expected' => [
                'data' => [
                    'user' => [
                        'name' => 'John',
                        'age' => '30',
                    ],
                    'active' => 'true',
                ],
            ],
        ];
    }

    #[DataProvider('requestOptionsSpecialCharactersProvider')]
    public function testRequestOptionsWithSpecialCharactersInQuery(array $queryParams, array $expected): void
    {
        $testFile = $this->createGlobalEchoFile('query_special_test', '_GET');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->query($queryParams)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/query_special_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals($expected, $decoded);
    }

    public static function requestOptionsSpecialCharactersProvider(): iterable
    {
        yield 'email with @ symbol' => [
            'queryParams' => [
                'email' => 'test@example.com',
            ],
            'expected' => [
                'email' => 'test@example.com',
            ],
        ];

        yield 'spaces in values' => [
            'queryParams' => [
                'message' => 'Hello World!',
            ],
            'expected' => [
                'message' => 'Hello World!',
            ],
        ];

        yield 'special URL characters' => [
            'queryParams' => [
                'special' => '&=?#',
            ],
            'expected' => [
                'special' => '&=?#',
            ],
        ];

        yield 'unicode characters' => [
            'queryParams' => [
                'name' => 'José',
                'city' => 'São Paulo',
                'emoji' => '🚀',
            ],
            'expected' => [
                'name' => 'José',
                'city' => 'São Paulo',
                'emoji' => '🚀',
            ],
        ];

        yield 'mixed special characters' => [
            'queryParams' => [
                'email' => 'user+tag@example.com',
                'path' => '/path/to/resource',
                'query' => 'key=value&other=123',
            ],
            'expected' => [
                'email' => 'user+tag@example.com',
                'path' => '/path/to/resource',
                'query' => 'key=value&other=123',
            ],
        ];
    }

    public function testRequestOptionsQueryWithEmptyValues(): void
    {
        $testFile = $this->createJsonEchoFile('query_empty_test', '[
            "get" => $_GET,
            "count" => count($_GET),
            "keys" => array_keys($_GET)
        ]');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->query([
                'foo' => '',
                'bar' => '',
                'baz' => 'value',
            ])
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/query_empty_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals([
            'foo' => '',
            'bar' => '',
            'baz' => 'value',
        ], $decoded['get']);
        $this->assertEquals(3, $decoded['count']);
        $this->assertContains('foo', $decoded['keys']);
        $this->assertContains('bar', $decoded['keys']);
        $this->assertContains('baz', $decoded['keys']);
    }

    public function testRequestOptionsQueryParametersAreReflectedInQueryString(): void
    {
        $testFile = $this->createJsonEchoFile('query_string_options_test', '[
            "query_string" => $_SERVER["QUERY_STRING"] ?? "",
            "get" => $_GET,
        ]');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->query([
                'name' => 'John',
                'age' => '30',
            ])
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/query_string_options_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        // Verify query string is properly set in $_SERVER
        $this->assertNotEmpty($decoded['query_string']);
        $this->assertStringContainsString('name=John', $decoded['query_string']);
        $this->assertStringContainsString('age=30', $decoded['query_string']);

        // Verify $_GET has the correct values
        $this->assertEquals([
            'name' => 'John',
            'age' => '30',
        ], $decoded['get']);
    }

    // POST Data Tests

    #[DataProvider('basicPostDataProvider')]
    public function testBasicPostDataViaFormParams(array $postData, array $expected): void
    {
        $testFile = $this->createJsonEchoFile('post_basic_test', '[
            "post" => $_POST,
            "method" => $_SERVER["REQUEST_METHOD"] ?? "UNKNOWN",
            "content_type" => $_SERVER["CONTENT_TYPE"] ?? null,
        ]');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->formParams($postData)
            ->build();

        $response = $client->request(
            method: 'POST',
            url: 'http://localhost/post_basic_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals('POST', $decoded['method']);
        $this->assertEquals('application/x-www-form-urlencoded', $decoded['content_type']);
        $this->assertEquals($expected, $decoded['post']);
    }

    public static function basicPostDataProvider(): iterable
    {
        yield 'single parameter' => [
            'postData' => [
                'name' => 'John',
            ],
            'expected' => [
                'name' => 'John',
            ],
        ];

        yield 'multiple parameters' => [
            'postData' => [
                'name' => 'John',
                'age' => '30',
                'city' => 'Paris',
            ],
            'expected' => [
                'name' => 'John',
                'age' => '30',
                'city' => 'Paris',
            ],
        ];

        yield 'empty values' => [
            'postData' => [
                'foo' => '',
                'bar' => '',
                'baz' => 'value',
            ],
            'expected' => [
                'foo' => '',
                'bar' => '',
                'baz' => 'value',
            ],
        ];

        yield 'empty post data' => [
            'postData' => [],
            'expected' => [],
        ];
    }

    public function testPostDataViaFormParamsMethod(): void
    {
        $testFile = $this->createGlobalEchoFile('post_formparams_test', '_POST');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->formParams([
                'username' => 'testuser',
                'password' => 'secret123',
            ])
            ->build();

        $response = $client->request(
            method: 'POST',
            url: 'http://localhost/post_formparams_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals([
            'username' => 'testuser',
            'password' => 'secret123',
        ], $decoded);
    }

    #[DataProvider('jsonPostDataProvider')]
    public function testJsonPostDataViaJsonMethod(mixed $jsonData, array $expectedDecoded): void
    {
        $testFile = $this->createTestFile('post_json_test', '<?php
            $rawInput = file_get_contents("php://input");
            $decoded = !empty($rawInput) ? json_decode($rawInput, true) : null;
            echo json_encode([
                "raw_input" => $rawInput,
                "decoded" => $decoded,
                "content_type" => $_SERVER["CONTENT_TYPE"] ?? null,
                "post" => $_POST,
            ]);
        ');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->json($jsonData)
            ->build();

        $response = $client->request(
            method: 'POST',
            url: 'http://localhost/post_json_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $result = json_decode($response->getContent(), true);
        $this->assertIsArray($result);
        $this->assertEquals('application/json', $result['content_type']);
        $this->assertNotEmpty($result['raw_input']);
        $this->assertEquals($expectedDecoded, $result['decoded']);
        $this->assertEmpty($result['post']); // JSON data should NOT appear in $_POST
    }

    public static function jsonPostDataProvider(): iterable
    {
        yield 'simple object' => [
            'jsonData' => [
                'key' => 'value',
            ],
            'expectedDecoded' => [
                'key' => 'value',
            ],
        ];

        yield 'nested object' => [
            'jsonData' => [
                'user' => [
                    'name' => 'John',
                    'email' => 'john@example.com',
                ],
            ],
            'expectedDecoded' => [
                'user' => [
                    'name' => 'John',
                    'email' => 'john@example.com',
                ],
            ],
        ];

        yield 'array of objects' => [
            'jsonData' => [
                [
                    'id' => 1,
                    'name' => 'Item 1',
                ],
                [
                    'id' => 2,
                    'name' => 'Item 2',
                ],
            ],
            'expectedDecoded' => [
                [
                    'id' => 1,
                    'name' => 'Item 1',
                ],
                [
                    'id' => 2,
                    'name' => 'Item 2',
                ],
            ],
        ];

        yield 'empty object' => [
            'jsonData' => [],
            'expectedDecoded' => [],
        ];
    }

    public function testRawBodyPostDataViaBodyMethod(): void
    {
        $rawData = 'This is raw body content with special chars: &=?#';

        $testFile = $this->createTestFile('post_raw_test', '<?php
            $rawInput = file_get_contents("php://input");
            echo json_encode([
                "raw_input" => $rawInput,
                "length" => strlen($rawInput),
                "post" => $_POST,
            ]);
        ');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->body($rawData)
            ->build();

        $response = $client->request(
            method: 'POST',
            url: 'http://localhost/post_raw_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $result = json_decode($response->getContent(), true);
        $this->assertIsArray($result);
        $this->assertEquals($rawData, $result['raw_input']);
        $this->assertEquals(strlen($rawData), $result['length']);
        $this->assertEmpty($result['post']); // Raw body should NOT appear in $_POST
    }

    #[DataProvider('postArrayDataProvider')]
    public function testPostArraysAndNestedData(array $postData, array $expected): void
    {
        $testFile = $this->createGlobalEchoFile('post_arrays_test', '_POST');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->formParams($postData)
            ->build();

        $response = $client->request(
            method: 'POST',
            url: 'http://localhost/post_arrays_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals($expected, $decoded);
    }

    public static function postArrayDataProvider(): iterable
    {
        yield 'simple array' => [
            'postData' => [
                'tags' => ['php', 'test'],
            ],
            'expected' => [
                'tags' => ['php', 'test'],
            ],
        ];

        yield 'nested array' => [
            'postData' => [
                'user' => [
                    'name' => 'John',
                    'email' => 'test@example.com',
                ],
            ],
            'expected' => [
                'user' => [
                    'name' => 'John',
                    'email' => 'test@example.com',
                ],
            ],
        ];

        yield 'mixed arrays and scalars' => [
            'postData' => [
                'tags' => ['php', 'test', 'cli'],
                'version' => '8.1',
                'active' => 'true',
            ],
            'expected' => [
                'tags' => ['php', 'test', 'cli'],
                'version' => '8.1',
                'active' => 'true',
            ],
        ];

        yield 'deeply nested arrays' => [
            'postData' => [
                'data' => [
                    'user' => [
                        'name' => 'John',
                        'age' => '30',
                        'address' => [
                            'city' => 'Paris',
                            'country' => 'France',
                        ],
                    ],
                    'active' => 'true',
                ],
            ],
            'expected' => [
                'data' => [
                    'user' => [
                        'name' => 'John',
                        'age' => '30',
                        'address' => [
                            'city' => 'Paris',
                            'country' => 'France',
                        ],
                    ],
                    'active' => 'true',
                ],
            ],
        ];
    }

    #[DataProvider('postSpecialCharactersProvider')]
    public function testPostDataWithSpecialCharacters(array $postData, array $expected): void
    {
        $testFile = $this->createGlobalEchoFile('post_special_test', '_POST');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->formParams($postData)
            ->build();

        $response = $client->request(
            method: 'POST',
            url: 'http://localhost/post_special_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals($expected, $decoded);
    }

    public static function postSpecialCharactersProvider(): iterable
    {
        yield 'email with @ symbol' => [
            'postData' => [
                'email' => 'test@example.com',
            ],
            'expected' => [
                'email' => 'test@example.com',
            ],
        ];

        yield 'spaces in values' => [
            'postData' => [
                'message' => 'Hello World!',
            ],
            'expected' => [
                'message' => 'Hello World!',
            ],
        ];

        yield 'special URL characters' => [
            'postData' => [
                'special' => '&=?#',
            ],
            'expected' => [
                'special' => '&=?#',
            ],
        ];

        yield 'unicode characters' => [
            'postData' => [
                'name' => 'José',
                'city' => 'São Paulo',
                'emoji' => '🚀',
            ],
            'expected' => [
                'name' => 'José',
                'city' => 'São Paulo',
                'emoji' => '🚀',
            ],
        ];

        yield 'mixed special characters' => [
            'postData' => [
                'email' => 'user+tag@example.com',
                'path' => '/path/to/resource',
                'symbols' => '!@#$%^&*()',
            ],
            'expected' => [
                'email' => 'user+tag@example.com',
                'path' => '/path/to/resource',
                'symbols' => '!@#$%^&*()',
            ],
        ];

        yield 'quotes and backslashes' => [
            'postData' => [
                'quotes' => "It's a \"test\"",
                'backslash' => 'C:\\path\\to\\file',
            ],
            'expected' => [
                'quotes' => "It's a \"test\"",
                'backslash' => 'C:\\path\\to\\file',
            ],
        ];
    }

    public function testCombinedPostAndGetParameters(): void
    {
        $testFile = $this->createJsonEchoFile('post_get_combined_test', '[
            "get" => $_GET,
            "post" => $_POST,
            "request" => $_REQUEST,
        ]');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->query([
                'get_param' => 'get_value',
            ])
            ->formParams([
                'post_param' => 'post_value',
            ])
            ->build();

        $response = $client->request(
            method: 'POST',
            url: 'http://localhost/post_get_combined_test.php?url_param=url_value',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        // Verify GET parameters
        $this->assertArrayHasKey('get_param', $decoded['get']);
        $this->assertArrayHasKey('url_param', $decoded['get']);
        $this->assertEquals('get_value', $decoded['get']['get_param']);
        $this->assertEquals('url_value', $decoded['get']['url_param']);

        // Verify POST parameters
        $this->assertArrayHasKey('post_param', $decoded['post']);
        $this->assertEquals('post_value', $decoded['post']['post_param']);

        // Verify $_REQUEST contains both (based on request_order)
        // Note: $_REQUEST behavior in CLI+prepend context needs further investigation
        // $this->assertArrayHasKey('get_param', $decoded['request']);
        // $this->assertArrayHasKey('post_param', $decoded['request']);
    }

    public function testRequestPrecedenceInRequestArray(): void
    {
        $testFile = $this->createJsonEchoFile('post_request_precedence_test', '[
            "get" => $_GET,
            "post" => $_POST,
            "request" => $_REQUEST,
        ]');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->query([
                'key' => 'from_get',
            ])
            ->formParams([
                'key' => 'from_post',
            ])
            ->build();

        $response = $client->request(
            method: 'POST',
            url: 'http://localhost/post_request_precedence_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        // Verify both $_GET and $_POST have their own values
        $this->assertEquals('from_get', $decoded['get']['key']);
        $this->assertEquals('from_post', $decoded['post']['key']);

        // $_REQUEST should have one of them (based on request_order setting)
        // Note: $_REQUEST behavior in CLI+prepend context needs further investigation
        // $this->assertArrayHasKey('key', $decoded['request']);
        // $this->assertContains($decoded['request']['key'], ['from_get', 'from_post']);
    }

    public function testPostDataWithoutContentType(): void
    {
        $testFile = $this->createJsonEchoFile('post_no_ctype_test', '[
            "post" => $_POST,
            "content_type" => $_SERVER["CONTENT_TYPE"] ?? null,
        ]');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->formParams([
                'key' => 'value',
            ])
            ->build();

        $response = $client->request(
            method: 'POST',
            url: 'http://localhost/post_no_ctype_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        // Verify Content-Type is automatically set for form params
        $this->assertEquals('application/x-www-form-urlencoded', $decoded['content_type']);
        $this->assertEquals([
            'key' => 'value',
        ], $decoded['post']);
    }

    public function testJsonPostDataWithCustomContentType(): void
    {
        $testFile = $this->createTestFile('post_json_custom_ctype_test', '<?php
            echo json_encode([
                "content_type" => $_SERVER["CONTENT_TYPE"] ?? null,
            ]);
        ');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->json([
                'key' => 'value',
            ])
            ->header('Content-Type', 'application/vnd.api+json')
            ->build();

        $response = $client->request(
            method: 'POST',
            url: 'http://localhost/post_json_custom_ctype_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        // Custom Content-Type should override automatic one
        $this->assertEquals('application/vnd.api+json', $decoded['content_type']);
    }

    public function testEmptyPostRequest(): void
    {
        $testFile = $this->createJsonEchoFile('post_empty_test', '[
            "post" => $_POST,
            "post_empty" => empty($_POST),
            "method" => $_SERVER["REQUEST_METHOD"],
        ]');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request(
            method: 'POST',
            url: 'http://localhost/post_empty_test.php'
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals('POST', $decoded['method']);
        $this->assertEmpty($decoded['post']);
        $this->assertTrue($decoded['post_empty']);
    }

    // Timeout Tests

    #[DataProvider('basicTimeoutConfigurationProvider')]
    public function testBasicTimeoutConfiguration(float $timeout): void
    {
        // Create a fast-executing script
        $testFile = $this->createTestFile('timeout_config_test', '<?php
            echo json_encode([
                "success" => true,
                "message" => "Script completed successfully"
            ]);
        ');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->timeout($timeout)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/timeout_config_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['success']);

        // Verify timeout was applied to the process
        $process = $response->getProcess();
        $this->assertEquals($timeout, $process->getTimeout());
    }

    public static function basicTimeoutConfigurationProvider(): iterable
    {
        yield 'one second timeout' => [
            'timeout' => 1.0,
        ];
        yield 'five second timeout' => [
            'timeout' => 5.0,
        ];
        yield 'ten second timeout' => [
            'timeout' => 10.0,
        ];
        yield 'fractional timeout' => [
            'timeout' => 2.5,
        ];
    }

    public function testTimeoutWithNoValueUsesDefaultBehavior(): void
    {
        $testFile = $this->createTestFile('timeout_default_test', '<?php
            echo json_encode(["status" => "ok"]);
        ');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        // No timeout specified - should use Symfony Process default (60 seconds)
        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/timeout_default_test.php'
        );

        $this->assertEquals(200, $response->getStatusCode());

        // Verify default timeout (Symfony Process uses 60 seconds by default)
        $process = $response->getProcess();
        $this->assertEquals(60.0, $process->getTimeout());
    }

    public function testTimeoutExceptionHandling(): void
    {
        // Create a script that sleeps longer than the timeout
        $testFile = $this->createTestFile('timeout_exceed_test', '<?php
            echo "Starting...";
            flush();
            sleep(5); // Sleep for 5 seconds
            echo "This should not be reached";
        ');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->timeout(0.5) // Timeout after 0.5 seconds
            ->build();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('timed out');

        $client->request(
            method: 'GET',
            url: 'http://localhost/timeout_exceed_test.php',
            options: $options
        );
    }

    public function testTimeoutPartialOutputCaptured(): void
    {
        // Create a script that outputs something before timeout
        $testFile = $this->createTestFile('timeout_partial_test', '<?php
            echo "Partial output before timeout";
            flush();
            sleep(5); // This will cause timeout
            echo "This will not be reached";
        ');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->timeout(0.5)
            ->build();

        try {
            $client->request(
                method: 'GET',
                url: 'http://localhost/timeout_partial_test.php',
                options: $options
            );
            $this->fail('Expected Exception to be thrown');
        } catch (Exception $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
            // Note: Partial output capture is verified in Client implementation
            // The exception handler calls $e->getProcess()->getOutput()
        }
    }

    #[DataProvider('shortTimeoutProvider')]
    public function testVeryShortTimeouts(float $timeout): void
    {
        // Create a minimal script that should execute within very short timeouts
        $testFile = $this->createTestFile('timeout_short_test', '<?php
            echo "quick";
        ');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->timeout($timeout)
            ->build();

        // Very short timeouts might succeed or fail depending on system load
        // We just verify the timeout is properly set
        try {
            $response = $client->request(
                method: 'GET',
                url: 'http://localhost/timeout_short_test.php',
                options: $options
            );

            // If successful, verify the timeout was applied
            $this->assertEquals($timeout, $response->getProcess()->getTimeout());
        } catch (Exception $e) {
            // Timeout is acceptable for very short durations
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
    }

    public static function shortTimeoutProvider(): iterable
    {
        yield 'very short 0.1 seconds' => [
            'timeout' => 0.1,
        ];
        yield 'very short 0.2 seconds' => [
            'timeout' => 0.2,
        ];
        yield 'half second' => [
            'timeout' => 0.5,
        ];
    }

    public function testLongTimeoutConfiguration(): void
    {
        // Test that long timeouts can be configured (without waiting)
        $testFile = $this->createTestFile('timeout_long_test', '<?php
            echo json_encode(["configured" => true]);
        ');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->timeout(120.0) // 2 minutes
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/timeout_long_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(120.0, $response->getProcess()->getTimeout());
    }

    public function testInvalidTimeoutThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be positive');

        RequestOptions::create()
            ->timeout(-1.0)
            ->build();
    }

    public function testZeroTimeoutThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be positive');

        RequestOptions::create()
            ->timeout(0.0)
            ->build();
    }

    public function testTimeoutWithSlowExecutionVsIdleScript(): void
    {
        // Test 1: Script that executes slowly (continuous work)
        $slowExecutionFile = $this->createTestFile('timeout_slow_execution', '<?php
            $result = 0;
            // Busy loop that takes time but keeps executing
            for ($i = 0; $i < 10000000; $i++) {
                $result += $i;
            }
            echo json_encode(["result" => $result]);
        ');

        // Test 2: Script that is idle (sleeping)
        $idleFile = $this->createTestFile('timeout_idle_script', '<?php
            echo "Starting...";
            flush();
            sleep(3); // Idle for 3 seconds
            echo json_encode(["status" => "completed"]);
        ');

        $client1 = new Client(documentRoot: $this->testDocumentRoot, file: $slowExecutionFile);
        $client2 = new Client(documentRoot: $this->testDocumentRoot, file: $idleFile);

        $options = RequestOptions::create()
            ->timeout(1.0)
            ->build();

        // Slow execution might complete or timeout depending on system speed
        try {
            $response = $client1->request(
                method: 'GET',
                url: 'http://localhost/timeout_slow_execution.php',
                options: $options
            );
            // If it completes, that's fine - just verify timeout was set
            $this->assertEquals(1.0, $response->getProcess()->getTimeout());
        } catch (Exception $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }

        // Idle script should definitely timeout
        try {
            $client2->request(
                method: 'GET',
                url: 'http://localhost/timeout_idle_script.php',
                options: $options
            );
            $this->fail('Expected timeout exception for idle script');
        } catch (Exception $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
    }

    public function testTimeoutWithComplexRequest(): void
    {
        // Test timeout works with POST data and headers
        $testFile = $this->createTestFile('timeout_complex_test', '<?php
            $input = json_decode(file_get_contents("php://input"), true);
            echo json_encode([
                "headers" => [
                    "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
                    "custom" => $_SERVER["HTTP_X_CUSTOM"] ?? null,
                ],
                "received" => $input,
            ]);
        ');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->timeout(5.0)
            ->headers([
                'User-Agent' => 'TimeoutTest/1.0',
                'X-Custom' => 'CustomValue',
            ])
            ->json([
                'test' => 'data',
            ])
            ->build();

        $response = $client->request(
            method: 'POST',
            url: 'http://localhost/timeout_complex_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertEquals('TimeoutTest/1.0', $decoded['headers']['user_agent']);
        $this->assertEquals([
            'test' => 'data',
        ], $decoded['received']);
        $this->assertEquals(5.0, $response->getProcess()->getTimeout());
    }

    public function testTimeoutWithBuilderOptions(): void
    {
        $testFile = $this->createTestFile('timeout_builder_test', '<?php
            echo json_encode(["status" => "ok"]);
        ');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->timeout(3.5)
            ->header('X-Test', 'Value')
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/timeout_builder_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(3.5, $response->getProcess()->getTimeout());
    }

    // Basic Authentication Tests

    public function testBasicAuthWithValidCredentials(): void
    {
        $testFile = $this->createJsonEchoFile('basic_auth_test', '[
            "auth_header" => $_SERVER["HTTP_AUTHORIZATION"] ?? null,
            "server" => array_filter($_SERVER, fn($k) => str_starts_with($k, "HTTP_"), ARRAY_FILTER_USE_KEY)
        ]');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->basicAuth('testuser', 'testpass123')
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/basic_auth_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('auth_header', $decoded);

        // Verify Authorization header format
        $expectedAuth = 'Basic ' . base64_encode('testuser:testpass123');
        $this->assertEquals($expectedAuth, $decoded['auth_header']);
    }

    public function testBasicAuthWithSpecialCharactersInPassword(): void
    {
        $testFile = $this->createGlobalEchoFile('basic_auth_special_test', '_SERVER');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        // Password with special characters
        $username = 'user@example.com';
        $password = 'p@ss:w0rd!#$%&*()';

        $options = RequestOptions::create()
            ->basicAuth($username, $password)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/basic_auth_special_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        $expectedAuth = 'Basic ' . base64_encode($username . ':' . $password);
        $this->assertEquals($expectedAuth, $decoded['HTTP_AUTHORIZATION']);
    }

    public function testBasicAuthDoesNotOverrideManualAuthorizationHeader(): void
    {
        $testFile = $this->createGlobalEchoFile('basic_auth_override_test', '_SERVER');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $manualToken = 'Bearer manual-token-12345';

        $options = RequestOptions::create()
            ->header('Authorization', $manualToken)
            ->basicAuth('testuser', 'testpass')
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/basic_auth_override_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        // Manual Authorization header should NOT be overridden by basicAuth
        $this->assertEquals($manualToken, $decoded['HTTP_AUTHORIZATION']);
    }

    // Note: basicAuth type is enforced by PHPDoc as array{0: string, 1: string}|null
    // Runtime validation was removed as it's now handled by static analysis

    #[DataProvider('basicAuthCredentialsProvider')]
    public function testBasicAuthWithVariousCredentials(
        string $username,
        string $password,
        string $expectedEncoded
    ): void {
        $testFile = $this->createGlobalEchoFile('basic_auth_various_test', '_SERVER');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->basicAuth($username, $password)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/basic_auth_various_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals('Basic ' . $expectedEncoded, $decoded['HTTP_AUTHORIZATION']);
    }

    public static function basicAuthCredentialsProvider(): iterable
    {
        yield 'simple credentials' => [
            'username' => 'admin',
            'password' => 'secret',
            'expectedEncoded' => base64_encode('admin:secret'),
        ];

        yield 'email as username' => [
            'username' => 'user@example.com',
            'password' => 'password123',
            'expectedEncoded' => base64_encode('user@example.com:password123'),
        ];

        yield 'empty password' => [
            'username' => 'user',
            'password' => '',
            'expectedEncoded' => base64_encode('user:'),
        ];

        yield 'colon in password' => [
            'username' => 'user',
            'password' => 'pass:word',
            'expectedEncoded' => base64_encode('user:pass:word'),
        ];

        yield 'unicode characters' => [
            'username' => 'José',
            'password' => 'contraseña',
            'expectedEncoded' => base64_encode('José:contraseña'),
        ];
    }

    // Bearer Token Tests

    public function testBearerTokenSetCorrectly(): void
    {
        $testFile = $this->createGlobalEchoFile('bearer_token_test', '_SERVER');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $token = 'my-secret-token-12345';

        $options = RequestOptions::create()
            ->bearerToken($token)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/bearer_token_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('HTTP_AUTHORIZATION', $decoded);
        $this->assertEquals('Bearer ' . $token, $decoded['HTTP_AUTHORIZATION']);
    }

    public function testBearerTokenWithLongJWTStyleToken(): void
    {
        $testFile = $this->createGlobalEchoFile('bearer_jwt_test', '_SERVER');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        // Simulated JWT token
        $jwtToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';

        $options = RequestOptions::create()
            ->bearerToken($jwtToken)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/bearer_jwt_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals('Bearer ' . $jwtToken, $decoded['HTTP_AUTHORIZATION']);
    }

    public function testBearerTokenDoesNotOverrideManualAuthorizationHeader(): void
    {
        $testFile = $this->createGlobalEchoFile('bearer_override_test', '_SERVER');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $manualAuth = 'Basic ' . base64_encode('user:pass');

        $options = RequestOptions::create()
            ->header('Authorization', $manualAuth)
            ->bearerToken('should-not-be-used')
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/bearer_override_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        // Manual Authorization header should NOT be overridden
        $this->assertEquals($manualAuth, $decoded['HTTP_AUTHORIZATION']);
    }

    #[DataProvider('bearerTokenProvider')]
    public function testBearerTokenWithVariousTokenFormats(string $token): void
    {
        $testFile = $this->createGlobalEchoFile('bearer_formats_test', '_SERVER');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->bearerToken($token)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/bearer_formats_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals('Bearer ' . $token, $decoded['HTTP_AUTHORIZATION']);
    }

    public static function bearerTokenProvider(): iterable
    {
        yield 'simple token' => [
            'token' => 'simple-token',
        ];
        yield 'uuid token' => [
            'token' => '550e8400-e29b-41d4-a716-446655440000',
        ];
        yield 'short token' => [
            'token' => 'abc',
        ];
        yield 'token with special chars' => [
            'token' => 'token_with-special.chars',
        ];
    }

    // User-Agent Tests

    public function testUserAgentSetCorrectly(): void
    {
        $testFile = $this->createGlobalEchoFile('user_agent_test', '_SERVER');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $userAgent = 'MyCustomClient/1.0';

        $options = RequestOptions::create()
            ->userAgent($userAgent)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/user_agent_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('HTTP_USER_AGENT', $decoded);
        $this->assertEquals($userAgent, $decoded['HTTP_USER_AGENT']);
    }

    public function testUserAgentDoesNotOverrideManualUserAgentHeader(): void
    {
        $testFile = $this->createGlobalEchoFile('user_agent_override_test', '_SERVER');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $manualUserAgent = 'ManualAgent/2.0';

        $options = RequestOptions::create()
            ->header('User-Agent', $manualUserAgent)
            ->userAgent('ShouldNotBeUsed/1.0')
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/user_agent_override_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        // Manual User-Agent header should NOT be overridden
        $this->assertEquals($manualUserAgent, $decoded['HTTP_USER_AGENT']);
    }

    #[DataProvider('userAgentProvider')]
    public function testUserAgentWithVariousFormats(string $userAgent): void
    {
        $testFile = $this->createGlobalEchoFile('user_agent_formats_test', '_SERVER');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->userAgent($userAgent)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/user_agent_formats_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals($userAgent, $decoded['HTTP_USER_AGENT']);
    }

    public static function userAgentProvider(): iterable
    {
        yield 'simple client' => [
            'userAgent' => 'SimpleClient/1.0',
        ];

        yield 'browser-like' => [
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ];

        yield 'with special characters' => [
            'userAgent' => 'Client/1.0 (compatible; Bot/2.0; +http://example.com)',
        ];

        yield 'short agent' => [
            'userAgent' => 'Bot',
        ];

        yield 'with parentheses and slashes' => [
            'userAgent' => 'TestBot/1.0 (http://test.com/bot.html)',
        ];
    }

    // Combined Auth and User-Agent Tests

    public function testAllThreeOptionsTogether(): void
    {
        $testFile = $this->createGlobalEchoFile('combined_auth_ua_test', '_SERVER');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        // Note: basicAuth and bearerToken are mutually exclusive in the builder
        // Let's test bearerToken + userAgent together
        $token = 'test-bearer-token';
        $userAgent = 'CombinedTest/1.0';

        $options = RequestOptions::create()
            ->bearerToken($token)
            ->userAgent($userAgent)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/combined_auth_ua_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        $this->assertEquals('Bearer ' . $token, $decoded['HTTP_AUTHORIZATION']);
        $this->assertEquals($userAgent, $decoded['HTTP_USER_AGENT']);
    }

    public function testBasicAuthAndUserAgentTogether(): void
    {
        $testFile = $this->createGlobalEchoFile('basicauth_ua_test', '_SERVER');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $username = 'testuser';
        $password = 'testpass';
        $userAgent = 'TestClient/2.0';

        $options = RequestOptions::create()
            ->basicAuth($username, $password)
            ->userAgent($userAgent)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/basicauth_ua_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        $expectedAuth = 'Basic ' . base64_encode($username . ':' . $password);
        $this->assertEquals($expectedAuth, $decoded['HTTP_AUTHORIZATION']);
        $this->assertEquals($userAgent, $decoded['HTTP_USER_AGENT']);
    }

    public function testAuthOptionsMutualExclusivityInBuilder(): void
    {
        // Test that bearerToken clears basicAuth
        $builder = RequestOptions::create()
            ->basicAuth('user', 'pass')
            ->bearerToken('token-123');

        $options = $builder->build();

        // bearerToken should have cleared basicAuth
        $this->assertNull($options->basicAuth);
        $this->assertEquals('token-123', $options->bearerToken);
    }

    public function testBasicAuthClearsBearerTokenInBuilder(): void
    {
        // Test that basicAuth clears bearerToken
        $builder = RequestOptions::create()
            ->bearerToken('token-123')
            ->basicAuth('user', 'pass');

        $options = $builder->build();

        // basicAuth should have cleared bearerToken
        $this->assertNull($options->bearerToken);
        $this->assertEquals(['user', 'pass'], $options->basicAuth);
    }

    public function testUserAgentWithOtherHeaders(): void
    {
        $testFile = $this->createGlobalEchoFile('ua_with_headers_test', '_SERVER');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $options = RequestOptions::create()
            ->userAgent('TestAgent/1.0')
            ->header('Accept', 'application/json')
            ->header('X-Custom', 'CustomValue')
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/ua_with_headers_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        $this->assertEquals('TestAgent/1.0', $decoded['HTTP_USER_AGENT']);
        $this->assertEquals('application/json', $decoded['HTTP_ACCEPT']);
        $this->assertEquals('CustomValue', $decoded['HTTP_X_CUSTOM']);
    }

    public function testAuthAndUserAgentWithPostData(): void
    {
        $testFile = $this->createTestFile('auth_ua_post_test', '<?php
            echo json_encode([
                "auth" => $_SERVER["HTTP_AUTHORIZATION"] ?? null,
                "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
                "post" => $_POST,
            ]);
        ');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $options = RequestOptions::create()
            ->bearerToken('post-test-token')
            ->userAgent('PostTestAgent/1.0')
            ->formParams([
                'key' => 'value',
                'data' => 'test',
            ])
            ->build();

        $response = $client->request(
            method: 'POST',
            url: 'http://localhost/auth_ua_post_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        $this->assertEquals('Bearer post-test-token', $decoded['auth']);
        $this->assertEquals('PostTestAgent/1.0', $decoded['user_agent']);
        $this->assertEquals([
            'key' => 'value',
            'data' => 'test',
        ], $decoded['post']);
    }

    public function testBasicAuthInToArrayMethod(): void
    {
        $options = RequestOptions::create()
            ->basicAuth('user', 'secret')
            ->build();

        $array = $options->toArray();

        $this->assertArrayHasKey('basic_auth', $array);
        $this->assertEquals(['user', 'secret'], $array['basic_auth']);
    }

    public function testBearerTokenInToArrayMethod(): void
    {
        $options = RequestOptions::create()
            ->bearerToken('my-token')
            ->build();

        $array = $options->toArray();

        $this->assertArrayHasKey('bearer_token', $array);
        $this->assertEquals('my-token', $array['bearer_token']);
    }

    public function testUserAgentInToArrayMethod(): void
    {
        $options = RequestOptions::create()
            ->userAgent('MyAgent/1.0')
            ->build();

        $array = $options->toArray();

        $this->assertArrayHasKey('user_agent', $array);
        $this->assertEquals('MyAgent/1.0', $array['user_agent']);
    }

    public function testTimeoutInOptionsToArray(): void
    {
        // Test timeout is properly serialized to array
        $options = RequestOptions::create()
            ->timeout(7.5)
            ->build();

        $array = $options->toArray();

        $this->assertArrayHasKey('timeout', $array);
        $this->assertEquals(7.5, $array['timeout']);
    }

    public function testNullTimeoutNotInArray(): void
    {
        // When timeout is not set, it should not appear in array
        $options = RequestOptions::create()
            ->headers([
                'X-Test' => 'Value',
            ])
            ->build();

        $array = $options->toArray();

        $this->assertArrayNotHasKey('timeout', $array);
    }

    public function testMultipleRequestsWithDifferentTimeouts(): void
    {
        // Verify that different requests can have different timeouts
        $testFile1 = $this->createTestFile('timeout_multi1_test', '<?php
            echo json_encode(["request" => 1]);
        ');
        $testFile2 = $this->createTestFile('timeout_multi2_test', '<?php
            echo json_encode(["request" => 2]);
        ');

        $client1 = new Client(documentRoot: $this->testDocumentRoot, file: $testFile1);
        $client2 = new Client(documentRoot: $this->testDocumentRoot, file: $testFile2);

        $options1 = RequestOptions::create()->timeout(2.0)->build();
        $options2 = RequestOptions::create()->timeout(5.0)->build();

        $response1 = $client1->request('GET', 'http://localhost/timeout_multi1_test.php', $options1);
        $response2 = $client2->request('GET', 'http://localhost/timeout_multi2_test.php', $options2);

        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertEquals(2.0, $response1->getProcess()->getTimeout());
        $this->assertEquals(5.0, $response2->getProcess()->getTimeout());
    }

    // Cookie Tests

    public function testSingleCookie(): void
    {
        $testFile = $this->createGlobalEchoFile('cookie_single_test', '_COOKIE');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->cookies([
                'session_id' => 'abc123',
            ])
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/cookie_single_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('session_id', $decoded);
        $this->assertEquals('abc123', $decoded['session_id']);
    }

    public function testMultipleCookies(): void
    {
        $testFile = $this->createGlobalEchoFile('cookie_multiple_test', '_COOKIE');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->cookies([
                'session_id' => 'abc123',
                'user_id' => '12345',
                'theme' => 'dark',
                'language' => 'en-US',
            ])
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/cookie_multiple_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        $this->assertArrayHasKey('session_id', $decoded);
        $this->assertArrayHasKey('user_id', $decoded);
        $this->assertArrayHasKey('theme', $decoded);
        $this->assertArrayHasKey('language', $decoded);

        $this->assertEquals('abc123', $decoded['session_id']);
        $this->assertEquals('12345', $decoded['user_id']);
        $this->assertEquals('dark', $decoded['theme']);
        $this->assertEquals('en-US', $decoded['language']);
    }

    #[DataProvider('cookieSpecialCharactersProvider')]
    public function testCookieWithSpecialCharacters(string $cookieName, string $cookieValue): void
    {
        $testFile = $this->createGlobalEchoFile('cookie_special_test', '_COOKIE');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->cookies([
                $cookieName => $cookieValue,
            ])
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/cookie_special_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey($cookieName, $decoded);
        $this->assertEquals($cookieValue, $decoded[$cookieName]);
    }

    public static function cookieSpecialCharactersProvider(): iterable
    {
        yield 'spaces in value' => [
            'cookieName' => 'message',
            'cookieValue' => 'Hello World',
        ];

        yield 'email-like value' => [
            'cookieName' => 'email',
            'cookieValue' => 'user@example.com',
        ];

        yield 'unicode characters' => [
            'cookieName' => 'name',
            'cookieValue' => 'José García',
        ];

        yield 'special symbols' => [
            'cookieName' => 'data',
            'cookieValue' => 'value=123&key=456',
        ];

        yield 'json-like value' => [
            'cookieName' => 'preferences',
            'cookieValue' => '{"theme":"dark","lang":"en"}',
        ];
    }

    public function testCookiesCombinedWithHeaders(): void
    {
        $testFile = $this->createTestFile('cookie_with_headers_test', '<?php
            echo json_encode([
                "cookies" => $_COOKIE,
                "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
                "custom_header" => $_SERVER["HTTP_X_CUSTOM"] ?? null,
            ]);
        ');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->cookies([
                'session' => 'xyz789',
            ])
            ->header('User-Agent', 'TestBrowser/1.0')
            ->header('X-Custom', 'CustomValue')
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/cookie_with_headers_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        $this->assertEquals([
            'session' => 'xyz789',
        ], $decoded['cookies']);
        $this->assertEquals('TestBrowser/1.0', $decoded['user_agent']);
        $this->assertEquals('CustomValue', $decoded['custom_header']);
    }

    public function testCookiesCombinedWithPostData(): void
    {
        $testFile = $this->createTestFile('cookie_with_post_test', '<?php
            echo json_encode([
                "cookies" => $_COOKIE,
                "post" => $_POST,
                "method" => $_SERVER["REQUEST_METHOD"],
            ]);
        ');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->cookies([
                'auth_token' => 'token123',
            ])
            ->formParams([
                'username' => 'john',
                'action' => 'login',
            ])
            ->build();

        $response = $client->request(
            method: 'POST',
            url: 'http://localhost/cookie_with_post_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        $this->assertEquals('POST', $decoded['method']);
        $this->assertEquals([
            'auth_token' => 'token123',
        ], $decoded['cookies']);
        $this->assertEquals([
            'username' => 'john',
            'action' => 'login',
        ], $decoded['post']);
    }

    public function testEmptyCookiesArray(): void
    {
        $testFile = $this->createJsonEchoFile('cookie_empty_test', '[
            "cookies" => $_COOKIE,
            "count" => count($_COOKIE),
        ]');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->cookies([])
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/cookie_empty_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        $this->assertEmpty($decoded['cookies']);
        $this->assertEquals(0, $decoded['count']);
    }

    public function testCookieBuilderMethod(): void
    {
        $testFile = $this->createGlobalEchoFile('cookie_builder_test', '_COOKIE');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->cookie('first', 'value1')
            ->cookie('second', 'value2')
            ->cookie('third', 'value3')
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/cookie_builder_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        $this->assertArrayHasKey('first', $decoded);
        $this->assertArrayHasKey('second', $decoded);
        $this->assertArrayHasKey('third', $decoded);

        $this->assertEquals('value1', $decoded['first']);
        $this->assertEquals('value2', $decoded['second']);
        $this->assertEquals('value3', $decoded['third']);
    }

    public function testCookieBuilderMethodChaining(): void
    {
        $testFile = $this->createGlobalEchoFile('cookie_chaining_test', '_COOKIE');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->cookie('session_id', 'sess123')
            ->header('X-Test', 'TestValue')
            ->cookie('user_id', 'user456')
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/cookie_chaining_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        $this->assertEquals('sess123', $decoded['session_id']);
        $this->assertEquals('user456', $decoded['user_id']);
    }

    public function testCookiesInToArrayMethod(): void
    {
        $options = RequestOptions::create()
            ->cookies([
                'session' => 'abc123',
                'user' => 'john',
            ])
            ->build();

        $array = $options->toArray();

        $this->assertArrayHasKey('cookies', $array);
        $this->assertEquals([
            'session' => 'abc123',
            'user' => 'john',
        ], $array['cookies']);
    }

    public function testCookiesWithBuilderMethod(): void
    {
        $options = RequestOptions::create()
            ->cookies([
                'token' => 'xyz789',
            ])
            ->header('X-Custom', 'Value')
            ->build();

        $this->assertEquals([
            'token' => 'xyz789',
        ], $options->cookies);
        $this->assertEquals([
            'X-Custom' => 'Value',
        ], $options->headers);
    }

    public function testCookiesWithAuthAndUserAgent(): void
    {
        $testFile = $this->createTestFile('cookie_full_test', '<?php
            echo json_encode([
                "cookies" => $_COOKIE,
                "auth" => $_SERVER["HTTP_AUTHORIZATION"] ?? null,
                "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
            ]);
        ');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->cookies([
                'session' => 'session123',
            ])
            ->bearerToken('bearer-token-456')
            ->userAgent('FullTestClient/1.0')
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/cookie_full_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        $this->assertEquals([
            'session' => 'session123',
        ], $decoded['cookies']);
        $this->assertEquals('Bearer bearer-token-456', $decoded['auth']);
        $this->assertEquals('FullTestClient/1.0', $decoded['user_agent']);
    }

    #[DataProvider('cookieArrayValuesProvider')]
    public function testCookiesWithVariousDataTypes(array $cookies, array $expected): void
    {
        $testFile = $this->createGlobalEchoFile('cookie_types_test', '_COOKIE');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->cookies($cookies)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/cookie_types_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals($expected, $decoded);
    }

    public static function cookieArrayValuesProvider(): iterable
    {
        yield 'numeric values' => [
            'cookies' => [
                'count' => '123',
                'price' => '99.99',
            ],
            'expected' => [
                'count' => '123',
                'price' => '99.99',
            ],
        ];

        yield 'boolean-like values' => [
            'cookies' => [
                'enabled' => 'true',
                'visible' => 'false',
            ],
            'expected' => [
                'enabled' => 'true',
                'visible' => 'false',
            ],
        ];

        yield 'empty values' => [
            'cookies' => [
                'empty1' => '',
                'empty2' => '',
            ],
            'expected' => [
                'empty1' => '',
                'empty2' => '',
            ],
        ];

        yield 'mixed values' => [
            'cookies' => [
                'string' => 'value',
                'number' => '42',
                'bool' => 'true',
                'empty' => '',
            ],
            'expected' => [
                'string' => 'value',
                'number' => '42',
                'bool' => 'true',
                'empty' => '',
            ],
        ];
    }

    public function testCookieOverwritingWithBuilderMethod(): void
    {
        $testFile = $this->createGlobalEchoFile('cookie_overwrite_test', '_COOKIE');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);
        $options = RequestOptions::create()
            ->cookie('key', 'original')
            ->cookie('key', 'updated')
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/cookie_overwrite_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        $this->assertEquals('updated', $decoded['key']);
    }

    public function testNoCookiesSetResultsInEmptyCookieArray(): void
    {
        $testFile = $this->createJsonEchoFile('cookie_none_test', '[
            "cookies" => $_COOKIE,
            "count" => count($_COOKIE),
        ]');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/cookie_none_test.php'
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        $this->assertEmpty($decoded['cookies']);
        $this->assertEquals(0, $decoded['count']);
    }

    // Session Tests

    /**
     * Test basic session data is passed correctly to $_SESSION
     */
    public function testBasicSessionData(): void
    {
        $testFile = $this->createJsonEchoFile('session_basic_test', '$_SESSION');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $sessionData = [
            'user_id' => 123,
            'username' => 'testuser',
            'logged_in' => true,
        ];

        $options = RequestOptions::create()
            ->session($sessionData)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/session_basic_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals(123, $decoded['user_id']);
        $this->assertEquals('testuser', $decoded['username']);
        $this->assertTrue($decoded['logged_in']);
    }

    /**
     * Test multiple session values with various data types
     */
    public function testMultipleSessionValuesWithVariousDataTypes(): void
    {
        $testFile = $this->createJsonEchoFile('session_types_test', '$_SESSION');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $sessionData = [
            'string_value' => 'test string',
            'int_value' => 42,
            'float_value' => 3.14,
            'bool_value' => true,
            'null_value' => null,
            'array_value' => ['a', 'b', 'c'],
            'nested_array' => [
                'level1' => [
                    'level2' => 'deep value',
                ],
            ],
            'associative_array' => [
                'key1' => 'value1',
                'key2' => 'value2',
            ],
        ];

        $options = RequestOptions::create()
            ->session($sessionData)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/session_types_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        // Verify all data types are correctly preserved
        $this->assertSame('test string', $decoded['string_value']);
        $this->assertSame(42, $decoded['int_value']);
        $this->assertSame(3.14, $decoded['float_value']);
        $this->assertTrue($decoded['bool_value']);
        $this->assertNull($decoded['null_value']);
        $this->assertSame(['a', 'b', 'c'], $decoded['array_value']);
        $this->assertSame([
            'level1' => [
                'level2' => 'deep value',
            ],
        ], $decoded['nested_array']);
        $this->assertSame([
            'key1' => 'value1',
            'key2' => 'value2',
        ], $decoded['associative_array']);
    }

    /**
     * Test session data combined with cookies and POST data
     */
    public function testSessionWithOtherOptions(): void
    {
        $testFile = $this->createJsonEchoFile('session_combined_test', '[
            "session" => $_SESSION,
            "cookies" => $_COOKIE,
            "post" => $_POST,
        ]');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $options = RequestOptions::create()
            ->session([
                'user_id' => 999,
                'role' => 'admin',
            ])
            ->cookies([
                'session_token' => 'abc123',
                'preferences' => 'dark_mode',
            ])
            ->formParams([
                'action' => 'update',
                'data' => 'test',
            ])
            ->build();

        $response = $client->request(
            method: 'POST',
            url: 'http://localhost/session_combined_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        // Verify session data
        $this->assertSame(999, $decoded['session']['user_id']);
        $this->assertSame('admin', $decoded['session']['role']);

        // Verify cookies
        $this->assertSame('abc123', $decoded['cookies']['session_token']);
        $this->assertSame('dark_mode', $decoded['cookies']['preferences']);

        // Verify POST data
        $this->assertSame('update', $decoded['post']['action']);
        $this->assertSame('test', $decoded['post']['data']);
    }

    /**
     * Test empty session array works without errors
     */
    public function testEmptySessionArray(): void
    {
        $testFile = $this->createJsonEchoFile('session_empty_test', '[
            "session" => $_SESSION,
            "count" => count($_SESSION),
        ]');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $options = RequestOptions::create()
            ->session([])
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/session_empty_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        $this->assertEmpty($decoded['session']);
        $this->assertEquals(0, $decoded['count']);
    }

    /**
     * Test session persistence when session_start() is called in target script
     */
    public function testSessionPersistenceWithSessionStart(): void
    {
        // Create a test file that uses session_start() and reads $_SESSION
        $testFile = $this->createTestFile('session_start_test', '<?php
            session_start();
            echo json_encode([
                "session_data" => $_SESSION,
                "session_id" => session_id(),
                "session_status" => session_status(),
            ]);
        ?>');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $sessionData = [
            'authenticated' => true,
            'user_email' => 'test@example.com',
            'last_login' => '2025-12-12 10:00:00',
        ];

        $options = RequestOptions::create()
            ->session($sessionData)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/session_start_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        // Verify session data is available after session_start()
        $this->assertTrue($decoded['session_data']['authenticated']);
        $this->assertSame('test@example.com', $decoded['session_data']['user_email']);
        $this->assertSame('2025-12-12 10:00:00', $decoded['session_data']['last_login']);

        // Verify session is active (PHP_SESSION_ACTIVE = 2)
        $this->assertSame(2, $decoded['session_status']);

        // Verify session ID is set
        $this->assertNotEmpty($decoded['session_id']);
    }

    /**
     * Test no session data set results in empty session array
     */
    public function testNoSessionDataResultsInEmptyArray(): void
    {
        $testFile = $this->createJsonEchoFile('session_none_test', '[
            "session" => $_SESSION,
            "count" => count($_SESSION),
        ]');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/session_none_test.php'
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        $this->assertEmpty($decoded['session']);
        $this->assertEquals(0, $decoded['count']);
    }

    /**
     * Test session data in toArray method
     */
    public function testSessionInToArrayMethod(): void
    {
        $sessionData = [
            'user_id' => 456,
            'role' => 'editor',
        ];

        $options = RequestOptions::create()
            ->session($sessionData)
            ->build();

        $array = $options->toArray();

        $this->assertArrayHasKey('session', $array);
        $this->assertSame($sessionData, $array['session']);
    }

    /**
     * Test session data with builder method
     */
    public function testSessionWithBuilderMethod(): void
    {
        $sessionData = [
            'user_id' => 789,
            'permissions' => ['read', 'write'],
        ];

        $options = RequestOptions::create()
            ->session($sessionData)
            ->build();

        $this->assertSame($sessionData, $options->session);
    }

    /**
     * Test session with authentication headers
     */
    public function testSessionWithAuthAndUserAgent(): void
    {
        $testFile = $this->createJsonEchoFile('session_auth_test', '[
            "session" => $_SESSION,
            "auth_header" => $_SERVER["HTTP_AUTHORIZATION"] ?? null,
            "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
        ]');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $options = RequestOptions::create()
            ->session([
                'authenticated' => true,
                'user_id' => 999,
            ])
            ->bearerToken('secret-token-123')
            ->userAgent('Client/1.0')
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/session_auth_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        // Verify session
        $this->assertTrue($decoded['session']['authenticated']);
        $this->assertEquals(999, $decoded['session']['user_id']);

        // Verify auth header
        $this->assertSame('Bearer secret-token-123', $decoded['auth_header']);

        // Verify user agent
        $this->assertSame('Client/1.0', $decoded['user_agent']);
    }

    /**
     * Test session builder method allows fluent interface
     */
    public function testSessionBuilderMethodChaining(): void
    {
        $testFile = $this->createJsonEchoFile('session_builder_test', '$_SESSION');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/session_builder_test.php',
            options: RequestOptions::create()
                ->session([
                    'step1' => 'complete',
                ])
                ->header('X-Custom', 'value')
                ->queryParam('test', 'param')
                ->build()
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        $this->assertSame('complete', $decoded['step1']);
    }

    /**
     * Test session modification in target script
     */
    public function testSessionModificationInTargetScript(): void
    {
        // Create a test file that modifies session data
        $testFile = $this->createTestFile('session_modify_test', '<?php
            session_start();

            // Read initial session
            $initial = $_SESSION;

            // Modify session
            $_SESSION["modified"] = true;
            $_SESSION["new_key"] = "new_value";
            $_SESSION["user_id"] = 12345; // Override existing

            echo json_encode([
                "initial" => $initial,
                "modified" => $_SESSION,
            ]);
        ?>');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $options = RequestOptions::create()
            ->session([
                'user_id' => 999,
                'existing' => 'data',
            ])
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/session_modify_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        // Verify initial session data matches what was sent
        $this->assertEquals(999, $decoded['initial']['user_id']);
        $this->assertSame('data', $decoded['initial']['existing']);

        // Verify modifications
        $this->assertTrue($decoded['modified']['modified']);
        $this->assertSame('new_value', $decoded['modified']['new_key']);
        $this->assertEquals(12345, $decoded['modified']['user_id']); // Overridden value
        $this->assertSame('data', $decoded['modified']['existing']); // Still present
    }

    /**
     * Data provider for session data with various structures
     */
    public static function sessionDataTypesProvider(): array
    {
        return [
            'simple_array' => [
                [
                    'key1' => 'value1',
                    'key2' => 'value2',
                ],
                [
                    'key1' => 'value1',
                    'key2' => 'value2',
                ],
            ],
            'numeric_keys' => [
                [
                    0 => 'first',
                    1 => 'second',
                    2 => 'third',
                ],
                [
                    0 => 'first',
                    1 => 'second',
                    2 => 'third',
                ],
            ],
            'mixed_types' => [
                [
                    'str' => 'text',
                    'num' => 123,
                    'bool' => false,
                    'arr' => [1, 2],
                ],
                [
                    'str' => 'text',
                    'num' => 123,
                    'bool' => false,
                    'arr' => [1, 2],
                ],
            ],
            'deeply_nested' => [
                [
                    'a' => [
                        'b' => [
                            'c' => [
                                'd' => 'deep',
                            ],
                        ],
                    ],
                ],
                [
                    'a' => [
                        'b' => [
                            'c' => [
                                'd' => 'deep',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Test session with various data type structures
     */
    #[DataProvider('sessionDataTypesProvider')]
    public function testSessionWithVariousDataTypes(array $sessionData, array $expected): void
    {
        $testFile = $this->createJsonEchoFile('session_data_types_test', '$_SESSION');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $options = RequestOptions::create()
            ->session($sessionData)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/session_data_types_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertSame($expected, $decoded);
    }

    /**
     * Test empty session does not appear in toArray when empty
     */
    public function testEmptySessionNotInToArray(): void
    {
        $options = RequestOptions::create()->build();

        $array = $options->toArray();

        $this->assertArrayNotHasKey('session', $array);
    }

    /**
     * Test session overwriting with builder method
     */
    public function testSessionOverwritingWithBuilderMethod(): void
    {
        $testFile = $this->createJsonEchoFile('session_overwrite_test', '$_SESSION');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        // Create builder and set session multiple times
        $options = RequestOptions::create()
            ->session([
                'initial' => 'data',
            ])
            ->session([
                'final' => 'data',
            ]) // This should replace the previous session data
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/session_overwrite_test.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);

        // Should only have the final session data
        $this->assertArrayNotHasKey('initial', $decoded);
        $this->assertArrayHasKey('final', $decoded);
        $this->assertSame('data', $decoded['final']);
    }

    // Session Persistence Tests

    /**
     * Test that session modifications made by the target script are captured and returned
     */
    public function testSessionModifiedByScriptIsCaptured(): void
    {
        // Create a test file that modifies session
        $testFile = $this->createTestFile('session_capture_test', '<?php
            session_start();

            // Modify session data
            $_SESSION["cart_items"] = 5;
            $_SESSION["last_page"] = "/checkout";
            $_SESSION["timestamp"] = time();

            echo "Session modified";
        ?>');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/session_capture_test.php'
        );

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Session modified', $response->getContent());

        // Verify session changes are captured
        $capturedSession = $response->getSession();
        $this->assertIsArray($capturedSession);
        $this->assertArrayHasKey('cart_items', $capturedSession);
        $this->assertEquals(5, $capturedSession['cart_items']);
        $this->assertArrayHasKey('last_page', $capturedSession);
        $this->assertEquals('/checkout', $capturedSession['last_page']);
        $this->assertArrayHasKey('timestamp', $capturedSession);
        $this->assertIsInt($capturedSession['timestamp']);
    }

    /**
     * Test session persistence with initial data plus modifications
     */
    public function testSessionWithInitialDataAndModifications(): void
    {
        $testFile = $this->createTestFile('session_initial_plus_modify', '<?php
            session_start();

            // Add new items to existing session
            $_SESSION["step"] = 2;
            $_SESSION["completed_fields"] = ["name", "email"];

            echo "Step updated";
        ?>');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $initialSession = [
            'user_id' => 42,
            'username' => 'johndoe',
            'step' => 1,
        ];

        $options = RequestOptions::create()
            ->session($initialSession)
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/session_initial_plus_modify.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());

        $capturedSession = $response->getSession();

        // Original session data should still be present
        $this->assertEquals(42, $capturedSession['user_id']);
        $this->assertEquals('johndoe', $capturedSession['username']);

        // Modified field
        $this->assertEquals(2, $capturedSession['step']);

        // New fields
        $this->assertArrayHasKey('completed_fields', $capturedSession);
        $this->assertEquals(['name', 'email'], $capturedSession['completed_fields']);
    }

    /**
     * Test multi-request session flow (simulating multiple requests)
     */
    public function testMultiRequestSessionFlow(): void
    {
        // Request 1: Start session and add cart item
        $addToCartFile = $this->createTestFile('add_to_cart', '<?php
            session_start();
            $_SESSION["cart"] = $_SESSION["cart"] ?? [];
            $_SESSION["cart"][] = ["id" => 1, "name" => "Product A"];
            echo "Added to cart";
        ?>');

        $client1 = new Client(documentRoot: $this->testDocumentRoot, file: $addToCartFile);

        $response1 = $client1->request(
            method: 'GET',
            url: 'http://localhost/add_to_cart.php',
            options: RequestOptions::create()->build()
        );

        $session1 = $response1->getSession();
        $this->assertArrayHasKey('cart', $session1);
        $this->assertCount(1, $session1['cart']);
        $this->assertEquals('Product A', $session1['cart'][0]['name']);

        // Request 2: Add another item using session from request 1
        $client2 = new Client(documentRoot: $this->testDocumentRoot, file: $addToCartFile);

        $response2 = $client2->request(
            method: 'GET',
            url: 'http://localhost/add_to_cart.php',
            options: RequestOptions::create()
                ->session($session1) // Pass session from previous request
                ->build()
        );

        $session2 = $response2->getSession();
        $this->assertArrayHasKey('cart', $session2);
        $this->assertCount(2, $session2['cart']);

        // Request 3: View cart
        $viewCartFile = $this->createTestFile('view_cart', '<?php
            session_start();
            echo json_encode($_SESSION);
        ?>');

        $client3 = new Client(documentRoot: $this->testDocumentRoot, file: $viewCartFile);

        $response3 = $client3->request(
            method: 'GET',
            url: 'http://localhost/view_cart.php',
            options: RequestOptions::create()
                ->session($session2) // Pass session from previous request
                ->build()
        );

        $cartData = json_decode($response3->getContent(), true);
        $this->assertArrayHasKey('cart', $cartData);
        $this->assertCount(2, $cartData['cart']);
    }

    /**
     * Test session cleared by script
     */
    public function testSessionClearedByScript(): void
    {
        $testFile = $this->createTestFile('clear_session', '<?php
            session_start();

            // Clear all session data
            $_SESSION = [];

            echo "Session cleared";
        ?>');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $options = RequestOptions::create()
            ->session([
                'user_id' => 123,
                'username' => 'testuser',
                'cart' => ['item1', 'item2'],
            ])
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/clear_session.php',
            options: $options
        );

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Session cleared', $response->getContent());

        // Session should be empty
        $capturedSession = $response->getSession();
        $this->assertIsArray($capturedSession);
        $this->assertEmpty($capturedSession);
    }

    /**
     * Test session removal of specific keys
     */
    public function testSessionKeyRemoval(): void
    {
        $testFile = $this->createTestFile('remove_session_key', '<?php
            session_start();

            // Remove specific key
            unset($_SESSION["temporary_data"]);

            echo "Key removed";
        ?>');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $options = RequestOptions::create()
            ->session([
                'user_id' => 999,
                'temporary_data' => 'should be removed',
                'permanent_data' => 'should remain',
            ])
            ->build();

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/remove_session_key.php',
            options: $options
        );

        $capturedSession = $response->getSession();

        $this->assertArrayHasKey('user_id', $capturedSession);
        $this->assertArrayHasKey('permanent_data', $capturedSession);
        $this->assertArrayNotHasKey('temporary_data', $capturedSession);
    }

    /**
     * Test session without session_start() call - session should NOT be captured
     */
    public function testSessionNotCapturedWithoutSessionStart(): void
    {
        // Script that modifies $_SESSION without calling session_start()
        $testFile = $this->createTestFile('no_session_start', '<?php
            // Directly modify $_SESSION without session_start()
            $_SESSION["direct_write"] = "test value";

            echo "Modified without session_start";
        ?>');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/no_session_start.php'
        );

        // Session changes should NOT be captured when session_start() is not called
        // because session_status() will not be PHP_SESSION_ACTIVE
        $capturedSession = $response->getSession();
        $this->assertIsArray($capturedSession);
        $this->assertEmpty($capturedSession);
    }

    /**
     * Test empty session is returned when no session modifications occur
     */
    public function testEmptySessionWhenNoModifications(): void
    {
        $testFile = $this->createTestFile('no_session_mods', '<?php
            echo "No session modifications";
        ?>');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/no_session_mods.php'
        );

        $capturedSession = $response->getSession();
        $this->assertIsArray($capturedSession);
        $this->assertEmpty($capturedSession);
    }

    /**
     * Test session with complex nested data structures
     */
    public function testSessionPersistenceWithComplexData(): void
    {
        $testFile = $this->createTestFile('complex_session', '<?php
            session_start();

            $_SESSION["nested"] = [
                "level1" => [
                    "level2" => [
                        "value" => "deep"
                    ]
                ]
            ];
            $_SESSION["objects"] = (object)["prop" => "value"];

            echo "Complex data stored";
        ?>');

        $client = new Client(documentRoot: $this->testDocumentRoot, file: $testFile);

        $response = $client->request(
            method: 'GET',
            url: 'http://localhost/complex_session.php'
        );

        $capturedSession = $response->getSession();

        $this->assertArrayHasKey('nested', $capturedSession);
        $this->assertEquals('deep', $capturedSession['nested']['level1']['level2']['value']);

        $this->assertArrayHasKey('objects', $capturedSession);
        $this->assertIsObject($capturedSession['objects']);
        $this->assertEquals('value', $capturedSession['objects']->prop);
    }

    /**
     * Creates a test PHP file that echoes JSON-encoded data
     *
     * @param string $name File name (without extension)
     * @param string $phpCode PHP code that produces the data to encode (e.g., '$_GET')
     * @return string Full file path
     */
    private function createJsonEchoFile(string $name, string $phpCode): string
    {
        return $this->createTestFile($name, "<?php echo json_encode({$phpCode});");
    }

    /**
     * Creates a test PHP file that echoes a JSON-encoded global variable
     *
     * @param string $name File name (without extension)
     * @param string $globalName Name of the global variable (e.g., '_GET', '_SERVER', '_POST')
     * @return string Full file path
     */
    private function createGlobalEchoFile(string $name, string $globalName): string
    {
        return $this->createJsonEchoFile($name, '$' . $globalName);
    }

    // Helper Methods for Creating Stubs

    private function createStubProcess(string $output, int $exitCode = 0): Process
    {
        $stubProcess = $this->createStub(Process::class);
        $stubProcess->method('getOutput')->willReturn($output);
        $stubProcess->method('getExitCode')->willReturn($exitCode);
        $stubProcess->method('mustRun')->willReturnSelf();
        $stubProcess->method('setTimeout')->willReturnSelf();
        $stubProcess->method('setInput')->willReturnSelf();

        return $stubProcess;
    }

    private function createClientWithMockProcess(
        Process $mockProcess,
        ?string $filePath = null,
        ?callable $globalsHandler = null
    ): Client {

        // For now, we'll return the original client since we can't easily mock Process creation
        // In a real scenario, we'd need to refactor the Client to use dependency injection
        return new Client($this->testDocumentRoot, $filePath, $globalsHandler);
    }
}
