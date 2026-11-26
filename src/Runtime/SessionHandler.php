<?php

declare(strict_types=1);

namespace n5s\HttpCli\Runtime;

use SessionHandlerInterface;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Session handler for CLI environment.
 *
 * Provides session persistence for scripts executed via Client.
 * Sets the PHPSESSID cookie via HeaderHandler and initializes $_SESSION
 * with data passed from the parent process.
 *
 * It also implements the session ID interfaces, which PHP 8.6 warns about
 * when they are missing and PHP 9 will require. The warning would end up in
 * the response when display_errors is on, and the output it starts would
 * keep this handler from being installed.
 */
final readonly class SessionHandler implements SessionHandlerInterface, SessionIdInterface, SessionUpdateTimestampHandlerInterface
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

    public function create_sid(): string
    {
        // 32 hexadecimal characters: valid for every session.sid_bits_per_character value
        return bin2hex(random_bytes(16));
    }

    public function validateId(string $id): bool
    {
        // The session data comes from the client, whatever the ID
        return true;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return true;
    }
}
