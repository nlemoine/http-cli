<?php

declare(strict_types=1);

namespace n5s\HttpCli\Runtime;

/**
 * Builds $_REQUEST the way PHP does: the sources named by request_order (or
 * variables_order when it is empty), each letter once in its first position,
 * later sources overriding earlier ones and nested arrays merged recursively.
 */
final class RequestSuperglobal
{
    /**
     * @param array<mixed> $get
     * @param array<mixed> $post
     * @param array<mixed> $cookie
     * @param string $order Value of request_order, or of variables_order when request_order is empty
     * @return array<mixed>
     */
    public static function build(array $get, array $post, array $cookie, string $order): array
    {
        $sources = [
            'g' => $get,
            'p' => $post,
            'c' => $cookie,
        ];
        $request = [];

        foreach (array_unique(str_split(strtolower($order))) as $letter) {
            if (isset($sources[$letter])) {
                $request = array_replace_recursive($request, $sources[$letter]);
            }
        }

        return $request;
    }

    /**
     * The order the current PHP process uses for $_REQUEST.
     */
    public static function order(): string
    {
        return (string) (\ini_get('request_order') ?: \ini_get('variables_order'));
    }
}
