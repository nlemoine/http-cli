<?php

declare(strict_types=1);

namespace n5s\HttpCli;

use InvalidArgumentException;

/**
 * Unified HTTP request options - immutable value object
 *
 * @phpstan-type MultipartPart array{name: string, contents: string, filename?: string, headers?: array<string, string>}
 */
final readonly class RequestOptions
{
    /**
     * @param array<string, string> $headers Request headers as name => value pairs
     * @param array<string, mixed> $query Query parameters
     * @param array<string, mixed> $formParams Form parameters for POST requests
     * @param list<MultipartPart> $multipart Multipart form data parts
     * @param array{0: string, 1: string}|null $basicAuth Basic auth as [username, password]
     * @param array<string, string> $cookies Cookies as name => value pairs
     * @param array<string, mixed> $session Session data
     * @param array<string, mixed> $extras Extra library-specific options
     */
    public function __construct(
        public ?float $timeout = null,
        public array $headers = [],
        public array $query = [],
        public ?string $body = null,
        public mixed $json = null,
        public array $formParams = [],
        public array $multipart = [],
        public ?array $basicAuth = null,
        public ?string $bearerToken = null,
        public ?int $maxRedirects = null,
        public ?string $userAgent = null,
        public array $cookies = [],
        public array $session = [],
        public array $extras = []
    ) {
        $this->validate();
    }

    /**
     * Create a new builder instance
     */
    public static function create(): RequestOptionsBuilder
    {
        return new RequestOptionsBuilder();
    }

    /**
     * Create empty RequestOptions (for default parameter)
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Convert to array representation
     *
     * @return array<string, mixed> Options as associative array
     */
    public function toArray(): array
    {
        $result = [];

        if ($this->timeout !== null) {
            $result['timeout'] = $this->timeout;
        }
        if (! empty($this->headers)) {
            $result['headers'] = $this->headers;
        }
        if (! empty($this->query)) {
            $result['query'] = $this->query;
        }
        if ($this->body !== null) {
            $result['body'] = $this->body;
        }
        if ($this->json !== null) {
            $result['json'] = $this->json;
        }
        if (! empty($this->formParams)) {
            $result['form_params'] = $this->formParams;
        }
        if (! empty($this->multipart)) {
            $result['multipart'] = $this->multipart;
        }
        if ($this->basicAuth !== null) {
            $result['basic_auth'] = $this->basicAuth;
        }
        if ($this->bearerToken !== null) {
            $result['bearer_token'] = $this->bearerToken;
        }
        if ($this->maxRedirects !== null) {
            $result['max_redirects'] = $this->maxRedirects;
        }
        if ($this->userAgent !== null) {
            $result['user_agent'] = $this->userAgent;
        }
        if (! empty($this->cookies)) {
            $result['cookies'] = $this->cookies;
        }
        if (! empty($this->session)) {
            $result['session'] = $this->session;
        }
        if (! empty($this->extras)) {
            $result['extras'] = $this->extras;
        }

        return $result;
    }

    /**
     * Validate options for consistency and correctness
     */
    private function validate(): void
    {
        // Timeout validation
        if ($this->timeout !== null && $this->timeout <= 0) {
            throw new InvalidArgumentException('Timeout must be positive');
        }

        // Body options are mutually exclusive
        $bodyOptionCount = 0;
        if ($this->body !== null) {
            $bodyOptionCount++;
        }
        if ($this->json !== null) {
            $bodyOptionCount++;
        }
        if (! empty($this->formParams)) {
            $bodyOptionCount++;
        }
        if (! empty($this->multipart)) {
            $bodyOptionCount++;
        }

        if ($bodyOptionCount > 1) {
            throw new InvalidArgumentException('Only one body option (body, json, formParams, multipart) can be specified');
        }

        // Note: basicAuth type is enforced by PHPDoc as array{0: string, 1: string}|null
        // Headers are typed as array<string, string>, so keys are always strings

        // Redirects validation
        if ($this->maxRedirects !== null && $this->maxRedirects < 0) {
            throw new InvalidArgumentException('Max redirects must be non-negative');
        }
    }
}
