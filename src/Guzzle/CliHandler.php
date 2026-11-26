<?php

declare(strict_types=1);

namespace n5s\HttpCli\Guzzle;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use n5s\HttpCli\Client;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class CliHandler
{
    private readonly GuzzleToRequestOptionsAdapter $adapter;

    /**
     * Exception thrown by the caller's on_stats callback during the current
     * request. It is rethrown as is instead of being wrapped as a transfer
     * failure, like Guzzle 8's handlers do.
     */
    private ?\Throwable $onStatsException = null;

    public function __construct(
        private readonly Client $httpCliClient
    ) {
        $this->adapter = new GuzzleToRequestOptionsAdapter();
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(RequestInterface $request, array $options = []): PromiseInterface
    {
        // Sleep if there is a delay specified.
        if (isset($options[RequestOptions::DELAY])) {
            // Guzzle documents the delay in milliseconds, as int or float
            \usleep(\max(0, (int) \round((float) $options[RequestOptions::DELAY] * 1000)));
        }

        $protocolVersion = $request->getProtocolVersion();

        if ($protocolVersion !== '1.0' && $protocolVersion !== '1.1') {
            // Not a network failure: Guzzle 8 throws RequestException here,
            // and a ConnectException would look retryable to retry deciders.
            throw new RequestException(
                \sprintf('HTTP/%s is not supported by the CLI handler.', $protocolVersion),
                $request
            );
        }

        $startTime = isset($options[RequestOptions::ON_STATS]) ? self::currentTime() : null;

        try {
            // Does not support the expect header.
            $request = $request->withoutHeader('Expect');

            // Append a content-length header if body size is zero to match
            // the behavior of `CurlHandler`
            if (
                (
                    \strcasecmp('PUT', $request->getMethod()) === 0
                    || \strcasecmp('POST', $request->getMethod()) === 0
                )
                && $request->getBody()->getSize() === 0
            ) {
                $request = $request->withHeader('Content-Length', '0');
            }

            return $this->createResponse(
                $request,
                $options,
                $startTime
            );
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Exception $e) {
            $onStatsException = $this->onStatsException;
            $this->onStatsException = null;
            if ($e === $onStatsException) {
                throw $e;
            }

            // Already a Guzzle transfer failure, for example from a sink
            // stream the caller passed: keep it as is, like Guzzle 8.
            if ($e instanceof TransferException) {
                $this->invokeStats($options, $request, $startTime, null, $e);

                return P\Create::rejectionFor($e);
            }

            // Determine if the error was a networking error.
            $message = $e->getMessage();
            if (
                str_contains($message, 'getaddrinfo')
                || str_contains($message, 'Connection refused')
                || str_contains($message, "couldn't connect to host")
                || str_contains($message, 'connection attempt failed')
            ) {
                $e = new ConnectException($e->getMessage(), $request, $e);
            } else {
                $e = new RequestException($e->getMessage(), $request, previous: $e);
            }
            $this->invokeStats($options, $request, $startTime, null, $e);

            return P\Create::rejectionFor($e);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createResponse(RequestInterface $request, array $options, ?float $startTime): PromiseInterface
    {
        // Merge request data with options
        $guzzleOptions = $this->mergeRequestWithOptions($request, $options);

        // Transform Guzzle options to RequestOptions using our adapter
        $requestOptions = $this->adapter->transform($guzzleOptions);

        $cliResponse = $this->httpCliClient->request(
            $request->getMethod(),
            (string) $request->getUri(),
            $requestOptions
        );

        $parsedHeaders = $this->parseHeaders($cliResponse->getHeaders());
        [$stream, $headers] = $this->checkDecode($options, $parsedHeaders, $cliResponse->getContent());
        $stream = Psr7\Utils::streamFor($stream);
        $statusCode = (int) $cliResponse->getStatusCode();

        // A response without a body never touches the sink, so a sink file
        // is not truncated by a 204 or a 304.
        $canHaveBody = self::responseCanHaveBody($request->getMethod(), $statusCode);
        $sink = $canHaveBody ? $this->createSink($stream, $options) : Psr7\Utils::streamFor('');

        try {
            $response = new Response(
                $statusCode,
                $headers,
                $sink,
                '1.1', // HTTP version - CLI always simulates HTTP/1.1
                null   // Reason phrase - let Guzzle derive from status code
            );
        } catch (\Throwable $e) {
            $reason = new RequestException('An error was encountered while creating the response', $request, previous: $e);
            $this->invokeStats($options, $request, $startTime, null, $reason);

            return P\Create::rejectionFor($reason);
        }

        if (isset($options[RequestOptions::ON_HEADERS])) {
            try {
                $options[RequestOptions::ON_HEADERS]($response, $request);
            } catch (\Throwable $e) {
                $reason = self::responseException('An error was encountered during the on_headers event', $request, $response, $e);
                $this->invokeStats($options, $request, $startTime, $response, $reason);

                return P\Create::rejectionFor($reason);
            }
        }

        // Drain the source stream into the sink after on_headers callback
        if ($canHaveBody && $sink !== $stream) {
            $this->drain($stream, $sink, $response->getHeaderLine('Content-Length'));
        }

        $this->invokeStats($options, $request, $startTime, $response, null);

        return new FulfilledPromise($response);
    }

    /**
     * Invoke ON_STATS callback with transfer statistics
     *
     * @param array<string, mixed> $options Guzzle request options
     */
    private function invokeStats(
        array $options,
        RequestInterface $request,
        ?float $startTime,
        ?ResponseInterface $response = null,
        ?\Throwable $error = null
    ): void {
        if (! isset($options[RequestOptions::ON_STATS])) {
            return;
        }

        $stats = new TransferStats($request, $response, self::currentTime() - $startTime, $error, []);

        try {
            ($options[RequestOptions::ON_STATS])($stats);
        } catch (\Throwable $e) {
            $this->onStatsException = $e;

            throw $e;
        }
    }

    /**
     * RFC 9110: HEAD responses and 1xx, 204 and 304 statuses have no body.
     */
    private static function responseCanHaveBody(string $method, int $statusCode): bool
    {
        return \strcasecmp('HEAD', $method) !== 0
            && $statusCode >= 200
            && $statusCode !== 204
            && $statusCode !== 304;
    }

    /**
     * Create sink stream based on options.
     *
     * @param array<string, mixed> $options Guzzle request options
     */
    private function createSink(StreamInterface $stream, array $options): StreamInterface
    {
        // If stream option is set, return the original stream (no draining)
        if (! empty($options[RequestOptions::STREAM])) {
            return $stream;
        }

        $sink = $options[RequestOptions::SINK] ?? Psr7\Utils::tryFopen('php://temp', 'r+');

        return \is_string($sink) ? new Psr7\LazyOpenStream($sink, 'w+') : Psr7\Utils::streamFor($sink);
    }

    /**
     * Drain the source stream into the sink, respecting Content-Length.
     */
    private function drain(StreamInterface $source, StreamInterface $sink, string $contentLength): StreamInterface
    {
        Psr7\Utils::copyToStream(
            $source,
            $sink,
            (\strlen($contentLength) > 0 && (int) $contentLength > 0) ? (int) $contentLength : -1
        );

        $sink->seek(0);
        $source->close();

        return $sink;
    }

    /**
     * Automatically decode responses when instructed.
     *
     * @see https://github.com/guzzle/guzzle/blob/7b2f29fe81dc4da0ca0ea7d42107a0845946ea77/src/Handler/StreamHandler.php#L171-L203
     *
     * @param array<string, mixed> $options Guzzle request options
     * @param array<string, list<string>> $headers Response headers in PSR-7 format
     * @param string $stream Response body content
     * @return array{0: string|StreamInterface, 1: array<string, list<string>>} Tuple of [decoded content, modified headers]
     */
    private function checkDecode(array $options, array $headers, string $stream): array
    {
        if (empty($options[RequestOptions::DECODE_CONTENT])) {
            return [$stream, $headers];
        }

        // Lowercase name => original name. Utils::normalizeHeaderKeys() did
        // this but is deprecated since Guzzle 8.5.
        $normalizedKeys = [];
        foreach (\array_keys($headers) as $name) {
            $normalizedKeys[\strtolower($name)] = $name;
        }
        if (! isset($normalizedKeys['content-encoding'])) {
            return [$stream, $headers];
        }

        $encoding = $headers[$normalizedKeys['content-encoding']];
        if (! in_array($encoding[0], ['gzip', 'deflate'], true)) {
            return [$stream, $headers];
        }

        $stream = new Psr7\InflateStream(Psr7\Utils::streamFor($stream));
        $headers['x-encoded-content-encoding'] = $headers[$normalizedKeys['content-encoding']];

        // Remove content-encoding header
        unset($headers[$normalizedKeys['content-encoding']]);

        // The decoded length is unknown until the body is inflated, and
        // InflateStream::getSize() reports the encoded size. Keep the original
        // value aside and drop Content-Length, as Guzzle 8 does, so drain()
        // copies the whole decoded body.
        if (isset($normalizedKeys['content-length'])) {
            $headers['x-encoded-content-length'] = $headers[$normalizedKeys['content-length']];
            unset($headers[$normalizedKeys['content-length']]);
        }

        return [$stream, $headers];
    }

    /**
     * Parse header strings into PSR-7 compatible format
     *
     * @param list<string> $headers Array of "Name: Value" strings
     * @return array<string, list<string>> PSR-7 compatible headers
     */
    private function parseHeaders(array $headers): array
    {
        $parsed = [];
        foreach ($headers as $header) {
            $colonPos = strpos($header, ':');
            if ($colonPos !== false) {
                $name = trim(substr($header, 0, $colonPos));
                $value = trim(substr($header, $colonPos + 1));
                $parsed[$name] ??= [];
                $parsed[$name][] = $value;
            }
        }
        return $parsed;
    }

    /**
     * Merge PSR-7 request data with Guzzle options
     *
     * @param array<string, mixed> $options Guzzle request options
     * @return array<string, mixed> Merged options with request data
     */
    private function mergeRequestWithOptions(RequestInterface $request, array $options): array
    {
        // Start with provided options
        $guzzleOptions = $options;

        // Add headers from the request
        $requestHeaders = $request->getHeaders();
        if (! empty($requestHeaders)) {
            $existingHeaders = $guzzleOptions[RequestOptions::HEADERS] ?? [];
            $guzzleOptions[RequestOptions::HEADERS] = array_merge($existingHeaders, $requestHeaders);
        }

        // Add body from the request if present
        $body = $request->getBody();
        if ($body->getSize() > 0) {
            $bodyContent = (string) $body;

            // Check if it's JSON content
            $contentType = $request->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                $guzzleOptions[RequestOptions::JSON] = json_decode($bodyContent, true) ?: $bodyContent;
            } else {
                $guzzleOptions[RequestOptions::BODY] = $bodyContent;
            }
        }

        return $guzzleOptions;
    }

    /**
     * Monotonic clock in seconds. Guzzle 7 exposed this as Utils::currentTime(),
     * Guzzle 8 removed it.
     */
    private static function currentTime(): float
    {
        return \hrtime(true) / 1e9;
    }

    /**
     * Build a response-aware request exception. Guzzle 8 moved the response
     * from RequestException to the new ResponseException subclass.
     */
    private static function responseException(
        string $message,
        RequestInterface $request,
        ResponseInterface $response,
        \Throwable $previous
    ): RequestException {
        if (\class_exists(ResponseException::class)) {
            return new ResponseException($message, $request, $response, $previous);
        }

        // Guzzle 7 only. Coverage is collected on a Guzzle 8 install, the
        // lowest-dependencies CI job runs this branch.
        return new RequestException($message, $request, $response, $previous); // @codeCoverageIgnore
    }
}
