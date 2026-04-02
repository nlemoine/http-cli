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
 * Request-specific server variables take precedence over inherited env vars.
 */
final class InheritEnvGlobalsHandler implements GlobalsHandler
{
    public function handle(array &$globals): void
    {
        foreach ($_SERVER as $key => $value) {
            if (is_string($key)) {
                $globals['_SERVER'][$key] ??= $value;
            }
        }
        foreach ($_ENV as $key => $value) {
            if (is_string($key)) {
                $globals['_ENV'][$key] ??= $value;
            }
        }
    }
}
