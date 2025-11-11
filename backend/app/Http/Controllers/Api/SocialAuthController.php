<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class SocialAuthController extends Controller
{
    /**
     * Handle social login callback for SPA exchanges.
     * Expected payload: { provider: string, token: string }
     */
    public function exchangeToken(Request $request, string $provider): JsonResponse
    {
        if (! config("services.$provider")) {
            return response()->json([
                'message' => 'SSO provider is not configured',
            ], Response::HTTP_NOT_FOUND);
        }

        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid SSO token payload',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $socialUser = Socialite::driver($provider)->userFromToken($request->token);
        } catch (\Throwable $exception) {
            Log::warning('SSO token exchange failed', [
                'provider' => $provider,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to validate SSO token',
            ], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->findOrCreateUser($socialUser, $provider);
        $token = $user->createToken('sso-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'is_manager' => $user->isProjectManager(), // Based on project relationships
                'is_technician' => $user->hasRole('Technician'),
                'is_admin' => $user->hasRole('Admin'),
            ],
        ]);
    }

    protected function findOrCreateUser($socialUser, string $provider): User
    {
        $account = SocialAccount::where('provider_name', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if ($account) {
            $account->fill([
                'provider_email' => $socialUser->getEmail(),
                'provider_username' => $socialUser->getNickname(),
                'avatar' => $socialUser->getAvatar(),
                'access_token' => data_get($socialUser, 'token'),
                'refresh_token' => data_get($socialUser, 'refreshToken'),
                'token_expires_at' => $this->calculateExpiry($socialUser),
                'metadata' => $socialUser->user,
            ])->save();

            return $account->user;
        }

        $user = User::firstOrCreate(
            ['email' => $socialUser->getEmail()],
            [
                'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'SSO User',
                'password' => bcrypt(Str::random(32)),
            ]
        );

        $user->socialAccounts()->create([
            'provider_name' => $provider,
            'provider_id' => $socialUser->getId(),
            'provider_email' => $socialUser->getEmail(),
            'provider_username' => $socialUser->getNickname(),
            'avatar' => $socialUser->getAvatar(),
            'access_token' => data_get($socialUser, 'token'),
            'refresh_token' => data_get($socialUser, 'refreshToken'),
            'token_expires_at' => $this->calculateExpiry($socialUser),
            'metadata' => $socialUser->user,
        ]);

        return $user;
    }

    protected function calculateExpiry($socialUser): ?\Illuminate\Support\Carbon
    {
        $expiresIn = data_get($socialUser, 'expiresIn');

        if (!$expiresIn) {
            return null;
        }

        return now()->addSeconds((int) $expiresIn);
    }
}
