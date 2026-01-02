<?php

declare(strict_types=1);

namespace n5s\HttpCli\WordPress;

use n5s\HttpCli\Client;
use n5s\HttpCli\RequestOptions;
use n5s\HttpCli\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use WpOrg\Requests\Capability;
use WpOrg\Requests\Exception;
use WpOrg\Requests\Exception\InvalidArgument;
use WpOrg\Requests\HookManager;
use WpOrg\Requests\Transport;
use WpOrg\Requests\Utility\InputValidator;

/**
 * WordPress Requests Transport implementation using Client
 *
 * This transport allows WordPress HTTP API to execute requests through CLI PHP,
 * useful for testing WordPress plugins and themes without a real HTTP server.
 *
 * Supports the following hooks:
 * - cli.before_request: Dispatched before setting up the request
 * - cli.before_send: Dispatched before executing the PHP script
 * - cli.after_send: Dispatched after executing the PHP script
 * - cli.after_request: Dispatched after building the response, with &$headers and &$info
 */
class Cli implements Transport
{
    /**
     * Response headers (raw HTTP string)
     */
    public string $headers = '';

    /**
     * Request information (similar to curl_getinfo)
     *
     * @var array{url: string, http_code: int, total_time: float, method: string, content_length: int, content_type: string, size_download: int, redirect_count: int}|array{}
     */
    public array $info = [];

    private readonly WordPressToRequestOptionsAdapter $adapter;

    /**
     * @param Client $httpCliClient The CLI client to use for requests
     */
    public function __construct(
        private readonly Client $httpCliClient
    ) {
        $this->adapter = new WordPressToRequestOptionsAdapter();
    }

    /**
     * Perform a request
     *
     * @param string $url URL to request
     * @param array<string, string> $headers Associative array of request headers
     * @param string|array<string, mixed> $data Data to send either as the POST body, or as parameters in the URL for a GET/HEAD
     * @param array<string, mixed> $options Request options
     * @return string Raw HTTP result (status line + headers + body)
     *
     * @throws InvalidArgument When the passed $url argument is not a string or Stringable.
     * @throws InvalidArgument When the passed $headers argument is not an array.
     * @throws InvalidArgument When the passed $data parameter is not an array or string.
     * @throws InvalidArgument When the passed $options argument is not an array.
     * @throws Exception On request failure
     */
    public function request($url, $headers = [], $data = [], $options = [])
    {
        // Validate inputs (matching Curl transport behavior)
        if (InputValidator::is_string_or_stringable($url) === false) {
            throw InvalidArgument::create(1, '$url', 'string|Stringable', gettype($url));
        }

        if (is_array($headers) === false) {
            throw InvalidArgument::create(2, '$headers', 'array', gettype($headers));
        }

        if (! is_array($data) && ! is_string($data)) {
            if ($data === null) {
                $data = '';
            } else {
                throw InvalidArgument::create(3, '$data', 'array|string', gettype($data));
            }
        }

        if (is_array($options) === false) {
            throw InvalidArgument::create(4, '$options', 'array', gettype($options));
        }

        /** @var HookManager|null $hooks */
        $hooks = $options['hooks'] ?? null;
        /** @var string $method */
        $method = $options['type'] ?? 'GET';

        // Dispatch before_request hook
        $this->dispatch($hooks, 'cli.before_request', [&$url, &$headers, &$data, &$options]);

        // Build RequestOptions from WordPress options + request data
        $requestOptions = $this->buildRequestOptions($headers, $data, $options);

        try {
            // Dispatch before_send hook
            $this->dispatch($hooks, 'cli.before_send', [&$url, &$method, &$requestOptions]);

            $startTime = microtime(true);
            $response = $this->httpCliClient->request($method, (string) $url, $requestOptions);
            $totalTime = microtime(true) - $startTime;

            // Dispatch after_send hook
            $this->dispatch($hooks, 'cli.after_send', [$response]);

            // Get response content, applying max_bytes limit if specified
            $content = $response->getContent();
            /** @var int|false $maxBytes */
            $maxBytes = $options['max_bytes'] ?? false;
            if ($maxBytes !== false && is_int($maxBytes) && strlen($content) > $maxBytes) {
                $content = substr($content, 0, $maxBytes);
            }

            // Save to file if filename option is specified
            /** @var string|false $filename */
            $filename = $options['filename'] ?? false;
            if ($filename !== false && is_string($filename)) {
                file_put_contents($filename, $content);
            }

            // Build raw HTTP response string for WordPress to parse
            $this->headers = $this->buildRawResponse($response, $content);

            // Build info array (similar to curl_getinfo)
            $this->info = [
                'url' => (string) $url,
                'http_code' => $response->getStatusCode(),
                'total_time' => $totalTime,
                'method' => $method,
                'content_length' => strlen($content),
                'content_type' => $this->getContentType($response),
                'size_download' => strlen($content),
                'redirect_count' => 0, // CLI doesn't follow redirects internally
            ];

            // Dispatch after_request hook with headers and info (by reference like curl transport)
            $this->dispatch($hooks, 'cli.after_request', [&$this->headers, &$this->info]);

            return $this->headers;

        } catch (\Exception $e) {
            throw new Exception($e->getMessage(), 'httpclient', $e);
        }
    }

