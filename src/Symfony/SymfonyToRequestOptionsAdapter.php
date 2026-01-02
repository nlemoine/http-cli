<?php

declare(strict_types=1);

namespace n5s\HttpCli\Symfony;

use n5s\HttpCli\OptionsAdapterInterface;
use n5s\HttpCli\RequestOptions;
use n5s\HttpCli\RequestOptionsBuilder;
use n5s\HttpCli\UnsupportedFeatureException;

/**
 * Adapter to transform Symfony HTTP Client options TO unified RequestOptions
 *
 * @phpstan-type SymfonyOptions array{
 *     timeout?: float|null,
 *     headers?: array<string, string|list<string>>|list<string>,
 *     query?: array<string, mixed>,
 *     body?: string|resource|\Traversable<string>|\Closure,
 *     json?: mixed,
 *     auth_basic?: array{0: string, 1: string}|string|null,
 *     auth_bearer?: string|null,
 *     verify_peer?: bool,
 *     verify_host?: bool,
 *     cafile?: string|null,
 *     proxy?: string|null,
 *     max_redirects?: int|null,
 *     normalized_headers?: array<string, list<string>>,
 *     ...
 * }
 */
final class SymfonyToRequestOptionsAdapter implements OptionsAdapterInterface
{
    /**
     * @var array<string, bool>
     */
    private const SUPPORTED_OPTIONS = [
        'timeout' => true,
        'headers' => true,
        'query' => true,
        'body' => true,
        'json' => true,
        'auth_basic' => true,
        'auth_bearer' => true,
        'verify_peer' => false, // No network connection, SSL not applicable
        'verify_host' => false, // No network connection, SSL not applicable
        'cafile' => false, // No network connection, SSL not applicable
        'proxy' => false, // No network connection, proxy not applicable
        'max_redirects' => true,
        'user_data' => false, // Ignored - not applicable to our use case
        'http_version' => false, // Ignored - handled by transport
        'base_uri' => false, // Ignored - URL should be absolute
        'buffer' => false, // Ignored - always buffer in CLI context
        'on_progress' => false, // Ignored - not supported in CLI context
        'resolve' => false, // Ignored - DNS resolution not controllable
        'no_proxy' => false, // Ignored - use proxy option instead
        'max_duration' => false, // Ignored - use timeout instead
        'bindto' => false, // Ignored - not applicable in CLI context
        'capath' => false, // Ignored - use cafile instead
        'local_cert' => false, // Not implemented yet
        'local_pk' => false, // Not implemented yet
        'passphrase' => false, // Not implemented yet
        'ciphers' => false, // Not implemented yet
        'peer_fingerprint' => false, // Not implemented yet
        'capture_peer_cert_chain' => false, // Not implemented yet
        'crypto_method' => false, // Not implemented yet
        'extra' => false, // Handled separately
        'normalized_headers' => false, // Internal - added by HttpClientTrait::prepareRequest
    ];

    /**
     * @param SymfonyOptions $libraryOptions Symfony HTTP Client options
     */
    public function transform(array $libraryOptions): RequestOptions
    {
        $builder = RequestOptions::create();

        // Map Symfony options to RequestOptions
        foreach ($libraryOptions as $option => $value) {
            if (! $this->supportsOption($option)) {
                if (! isset(self::SUPPORTED_OPTIONS[$option])) {
                    throw new UnsupportedFeatureException($option, $this->getSourceLibrary());
                }
                // Option exists but is marked as unsupported (false) - ignore it
                continue;
            }

            match ($option) {
                'timeout' => $value !== null ? $builder->timeout((float) $value) : null,
                'headers' => ! empty($value) ? $this->handleHeaders($builder, $value) : null,
                'query' => ! empty($value) ? $builder->query((array) $value) : null,
                'body' => $value !== null && $value !== '' ? $this->handleBody($builder, $value, $libraryOptions) : null,
                'json' => $value !== null ? $builder->json($value) : null,
                'auth_basic' => $value !== null ? $this->handleBasicAuth($builder, $value) : null,
                'auth_bearer' => $value !== null ? $builder->bearerToken((string) $value) : null,
                'max_redirects' => $value !== null ? $builder->maxRedirects((int) $value) : null,
                default => null, // Should not reach here due to supportsOption check
            };
        }

        return $builder->build();
    }

    public function getSourceLibrary(): string
    {
        return 'symfony/http-client';
    }

    public function supportsOption(string $optionName): bool
    {
        return isset(self::SUPPORTED_OPTIONS[$optionName]) && self::SUPPORTED_OPTIONS[$optionName] === true;
    }

