# Filament Identity Verification Action

`filament-Identity-verification-action` — package `syriable/filament-identity-verification-action`.

Ask users to confirm their identity (their current password, by default) before a sensitive Filament 5 action opens, and refuse to run that action on the server unless a fresh, scoped, single-use verification grant is presented.

- The protected form is not filled or shown until verification succeeds.
- On success, the verification modal is replaced with the protected action using Filament's `replaceMountedAction()`.
- The protected action re-validates the grant on the server when it executes. A grant is bound to the user, the auth guard and a purpose; it expires, can be revoked, and is consumed atomically.
- The action's own validation, authorization, form schema and business logic are untouched.
- The verification core is UI-agnostic, and new verification methods can be added through a contract.

## Requirements

| | Constraint | Verified locally with |
| --- | --- | --- |
| PHP | `^8.2` | 8.3.6 |
| Laravel | `^11.28 \| ^12.0 \| ^13.0` | 13.35.0 |
| Filament | `^5.0` | 5.10.1 |
| Livewire | 4 (via Filament) | 4.4.7 |

The test suite was run against the "verified locally" versions only. The other combinations are what the constraints allow and what the CI workflow is set up to test; they have not been run yet.

## Installation

```bash
composer require syriable/filament-identity-verification-action
```

The service provider is registered through Laravel package discovery. Publish and run the migration (it creates the `identity_verification_grants` table):

```bash
php artisan vendor:publish --tag="filament-identity-verification-action-migrations"
php artisan migrate
```

Optionally publish the config and translations:

```bash
php artisan vendor:publish --tag="filament-identity-verification-action-config"
php artisan vendor:publish --tag="filament-identity-verification-action-translations"
```

`php artisan filament-identity-verification-action:install` publishes the config and migration and asks whether to migrate.

### Installing from a local checkout

Add a path repository to the host application's `composer.json`, then require the package:

```json
"repositories": [
    { "type": "path", "url": "../filament-identity-verification-action" }
]
```

```bash
composer require syriable/filament-identity-verification-action:@dev
```

### Registering the plugin

Register the plugin in each panel that should use panel-specific settings:

```php
use Syriable\Filament\Plugins\IdentityVerificationAction\IdentityVerificationActionPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(
            IdentityVerificationActionPlugin::make()
                ->grantLifetime(300)        // optional, seconds
                ->defaultMethod('password') // optional
        );
}
```

The plugin adds no pages, resources, navigation or global hooks. Protected actions also work without it, using the package config and the application's default guard. The plugin's only job is to set defaults for its own panel.

## Protecting an action

Protection lives in the action's class, through the `RequiresIdentityVerification` trait. For inline actions, use `ProtectedAction` (a plain `Action` that already uses the trait):

```php
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\ProtectedAction;

ProtectedAction::make('updateEmail')
    ->requiresIdentityVerification(purpose: 'update_email')
    ->schema([
        TextInput::make('email')->email()->required(),
    ])
    ->fillForm(fn (): array => ['email' => Filament::auth()->user()->email])
    ->authorize('update', Filament::auth()->user())
    ->action(function (array $data): void {
        Filament::auth()->user()->update(['email' => $data['email']]);
    });
```

To protect an action class, existing or new, extend it and add the trait and interface. The class doesn't need to be a custom base class. Any `Action` subclass works:

```php
use Filament\Actions\DeleteAction;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\Contracts\ProtectedByIdentityVerification;
use Syriable\Filament\Plugins\IdentityVerificationAction\Concerns\RequiresIdentityVerification;

class VerifiedDeleteAction extends DeleteAction implements ProtectedByIdentityVerification
{
    use RequiresIdentityVerification;
}

VerifiedDeleteAction::make()
    ->requiresIdentityVerification(purpose: 'delete_account');
```

`requiresIdentityVerification()` takes these parameters:

