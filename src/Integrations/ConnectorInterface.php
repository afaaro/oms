<?php

declare(strict_types=1);

namespace App\Integrations;

interface ConnectorInterface
{
    public function key(): string;

    public function label(): string;

    /** marketplace | webstore | courier */
    public function group(): string;

    public function isConfigured(): bool;

    public function supportsOAuth(): bool;

    public function supportsCredentials(): bool;

    /**
     * @param array<string, mixed> $channel
     * @param array<string, string> $settings
     */
    public function authorizationUrl(array $channel, array $settings, string $state): string;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $channel
     * @param array<string, string> $settings
     * @return array{ok:bool, settings?:array<string,string>, message?:string}
     */
    public function handleCallback(array $query, array $channel, array $settings): array;

    /**
     * Credential-based link (API key/secret) when OAuth is not used.
     *
     * @param array<string, mixed> $channel
     * @param array<string, string> $settings
     * @return array{ok:bool, settings?:array<string,string>, message?:string}
     */
    public function connectCredentials(array $channel, array $settings): array;

    /**
     * @param array<string, string> $settings
     * @return array{ok:bool, message:string, settings?:array<string,string>, soft?:bool}
     */
    public function testConnection(array $settings): array;

    /**
     * @param array<string, string> $settings
     * @return list<array<string, mixed>>
     */
    public function fetchOrders(array $settings, \DateTimeInterface $since): array;

    /**
     * Buyer / seller messages from the channel (ebay, amazon, etsy, …).
     *
     * @param array<string, string> $settings
     * @return list<array<string, mixed>>
     */
    public function fetchMessages(array $settings, \DateTimeInterface $since): array;

    /**
     * Push tracking / fulfillment to the marketplace after local despatch.
     *
     * @param array<string, string> $settings
     * @param array{
     *   external_order_id:string,
     *   tracking_number:string,
     *   carrier_code:string,
     *   service_code?:string,
     *   shipped_at?:string,
     *   lines?:list<array{sku:string,qty:int,name?:string}>
     * } $payload
     * @return array{ok:bool, skipped?:bool, status:string, message:string, settings?:array<string,string>}
     */
    public function submitFulfillment(array $settings, array $payload): array;
}
