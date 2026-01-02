<?php

declare(strict_types=1);

namespace n5s\HttpCli;

use RuntimeException;

/**
 * Exception thrown when a feature is not supported by a specific adapter
 */
class UnsupportedFeatureException extends RuntimeException
{
    public function __construct(
        string $feature,
        string $adapter,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $message = "Feature '{$feature}' is not supported by adapter '{$adapter}'";
        parent::__construct($message, $code, $previous);
    }
}