    /**
     * Send multiple requests simultaneously
     *
     * Since CLI execution is synchronous, this executes requests sequentially.
     *
     * @param array<int, array{url: string, headers?: array<string, string>, data?: string|array<string, mixed>, options?: array<string, mixed>}> $requests Request data
     * @param array<string, mixed> $options Global options
     * @return array<int, string|Exception> Array of raw HTTP responses or exceptions
     *
     * @throws InvalidArgument When the passed $requests argument is not an array or iterable object with array access.
     * @throws InvalidArgument When the passed $options argument is not an array.
     */
    public function request_multiple($requests, $options)
    {
        // Validate inputs (matching Curl transport behavior)
        if (InputValidator::has_array_access($requests) === false || InputValidator::is_iterable($requests) === false) {
            throw InvalidArgument::create(1, '$requests', 'array|ArrayAccess&Traversable', gettype($requests));
        }

        if (is_array($options) === false) {
            throw InvalidArgument::create(2, '$options', 'array', gettype($options));
        }

        $responses = [];

        foreach ($requests as $id => $request) {
            $requestOptions = $request['options'] ?? $options;
            /** @var HookManager|null $hooks */
            $hooks = $requestOptions['hooks'] ?? null;

            try {
                $responses[$id] = $this->request(
                    $request['url'],
                    $request['headers'] ?? [],
                    $request['data'] ?? [],
                    $requestOptions
                );

                // Dispatch parse_response hook
                $this->dispatch($hooks, 'transport.internal.parse_response', [&$responses[$id], $request]);

            } catch (Exception $e) {
                $responses[$id] = $e;

                // Dispatch parse_error hook
                $this->dispatch($hooks, 'transport.internal.parse_error', [&$responses[$id], $request]);
            }

            // Dispatch request.complete hook if response is not a string (for compatibility)
            if (! is_string($responses[$id])) {
                $this->dispatch($hooks, 'multiple.request.complete', [&$responses[$id], $id]);
            }
        }

        return $responses;
    }

    /**
     * Self-test whether the transport can be used.
     *
     * @param array<string, bool> $capabilities Optional. Associative array of capabilities to test against.
     * @return bool Whether the transport can be used.
     */
    public static function test($capabilities = [])
    {
        // CLI transport doesn't support SSL in the traditional sense
        // as it runs PHP scripts directly
        if (isset($capabilities[Capability::SSL]) && $capabilities[Capability::SSL]) {
            return false;
        }

        return true;
    }

    /**
     * Factory method to create Cli transport with document root
     *
     * @param string $documentRoot Document root path
     * @param string $file PHP file to execute (default: index.php)
     */
    public static function create(string $documentRoot, string $file = 'index.php'): self
    {
        return new self(new Client($documentRoot, $file));
    }

    /**
     * Factory method to create Cli transport from existing Client
     *
     * @param Client $httpCliClient Existing client instance
     */
    public static function createFromClient(Client $httpCliClient): self
    {
        return new self($httpCliClient);
    }

