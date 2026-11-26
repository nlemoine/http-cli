<?php

declare(strict_types=1);

namespace n5s\HttpCli\Runtime;

/**
 * Builds $_REQUEST the way PHP does: the sources named by request_order (or
 * variables_order when request_order is not set), each letter once in its
 * first position, later sources overriding earlier ones and nested arrays
 * merged recursively.
 */
final class RequestSuperglobal
{
    /**
     * @param array<mixed> $get
     * @param array<mixed> $post
     * @param array<mixed> $cookie
     * @param string $order Value of request_order, or of variables_order when request_order is not set
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
     * The order the current PHP process uses for $_REQUEST. PHP falls back to
     * variables_order only when request_order is not set: set but empty, it
     * builds an empty $_REQUEST. ini_get() returns '' in both cases, while
     * ini_get_all() gives null for a directive that is not set.
     */
    public static function order(): string
    {
        $ini = \ini_get_all(null, false);
        $requestOrder = \is_array($ini) ? ($ini['request_order'] ?? null) : null;

        return (string) ($requestOrder ?? \ini_get('variables_order'));
    }
}