| Parameter | Default | Meaning |
| --- | --- | --- |
| `purpose` | required | What the grant authorizes (`[A-Za-z0-9_.:-]`, max 100). A grant for one purpose is never accepted for another. |
| `method` | panel plugin default, then config `default_method` | Verification method key. |
| `singleUse` | `true` | Consume the grant when the action runs. Set `false` only for actions that `halt()` and are resubmitted. The grant then stays valid until it expires. |

Automated tests cover two contexts: actions returned from `fooAction()` methods on a Livewire component that uses `InteractsWithActions`, and table record actions. Page header actions, bulk actions and schema component actions are mounted at the root in the same way, so they should work, but they are not tested yet. Nested (child) actions are not supported; see the limitations.

### Why a trait and not a fluent macro on every `Action`

Filament's lifecycle hooks (`mountUsing`, `before`, `fillForm`, ...) each hold a single closure, so a later fluent call would silently replace a check installed by a macro. Filament also clones actions, so state kept outside the action object would not follow the clone. Because the checks are overridden lifecycle methods on the action class, no later configuration can remove them, and they survive cloning. An action that does not use the trait is not protected, and calling `requiresIdentityVerification()` on it throws.

## What happens at runtime

1. The user clicks the protected action, and Filament calls `mountAction('updateEmail')`.
2. The action has no valid grant, so its `mount()` does not fill the form or run `beforeFormFilled`/`afterFormFilled`. It mounts the `identityVerification` modal action as its child instead.
3. The user enters their password. `VerificationManager::verify()` checks it through the guard's user provider and applies rate limiting. On failure, the modal stays open with a field error, and the submitted value is cleared from component state.
4. On success, a grant is stored (only a SHA-256 hash of its 64-character random token), bound to user, guard, purpose and method, and expiring after `grants.lifetime` seconds.
5. The verification action calls `$livewire->replaceMountedAction('updateEmail', [...$arguments, 'identityVerificationGrant' => $token], $context)`. The protected action mounts again, the server validates the grant, and the form is filled and shown.
6. On submit, Filament runs its usual checks first: disabled/authorization, form validation. Then the trait's `callBefore()` consumes the grant with a single conditional `UPDATE` (or only validates it if `singleUse: false`). Your `before()` hook and action callback run only after that succeeds. Otherwise a notification is shown and the action is cancelled.
7. Cancelling or closing the verification modal also cancels the protected action (`cancelParentActions()` / `cancelParentActionsOnClose()`). Nothing runs.

The grant token is the only verification input that comes from the client. It is never trusted on its own: the server always checks the hash, user, guard, purpose, expiry, revocation and consumption. The purpose and method come from the PHP configuration of the action being executed. They are never read from request data.

## Configuration

`config/filament-identity-verification-action.php`:

```php
return [
    'default_method' => 'password',
    'methods' => [
        'password' => PasswordVerification::class,
    ],
    'grants' => [
        'lifetime' => 300,          // seconds
        'connection' => null,       // database connection for the grants table
        'table' => 'identity_verification_grants',
    ],
    'rate_limiting' => [
        'max_attempts' => 5,        // failed attempts per user + guard
        'decay_seconds' => 60,
    ],
];
```

Prune spent grants on a schedule:

```php
Schedule::command('identity-verification:prune')->daily();
```

## Adding a verification method

Implement the core contract. Implement `HasVerificationSchema` as well, so that the Filament modal knows which fields to render:

```php
use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\Contracts\HasVerificationSchema;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Contracts\VerificationMethod;

class EmailCodeVerification implements VerificationMethod, HasVerificationSchema
{
    public function verify(Authenticatable $user, #[SensitiveParameter] array $credentials, ?string $guard = null): bool
    {
        // Compare $credentials['code'] with the code you sent, in constant time.
    }

    public function isAvailableFor(Authenticatable $user, ?string $guard = null): bool
    {
        return filled($user->email);
    }

    public function getVerificationSchema(): array
    {
        return [TextInput::make('code')->required()->autocomplete('one-time-code')];
    }

    public function getVerificationErrorField(): string
    {
        return 'code';
    }
}
```

