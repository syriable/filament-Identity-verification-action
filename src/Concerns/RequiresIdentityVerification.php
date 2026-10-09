<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Concerns;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use LogicException;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\Contracts\ProtectedByIdentityVerification;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\IdentityVerificationAction;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\IdentityVerificationContext;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\IdentityVerificationRequirement;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\VerificationManager;
use Throwable;

/**
 * Makes a Filament action require a fresh, server-verified identity grant.
 *
 * Use it in a class that extends `Filament\Actions\Action` (or any subclass, such
 * as `DeleteAction`) and implements `ProtectedByIdentityVerification`. The checks
 * live in overridden lifecycle methods of the action class itself, so they cannot
 * be removed by later fluent configuration such as `->before()` or `->fillForm()`.
 *
 * - Mounting without a valid grant does not fill the form or run mount hooks;
 *   it opens the identity verification modal instead.
 * - Executing (submitting) without a valid grant is refused: the grant is
 *   validated, and consumed when single-use, before `before()` hooks and the
 *   action's callback run.
 *
 * @mixin Action
 */
trait RequiresIdentityVerification
{
    protected ?IdentityVerificationRequirement $identityVerificationRequirement = null;

    /**
     * Memoizes the last grant check of this instance: [token hash, is valid].
     *
     * @var array{0: string, 1: bool}|null
     */
    protected ?array $identityVerificationGrantCheck = null;

    protected bool $hasPassedIdentityVerification = false;

    protected bool $hasRegisteredIdentityVerificationAction = false;

    /**
     * @param  string  $purpose  What the grant authorizes, e.g. `update_email`. A grant issued for one purpose is never accepted for another.
     * @param  string|null  $method  The verification method key. Defaults to the panel plugin's, then the configured, default method.
     * @param  bool  $singleUse  Consume the grant when the action executes. Disable only for actions that are expected to `halt()` and be resubmitted.
     */
    public function requiresIdentityVerification(string $purpose, ?string $method = null, bool $singleUse = true): static
    {
        $this->identityVerificationRequirement = new IdentityVerificationRequirement($purpose, $method, $singleUse);

        if (! $this->hasRegisteredIdentityVerificationAction) {
            $this->registerModalActions([
                fn (): IdentityVerificationAction => IdentityVerificationAction::make(),
            ]);

            $this->hasRegisteredIdentityVerificationAction = true;
        }

        return $this;
    }

    public function getIdentityVerificationRequirement(): ?IdentityVerificationRequirement
    {
        return $this->identityVerificationRequirement;
    }

    public function isIdentityVerificationPending(): bool
    {
        $requirement = $this->getIdentityVerificationRequirement();

        if (! $requirement) {
            return false;
        }

        $token = $this->getArguments()[ProtectedByIdentityVerification::GRANT_ARGUMENT] ?? null;

        if (! is_string($token) || $token === '') {
            return true;
        }

        $tokenHash = hash('sha256', $token);

        if (($this->identityVerificationGrantCheck[0] ?? null) !== $tokenHash) {
            $this->identityVerificationGrantCheck = [
                $tokenHash,
                app(VerificationManager::class)->isValid(
                    $token,
                    IdentityVerificationContext::user(),
                    IdentityVerificationContext::guard(),
                    $requirement->purpose,
                ),
            ];
        }

        return ! $this->identityVerificationGrantCheck[1];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function mount(array $parameters): mixed
    {
        if (! $this->isIdentityVerificationPending()) {
            return parent::mount($parameters);
        }

        if ($this->getNestingIndex() !== 0) {
            throw new LogicException("The [{$this->getName()}] action requires identity verification, so it must be mounted as a root action, not as a child of another action's modal.");
        }

        // The form of the protected action is deliberately left unfilled. The
        // verification modal is mounted as a child, and on success it replaces
        // this action with a fresh mount that carries the issued grant.
        $livewire = $this->getLivewire();

        if (! $livewire instanceof HasActions) {
            throw new LogicException("The [{$this->getName()}] action requires identity verification, so it must belong to a Livewire component that implements [" . HasActions::class . '].');
        }

        $livewire->mountAction(IdentityVerificationAction::getDefaultName());

        return null;
    }

    public function callBeforeFormFilled(): mixed
    {
        if ($this->isIdentityVerificationPending()) {
            return null;
        }

        return parent::callBeforeFormFilled();
    }

    public function callAfterFormFilled(): mixed
    {
        if ($this->isIdentityVerificationPending()) {
            return null;
        }

        return parent::callAfterFormFilled();
    }

    public function shouldOpenModal(?Closure $checkForSchemaUsing = null): bool
    {
        // While verification is pending, report a modal so that Filament never
        // auto-executes a modal-less protected action on mount.
        if ($this->isIdentityVerificationPending()) {
            return true;
        }

        return parent::shouldOpenModal($checkForSchemaUsing);
    }

    public function callBefore(): mixed
    {
        $this->enforceIdentityVerification();

        try {
            return parent::callBefore();
        } catch (Throwable $exception) {
            $this->hasPassedIdentityVerification = false;

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function call(array $parameters = []): mixed
    {
        // `callBefore()` normally enforces verification first. This covers any
        // code path that calls the action without it.
        if (! $this->hasPassedIdentityVerification) {
            $this->enforceIdentityVerification();
        }

        try {
            return parent::call($parameters);
        } finally {
            $this->hasPassedIdentityVerification = false;
        }
    }

    protected function enforceIdentityVerification(): void
    {
        $requirement = $this->getIdentityVerificationRequirement();

        if (! $requirement) {
            return;
        }

        $token = $this->getArguments()[ProtectedByIdentityVerification::GRANT_ARGUMENT] ?? null;
        $user = IdentityVerificationContext::user();
        $guard = IdentityVerificationContext::guard();
        $manager = app(VerificationManager::class);

        $passed = $requirement->singleUse
            ? $manager->consume($token, $user, $guard, $requirement->purpose)
            : $manager->isValid($token, $user, $guard, $requirement->purpose);

        $this->identityVerificationGrantCheck = null;

        if (! $passed) {
            Notification::make()
                ->danger()
                ->title(__('filament-identity-verification-action::messages.notifications.verification_required.title'))
                ->body(__('filament-identity-verification-action::messages.notifications.verification_required.body'))
                ->send();

            $this->cancel(shouldRollBackDatabaseTransaction: true);
        }

        $this->hasPassedIdentityVerification = true;
    }
}
