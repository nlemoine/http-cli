<?php

declare(strict_types=1);

namespace n5s\HttpCli;

interface GlobalsHandler
{
    /**
     * Customize the superglobals array before it's sent to the child process.
     *
     * @param array{
     *     _ENV: array<string, mixed>,
     *     _GET: array<string, mixed>,
     *     _POST: array<string, mixed>,
     *     _COOKIE: array<string, mixed>,
     *     _FILES: array<string, mixed>,
     *     _SESSION: array<string, mixed>,
     *     _SERVER: array<string, mixed>,
     *     _RAW_INPUT: string,
     *     _REQUEST: array<string, mixed>,
     * } $globals
     */
    public function handle(array &$globals): void;
}
