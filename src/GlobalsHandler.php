<?php

declare(strict_types=1);

namespace n5s\HttpCli;

interface GlobalsHandler
{
    /**
     * Customize the superglobals array before it's sent to the child process.
     *
     * The script gets these arrays as they are left here, whatever the ini of
     * the PHP process that runs it. Only the keys below reach the script:
     * other keys added to the array are ignored.
     *
     * _ENV starts empty, so $_ENV holds only what is set here. The
     * environment of the PHP process stays readable with getenv().
     *
     * _REQUEST is built from _GET, _POST and _COOKIE with the current
     * request_order, for reading. The script's PHP builds it again with its
     * own ini from the arrays left here, unless this method changes _REQUEST,
     * which is then sent as is (so built with the current request_order). A
     * _REQUEST left equal to the value built here counts as unchanged.
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
