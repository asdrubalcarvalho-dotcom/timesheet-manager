<?php

namespace App\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class EmailPolicyService
{
    public function __construct(
        private readonly AbuseGate $abuseGate,
    ) {
    }

    public function extractDomain(string $email): string
    {
        $email = strtolower(trim($email));

        $atPos = strrpos($email, '@');
        if ($atPos === false) {
            return '';
        }

        $domain = substr($email, $atPos + 1);
        $domain = strtolower(trim($domain));
        $domain = rtrim($domain, '.');

        return $domain;
    }

    public function isDisposableDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return false;
        }

        $blocked = (array) config('disposable_email_domains', []);
        if ($blocked === []) {
            $path = base_path('config/disposable_email_domains.php');
            if (is_file($path)) {
                $loaded = require $path;
                if (is_array($loaded)) {
                    $blocked = $loaded;
                }
            }
        }
        $blocked = array_map(static fn ($value) => strtolower(trim((string) $value)), $blocked);

        return in_array($domain, $blocked, true);
    }

    public function assertAllowedEmail(string $email, Request $request, array $context = []): void
    {
        $domain = $this->extractDomain($email);

        // If the controller's validator already accepted an email, domain should exist.
        // For safety, treat invalid emails from third-parties as not allowed.
        if ($domain === '') {
            $this->reject($request, $domain, 'invalid_email', $context);
        }

        if ($this->isDisposableDomain($domain)) {
            $this->reject($request, $domain, 'disposable_email_domain', $context);
        }
    }

    private function reject(Request $request, string $domain, string $reason, array $context): void
    {
        $tenantSlug = isset($context['tenant_slug']) && is_string($context['tenant_slug'])
            ? trim($context['tenant_slug'])
            : null;

        $provider = isset($context['provider']) && is_string($context['provider'])
            ? trim($context['provider'])
            : null;

        $requestId = $this->getRequestId($request);

        $logContext = array_filter([
            'email_domain' => $domain !== '' ? $domain : null,
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'tenant_slug' => $tenantSlug,
            'provider' => $provider,
            'reason' => $reason,
            'endpoint' => $request->method() . ' ' . $request->path(),
            'request_id' => $requestId,
        ], static fn ($value) => $value !== null && $value !== '');

        Log::warning('email_policy.rejected', $logContext);

        // CAPTCHA hook only (no blocking beyond EMAIL_POLICY hard rules).
        $this->abuseGate->recordSignal($request, [
            'email_domain' => $domain,
            'disposable_email_domain' => $reason === 'disposable_email_domain',
            'tenant_slug' => $tenantSlug,
            'provider' => $provider,
            'reason' => $reason,
        ]);

        throw new HttpResponseException(
            response()->json([
                'message' => 'Please use a valid business or personal email address.',
            ], 422)
        );
    }

    private function getRequestId(Request $request): ?string
    {
        $id = $request->header('X-Request-Id') ?? $request->header('X-Request-ID');
        $id = is_string($id) ? trim($id) : '';

        return $id !== '' ? $id : null;
    }
}
