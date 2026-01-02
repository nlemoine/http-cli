<?php

declare(strict_types=1);

namespace n5s\HttpCli\Symfony;

use n5s\HttpCli\Client;
use Symfony\Component\HttpClient\HttpClientTrait;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Symfony HttpClient implementation using Client as transport
 *
 * @phpstan-import-type SymfonyOptions from SymfonyToRequestOptionsAdapter
 */
final class CliClient implements HttpClientInterface
{
    use HttpClientTrait;

    /**
     * @var array<string, mixed>
     */
    private array $defaultOptions = self::OPTIONS_DEFAULTS;

    private readonly SymfonyToRequestOptionsAdapter $adapter;

    /**
     * @param array<string, mixed> $defaultOptions Default options for all requests
     */
    public function __construct(
        private readonly Client $httpCliClient,
        array $defaultOptions = []
    ) {
        $this->defaultOptions = array_merge(self::OPTIONS_DEFAULTS, $defaultOptions);
        $this->adapter = new SymfonyToRequestOptionsAdapter();
    }

    /**
     * @param array<string, mixed> $options Symfony HTTP Client options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        /**
         * @var array<int, string>|string $parsedUrl URL components or full URL string
         * @var array<string, mixed> $processedOptions Merged and normalized options
         */
        [$parsedUrl, $processedOptions] = self::prepareRequest($method, $url, $options, $this->defaultOptions, true);

        // Reconstruct URL from parsed components (Symfony parses URLs into arrays)
        $url = is_array($parsedUrl) ? implode('', $parsedUrl) : $parsedUrl;

        // Use adapter to transform Symfony options to RequestOptions
        $requestOptions = $this->adapter->transform($processedOptions);

        /** @var array<string, mixed> $responseInfo Response metadata for CliResponse */
        $responseInfo = $processedOptions + [
            'url' => $url,
            'method' => $method,
        ];

        try {
            // Execute request using Client with unified RequestOptions
            $response = $this->httpCliClient->request($method, $url, $requestOptions);

            // Wrap in Symfony-compatible response
            return new CliResponse($response, $responseInfo);

        } catch (\Exception $e) {
            // Convert to Symfony transport exception
            throw new class($e->getMessage(), 0, $e) extends \RuntimeException implements TransportExceptionInterface {
                public function __construct(
                    string $message,
                    int $code = 0,
                    ?\Throwable $previous = null
                ) {
                    parent::__construct($message, $code, $previous);
                }
            };
        }
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        // Convert single response to iterable
        if ($responses instanceof ResponseInterface) {
            $responses = [$responses];
        }

        // For CLI responses, they're already complete, so we create a simple stream
        // that yields each response as a chunk (timeout is ignored since responses are complete)
        return new class($responses) implements ResponseStreamInterface {
            /**
             * @var array<int, ResponseInterface>
             */
            private array $responseArray;

            private int $position = 0;

            /**
             * @param iterable<ResponseInterface> $responses
             */
            public function __construct(
                iterable $responses
            ) {
                $this->responseArray = is_array($responses) ? $responses : iterator_to_array($responses);
            }

            public function key(): ResponseInterface
            {
                return $this->responseArray[$this->position];
            }

            public function current(): ChunkInterface
            {
                $response = $this->responseArray[$this->position];

                // Return a simple chunk that represents the complete response
                return new class($response) implements ChunkInterface {
                    public function __construct(
                        private readonly ResponseInterface $response
                    ) {
                    }

                    public function isTimeout(): bool
                    {
                        return false;
                    }

                    public function isFirst(): bool
                    {
                        return true;
                    }

                    public function isLast(): bool
                    {
                        return true;
                    }

                    /**
                     * @return array{0?: int, 1?: string}
                     */
                    public function getInformationalStatus(): array
                    {
                        return [];
                    }

                    public function getContent(): string
                    {
                        return $this->response->getContent(false);
                    }

                    public function getOffset(): int
                    {
                        return 0;
                    }

                    public function getError(): ?string
                    {
                        return null;
                    }
                };
            }

            public function next(): void
            {
                $this->position++;
            }

            public function rewind(): void
            {
                $this->position = 0;
            }

            public function valid(): bool
            {
                return isset($this->responseArray[$this->position]);
            }
        };
    }

    /**
     * Override withOptions to handle extra options properly
     *
     * @param array<string, mixed> $options Options to merge with defaults
     */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->defaultOptions = self::mergeDefaultOptions($options, $this->defaultOptions, true);

        return $clone;
    }

    /**
     * Factory method to create CliClient with document root
     *
     * @param array<string, mixed> $options Default options for all requests
     */
    public static function create(string $documentRoot, array $options = []): self
    {
        $httpCliClient = new Client($documentRoot);
        return new self($httpCliClient, $options);
    }

    /**
     * Factory method to create CliClient with existing Client
     *
     * @param array<string, mixed> $options Default options for all requests
     */
    public static function createFromClient(Client $httpCliClient, array $options = []): self
    {
        return new self($httpCliClient, $options);
    }
}
