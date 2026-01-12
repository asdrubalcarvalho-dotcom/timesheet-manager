<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\SsoStateService;
use App\Services\Security\EmailPolicyService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class SsoAuthController extends Controller
{
    private const PROVIDERS = ['google', 'microsoft'];

    public function __construct(
        private readonly SsoStateService $stateService,
        private readonly EmailPolicyService $emailPolicy,
    ) {
    }

    /**
     * SSO-2: Start linking flow (authenticated endpoint).
     * Returns link state that frontend will use to redirect to /auth/{provider}/redirect.
     */
    public function linkStart(Request $request, string $provider): JsonResponse
    {
        $provider = $this->validateProvider($provider);
        
        if (!tenancy()->initialized || !tenant()) {
            return response()->json(['message' => 'Tenant context required.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        // Check if user already has this provider linked
        if (SocialAccount::existsForUserAndProvider($user->id, $provider)) {
            return response()->json([
                'message' => 'This provider is already linked to your account.',
            ], Response::HTTP_CONFLICT);
        }

        $tenantSlug = tenant()->slug;
        $linkState = $this->stateService->generateLinkState($tenantSlug, $user->id, $provider);

        return response()->json([
            'link_state' => $linkState,
            // NOTE: OAuth redirect/callback routes live under web middleware at /auth/*.
            // Include tenant slug as a query param so tenant.initialize can resolve context.
            'redirect_url' => "/auth/{$provider}/redirect?mode=link&state={$linkState}&tenant={$tenantSlug}",
        ]);
    }

    public function redirect(Request $request, string $provider): JsonResponse|RedirectResponse
    {
        $provider = $this->validateProvider($provider);
        $mode = $request->query('mode'); // 'link' or null (login)

        if (!tenancy()->initialized || !tenant()) {
            return $this->reject(
                $request,
                provider: $provider,
                tenantSlug: null,
                emailDomain: null,
                reason: 'tenant_missing_redirect',
                status: Response::HTTP_BAD_REQUEST,
                message: 'Tenant context is required.'
            );
        }

        // For link mode, state is already provided by linkStart
        // For login mode, generate new state
        $state = $mode === 'link' 
            ? $request->query('state')
            : $this->stateService->generate(tenant()->slug);

        return Socialite::driver($provider)
            ->with(['state' => $state])
            ->redirect();
    }

    public function callback(Request $request, string $provider): JsonResponse|RedirectResponse
    {
        $provider = $this->validateProvider($provider);
        $mode = $request->query('mode'); // 'link' or null (login)
        $state = (string) $request->query('state', '');

        try {
            $statePayload = $mode === 'link'
                ? $this->stateService->validateLinkState($state)
                : $this->stateService->validate($state);
        } catch (\Throwable $exception) {
            return $this->reject(
                $request,
                provider: $provider,
                tenantSlug: null,
                emailDomain: null,
                reason: 'invalid_state',
                status: Response::HTTP_BAD_REQUEST,
                message: 'Unable to sign in with SSO.'
            );
        }

        $tenantSlug = $statePayload['tenant'];
        $tenant = Tenant::where('slug', $tenantSlug)->first();

        if (!$tenant) {
            return $this->reject(
                $request,
                provider: $provider,
                tenantSlug: $tenantSlug,
                emailDomain: null,
                reason: 'tenant_not_found',
                status: Response::HTTP_FORBIDDEN,
                message: 'Unable to sign in with SSO.'
            );
        }

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $exception) {
            return $this->reject(
                $request,
                provider: $provider,
                tenantSlug: $tenantSlug,
                emailDomain: null,
                reason: 'provider_error',
                status: Response::HTTP_BAD_REQUEST,
                message: 'Unable to sign in with SSO.'
            );
        }

        $providerUserId = (string) $socialUser->getId();
        $email = (string) ($socialUser?->getEmail() ?? '');
        $emailDomain = $this->extractDomain($email);

        if (!$this->isVerifiedEmail($provider, $socialUser)) {
            return $this->reject(
                $request,
                provider: $provider,
                tenantSlug: $tenantSlug,
                emailDomain: $emailDomain,
                reason: 'email_not_verified',
                status: Response::HTTP_FORBIDDEN,
                message: 'Unable to sign in with SSO.'
            );
        }

        // EMAIL_POLICY.md: disposable blocking MUST happen before any user lookup.
        try {
            $this->emailPolicy->assertAllowedEmail($email, $request, [
                'tenant_slug' => $tenantSlug,
                'provider' => $provider,
            ]);
        } catch (HttpResponseException $exception) {
            if ($this->shouldReturnJson($request)) {
                return $exception->getResponse();
            }

            return $this->redirectToFrontendLoginError();
        }

        // Handle link mode vs login mode
        return $mode === 'link'
            ? $this->handleLinkCallback($tenant, $statePayload, $provider, $providerUserId, $email, $emailDomain, $request, $tenantSlug)
            : $this->handleLoginCallback($tenant, $provider, $providerUserId, $email, $emailDomain, $request, $tenantSlug);
    }

    private function handleLoginCallback(
        Tenant $tenant,
        string $provider,
        string $providerUserId,
        string $email,
        ?string $emailDomain,
        Request $request,
        string $tenantSlug
    ): JsonResponse|RedirectResponse {
        return $tenant->run(function () use ($tenant, $provider, $providerUserId, $email, $emailDomain, $request, $tenantSlug) {
            $databaseName = $tenant->getInternal('db_name') ?? ('timesheet_' . $tenant->id);

            config(['database.connections.tenant.database' => $databaseName]);
            DB::purge('tenant');
            DB::reconnect('tenant');
            DB::setDefaultConnection('tenant');
            config(['database.default' => 'tenant']);

            // SSO-2: Priority 1 - match by provider_user_id
            $socialAccount = SocialAccount::findByProvider($provider, $providerUserId);
            
            if ($socialAccount) {
                $user = $socialAccount->user;
                
                if (!$user) {
                    return $this->reject(
                        $request,
                        provider: $provider,
                        tenantSlug: $tenantSlug,
                        emailDomain: $emailDomain,
                        reason: 'linked_user_not_found',
                        status: Response::HTTP_FORBIDDEN,
                        message: 'Unable to sign in with SSO.'
                    );
                }

                $token = $user->createToken(
                    "tenant-{$tenant->id}",
                    ["tenant:{$tenant->id}"]
                )->plainTextToken;

                return $this->respondWithToken($request, $tenantSlug, $user, $token);
            }

            // SSO-2: Priority 2 - match by email (SSO-1 behavior, no auto-link)
            $user = User::where('email', strtolower(trim($email)))->first();

            if (!$user) {
                return $this->reject(
                    $request,
                    provider: $provider,
                    tenantSlug: $tenantSlug,
                    emailDomain: $emailDomain,
                    reason: 'user_not_found',
                    status: Response::HTTP_FORBIDDEN,
                    message: 'No account exists for this email in this workspace.'
                );
            }

            // SSO-1 behavior: login without linking (user must explicitly link via link/start flow)
            $token = $user->createToken(
                "tenant-{$tenant->id}",
                ["tenant:{$tenant->id}"]
            )->plainTextToken;

            return $this->respondWithToken($request, $tenantSlug, $user, $token);
        });
    }

    private function handleLinkCallback(
        Tenant $tenant,
        array $statePayload,
        string $provider,
        string $providerUserId,
        string $email,
        ?string $emailDomain,
        Request $request,
        string $tenantSlug
    ): JsonResponse|RedirectResponse {
        return $tenant->run(function () use ($tenant, $statePayload, $provider, $providerUserId, $email, $emailDomain, $request, $tenantSlug) {
            $databaseName = $tenant->getInternal('db_name') ?? ('timesheet_' . $tenant->id);

            config(['database.connections.tenant.database' => $databaseName]);
            DB::purge('tenant');
            DB::reconnect('tenant');
            DB::setDefaultConnection('tenant');
            config(['database.default' => 'tenant']);

            $userId = $statePayload['user_id'] ?? null;
            if (!$userId) {
                return $this->reject(
                    $request,
                    provider: $provider,
                    tenantSlug: $tenantSlug,
                    emailDomain: $emailDomain,
                    reason: 'invalid_link_state',
                    status: Response::HTTP_BAD_REQUEST,
                    message: 'Unable to link account.'
                );
            }

            $user = User::find($userId);
            if (!$user) {
                return $this->reject(
                    $request,
                    provider: $provider,
                    tenantSlug: $tenantSlug,
                    emailDomain: $emailDomain,
                    reason: 'link_user_not_found',
                    status: Response::HTTP_FORBIDDEN,
                    message: 'Unable to link account.'
                );
            }

            // Check if this provider_user_id is already linked to a different user
            $existingSocialAccount = SocialAccount::findByProvider($provider, $providerUserId);
            if ($existingSocialAccount && $existingSocialAccount->user_id !== $user->id) {
                return $this->reject(
                    $request,
                    provider: $provider,
                    tenantSlug: $tenantSlug,
                    emailDomain: $emailDomain,
                    reason: 'link_conflict',
                    status: Response::HTTP_CONFLICT,
                    message: 'This SSO account is already linked to another user.'
                );
            }

            // Idempotent: if already linked to this user, just return success
            if ($existingSocialAccount && $existingSocialAccount->user_id === $user->id) {
                $token = $user->createToken(
                    "tenant-{$tenant->id}",
                    ["tenant:{$tenant->id}"]
                )->plainTextToken;

                return $this->respondWithToken($request, $tenantSlug, $user, $token, 'Account already linked.');
            }

            // Create new link
            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
                'provider_email' => strtolower(trim($email)),
            ]);

            $token = $user->createToken(
                "tenant-{$tenant->id}",
                ["tenant:{$tenant->id}"]
            )->plainTextToken;

            return $this->respondWithToken($request, $tenantSlug, $user, $token, 'Account linked successfully.');
        });
    }

    private function respondWithToken(
        Request $request,
        string $tenantSlug,
        User $user,
        string $token,
        ?string $message = null
    ): JsonResponse|RedirectResponse {
        if ($this->shouldReturnJson($request)) {
            $payload = [
                'token' => $token,
                'user' => $this->formatUserResponse($user),
            ];

            if (is_string($message) && trim($message) !== '') {
                $payload = ['message' => $message] + $payload;
            }

            return response()->json($payload);
        }

        return $this->redirectToFrontendCallback($tenantSlug, $token);
    }

    private function shouldReturnJson(Request $request): bool
    {
        return $request->expectsJson() || $request->wantsJson();
    }

    private function redirectToFrontendCallback(string $tenantSlug, string $token): RedirectResponse
    {
        $frontend = rtrim((string) config('app.frontend_url', ''), '/');
        $url = $frontend . '/sso/callback?token=' . urlencode($token) . '&tenant=' . urlencode($tenantSlug);

        return redirect()->away($url);
    }

    private function redirectToFrontendLoginError(): RedirectResponse
    {
        $frontend = rtrim((string) config('app.frontend_url', ''), '/');
        $url = $frontend . '/login?error=sso_failed';

        return redirect()->away($url);
    }

    private function validateProvider(string $provider): string
    {
        $provider = strtolower($provider);

        if (!in_array($provider, self::PROVIDERS, true)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $provider;
    }

    private function extractDomain(string $email): ?string
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }

        [, $domain] = explode('@', $email, 2);

        $domain = strtolower(trim($domain));

        return $domain !== '' ? $domain : null;
    }

    private function isVerifiedEmail(string $provider, $socialUser): bool
    {
        $raw = [];
        if (is_object($socialUser) && method_exists($socialUser, 'getRaw')) {
            $raw = (array) $socialUser->getRaw();
        } elseif (is_object($socialUser) && property_exists($socialUser, 'user')) {
            $raw = (array) $socialUser->user;
        }

        if ($provider === 'google') {
            return (bool) ($raw['email_verified'] ?? $raw['verified_email'] ?? false);
        }

        // Microsoft Azure AD accounts are treated as verified if email is present.
        return (string) ($socialUser?->getEmail() ?? '') !== '';
    }

    private function reject(
        Request $request,
        string $provider,
        ?string $tenantSlug,
        ?string $emailDomain,
        string $reason,
        int $status,
        string $message,
    ): JsonResponse|RedirectResponse {
        Log::warning('SSO rejected', [
            'tenant' => $tenantSlug,
            'provider' => $provider,
            'email_domain' => $emailDomain,
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'reason' => $reason,
        ]);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
            ], $status);
        }

        return $this->redirectToFrontendLoginError();
    }

    private function formatUserResponse(User $user): array
    {
        $projectMemberships = $user->memberRecords()
            ->select('project_id', 'project_role', 'expense_role', 'finance_role')
            ->get()
            ->map(function ($membership) {
                return [
                    'project_id' => $membership->project_id,
                    'project_role' => $membership->project_role,
                    'expense_role' => $membership->expense_role,
                    'finance_role' => $membership->finance_role,
                ];
            });

        $tenant = tenant();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ?? 'Technician',
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'is_owner' => $user->hasRole('Owner'),
            'is_manager' => $user->isProjectManager(),
            'is_technician' => $user->hasRole('Technician'),
            'is_admin' => $user->hasRole('Admin') || $user->hasRole('Owner'),
            'managed_projects' => $user->isProjectManager()
                ? $user->getManagedProjectIds()
                : [],
            'project_memberships' => $projectMemberships,
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'status' => $tenant->status,
            ] : null,
        ];
    }
}