    /**
     * Build RequestOptions from WordPress request parameters
     *
     * @param array<string, string> $headers Request headers
     * @param string|array<string, mixed> $data Request data
     * @param array<string, mixed> $options WordPress options
     */
    private function buildRequestOptions(array $headers, string|array $data, array $options): RequestOptions
    {
        // First transform WordPress options to RequestOptions
        $baseOptions = $this->adapter->transform($options);

        // Now build the final options with headers and body
        $builder = RequestOptions::create();

        // Copy base options
        if ($baseOptions->timeout !== null) {
            $builder->timeout($baseOptions->timeout);
        }
        if ($baseOptions->userAgent !== null) {
            $builder->userAgent($baseOptions->userAgent);
        }
        if ($baseOptions->maxRedirects !== null) {
            $builder->maxRedirects($baseOptions->maxRedirects);
        }
        if ($baseOptions->basicAuth !== null) {
            $builder->basicAuth($baseOptions->basicAuth[0], $baseOptions->basicAuth[1]);
        }
        if (! empty($baseOptions->cookies)) {
            $builder->cookies($baseOptions->cookies);
        }

        // Add headers
        if (! empty($headers)) {
            $builder->headers($headers);
        }

        // Handle request data based on data_format option
        $dataFormat = $options['data_format'] ?? 'body';
        /** @var string $type */
        $type = $options['type'] ?? 'GET';
        $method = strtoupper($type);

        if (! empty($data)) {
            if ($dataFormat === 'query' || in_array($method, ['GET', 'HEAD'], true)) {
                // Data should be appended as query parameters
                if (is_array($data)) {
                    /** @var array<string, mixed> $data */
                    $builder->query($data);
                } elseif (is_string($data)) {
                    parse_str($data, $queryParams);
                    // @phpstan-ignore argument.type (parse_str output is compatible)
                    $builder->query($queryParams);
                }
            } else {
                // Data should be sent as body
                if (is_array($data)) {
                    // Check content type to determine encoding
                    $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
                    if (str_contains($contentType, 'application/json')) {
                        $builder->json($data);
                    } else {
                        $builder->formParams($data);
                    }
                } else {
                    $builder->body((string) $data);
                }
            }
        }

        return $builder->build();
    }

    /**
     * Build raw HTTP response string from Response object
     *
     * @param Response $response The response object
     * @param string|null $content Optional content override (for max_bytes truncation)
     * @return string Raw HTTP response (status line + headers + body)
     */
    private function buildRawResponse(Response $response, ?string $content = null): string
    {
        $statusCode = $response->getStatusCode();
        $reasonPhrase = $this->getReasonPhrase($statusCode);

        // Build status line
        $raw = "HTTP/1.1 {$statusCode} {$reasonPhrase}\r\n";

        // Add headers
        foreach ($response->getHeaders() as $header) {
            $raw .= $header . "\r\n";
        }

        // Empty line to separate headers from body
        $raw .= "\r\n";

        // Add body (use provided content or get from response)
        $raw .= $content ?? $response->getContent();

        return $raw;
    }

    /**
     * Get Content-Type from response headers
     *
     * @param Response $response The response object
     * @return string Content-Type header value or empty string
     */
    private function getContentType(Response $response): string
    {
        foreach ($response->getHeaders() as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                return trim(substr($header, 13));
            }
        }

        return '';
    }

    /**
     * Get HTTP reason phrase for status code
     *
     * @param int $statusCode HTTP status code
     * @return string Reason phrase
     */
    private function getReasonPhrase(int $statusCode): string
    {
        return SymfonyResponse::$statusTexts[$statusCode] ?? 'Unknown';
    }

    /**
     * Dispatch a hook if hooks are available
     *
     * @param HookManager|null $hooks The hook manager
     * @param string $hook The hook name
     * @param array<int, mixed> $parameters Parameters to pass to callbacks
     */
    private function dispatch(?HookManager $hooks, string $hook, array $parameters = []): void
    {
        if ($hooks !== null) {
            $hooks->dispatch($hook, $parameters);
        }
    }
}
