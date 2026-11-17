<?php

use App\Models\User;
use App\Support\AuthentikLogin;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as AuthentikAccount;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

/** Turns sign-in with Authentik on, pointed at a made-up Authentik. */
function configureAuthentik(array $settings = []): void
{
    config(['services.authentik' => [
        'base_url' => 'https://authentik.test',
        'client_id' => 'invoiceshelf',
        'client_secret' => 'client-secret',
        'redirect' => '/auth/authentik/callback',
        'button_label' => 'Authentik',
        ...$settings,
    ]]);
}

/** Authentik answers the callback with this account. */
function signInAs(string $id, ?string $email): void
{
    Socialite::fake('authentik', (new AuthentikAccount)->map(['id' => $id, 'email' => $email]));
}

/** The callback Authentik sends the browser back to. */
function authentikCallback(): TestResponse
{
    return get('/auth/authentik/callback?code=the-code&state=the-state');
}

/** What the login page passes to the Vue app about sign-in with Authentik. */
function authentikOnLoginPage(): ?array
{
    test()->withoutVite();

    if (! preg_match('/window\.authentik_login = (\{.*\})/', (string) test()->view('app'), $match)) {
        return null;
    }

    return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
}

function seededAdmin(): User
{
    return User::query()->where('email', 'admin@invoiceshelf.com')->firstOrFail();
}

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    configureAuthentik();
});

test('sign-in with Authentik is off until its base URL, client ID and secret are set', function (string $setting) {
    configureAuthentik([$setting => null]);

    get('/auth/authentik/redirect')->assertNotFound();
    authentikCallback()->assertNotFound();

    expect(authentikOnLoginPage())->toBeNull();
})->with(['base_url', 'client_id', 'client_secret']);

test('the login page offers sign-in with Authentik', function () {
    configureAuthentik(['button_label' => 'Company SSO']);

    expect(authentikOnLoginPage())->toBe([
        'url' => '/auth/authentik/redirect',
        'label' => 'Company SSO',
        'error' => null,
    ]);
});

test('the redirect asks Authentik for an authorization code with PKCE and only the scopes InvoiceShelf needs', function () {
    $response = get('/auth/authentik/redirect')->assertRedirect();

    $location = $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $verifier = session('code_verifier');

    expect(strtok($location, '?'))->toBe('https://authentik.test/application/o/authorize/')
        ->and($query)->toMatchArray([
            'client_id' => 'invoiceshelf',
            'redirect_uri' => url('/auth/authentik/callback'),
            'scope' => 'openid profile email',
            'response_type' => 'code',
            'state' => session('state'),
            'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ])
        ->and($query['state'])->toHaveLength(40);
});

test('a fixed redirect URI replaces the one built from the request', function () {
    configureAuthentik(['redirect' => 'https://invoices.example.com/auth/authentik/callback']);

    $location = get('/auth/authentik/redirect')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['redirect_uri'])->toBe('https://invoices.example.com/auth/authentik/callback');
});

test('the callback trades the code for a token, reads the account and signs its user in', function () {
    $requests = [];
    $authentik = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'the-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 300,
            'scope' => 'openid profile email',
        ])),
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'sub' => 'authentik-account-1',
            'email' => 'admin@invoiceshelf.com',
            'email_verified' => false,
            'name' => 'Jane Doe',
        ])),
    ]));
    $authentik->push(Middleware::history($requests));
    configureAuthentik(['guzzle' => ['handler' => $authentik]]);

    $this->withSession(['state' => 'the-state', 'code_verifier' => 'the-code-verifier']);
    authentikCallback()->assertRedirect('/admin/dashboard');

    [$token, $userinfo] = array_column($requests, 'request');
    parse_str((string) $token->getBody(), $fields);

    expect((string) $token->getUri())->toBe('https://authentik.test/application/o/token/')
        ->and($fields)->toMatchArray([
            'grant_type' => 'authorization_code',
            'code' => 'the-code',
            'client_id' => 'invoiceshelf',
            'client_secret' => 'client-secret',
            'redirect_uri' => url('/auth/authentik/callback'),
            'code_verifier' => 'the-code-verifier',
        ])
        ->and((string) $userinfo->getUri())->toBe('https://authentik.test/application/o/userinfo/')
        ->and($userinfo->getHeaderLine('Authorization'))->toBe('Bearer the-access-token')
        ->and(seededAdmin()->authentik_id)->toBe('authentik-account-1');

    $this->assertAuthenticatedAs(seededAdmin(), 'web');
});

test('the first sign-in links the user with the same email address, whatever its case', function () {
    signInAs('authentik-account-1', 'Admin@InvoiceShelf.com');

    authentikCallback()->assertRedirect('/admin/dashboard');

    $this->assertAuthenticatedAs(seededAdmin(), 'web');
    expect(seededAdmin()->authentik_id)->toBe('authentik-account-1');
});

