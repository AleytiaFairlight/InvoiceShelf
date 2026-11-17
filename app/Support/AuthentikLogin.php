<?php

namespace App\Support;

/**
 * Sign-in with Authentik (OpenID Connect): whether it is set up, and what the
 * login page needs to offer it. docs/authentik-sso.md covers the setup.
 */
final class AuthentikLogin
{
    /**
     * Session key that carries the reason a sign-in failed back to the login
     * page: "failed" or "no_account".
     */
    public const ERROR = 'authentik_login_error';

    /**
     * Sign-in with Authentik is on once its base URL, client ID and client
     * secret are all set.
     */
    public static function enabled(): bool
    {
        return filled(config('services.authentik.base_url'))
            && filled(config('services.authentik.client_id'))
            && filled(config('services.authentik.client_secret'));
    }

    /**
     * What the login page needs for its button: where the button points, its
     * label, and why the sign-in that just came back failed, if it did. Null
     * while sign-in with Authentik is off.
     *
     * @return array{url: string, label: string, error: string|null}|null
     */
    public static function forLoginPage(): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        return [
            'url' => route('authentik.redirect', [], false),
            'label' => (string) config('services.authentik.button_label'),
            'error' => session(self::ERROR),
        ];
    }
}
