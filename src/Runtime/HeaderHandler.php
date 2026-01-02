<?php

declare(strict_types=1);

namespace n5s\HttpCli\Runtime;

use RuntimeException;
use Stringable;

/**
 * HTTP header handler for CLI environment.
 *
 * Provides PHP's header functions (header, headers_sent, etc.) for scripts
 * executed via Client where native header functions don't work.
 *
 * This class can be:
 * - Used directly via Composer autoloading for testing
 * - Required standalone by functions.php for child process execution
 */
final class HeaderHandler implements Stringable
{
    /**
     * @var array<int, string>
     */
    private const HTTP_STATUS_CODES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        103 => 'Early Hints',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Content',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    public bool $headersOutputted = false;

    /**
     * @var array<int, string>
     */
    private array $headers = [];

    private int $responseCode = 200;

    private bool $headersSent = false;

    private ?string $headersSentFile = null;

    private ?int $headersSentLine = null;

    /**
     * @var resource|closed-resource|null
     */
    private mixed $outputStream = null;

    private static ?self $instance = null;

    private int $outputBufferLevel = 0;

    public function __construct()
    {
        $stream = fopen('php://stdout', 'w');
        $this->outputStream = $stream !== false ? $stream : null;
        $this->initializeOutputHandling();
        self::$instance = $this;
    }

    public function __toString(): string
    {
        return $this->buildHeaderString();
    }

    public static function getInstance(): self
    {
        return self::$instance ?? throw new RuntimeException('Header handler not initialized');
    }

    /**
     * Check if an instance has been initialized.
     */
    public static function hasInstance(): bool
    {
        return self::$instance !== null;
    }

    /**
     * Reset the singleton instance (for testing).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Force immediate header output to stdout.
     */
    public function outputHeaders(): void
    {
        if ($this->headersOutputted) {
            return;
        }

        $headerString = $this->buildHeaderString();

        if ($this->outputStream !== null && is_resource($this->outputStream)) {
            fwrite($this->outputStream, $headerString);
            fflush($this->outputStream);
        } else {
            file_put_contents('php://stdout', $headerString);
        }

        $this->markHeadersAsOutputted();
    }

    /**
     * Emergency header output for shutdown scenarios.
     */
    public function emergencyHeaderOutput(): void
    {
        if (! $this->headersOutputted) {
            $this->outputHeaders();
        }
    }

    /**
     * Final cleanup on script termination.
     */
    public function finalCleanup(): void
    {
        while (ob_get_level() > $this->outputBufferLevel) {
            ob_end_flush();
        }

        if ($this->outputStream !== null && is_resource($this->outputStream)) {
            fflush($this->outputStream);
        }
    }

    /**
     * Build the HTTP response header string.
     */
    public function buildHeaderString(): string
    {
        $code = $this->http_response_code();
        $headers = $this->headers_list();
        $statusText = self::HTTP_STATUS_CODES[$code] ?? 'Unknown';

        return "HTTP/1.1 {$code} {$statusText}" . PHP_EOL . implode(PHP_EOL, $headers) . PHP_EOL . PHP_EOL;
    }

    /**
     * Set or get a raw HTTP header.
     */
    public function header(string $header, bool $replace = true, int $http_response_code = 0): void
    {
        if ($this->headersSent) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
            $file = $trace[0]['file'] ?? 'unknown';
            trigger_error(
                'Cannot modify header information - headers already sent' .
                ($file !== 'unknown' ? " (output started at {$this->headersSentFile}:{$this->headersSentLine})" : ''),
                E_USER_WARNING
            );

            return;
        }

        if (str_contains($header, "\n") || str_contains($header, "\r") || str_contains($header, "\0")) {
            trigger_error('Header may not contain more than a single header, new line detected', E_USER_WARNING);

            return;
        }

        if (preg_match('/^HTTP\/[0-9]\.[0-9]\s+([0-9]+)\s*(.*)?$/i', $header, $matches)) {
            $this->responseCode = (int) $matches[1];

            return;
        }

        if (stripos($header, 'Location:') === 0 && $this->responseCode === 200) {
            $this->responseCode = 302;
        }

        $colonPos = strpos($header, ':');
        if ($colonPos === false) {
            return;
        }

        $name = trim(substr($header, 0, $colonPos));
        $value = trim(substr($header, $colonPos + 1));

        if ($name === '' || preg_match('/[\s\t\r\n]/', $name)) {
            return;
        }

        if ($replace) {
            $this->headers = array_values(array_filter($this->headers, function ($existingHeader) use ($name) {
                $colonPos = strpos($existingHeader, ':');
                $existingName = $colonPos !== false ? trim(substr($existingHeader, 0, $colonPos)) : '';

                return strcasecmp($existingName, $name) !== 0;
            }));
        }

        $this->headers[] = $name . ': ' . $value;

        if ($http_response_code > 0) {
            $this->responseCode = $http_response_code;
        }
    }

    /**
     * Remove a previously set header.
     */
    public function header_remove(?string $name = null): void
    {
        if ($this->headersSent || $this->headersOutputted) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
            $file = $trace[0]['file'] ?? 'unknown';
            $line = $trace[0]['line'] ?? 0;
            trigger_error(
                'Cannot modify header information - headers already sent' .
                ($file !== 'unknown' ? " (output started at {$file}:{$line})" : ''),
                E_USER_WARNING
            );

            return;
        }

        if ($name === null) {
            $this->headers = [];
        } else {
            $this->headers = array_values(array_filter($this->headers, function ($header) use ($name) {
                $colonPos = strpos($header, ':');
                $headerName = $colonPos !== false ? trim(substr($header, 0, $colonPos)) : '';

                return strcasecmp($headerName, $name) !== 0;
            }));
        }
    }

    /**
     * Check if headers have been sent.
     */
    public function headers_sent(?string &$filename = null, ?int &$line = null): bool
    {
        if ($this->headersSent) {
            if ($filename !== null && isset($this->headersSentFile)) {
                $filename = $this->headersSentFile;
            }
            if ($line !== null && isset($this->headersSentLine)) {
                $line = $this->headersSentLine;
            }

            return true;
        }

        return false;
    }

    /**
     * Get the list of response headers.
     *
     * @return array<int, string>
     */
    public function headers_list(): array
    {
        return $this->headers;
    }

    /**
     * Get or set the HTTP response code.
     */
    public function http_response_code(int $response_code = 0): int|false
    {
        if ($response_code === 0) {
            return $this->responseCode;
        }

        if ($this->headersSent || $this->headersOutputted) {
            trigger_error('Cannot modify header information - headers already sent', E_USER_WARNING);

            return false;
        }

        if ($response_code < 100 || $response_code > 599) {
            trigger_error("Invalid response code {$response_code}", E_USER_WARNING);

            return false;
        }

        $previous = $this->responseCode;
        $this->responseCode = $response_code;

        return $previous;
    }

    /**
     * Get the reason phrase for a status code.
     */
    public static function getReasonPhrase(int $statusCode): string
    {
        return self::HTTP_STATUS_CODES[$statusCode] ?? 'Unknown';
    }

    private function initializeOutputHandling(): void
    {
        $this->outputBufferLevel = ob_get_level();
    }

    /**
     * Mark headers as outputted and sent.
     */
    private function markHeadersAsOutputted(): void
    {
        $this->headersOutputted = true;
        $this->markHeadersSent();
    }

    private function markHeadersSent(): void
    {
        $this->headersSent = true;
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        if (isset($trace[1])) {
            $this->headersSentFile = $trace[1]['file'] ?? null;
            $this->headersSentLine = $trace[1]['line'] ?? null;
        }
    }
}
