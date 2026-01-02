<?php

declare(strict_types=1);

use n5s\HttpCli\Runtime\HeaderHandler;

/**
 * Global function wrappers for CLI environment.
 *
 * This file is loaded via auto_prepend_file in child processes where
 * Composer autoloading is not available. It requires the HeaderHandler
 * class directly and creates global function wrappers.
 */

require_once __DIR__ . '/HeaderHandler.php';

// Initialize the singleton instance
new HeaderHandler();

if (! function_exists('header')) {
    function header(string $header, bool $replace = true, int $http_response_code = 0): void
    {
        HeaderHandler::getInstance()->header($header, $replace, $http_response_code);
    }
}

if (! function_exists('header_remove')) {
    function header_remove(?string $name = null): void
    {
        HeaderHandler::getInstance()->header_remove($name);
    }
}

if (! function_exists('headers_list')) {
    /** @return array<int, string> */
    function headers_list(): array
    {
        return HeaderHandler::getInstance()->headers_list();
    }
}

if (! function_exists('headers_sent')) {
    function headers_sent(?string &$filename = null, ?int &$line = null): bool
    {
        return HeaderHandler::getInstance()->headers_sent($filename, $line);
    }
}

if (! function_exists('http_response_code')) {
    function http_response_code(int $response_code = 0): int|bool
    {
        return HeaderHandler::getInstance()->http_response_code($response_code);
    }
}

if (! function_exists('php_sapi_name')) {
    function php_sapi_name(): string
    {
        return 'cgi-fcgi';
    }
}
