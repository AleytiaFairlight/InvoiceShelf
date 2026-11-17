# Sign in with Authentik

InvoiceShelf can let its users sign in with [Authentik](https://goauthentik.io) over OpenID Connect. It adds an **Authentik** button under the login form. The email and password form keeps working next to it, and the customer portal does not change.

## How accounts are matched

Signing in with Authentik never creates a user. Add everyone who should use InvoiceShelf under **Settings → Users** first, with the same email address they have in Authentik.

1. On a person's first sign-in with Authentik, InvoiceShelf finds the user with that email address, ignoring upper and lower case, and links the two accounts.
2. From then on only the link counts. It stores Authentik's `sub` claim in `users.authentik_id`, so changing the email address in either system later doesn't break the link. A different Authentik account that later gets the same email address can't take the user over either.
3. To remove a link, for example after the Authentik account was deleted and created again, run:

   ```bash
   php artisan authentik:unlink person@example.com
   ```

   Their next sign-in links them again by email address.

When InvoiceShelf refuses a sign-in, `storage/logs/laravel.log` records the reason.

## 1. Set up Authentik

These steps assume InvoiceShelf runs at `https://invoices.example.com` and Authentik at `https://authentik.example.com`. Replace both with your own URLs.

In the Authentik admin interface, open **Applications → Applications** and choose **Create with provider**:

1. **Application:** name it *InvoiceShelf*, slug `invoiceshelf`. Under *UI settings*, set the **Launch URL** to `https://invoices.example.com/auth/authentik/redirect`. The InvoiceShelf tile in a user's Authentik library then signs them in.
2. **Provider type:** *OAuth2/OpenID Provider*.
3. **Provider:**
   - **Authorization flow:** `default-provider-authorization-implicit-consent`, or the explicit-consent flow if users should confirm.
   - **Client type:** *Confidential*.
   - **Redirect URIs:** one entry, matching mode *Strict*, `https://invoices.example.com/auth/authentik/callback`.
   - Copy the **Client ID** and **Client Secret** for step 2.
   - Leave the scopes at the defaults. InvoiceShelf asks for `openid`, `profile` and `email`, which Authentik's default mappings provide. Leave the **Subject mode** at its default.
4. **Bindings:** bind the group (or the users) that may use InvoiceShelf, so that nobody else can even start a sign-in.

Under **System → Settings**, leave **Allow users to change email** off, which is the default. If users could change their own email address in Authentik, someone could set theirs to the address of an InvoiceShelf user who has never signed in with Authentik, and be linked to that user.

InvoiceShelf doesn't require the `email_verified` claim. Since 2025.10, Authentik's default email mapping sends `false` for it.

## 2. Configure InvoiceShelf

Add these lines to InvoiceShelf's `.env`, or to the container's environment if you run it in Docker:

```dotenv
AUTHENTIK_BASE_URL=https://authentik.example.com
AUTHENTIK_CLIENT_ID=the client ID from Authentik
AUTHENTIK_CLIENT_SECRET=the client secret from Authentik
```

The base URL is Authentik's root URL. Don't add `/application/o/...` to it.

If you cache the configuration (`php artisan config:cache` or `php artisan optimize`), run that command again, or run `php artisan config:clear`. The button appears once all three settings are set.

The InvoiceShelf server has to reach the base URL itself, because it exchanges the sign-in code for a token and reads the user's details from Authentik. A URL that only works in users' browsers isn't enough.

Optional settings:

| Setting | Default | What it does |
| --- | --- | --- |
| `AUTHENTIK_BUTTON_LABEL` | `Authentik` | The text on the button, for example `Company SSO`. |
| `AUTHENTIK_REDIRECT_URI` | `/auth/authentik/callback` on the host the login page was opened on | A fixed callback URL. It must match the redirect URI in Authentik exactly. |

Run `php artisan migrate` after updating InvoiceShelf. The update adds the `authentik_id` column to `users`.

## Behind a reverse proxy

InvoiceShelf builds the callback URL from the address the browser used. Behind a reverse proxy, it must see the original scheme and host through `X-Forwarded-Proto` and `X-Forwarded-Host`. `TRUSTED_PROXIES` (default `*`) controls which proxies it believes. If Authentik reports a redirect URI error and the URL it shows starts with `http://` or names an internal host, fix the proxy headers or set `AUTHENTIK_REDIRECT_URI`.

## Troubleshooting

| What you see | Likely cause |
| --- | --- |
| No Authentik button | One of the three settings is missing, or the configuration is cached. Run `php artisan config:clear`. |
| Authentik shows a redirect URI error | The callback URL InvoiceShelf sent doesn't match Authentik's redirect URI exactly. See *Behind a reverse proxy*. |
| "Signing in with Authentik did not work" | Authentik refused the code, or InvoiceShelf couldn't reach Authentik. Check `storage/logs/laravel.log` for a wrong client secret or a connection error. The message also appears when the sign-in took too long or was opened in a different browser. |
| "Your Authentik account does not match a user here" | No user has that email address, or the user is linked to another Authentik account. The log says which. Fix the email address, or run `php artisan authentik:unlink`. |
