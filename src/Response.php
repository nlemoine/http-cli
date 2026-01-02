<?php

declare(strict_types=1);

namespace n5s\HttpCli;

use Symfony\Component\Process\Process;

final readonly class Response
{
    /**
     * @param list<string> $headers
     * @param array<string, mixed> $session
     */
    public function __construct(
        private int $statusCode,
        private array $headers,
        private string $content,
        private Process $process,
        private array $session = [],
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return list<string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getProcess(): Process
    {
        return $this->process;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSession(): array
    {
        return $this->session;
    }
}
