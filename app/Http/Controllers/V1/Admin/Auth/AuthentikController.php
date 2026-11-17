<?php

namespace App\Http\Controllers\V1\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\AuthentikLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as AuthentikAccount;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

/**
 * Sign-in with Authentik over OpenID Connect: the authorization code flow,
 * with PKCE and Socialite's state check.
 *
 * Only existing users can sign in this way; it never creates one. The first
 * time an Authentik account signs in, it is linked to the one user with the
 * same email address. From then on only that link counts (Authentik's "sub"
 * claim, kept in users.authentik_id): changing either email address later
 * does not break it, and no other Authentik account can take the user over.
 * `php artisan authentik:unlink` removes a link.
 */
class AuthentikController extends Controller
{
    /**
     * Who the user is and their email address. Authentik's provider package
     * also asks for goauthentik.io/api, which InvoiceShelf has no use for.
     */
    private const SCOPES = ['openid', 'profile', 'email'];

    public function redirect(): RedirectResponse
    {
        abort_unless(AuthentikLogin::enabled(), 404);

        return $this->authentik()->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(AuthentikLogin::enabled(), 404);

        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->backToLogin('failed');
        }

        try {
            $account = $this->authentik()->user();
        } catch (InvalidStateException) {
            return $this->backToLogin('failed');
        } catch (Throwable $exception) {
            report($exception);

            return $this->backToLogin('failed');
        }

        $user = $this->userFor($account);

        if (! $user) {
            return $this->backToLogin('no_account');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->intended(AppServiceProvider::HOME);
    }

    /**
     * The user an Authentik account signs in as: the user linked to it, or on
     * its first sign-in the one user with its email address, which this then
     * links to it. A user linked to another account is never taken over.
     */
    private function userFor(AuthentikAccount $account): ?User
    {
        $subject = (string) $account->getId();

        if ($subject === '') {
            return null;
        }

        $linked = User::query()->where('authentik_id', $subject)->first();

        if ($linked) {
            return $linked;
        }

        $email = Str::lower(trim((string) $account->getEmail()));
        $users = $email === ''
            ? collect()
            : User::query()->whereRaw('LOWER(email) = ?', [$email])->limit(2)->get();

        if ($users->count() !== 1) {
            Log::notice('Sign-in with Authentik refused: no single user has this email address.', [
                'email' => $email,
                'authentik_id' => $subject,
            ]);

            return null;
        }

        $user = $users->first();

        if ($user->authentik_id !== null) {
            Log::notice('Sign-in with Authentik refused: the user with this email address is linked to another Authentik account. `php artisan authentik:unlink` removes that link.', [
                'user_id' => $user->id,
                'email' => $email,
                'authentik_id' => $subject,
            ]);

            return null;
        }

        $user->forceFill(['authentik_id' => $subject])->save();

        Log::info('Linked a user to their Authentik account on their first sign-in with Authentik.', [
            'user_id' => $user->id,
            'authentik_id' => $subject,
        ]);

        return $user;
    }

    /**
     * Socialite's Authentik provider, asking only for the scopes InvoiceShelf
     * needs.
     */
    private function authentik(): Provider
    {
        return Socialite::driver('authentik')
            ->setScopes(self::SCOPES)
            ->enablePKCE();
    }

    /**
     * Back to the login page, which says why the sign-in failed: "failed" or
     * "no_account".
     */
    private function backToLogin(string $error): RedirectResponse
    {
        return redirect()->route('login')->with(AuthentikLogin::ERROR, $error);
    }
}