    /**
     * @return list<string> List of supported option names
     */
    public function getSupportedOptions(): array
    {
        return array_keys(array_filter(self::SUPPORTED_OPTIONS));
    }

    /**
     * Handle body - converts to formParams if Content-Type is application/x-www-form-urlencoded
     *
     * @param array<string, mixed> $options Symfony options containing headers for content-type detection
     */
    private function handleBody(RequestOptionsBuilder $builder, mixed $value, array $options): void
    {
        $contentType = $this->getContentTypeFromHeaders($options['headers'] ?? []);

        // If Content-Type is form-urlencoded, parse body into form params
        if ($contentType !== null && str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str((string) $value, $formParams);
            if (! empty($formParams)) {
                // @phpstan-ignore argument.type (parse_str with valid query string produces string keys)
                $builder->formParams($formParams);
                return;
            }
        }

        $builder->body((string) $value);
    }

    /**
     * Extract Content-Type from headers array
     *
     * @param array<int|string, string|list<string>> $headers Headers in indexed or key-value format
     */
    private function getContentTypeFromHeaders(array $headers): ?string
    {
        foreach ($headers as $key => $value) {
            if (is_int($key) && is_string($value)) {
                // Indexed format: "Content-Type: application/json"
                if (stripos($value, 'content-type:') === 0) {
                    return trim(substr($value, 13));
                }
            } elseif (is_string($key) && strcasecmp($key, 'content-type') === 0) {
                // Key-value format
                return is_array($value) ? $value[0] : (string) $value;
            }
        }
        return null;
    }

    /**
     * Handle headers - supports both key-value arrays and indexed arrays with "Name: Value" strings
     * Also extracts Cookie header and sets cookies separately
     *
     * @param array<int|string, string|list<string>> $value Headers in indexed or key-value format
     */
    private function handleHeaders(RequestOptionsBuilder $builder, array $value): void
    {
        $normalizedHeaders = [];
        $cookieString = null;

        foreach ($value as $key => $headerValue) {
            if (is_int($key) && is_string($headerValue)) {
                // Indexed array format from prepareRequest: ["X-Test: value", "Accept: */*"]
                $colonPos = strpos($headerValue, ':');
                if ($colonPos !== false) {
                    $name = trim(substr($headerValue, 0, $colonPos));
                    $val = trim(substr($headerValue, $colonPos + 1));

                    // Extract Cookie header for separate handling
                    if (strcasecmp($name, 'Cookie') === 0) {
                        $cookieString = $val;
                    } else {
                        $normalizedHeaders[$name] = $val;
                    }
                }
            } elseif (is_string($key)) {
                // Key-value format: ["X-Test" => "value"] or ["X-Test" => ["value1", "value2"]]
                if (strcasecmp($key, 'Cookie') === 0) {
                    $cookieString = is_array($headerValue) ? implode('; ', $headerValue) : (string) $headerValue;
                } elseif (is_array($headerValue)) {
                    $normalizedHeaders[$key] = implode(', ', $headerValue);
                } else {
                    $normalizedHeaders[$key] = (string) $headerValue;
                }
            }
        }

        if (! empty($normalizedHeaders)) {
            $builder->headers($normalizedHeaders);
        }

        // Parse Cookie header into cookies array
        if ($cookieString !== null) {
            $cookies = $this->parseCookieString($cookieString);
            if (! empty($cookies)) {
                $builder->cookies($cookies);
            }
        }
    }

    /**
     * Parse cookie string (e.g., "name=value; other=val2") into array
     *
     * @return array<string, string> Cookies as name => value pairs
     */
    private function parseCookieString(string $cookieString): array
    {
        $cookies = [];
        $pairs = explode(';', $cookieString);

        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }

            $eqPos = strpos($pair, '=');
            if ($eqPos !== false) {
                $name = trim(substr($pair, 0, $eqPos));
                $value = trim(substr($pair, $eqPos + 1));
                $cookies[$name] = $value;
            }
        }

        return $cookies;
    }

    /**
     * Handle basic authentication
     *
     * @param array{0: string, 1: string}|string $value Auth as [username, password] array or "user:pass" string
     */
    private function handleBasicAuth(RequestOptionsBuilder $builder, mixed $value): void
    {
        if (is_array($value)) {
            $builder->basicAuth((string) $value[0], (string) $value[1]);
        } elseif (str_contains((string) $value, ':')) {
            [$username, $password] = explode(':', (string) $value, 2);
            $builder->basicAuth($username, $password);
        } else {
            throw new UnsupportedFeatureException('auth_basic format not supported', $this->getSourceLibrary());
        }
    }
}
