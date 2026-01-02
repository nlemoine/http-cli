<?php

declare(strict_types=1);

namespace n5s\HttpCli\Symfony;

use n5s\HttpCli\Response as HttpCliResponse;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\JsonException;
use Symfony\Component\HttpClient\Exception\RedirectionException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Adapter that wraps Client Response to implement Symfony's ResponseInterface
 * following the exact patterns used in Symfony's MockResponse.
 */
final class CliResponse implements ResponseInterface
{
    /**
     * @var array<string, mixed> Response metadata
     */
    private array $info = [];

    /**
     * @var array<string, list<string>> Normalized headers (lowercase keys, array values)
     */
    private array $headers = [];

    /**
     * @var array<string, mixed>|null Cached JSON-decoded response body
     */
    private ?array $jsonData = null;

    /**
     * @param array{url?: string, method?: string} $requestOptions Request metadata for info array
     */
    public function __construct(
        private readonly HttpCliResponse $response,
        array $requestOptions = []
    ) {
        // Initialize info array following MockResponse pattern
        $this->info = [
            'http_code' => $this->response->getStatusCode(),
            'url' => $requestOptions['url'] ?? '',
            'start_time' => microtime(true),
            'total_time' => 0,
            'redirect_count' => 0,
            'redirect_url' => null,
            'debug' => '',
            'response_headers' => [],
        ];

        // Add headers to the headers array (lowercase keys with arrays of values)
        // Also populates info['response_headers'] as raw header strings
        $this->addResponseHeaders($this->response->getHeaders(), $this->info, $this->headers);
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * @return array<string, list<string>> Normalized headers (lowercase keys, array values)
     */
    public function getHeaders(bool $throw = true): array
    {
        if ($throw) {
            $this->checkStatusCode();
        }

        return $this->headers;
    }

    public function getContent(bool $throw = true): string
    {
        if ($throw) {
            $this->checkStatusCode();
        }

        return $this->response->getContent();
    }

    /**
     * @return array<string, mixed> JSON-decoded response body
     */
    public function toArray(bool $throw = true): array
    {
        if ($this->jsonData !== null) {
            return $this->jsonData;
        }

        $content = $this->getContent($throw);

        if ($content === '') {
            throw new JsonException('Response body is empty.');
        }

        try {
            $content = json_decode($content, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new JsonException($e->getMessage() . sprintf(' for "%s".', $this->getInfo('url')), $e->getCode());
        }

        if (! \is_array($content)) {
            throw new JsonException(sprintf('JSON content was expected to decode to an array, "%s" returned for "%s".', get_debug_type($content), $this->getInfo('url')));
        }

        return $this->jsonData = $content;
    }

    public function cancel(): void
    {
        // CLI responses are already complete, nothing to cancel
        $this->info['canceled'] = true;
    }

    /**
     * @return ($type is null ? array<string, mixed> : mixed) Response metadata or specific value
     */
    public function getInfo(?string $type = null): mixed
    {
        // Lazy load content type if not set
        if (! isset($this->info['content_type'])) {
            $this->info['content_type'] = $this->headers['content-type'][0] ?? null;
        }

        if ($type === null) {
            return $this->info;
        }

        return $this->info[$type] ?? null;
    }

    /**
     * Add response headers following Symfony patterns
     * Simplified version inspired by TransportResponseTrait::addResponseHeaders
     *
     * @param list<string> $responseHeaders Raw header strings ("Name: Value")
     * @param array<string, mixed> $info Response info array (modified by reference)
     * @param array<string, list<string>> $headers Headers array (modified by reference)
     */
    private function addResponseHeaders(array $responseHeaders, array &$info, array &$headers): void
    {
        foreach ($responseHeaders as $header) {
            if (! str_contains($header, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $header, 2);
            $name = strtolower(trim($name));
            $value = trim($value);

            if (! isset($headers[$name])) {
                $headers[$name] = [];
            }
            $headers[$name][] = $value;

            // Add to info response_headers array (preserving original case)
            $info['response_headers'][] = $header;
        }
    }

    /**
     * Check status code and throw appropriate exceptions
     */
    private function checkStatusCode(): void
    {
        $code = $this->getStatusCode();

        if ($code >= 300 && $code < 400) {
            throw new RedirectionException($this);
        }

        if ($code >= 400 && $code < 500) {
            throw new ClientException($this);
        }

        if ($code >= 500) {
            throw new ServerException($this);
        }
    }
}
