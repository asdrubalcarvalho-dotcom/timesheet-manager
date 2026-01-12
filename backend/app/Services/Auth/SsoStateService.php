<?php

namespace App\Services\Auth;

use Illuminate\Support\Str;

class SsoStateService
{
    private const DEFAULT_TTL_SECONDS = 600;
    private const LINK_TTL_SECONDS = 600; // 10 minutes for link flow

    public function generate(string $tenantSlug): string
    {
        $payload = [
            'tenant' => $tenantSlug,
            'ts' => time(),
            'nonce' => Str::random(16),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $encoded = $this->base64UrlEncode($json);
        $signature = $this->sign($encoded);

        return $encoded . '.' . $signature;
    }

    /**
     * Generate signed state for account linking flow.
     */
    public function generateLinkState(string $tenantSlug, int $userId, string $provider): string
    {
        $payload = [
            'tenant' => $tenantSlug,
            'user_id' => $userId,
            'provider' => $provider,
            'mode' => 'link',
            'ts' => time(),
            'nonce' => Str::random(16),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $encoded = $this->base64UrlEncode($json);
        $signature = $this->sign($encoded);

        return $encoded . '.' . $signature;
    }

    /**
     * @return array{tenant: string}
     */
    public function validate(string $state, ?int $ttlSeconds = null): array
    {
        $ttlSeconds = $ttlSeconds ?? self::DEFAULT_TTL_SECONDS;

        [$encoded, $signature] = array_pad(explode('.', $state, 2), 2, null);

        if (!$encoded || !$signature) {
            throw new \InvalidArgumentException('Invalid SSO state');
        }

        $expected = $this->sign($encoded);
        if (!hash_equals($expected, $signature)) {
            throw new \InvalidArgumentException('Invalid SSO state');
        }

        $json = $this->base64UrlDecode($encoded);
        $payload = json_decode($json, true);

        if (!is_array($payload) || empty($payload['tenant']) || empty($payload['ts'])) {
            throw new \InvalidArgumentException('Invalid SSO state');
        }

        if (!is_numeric($payload['ts']) || (time() - (int) $payload['ts']) > $ttlSeconds) {
            throw new \InvalidArgumentException('Expired SSO state');
        }

        return ['tenant' => (string) $payload['tenant']];
    }

    /**
     * Validate link state and return tenant + user_id.
     * @return array{tenant: string, user_id: int, provider: string}
     */
    public function validateLinkState(string $state): array
    {
        [$encoded, $signature] = array_pad(explode('.', $state, 2), 2, null);

        if (!$encoded || !$signature) {
            throw new \InvalidArgumentException('Invalid SSO link state');
        }

        $expected = $this->sign($encoded);
        if (!hash_equals($expected, $signature)) {
            throw new \InvalidArgumentException('Invalid SSO link state');
        }

        $json = $this->base64UrlDecode($encoded);
        $payload = json_decode($json, true);

        if (
            !is_array($payload) 
            || empty($payload['tenant']) 
            || empty($payload['user_id']) 
            || empty($payload['provider'])
            || ($payload['mode'] ?? null) !== 'link'
            || empty($payload['ts'])
        ) {
            throw new \InvalidArgumentException('Invalid SSO link state');
        }

        if (!is_numeric($payload['ts']) || (time() - (int) $payload['ts']) > self::LINK_TTL_SECONDS) {
            throw new \InvalidArgumentException('Expired SSO link state');
        }

        return [
            'tenant' => (string) $payload['tenant'],
            'user_id' => (int) $payload['user_id'],
            'provider' => (string) $payload['provider'],
        ];
    }

    private function sign(string $encodedPayload): string
    {
        $key = (string) config('app.key');

        return hash_hmac('sha256', $encodedPayload, $key);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding !== 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid SSO state');
        }

        return $decoded;
    }
}
