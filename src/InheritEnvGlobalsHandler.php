<?php

declare(strict_types=1);

namespace n5s\HttpCli;

/**
 * Merges the parent process's environment variables into $_SERVER and $_ENV.
 *
 * By default, the child process receives a clean $_SERVER with only
 * HTTP request-specific variables. Use this handler to inherit the
 * parent's environment (e.g. APP_ENV, DATABASE_URL).
 *
 * Variables that describe a request are never inherited: when the parent process is itself handling one (PHP-FPM,
 * CGI), its $_SERVER and $_ENV also hold that request's headers, credentials and metadata. The list is the one
 * Symfony Process keeps out of the environment of subprocesses.
 *
 * Request-specific server variables take precedence over inherited env vars.
 */
final class InheritEnvGlobalsHandler implements GlobalsHandler
{
    private const REQUEST_VARIABLE_PREFIXES = ['HTTP_', 'ORIG_', 'REDIRECT_'];

    private const REQUEST_VARIABLES = [
        'AUTH_TYPE', 'CONTENT_LENGTH', 'CONTENT_TYPE', 'DOCUMENT_ROOT',
        'DOCUMENT_URI', 'GATEWAY_INTERFACE', 'HTTPS', 'PATH_INFO',
        'PATH_TRANSLATED', 'PHP_AUTH_DIGEST', 'PHP_AUTH_PW', 'PHP_AUTH_USER',
        'PHP_SELF', 'QUERY_STRING', 'REMOTE_ADDR', 'REMOTE_HOST',
        'REMOTE_IDENT', 'REMOTE_PORT', 'REMOTE_USER', 'REQUEST_METHOD',
        'REQUEST_SCHEME', 'REQUEST_TIME', 'REQUEST_TIME_FLOAT', 'REQUEST_URI',
        'SCRIPT_FILENAME', 'SCRIPT_NAME', 'SCRIPT_URI', 'SCRIPT_URL',
        'SERVER_ADDR', 'SERVER_ADMIN', 'SERVER_NAME', 'SERVER_PORT',
        'SERVER_PROTOCOL', 'SERVER_SIGNATURE', 'SERVER_SOFTWARE',
    ];

    public function handle(array &$globals): void
    {
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && ! self::isRequestVariable($key)) {
                $globals['_SERVER'][$key] ??= $value;
            }
        }
        foreach ($_ENV as $key => $value) {
            if (is_string($key) && ! self::isRequestVariable($key)) {
                $globals['_ENV'][$key] ??= $value;
            }
        }
    }

    private static function isRequestVariable(string $name): bool
    {
        foreach (self::REQUEST_VARIABLE_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return in_array($name, self::REQUEST_VARIABLES, true);
    }
}
