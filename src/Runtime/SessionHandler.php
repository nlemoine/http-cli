<?php

declare(strict_types=1);

namespace n5s\HttpCli\Runtime;

use SessionHandlerInterface;

/**
 * Session handler for CLI environment.
 *
 * Provides session persistence for scripts executed via Client.
 * Sets the PHPSESSID cookie via HeaderHandler and initializes $_SESSION
 * with data passed from the parent process.
 */
final readonly class SessionHandler implements SessionHandlerInterface
{
    /**
     * @param array<string, mixed> $initialSession
     */
    public function __construct(
        private array $initialSession
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        // Set session cookie via header handler
        HeaderHandler::getInstance()->header('Set-Cookie: PHPSESSID=' . $id);
        $_SESSION = $this->initialSession;

        $encoded = session_encode();

        return $encoded !== false ? $encoded : '';
    }

    public function write(string $id, string $data): bool
    {
        return true;
    }

    public function destroy(string $id): bool
    {
        return true;
    }

    public function gc(int $max_lifetime): int
    {
        return 0;
    }
}
