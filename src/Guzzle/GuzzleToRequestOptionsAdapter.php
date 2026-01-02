<?php

declare(strict_types=1);

namespace n5s\HttpCli\Guzzle;

use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\RequestOptions as GuzzleOptions;
use InvalidArgumentException;
use n5s\HttpCli\OptionsAdapterInterface;
use n5s\HttpCli\RequestOptions;
use n5s\HttpCli\RequestOptionsBuilder;
use n5s\HttpCli\UnsupportedFeatureException;
use Psr\Http\Message\StreamInterface;
use SplObjectStorage;

/**
 * Comprehensive adapter that transforms Guzzle options TO unified RequestOptions
 *
 * This adapter handles all major Guzzle RequestOptions constants and provides
 * comprehensive error handling, validation, and modern PHP patterns.
 */
final readonly class GuzzleToRequestOptionsAdapter implements OptionsAdapterInterface
{
    /**
     * Supported Guzzle options mapped to handler methods.
     * These options are transformed to RequestOptions for Client.
     */
    private const OPTION_HANDLERS = [
        GuzzleOptions::TIMEOUT => 'handleTimeout',
        GuzzleOptions::HEADERS => 'handleHeaders',
        GuzzleOptions::QUERY => 'handleQuery',
        GuzzleOptions::BODY => 'handleBody',
        GuzzleOptions::JSON => 'handleJson',
        GuzzleOptions::FORM_PARAMS => 'handleFormParams',
        GuzzleOptions::MULTIPART => 'handleMultipart',
        GuzzleOptions::AUTH => 'handleAuth',
        GuzzleOptions::ALLOW_REDIRECTS => 'handleAllowRedirects',
        GuzzleOptions::COOKIES => 'handleCookies',
    ];

    /**
     * Options that are callbacks handled directly by CliHandler (not transformed)
     */
    private const CALLBACK_OPTIONS = [
        GuzzleOptions::ON_HEADERS,
        GuzzleOptions::ON_STATS,
        GuzzleOptions::PROGRESS,
    ];

    /**
     * Options handled directly by CliHandler from the original options array,
     * or not applicable in CLI context (no network, no SSL, etc.)
     */
    private const INTERNAL_OPTIONS = [
        // Handled directly by CliHandler
        GuzzleOptions::DECODE_CONTENT,
        GuzzleOptions::STREAM,
        GuzzleOptions::SINK,
        GuzzleOptions::DELAY,
        GuzzleOptions::HTTP_ERRORS,
        GuzzleOptions::EXPECT,       // Stripped by CliHandler
        GuzzleOptions::VERSION,      // Validated by CliHandler from request
        GuzzleOptions::SYNCHRONOUS,  // CLI is always synchronous
        GuzzleOptions::DEBUG,
        // Not applicable in CLI (no actual network connection)
        GuzzleOptions::READ_TIMEOUT,
        GuzzleOptions::CONNECT_TIMEOUT,
        GuzzleOptions::FORCE_IP_RESOLVE,
        GuzzleOptions::IDN_CONVERSION,
        GuzzleOptions::CRYPTO_METHOD,
        GuzzleOptions::SSL_KEY,
        GuzzleOptions::VERIFY,
        GuzzleOptions::CERT,
        GuzzleOptions::PROXY,
    ];

    /**
     * Cache for supported options (performance optimization)
     *
     * @var list<string>
     */
    private array $supportedOptionsCache;

    public function __construct()
    {
        $this->supportedOptionsCache = array_keys(self::OPTION_HANDLERS);
    }

    /**
     * Transform Guzzle options to unified RequestOptions
     *
     * @param array<string, mixed> $libraryOptions Guzzle options array
     * @return RequestOptions Unified request options
     * @throws UnsupportedFeatureException When an unsupported option is encountered
     * @throws InvalidArgumentException When option values are invalid
     */
    public function transform(array $libraryOptions): RequestOptions
    {
        if (empty($libraryOptions)) {
            return RequestOptions::empty();
        }

        $this->validateOptions($libraryOptions);

        $builder = RequestOptions::create();
        $processedOptions = new SplObjectStorage();
        $extras = [];

        foreach ($libraryOptions as $optionName => $optionValue) {
            if (isset(self::OPTION_HANDLERS[$optionName])) {
                $handler = self::OPTION_HANDLERS[$optionName];
                $this->{$handler}($builder, $optionValue, $optionName);
                $processedOptions->attach((object) [
                    'name' => $optionName,
                ]);
            } elseif (in_array($optionName, self::CALLBACK_OPTIONS, true)) {
                // Store callback options in extras for advanced users
                $extras["guzzle_{$optionName}"] = $optionValue;
            } elseif (in_array($optionName, self::INTERNAL_OPTIONS, true)) {
                // Store internal options in extras as metadata
                $extras["guzzle_internal_{$optionName}"] = $optionValue;
            } else {
                throw new UnsupportedFeatureException($optionName, $this->getSourceLibrary());
            }
        }

        if (! empty($extras)) {
            $builder->extras($extras);
        }

        return $builder->build();
    }

    /**
     * Get the source library name
     */
    public function getSourceLibrary(): string
    {
        return 'Guzzle HTTP';
    }

    /**
     * Check if a specific option is supported
     */
    public function supportsOption(string $optionName): bool
    {
        return in_array($optionName, $this->supportedOptionsCache, true) ||
               in_array($optionName, self::CALLBACK_OPTIONS, true) ||
               in_array($optionName, self::INTERNAL_OPTIONS, true);
    }

    /**
     * Get all supported options
     *
     * @return list<string> List of supported option names
     */
    public function getSupportedOptions(): array
    {
        return array_merge(
            $this->supportedOptionsCache,
            self::CALLBACK_OPTIONS,
            self::INTERNAL_OPTIONS
        );
    }

    /**
     * Validate the incoming options array
     *
     * @param array<string, mixed> $options
     * @throws InvalidArgumentException
     */
    private function validateOptions(array $options): void
    {
        // Check for conflicting body options
        $bodyOptions = [GuzzleOptions::BODY, GuzzleOptions::JSON, GuzzleOptions::FORM_PARAMS, GuzzleOptions::MULTIPART];
        $presentBodyOptions = array_filter($bodyOptions, fn ($opt) => isset($options[$opt]));

        if (count($presentBodyOptions) > 1) {
            $optionsList = implode(', ', $presentBodyOptions);
            throw new InvalidArgumentException("Multiple body options specified: {$optionsList}. Only one is allowed.");
        }

        // Validate option types early
        $this->validateOptionTypes($options);
    }

    /**
     * Validate types of options that are transformed by this adapter
     *
     * @param array<string, mixed> $options
     * @throws InvalidArgumentException
     */
    private function validateOptionTypes(array $options): void
    {
        $typeValidations = [
            GuzzleOptions::TIMEOUT => 'is_numeric',
            GuzzleOptions::HEADERS => 'is_array',
        ];

        foreach ($typeValidations as $option => $validator) {
            if (isset($options[$option]) && ! $validator($options[$option])) {
                $expectedType = str_replace('is_', '', $validator);
                throw new InvalidArgumentException("Option '{$option}' must be of type {$expectedType}");
            }
        }
    }

    /**
     * Handle timeout option
     */
    private function handleTimeout(RequestOptionsBuilder $builder, mixed $value, string $optionName): void
    {
        if (! is_numeric($value) || $value < 0) {
            throw new InvalidArgumentException('Timeout must be a non-negative number, got: ' . gettype($value));
        }
        $builder->timeout((float) $value);
    }

    /**
     * Handle headers option
     */
    private function handleHeaders(RequestOptionsBuilder $builder, mixed $value, string $optionName): void
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Headers must be an array, got: ' . gettype($value));
        }

        $normalizedHeaders = [];
        foreach ($value as $name => $headerValue) {
            // Handle both string and array header values (Guzzle supports both)
            if (is_array($headerValue)) {
                $normalizedHeaders[(string) $name] = implode(', ', $headerValue);
            } else {
                $normalizedHeaders[(string) $name] = (string) $headerValue;
            }
        }

        $builder->headers($normalizedHeaders);
    }

    /**
     * Handle query parameters option
     */
    private function handleQuery(RequestOptionsBuilder $builder, mixed $value, string $optionName): void
    {
        if (is_string($value)) {
            // Parse query string into array
            parse_str($value, $queryArray);
            // @phpstan-ignore argument.type (parse_str with valid query string produces string keys)
            $builder->query($queryArray);
        } elseif (is_array($value)) {
            $builder->query($value);
        } else {
            throw new InvalidArgumentException('Query must be string or array, got: ' . gettype($value));
        }
    }

    /**
     * Handle body option
     */
    private function handleBody(RequestOptionsBuilder $builder, mixed $value, string $optionName): void
    {
        if ($value === null) {
            return;
        }

        if (is_string($value)) {
            $builder->body($value);
        } elseif (is_resource($value)) {
            $content = stream_get_contents($value);
            $builder->body($content !== false ? $content : '');
        } elseif ($value instanceof StreamInterface) {
            $builder->body($value->getContents());
        } elseif (is_callable($value) || $value instanceof \Iterator) {
            // For complex types, convert to string representation
            $encoded = json_encode([
                'guzzle_complex_body' => 'See extras for original value',
            ]);
            $builder->body($encoded !== false ? $encoded : '{}');
            // Store original in extras for advanced handling
            $builder->extra('guzzle_original_body', $value);
        } else {
            $builder->body((string) $value);
        }
    }

    /**
     * Handle JSON option
     */
    private function handleJson(RequestOptionsBuilder $builder, mixed $value, string $optionName): void
    {
        $builder->json($value);
    }

    /**
     * Handle form parameters option
     */
    private function handleFormParams(RequestOptionsBuilder $builder, mixed $value, string $optionName): void
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Form params must be an array, got: ' . gettype($value));
        }
        $builder->formParams($value);
    }

    /**
     * Handle multipart option
     */
    private function handleMultipart(RequestOptionsBuilder $builder, mixed $value, string $optionName): void
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Multipart must be an array, got: ' . gettype($value));
        }

        $processedParts = [];
        foreach ($value as $part) {
            if (! is_array($part) || ! isset($part['name']) || ! isset($part['contents'])) {
                throw new InvalidArgumentException("Each multipart element must have 'name' and 'contents' keys");
            }

            $processedPart = [
                'name' => $part['name'],
                'contents' => $this->normalizeMultipartContents($part['contents']),
            ];

            if (isset($part['filename'])) {
                $processedPart['filename'] = $part['filename'];
            }

            if (isset($part['headers']) && is_array($part['headers'])) {
                $processedPart['headers'] = $part['headers'];
            }

            $processedParts[] = $processedPart;
        }

        $builder->multipart($processedParts);
    }

    /**
     * Normalize multipart contents to string
     */
    private function normalizeMultipartContents(mixed $contents): string
    {
        if (is_string($contents)) {
            return $contents;
        }

        if (is_resource($contents)) {
            return stream_get_contents($contents);
        }

        if ($contents instanceof StreamInterface) {
            return $contents->getContents();
        }

        return (string) $contents;
    }

    /**
     * Handle authentication option
     */
    private function handleAuth(RequestOptionsBuilder $builder, mixed $value, string $optionName): void
    {
        if ($value === null) {
            return;
        }

        if (! is_array($value) || count($value) < 2) {
            throw new InvalidArgumentException('Auth must be an array with at least [username, password]');
        }

        $username = $value[0];
        $password = $value[1];
        $authType = $value[2] ?? 'basic';

        match ($authType) {
            'basic' => $builder->basicAuth($username, $password),
            'bearer' => $builder->bearerToken($password), // In bearer auth, password is the token
            'digest', 'ntlm' => throw new UnsupportedFeatureException("Auth type '{$authType}'", $this->getSourceLibrary()),
            default => throw new InvalidArgumentException("Unknown auth type: {$authType}")
        };
    }

    /**
     * Handle allow redirects option
     */
    private function handleAllowRedirects(RequestOptionsBuilder $builder, mixed $value, string $optionName): void
    {
        if (is_bool($value)) {
            $builder->maxRedirects($value ? 5 : 0); // Default Guzzle max is 5
        } elseif (is_array($value)) {
            $max = $value['max'] ?? 5;
            $builder->maxRedirects($max);

            // Store full redirect config in extras
            $builder->extra('guzzle_redirect_config', $value);
        } else {
            throw new InvalidArgumentException('Allow redirects must be boolean or array, got: ' . gettype($value));
        }
    }

    /**
     * Handle cookies option
     */
    private function handleCookies(RequestOptionsBuilder $builder, mixed $value, string $optionName): void
    {
        // Guzzle cookies can be:
        // - true (use shared cookie jar - not applicable for CLI, ignore)
        // - false (disable cookies - ignore)
        // - CookieJarInterface object - extract cookies if possible
        // - For simplicity, store in extras if complex

        if ($value === true || $value === false) {
            return; // Boolean just enables/disables cookie handling
        }

        // If it's a CookieJar, try to extract cookies
        if ($value instanceof CookieJarInterface) {
            $cookies = [];
            foreach ($value as $cookie) {
                $cookieValue = $cookie->getValue();
                if ($cookieValue !== null) {
                    $cookies[$cookie->getName()] = $cookieValue;
                }
            }
            if (! empty($cookies)) {
                $builder->cookies($cookies);
            }
            return;
        }

        // If it's an array (simplified cookie format), use directly
        if (is_array($value)) {
            $builder->cookies($value);
            return;
        }

        // Store unknown formats in extras
        $builder->extra('guzzle_cookies', $value);
    }
}
