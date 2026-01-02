<?php

declare(strict_types=1);

namespace n5s\HttpCli\WordPress;

use n5s\HttpCli\OptionsAdapterInterface;
use n5s\HttpCli\RequestOptions;
use n5s\HttpCli\RequestOptionsBuilder;
use n5s\HttpCli\UnsupportedFeatureException;
use WpOrg\Requests\Cookie;
use WpOrg\Requests\Cookie\Jar;

/**
 * Adapter to transform WordPress Requests options TO unified RequestOptions
 *
 * @phpstan-type WordPressOptions array{
 *     timeout?: float|int,
 *     connect_timeout?: float|int,
 *     useragent?: string,
 *     protocol_version?: float,
 *     redirected?: int,
 *     redirects?: int,
 *     follow_redirects?: bool,
 *     blocking?: bool,
 *     type?: string,
 *     filename?: string|false,
 *     auth?: array{0: string, 1: string}|false,
 *     proxy?: string|array<string, string>|false,
 *     cookies?: \WpOrg\Requests\Cookie\Jar|array<string, string>|false,
 *     max_bytes?: int|false,
 *     idn?: bool,
 *     hooks?: \WpOrg\Requests\Hooks|null,
 *     transport?: string|object|null,
 *     verify?: string|bool|null,
 *     verifyname?: bool,
 *     data_format?: string,
 * }
 */
final class WordPressToRequestOptionsAdapter implements OptionsAdapterInterface
{
    /**
     * @var array<string, bool>
     */
    private const SUPPORTED_OPTIONS = [
        'timeout' => true,
        'connect_timeout' => false, // No network connection, connect timeout not applicable
        'useragent' => true,
        'redirects' => true,
        'follow_redirects' => true,
        'auth' => true,
        'proxy' => false, // No network connection, proxy not applicable
        'cookies' => true,
        'verify' => false, // No network connection, SSL not applicable
        'verifyname' => false, // No network connection, SSL not applicable
        // Ignored options (not applicable to CLI context or handled elsewhere)
        'protocol_version' => false,
        'redirected' => false,
        'blocking' => false,
        'type' => false, // HTTP method is passed separately
        'filename' => false, // Streaming not supported in CLI context
        'max_bytes' => false, // Not applicable
        'idn' => false, // IDN handled by URL parsing
        'hooks' => false, // Internal to WordPress Requests
        'transport' => false, // We ARE the transport
        'data_format' => false, // Handled in request method
    ];

    /**
     * Transform WordPress Requests options to unified RequestOptions
     *
     * @param array<string, mixed> $libraryOptions WordPress Requests options
     */
    public function transform(array $libraryOptions): RequestOptions
    {
        $builder = RequestOptions::create();

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
                'useragent' => $value !== null ? $builder->userAgent((string) $value) : null,
                'redirects' => $this->handleRedirects($builder, $value, $libraryOptions),
                'follow_redirects' => null, // Handled together with redirects
                'auth' => $value !== false ? $this->handleAuth($builder, $value) : null,
                'cookies' => $value !== false ? $this->handleCookies($builder, $value) : null,
                default => null,
            };
        }

        return $builder->build();
    }

    public function getSourceLibrary(): string
    {
        return 'WordPress Requests';
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
     * Handle redirects option
     *
     * @param array<string, mixed> $options Full options array for follow_redirects check
     */
    private function handleRedirects(RequestOptionsBuilder $builder, mixed $value, array $options): void
    {
        $followRedirects = $options['follow_redirects'] ?? true;

        if ($followRedirects === false) {
            $builder->maxRedirects(0);
        } elseif (is_int($value)) {
            $builder->maxRedirects($value);
        }
    }

    /**
     * Handle authentication option
     *
     * @param array{0: string, 1: string}|mixed $value Auth credentials
     */
    private function handleAuth(RequestOptionsBuilder $builder, mixed $value): void
    {
        if (! is_array($value) || count($value) < 2) {
            return;
        }

        $builder->basicAuth((string) $value[0], (string) $value[1]);
    }

    /**
     * Handle cookies option
     *
     * @param Jar|array<string, string>|mixed $value Cookies
     */
    private function handleCookies(RequestOptionsBuilder $builder, mixed $value): void
    {
        if ($value instanceof Jar) {
            $cookies = [];
            foreach ($value as $cookie) {
                /** @var Cookie $cookie */
                $cookies[$cookie->name] = $cookie->value;
            }
            if (! empty($cookies)) {
                $builder->cookies($cookies);
            }
        } elseif (is_array($value)) {
            /** @var array<string, string> $value */
            $builder->cookies($value);
        }
    }
}
