<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Actions;

use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\Contracts\HasVerificationSchema;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\Contracts\ProtectedByIdentityVerification;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Exceptions\InvalidVerification;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Exceptions\VerificationFailure;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\VerificationManager;

/**
 * The identity verification modal. It is registered automatically as a modal
 * action of every action that uses `RequiresIdentityVerification`, and is only
 * resolvable as a child of such an action.
 *
 * On success it issues a grant for the protected action's server-configured
 * purpose and replaces itself (and its parent) with a fresh mount of the
 * protected action that carries the grant token.
 */
class IdentityVerificationAction extends Action
{
    public static function getDefaultName(): string
    {
        return 'identityVerification';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('filament-identity-verification-action::messages.action.label'))
            ->modalHeading(__('filament-identity-verification-action::messages.action.modal.heading'))
            ->modalDescription(__('filament-identity-verification-action::messages.action.modal.description'))
            ->modalIcon(Heroicon::OutlinedShieldCheck)
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel(__('filament-identity-verification-action::messages.action.modal.actions.submit.label'))
            // Cancelling or closing verification must also discard the protected action.
            ->cancelParentActions()
            ->cancelParentActionsOnClose()
            ->schema(fn (): array => $this->getVerificationSchema())
            ->action(function (array $data, HasActions $livewire): void {
                $this->verifyIdentity($data, $livewire);
            });
    }

    /**
     * The protected action this verification belongs to, resolved server-side
     * from the mounted action stack.
     */
    public function getProtectedAction(): (Action & ProtectedByIdentityVerification) | null
    {
        $nestingIndex = $this->getNestingIndex();

        if (! $nestingIndex) {
            return null;
        }

        $livewire = $this->getLivewire();

        if ((! $livewire instanceof HasActions) || (! method_exists($livewire, 'getMountedAction'))) {
            return null;
        }

        $parent = $livewire->getMountedAction($nestingIndex - 1);

        if ((! $parent instanceof Action) || (! $parent instanceof ProtectedByIdentityVerification)) {
            return null;
        }

        if (! $parent->getIdentityVerificationRequirement()) {
            return null;
        }

        return $parent;
    }

    /**
     * @return array<Component>
     */
    public function getVerificationSchema(): array
    {
        $method = $this->getProtectedAction()?->getIdentityVerificationRequirement()?->resolveMethod();

        if ($method === null) {
            return [];
        }

        if ($method === 'password') {
            return [
                TextInput::make('password')
                    ->label(__('filament-identity-verification-action::messages.action.modal.form.password.label'))
                    ->password()
                    ->revealable(Filament::getCurrentPanel()?->arePasswordsRevealable() ?? false)
                    ->autocomplete('current-password')
                    ->required()
                    ->autofocus(),
            ];
        }

        $verificationMethod = app(VerificationManager::class)->method($method);

        if (! $verificationMethod instanceof HasVerificationSchema) {
            throw new LogicException("The identity verification method [{$method}] must implement [" . HasVerificationSchema::class . '] to be used in a Filament action.');
        }

        return $verificationMethod->getVerificationSchema();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function verifyIdentity(#[SensitiveParameter] array $data, HasActions $livewire): void
    {
        $protectedAction = $this->getProtectedAction();
        $requirement = $protectedAction?->getIdentityVerificationRequirement();
        $protectedNestingIndex = $protectedAction?->getNestingIndex();

        $mountedProtectedAction = property_exists($livewire, 'mountedActions')
            ? ($livewire->mountedActions[0] ?? null)
            : null;

        if (
            (! $protectedAction) ||
            (! $requirement) ||
            ($protectedNestingIndex !== 0) ||
            (! is_array($mountedProtectedAction)) ||
            (! method_exists($livewire, 'replaceMountedAction'))
        ) {
            Notification::make()
                ->danger()
                ->title(__('filament-identity-verification-action::messages.notifications.verification_required.title'))
                ->send();

            $this->cancel();

            return;
        }

        $method = $requirement->resolveMethod();

        try {
            $grant = app(VerificationManager::class)->verify(
                user: IdentityVerificationContext::user(),
                guard: IdentityVerificationContext::guard(),
                method: $method,
                purpose: $requirement->purpose,
                credentials: $data,
                grantLifetime: IdentityVerificationContext::grantLifetime(),
            );
        } catch (InvalidVerification $exception) {
            $this->failVerification($exception, $method, $livewire);
        }

        $arguments = is_array($mountedProtectedAction['arguments'] ?? null) ? $mountedProtectedAction['arguments'] : [];
        $context = is_array($mountedProtectedAction['context'] ?? null) ? $mountedProtectedAction['context'] : [];

        // Arguments and context are carried over so that the protected action is
        // resolved exactly as before (e.g. the same table record). The grant is
        // only valid for the purpose configured on whichever action resolves, so
        // tampering with them cannot widen what the grant authorizes.
        $livewire->replaceMountedAction(
            $protectedAction->getName(),
            [
                ...$arguments,
                ProtectedByIdentityVerification::GRANT_ARGUMENT => $grant->token,
            ],
            // The user has interacted with the page, so this is no longer a URL mount.
            Arr::except($context, ['mountedFromUrl']),
        );
    }

    protected function failVerification(InvalidVerification $exception, string $method, HasActions $livewire): never
    {
        $field = 'password';

        if ($method !== 'password') {
            $verificationMethod = app(VerificationManager::class)->method($method);

            if ($verificationMethod instanceof HasVerificationSchema) {
                $field = $verificationMethod->getVerificationErrorField();
            }
        }

        $statePath = "mountedActions.{$this->getNestingIndex()}.data";

        // Do not send the submitted secret back to the browser.
        foreach (array_keys(data_get($livewire, $statePath) ?? []) as $key) {
            data_set($livewire, "{$statePath}.{$key}", null);
        }

        throw ValidationException::withMessages([
            "{$statePath}.{$field}" => match ($exception->reason) {
                VerificationFailure::TooManyAttempts => __('filament-identity-verification-action::messages.errors.too_many_attempts', [
                    'seconds' => $exception->retryAfterSeconds,
                    'minutes' => (int) ceil(($exception->retryAfterSeconds ?? 0) / 60),
                ]),
                VerificationFailure::Unauthenticated => __('filament-identity-verification-action::messages.errors.unauthenticated'),
                VerificationFailure::MethodUnavailable => __('filament-identity-verification-action::messages.errors.method_unavailable'),
                VerificationFailure::InvalidCredentials => Lang::has($methodMessageKey = "filament-identity-verification-action::messages.errors.invalid_credentials.{$method}")
                    ? __($methodMessageKey)
                    : __('filament-identity-verification-action::messages.errors.invalid_credentials.default'),
            },
        ]);
    }
}
