<?php

declare(strict_types=1);

namespace n5s\HttpCli;

/**
 * Interface for transforming library-specific options TO unified RequestOptions
 */
interface OptionsAdapterInterface
{
    /**
     * Transform library-specific options to unified RequestOptions
     *
     * @param array<string, mixed> $libraryOptions Library-specific options array
     * @return RequestOptions Unified request options
     * @throws UnsupportedFeatureException When an option is not supported or recognized
     */
    public function transform(array $libraryOptions): RequestOptions;

    /**
     * Get the name of the source library this adapter supports
     */
    public function getSourceLibrary(): string;

    /**
     * Check if a specific library option is supported by this adapter
     */
    public function supportsOption(string $optionName): bool;

    /**
     * Get all supported options for this library
     *
     * @return list<string> List of supported option names
     */
    public function getSupportedOptions(): array;
}
