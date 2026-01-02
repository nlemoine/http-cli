<?php

declare(strict_types=1);

namespace n5s\HttpCli;

/**
 * Fluent builder for RequestOptions
 *
 * @phpstan-import-type MultipartPart from RequestOptions
 */
final class RequestOptionsBuilder
{
    private ?float $timeout = null;

    /**
     * @var array<string, string>
     */
    private array $headers = [];

    /**
     * @var array<string, mixed>
     */
    private array $query = [];

    private ?string $body = null;

    private mixed $json = null;

    /**
     * @var array<string, mixed>
     */
    private array $formParams = [];

    /**
     * @var list<MultipartPart>
     */
    private array $multipart = [];

    /**
     * @var array{0: string, 1: string}|null
     */
    private ?array $basicAuth = null;

    private ?string $bearerToken = null;

    private ?int $maxRedirects = null;

    private ?string $userAgent = null;

    /**
     * @var array<string, string>
     */
    private array $cookies = [];

    /**
     * @var array<string, mixed>
     */
    private array $session = [];

    /**
     * @var array<string, mixed>
     */
    private array $extras = [];

    /**
     * Set request timeout in seconds
     */
    public function timeout(float $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * Set all headers at once
     *
     * @param array<string, string> $headers Headers as name => value pairs
     */
    public function headers(array $headers): self
    {
        $this->headers = $headers;
        return $this;
    }

    /**
     * Add a single header
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Set query parameters
     *
     * @param array<string, mixed> $params Query parameters
     */
    public function query(array $params): self
    {
        $this->query = $params;
        return $this;
    }

    /**
     * Add a single query parameter
     */
    public function queryParam(string $name, string $value): self
    {
        $this->query[$name] = $value;
        return $this;
    }

    /**
     * Set raw body content
     */
    public function body(string $content): self
    {
        $this->clearBodyOptions();
        $this->body = $content;
        return $this;
    }

    /**
     * Set JSON payload (will be automatically encoded)
     */
    public function json(mixed $data): self
    {
        $this->clearBodyOptions();
        $this->json = $data;
        return $this;
    }

    /**
     * Set form parameters (application/x-www-form-urlencoded)
     *
     * @param array<string, mixed> $params Form parameters
     */
    public function formParams(array $params): self
    {
        $this->clearBodyOptions();
        $this->formParams = $params;
        return $this;
    }

    /**
     * Set multipart form data
     *
     * @param list<MultipartPart> $parts Multipart form data parts
     */
    public function multipart(array $parts): self
    {
        $this->clearBodyOptions();
        $this->multipart = $parts;
        return $this;
    }

    /**
     * Set HTTP Basic authentication
     */
    public function basicAuth(string $username, string $password): self
    {
        $this->basicAuth = [$username, $password];
        $this->bearerToken = null; // Clear other auth
        return $this;
    }

    /**
     * Set Bearer token authentication
     */
    public function bearerToken(string $token): self
    {
        $this->bearerToken = $token;
        $this->basicAuth = null; // Clear other auth
        return $this;
    }

    /**
     * Set maximum number of redirects
     */
    public function maxRedirects(int $max): self
    {
        $this->maxRedirects = $max;
        return $this;
    }

    /**
     * Set user agent string
     */
    public function userAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    /**
     * Set all cookies at once
     *
     * @param array<string, string> $cookies Cookies as name => value pairs
     */
    public function cookies(array $cookies): self
    {
        $this->cookies = $cookies;
        return $this;
    }

    /**
     * Add a single cookie
     */
    public function cookie(string $name, string $value): self
    {
        $this->cookies[$name] = $value;
        return $this;
    }

    /**
     * Set session data
     *
     * @param array<string, mixed> $data Session data
     */
    public function session(array $data): self
    {
        $this->session = $data;
        return $this;
    }

    /**
     * Add extra options (for library-specific features)
     */
    public function extra(string $key, mixed $value): self
    {
        $this->extras[$key] = $value;
        return $this;
    }

    /**
     * Set multiple extra options at once
     *
     * @param array<string, mixed> $extras Extra options
     */
    public function extras(array $extras): self
    {
        $this->extras = array_merge($this->extras, $extras);
        return $this;
    }

    /**
     * Build the immutable RequestOptions object
     */
    public function build(): RequestOptions
    {
        return new RequestOptions(
            timeout: $this->timeout,
            headers: $this->headers,
            query: $this->query,
            body: $this->body,
            json: $this->json,
            formParams: $this->formParams,
            multipart: $this->multipart,
            basicAuth: $this->basicAuth,
            bearerToken: $this->bearerToken,
            maxRedirects: $this->maxRedirects,
            userAgent: $this->userAgent,
            cookies: $this->cookies,
            session: $this->session,
            extras: $this->extras
        );
    }

    /**
     * Clear all body-related options (used internally to enforce mutual exclusivity)
     */
    private function clearBodyOptions(): void
    {
        $this->body = null;
        $this->json = null;
        $this->formParams = [];
        $this->multipart = [];
    }
}