test('a linked account signs in as its user even after an email address changed', function () {
    seededAdmin()->forceFill(['authentik_id' => 'authentik-account-1'])->save();
    signInAs('authentik-account-1', 'new-address@example.com');

    authentikCallback()->assertRedirect('/admin/dashboard');

    $this->assertAuthenticatedAs(seededAdmin(), 'web');
});

test('an account that matches no user is refused, and nobody is created', function () {
    $users = User::query()->count();
    signInAs('authentik-account-1', 'stranger@example.com');

    authentikCallback()
        ->assertRedirect('/login')
        ->assertSessionHas(AuthentikLogin::ERROR, 'no_account');

    $this->assertGuest('web');
    expect(User::query()->count())->toBe($users);
});

test('an account without an email address is refused', function () {
    signInAs('authentik-account-1', null);

    authentikCallback()->assertSessionHas(AuthentikLogin::ERROR, 'no_account');

    $this->assertGuest('web');
});

test('a user linked to another account is not taken over by a new account with their email address', function () {
    seededAdmin()->forceFill(['authentik_id' => 'authentik-account-1'])->save();
    signInAs('authentik-account-2', 'admin@invoiceshelf.com');

    authentikCallback()->assertSessionHas(AuthentikLogin::ERROR, 'no_account');

    $this->assertGuest('web');
    expect(seededAdmin()->authentik_id)->toBe('authentik-account-1');
});

test('a cancelled or incomplete sign-in goes back to the login page', function (string $query) {
    signInAs('authentik-account-1', 'admin@invoiceshelf.com');

    get('/auth/authentik/callback'.$query)
        ->assertRedirect('/login')
        ->assertSessionHas(AuthentikLogin::ERROR, 'failed');

    $this->assertGuest('web');
})->with([
    'refused' => ['?error=access_denied&state=the-state'],
    'no code' => ['?state=the-state'],
]);

test('a callback whose state does not match is refused before Authentik is asked anything', function () {
    $requests = [];
    $authentik = HandlerStack::create(new MockHandler([]));
    $authentik->push(Middleware::history($requests));
    configureAuthentik(['guzzle' => ['handler' => $authentik]]);

    $this->withSession(['state' => 'the-state', 'code_verifier' => 'the-code-verifier']);
    get('/auth/authentik/callback?code=the-code&state=another-state')
        ->assertRedirect('/login')
        ->assertSessionHas(AuthentikLogin::ERROR, 'failed');

    $this->assertGuest('web');
    expect($requests)->toBeEmpty();
});

test('an error from Authentik is reported and sends the user back to the login page', function () {
    Exceptions::fake();
    configureAuthentik(['guzzle' => ['handler' => HandlerStack::create(new MockHandler([
        new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'invalid_grant'])),
    ]))]]);

    $this->withSession(['state' => 'the-state', 'code_verifier' => 'the-code-verifier']);
    authentikCallback()->assertSessionHas(AuthentikLogin::ERROR, 'failed');

    $this->assertGuest('web');
    Exceptions::assertReported(ClientException::class);
});

test('the login page says why the sign-in failed', function () {
    $this->withSession([AuthentikLogin::ERROR => 'no_account']);

    expect(authentikOnLoginPage())->toMatchArray(['error' => 'no_account']);
});

test('signing in returns to the page that asked for it', function () {
    signInAs('authentik-account-1', 'admin@invoiceshelf.com');

    $this->withSession(['url.intended' => url('/admin/invoices')]);

    authentikCallback()->assertRedirect('/admin/invoices');
});

test('a user who is signed in already goes straight to the dashboard', function () {
    actingAs(seededAdmin());

    get('/auth/authentik/redirect')->assertRedirect('/admin/dashboard');
});

test('authentik:unlink removes the link, so the next sign-in links by email address again', function () {
    seededAdmin()->forceFill(['authentik_id' => 'authentik-account-1'])->save();

    artisan('authentik:unlink', ['email' => 'ADMIN@invoiceshelf.com'])
        ->expectsOutputToContain('Unlinked admin@invoiceshelf.com')
        ->assertSuccessful();

    expect(seededAdmin()->authentik_id)->toBeNull();

    signInAs('authentik-account-2', 'admin@invoiceshelf.com');
    authentikCallback()->assertRedirect('/admin/dashboard');

    expect(seededAdmin()->authentik_id)->toBe('authentik-account-2');
});

test('authentik:unlink fails for an unknown email address', function () {
    artisan('authentik:unlink', ['email' => 'nobody@example.com'])->assertFailed();
});
