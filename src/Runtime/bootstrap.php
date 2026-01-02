<?php

declare(strict_types=1);

use n5s\HttpCli\Runtime\HeaderHandler;
use n5s\HttpCli\Runtime\InputStream;
use n5s\HttpCli\Runtime\SessionHandler;

/**
 * This file executes before target file to set up global variables such as $_SERVER['DOCUMENT_ROOT']
 */
(function (array $httpCliClientVars): void {
    if (empty($httpCliClientVars)) {
        throw new Exception('No globals data received via STDIN');
    }

    // Set up php://input stream wrapper before setting globals
    // This replaces the deprecated HTTP_RAW_POST_DATA global variable
    require_once __DIR__ . '/InputStream.php';
    InputStream::register();
    /** @var string $rawInput */
    $rawInput = $httpCliClientVars['_RAW_INPUT'] ?? '';
    InputStream::setRawInput($rawInput);
    unset($httpCliClientVars['_RAW_INPUT']);

    foreach ($httpCliClientVars as $globalName => $variables) {
        $GLOBALS[$globalName] = $variables;
    }

    require __DIR__ . '/functions.php';

    // Get the header handler instance (typed via getInstance())
    $headerManager = HeaderHandler::getInstance();

    /** @var array<string, mixed> $initialSession */
    $initialSession = $httpCliClientVars['_SESSION'] ?? [];

    require_once __DIR__ . '/SessionHandler.php';
    session_set_save_handler(new SessionHandler($initialSession), false);

    // Simple header capture at script end - no buffering conflicts
    register_shutdown_function(function () use ($headerManager): void {
        // Capture final session state
        $sessionData = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionData = $_SESSION ?? [];
        }

        // Create header data for parsing
        $headerData = [
            'status' => $headerManager->http_response_code(),
            'headers' => $headerManager->headers_list(),
            'session' => $sessionData,
            'version' => '1.0',
        ];

        // Output parseable header marker after all content
        $marker = '<!--HTTP_CLI_HEADERS:' . base64_encode(serialize($headerData)) . '-->';
        echo $marker;
    });

    // Hook into error handling for better error responses
    set_exception_handler(function (Throwable $exception) use ($headerManager): void {
        // Set appropriate error status code
        $headerManager->http_response_code(500);
        $headerManager->header('Content-Type: text/plain');

        // Output error details (header marker will be added by shutdown function)
        echo sprintf(
            "Uncaught %s: %s in %s on line %d\n",
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );
    });
})(
    // Read and unserialize data from STDIN
    (function (): array {
        // Read all input from STDIN
        $stdinData = '';
        $stdin = fopen('php://stdin', 'r');

        if ($stdin === false) {
            throw new RuntimeException('Failed to open STDIN for reading');
        }

        try {
            while (! feof($stdin)) {
                $chunk = fread($stdin, 8192);
                if ($chunk === false) {
                    throw new RuntimeException('Failed to read from STDIN');
                }
                $stdinData .= $chunk;
            }
        } finally {
            fclose($stdin);
        }

        // Handle empty STDIN
        if (empty($stdinData)) {
            throw new Exception('No data received via STDIN');
        }

        // Unserialize the data with error handling
        // Note: unserialize() doesn't throw exceptions, it returns false and emits warnings
        set_error_handler(function ($severity, $message, $file, $line): void {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }, E_WARNING | E_NOTICE);

        try {
            $unserializedData = unserialize($stdinData);
        } catch (ErrorException $e) {
            restore_error_handler();
            throw new Exception('Failed to unserialize STDIN data: ' . $e->getMessage());
        } finally {
            restore_error_handler();
        }

        // Additional validation for unserialize result
        if ($unserializedData === false) {
            // unserialize returns false on failure, but we need to distinguish from legitimate false
            // Check if the original data was actually the serialized false value
            if ($stdinData !== serialize(false)) {
                throw new Exception('Failed to unserialize STDIN data: Invalid serialized format');
            }
        }

        if (! is_array($unserializedData)) {
            throw new Exception('Invalid STDIN data: expected array, got ' . gettype($unserializedData));
        }

        return $unserializedData;
    })()
);
