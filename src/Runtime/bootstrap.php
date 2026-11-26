<?php

declare(strict_types=1);

use n5s\HttpCli\Runtime\HeaderHandler;
use n5s\HttpCli\Runtime\InputStream;
use n5s\HttpCli\Runtime\RequestSuperglobal;
use n5s\HttpCli\Runtime\SessionHandler;
use n5s\HttpCli\Runtime\UploadedFiles;

/**
 * This file executes before target file to set up global variables such as $_SERVER['DOCUMENT_ROOT']
 */
(function (array $payload): void {
    $httpCliClientVars = $payload['globals'] ?? null;
    $metadataFile = $payload['metadataFile'] ?? null;

    if (! is_array($httpCliClientVars) || $httpCliClientVars === []) {
        throw new Exception('No globals data received via STDIN');
    }

    if (! is_string($metadataFile) || $metadataFile === '') {
        throw new Exception('No metadata file received via STDIN');
    }

    // Set up php://input stream wrapper before setting globals
    // This replaces the deprecated HTTP_RAW_POST_DATA global variable
    require_once __DIR__ . '/InputStream.php';
    InputStream::register();
    /** @var string $rawInput */
    $rawInput = $httpCliClientVars['_RAW_INPUT'] ?? '';
    InputStream::setRawInput($rawInput);
    unset($httpCliClientVars['_RAW_INPUT']);

    // Assigned by name, never through $GLOBALS. With auto_globals_jit (on by default), PHP builds $_ENV, $_SERVER
    // and $_REQUEST the first time a file that names them is compiled, or when filter_input() needs them, and would
    // replace values set through $GLOBALS. Naming them here builds them while this file compiles, before the values
    // below are set. $_ENV is exactly what the client sent, whatever variables_order the PHP process uses.
    $_GET = $httpCliClientVars['_GET'] ?? [];
    $_POST = $httpCliClientVars['_POST'] ?? [];
    $_COOKIE = $httpCliClientVars['_COOKIE'] ?? [];
    $_FILES = $httpCliClientVars['_FILES'] ?? [];
    $_SERVER = $httpCliClientVars['_SERVER'] ?? [];
    $_ENV = $httpCliClientVars['_ENV'] ?? [];
    // Built from this process's ini, as PHP would, unless a globals handler set it
    require_once __DIR__ . '/RequestSuperglobal.php';
    $_REQUEST = $httpCliClientVars['_REQUEST'] ?? RequestSuperglobal::build(
        // A globals handler may have broken the documented shape
        \is_array($httpCliClientVars['_GET'] ?? null) ? $httpCliClientVars['_GET'] : [],
        \is_array($httpCliClientVars['_POST'] ?? null) ? $httpCliClientVars['_POST'] : [],
        \is_array($httpCliClientVars['_COOKIE'] ?? null) ? $httpCliClientVars['_COOKIE'] : [],
        RequestSuperglobal::order()
    );
    $_SESSION = $httpCliClientVars['_SESSION'] ?? [];

    require __DIR__ . '/functions.php';

    // Only the uploads the client wrote count as uploaded files, whatever the script later puts in $_FILES
    /** @var array<mixed> $files */
    $files = $httpCliClientVars['_FILES'] ?? [];
    UploadedFiles::register($files);

    // Get the header handler instance (typed via getInstance())
    $headerManager = HeaderHandler::getInstance();

    /** @var array<string, mixed> $initialSession */
    $initialSession = $httpCliClientVars['_SESSION'] ?? [];

    require_once __DIR__ . '/SessionHandler.php';
    session_set_save_handler(new SessionHandler($initialSession), false);

    // Hand the status, headers and session back to the client at script end, through the metadata file rather than
    // the output, so that nothing the script prints can change them
    register_shutdown_function(function () use ($headerManager, $metadataFile): void {
        // Capture final session state
        $sessionData = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionData = $_SESSION ?? [];
        }

        $metadata = [
            'status' => $headerManager->http_response_code(),
            'headers' => $headerManager->headers_list(),
            'session' => $sessionData,
        ];

        // Silenced: a warning would end up in the response body. The client falls back to defaults if the file is empty.
        @file_put_contents($metadataFile, serialize($metadata));
    });

    // Hook into error handling for better error responses
    set_exception_handler(function (Throwable $exception) use ($headerManager): void {
        // Set appropriate error status code
        $headerManager->http_response_code(500);
        $headerManager->header('Content-Type: text/plain');

        // Output error details (the shutdown function hands the status and headers back to the client)
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

        // Unserialize the data with error handling. All classes are allowed on purpose: the payload comes from the
        // calling process, and objects in the session have to be restored
        // Note: unserialize() doesn't throw exceptions, it returns false and emits warnings
        $unserializeError = null;
        set_error_handler(static function (int $severity, string $message) use (&$unserializeError): bool {
            $unserializeError = $message;

            return true;
        }, E_WARNING | E_NOTICE);

        try {
            $unserializedData = unserialize($stdinData);
        } finally {
            restore_error_handler();
        }

        if ($unserializeError !== null) {
            throw new Exception('Failed to unserialize STDIN data: ' . $unserializeError);
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