Register it in the config (`'methods' => ['email_code' => EmailCodeVerification::class]`) or at runtime:

```php
app(VerificationManager::class)->extend('email_code', fn () => app(EmailCodeVerification::class));
```

Then use it with `->requiresIdentityVerification(purpose: 'delete_account', method: 'email_code')`. The verification fails closed if a method is unknown, unavailable for the user, or misconfigured. To add translated messages for a method, add the `errors.invalid_credentials.<method>` key.

## Using the core without Filament

```php
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\VerificationManager;

$manager = app(VerificationManager::class);

$grant = $manager->verify($user, 'web', 'password', 'export_data', ['password' => $request->input('password')]);
// $grant->token goes back to the client.

$manager->consume($token, $request->user(), 'web', 'export_data'); // true exactly once
$manager->isValid($token, $user, 'web', 'export_data');              // non-consuming check
$manager->revokeAll($user);                                          // all guards
```

Failures throw `InvalidVerification`, whose `reason` is a `VerificationFailure` enum (`Unauthenticated`, `InvalidCredentials`, `TooManyAttempts`, `MethodUnavailable`).

To store grants elsewhere, bind your own implementation of `Verification\Contracts\VerificationGrantStore` in the container. The store receives only token hashes. `consume()` must be atomic.

## Security notes and known limitations

- **Supported placement.** A protected action must be mounted as a root action, not as a child modal action of another action. In the child case it throws a `LogicException`, because `replaceMountedAction()` would discard the parent. Root placement is tested for Livewire component actions and table record actions.
- **Only trait-based actions are protected.** Filament actions that don't use `RequiresIdentityVerification` are not affected by this package.
- **Grant scope.** A grant is scoped to user + guard + purpose. It is not bound to a record or to action arguments. Within its lifetime, a grant for `delete_post` could be presented for another record the same user can access. Authorization still applies to that record. Use specific purposes and short lifetimes where this matters.
- **Mount-time schema.** While verification is pending, the protected action's schema may be built (not filled) so Filament can resolve it. Mount and fill hooks don't run, and its modal is never shown on top of verification. Keep side effects out of schema closures.
- **Single-use and `halt()`.** With `singleUse: true`, the grant is consumed before the action callback runs. If the callback halts to keep the modal open, a resubmission needs a new verification. A rolled-back database transaction (`databaseTransaction()`) on the same connection also rolls back the consumption.
- **After cancelling.** If the user verifies and then cancels the protected modal, the unconsumed grant stays valid until it expires. Only that user's browser received the token. A new attempt always starts with a new verification.
- **Revocation.** Grants are revoked on `Illuminate\Auth\Events\Logout` (for that guard) and `PasswordReset` (all guards). Password changes that don't fire `PasswordReset`, such as a profile page, should call `VerificationManager::revokeAll($user)`.
- **Rate limiting** uses Laravel's `RateLimiter` (your cache store), keyed by user + guard. It doesn't limit by IP address.
- **Secrets.** Passwords are never stored or logged. Credential parameters are marked `#[SensitiveParameter]`. Grant tokens are stored only as hashes.
- **Authorization is never implied.** A valid grant doesn't bypass `authorize()`, policies, `visible()`/`hidden()` or `disabled()`. Filament checks those first, and an unauthorized attempt does not consume the grant.

## Testing

```bash
composer test      # Pest
composer analyse   # PHPStan (Larastan), level 8
composer lint      # Pint
```

The suite covers the verification core, the full Livewire flow, direct execution with forged, expired, mismatched, revoked and replayed grants, authorization, cancellation, a multi-process race on single-use consumption (needs `pcntl`/`posix`), and package wiring (plugin, provider, migration, translations, and an architecture rule that keeps the core free of Filament).

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
