<?php

declare(strict_types=1);

namespace n5s\HttpCli;

interface GlobalsHandler
{
    /**
     * Customize the superglobals array before it's sent to the child process.
     *
     * The script gets these arrays as they are left here, whatever the ini of
     * the PHP process that runs it. _ENV starts empty: the environment of the
     * PHP process is only readable with getenv(). _REQUEST is built from
     * _GET, _POST and _COOKIE with the current request_order, for reading:
     * the script's PHP builds it again with its own ini from the arrays left
     * here, unless this method changes _REQUEST itself, which is then kept.
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
