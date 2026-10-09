# Changelog

All notable changes to `syriable/filament-identity-verification-action` are documented in this file.

## Unreleased

### Added

- Framework-independent verification core: `VerificationManager`, `VerificationMethod` contract, `PasswordVerification`, and hashed, single-use, expiring verification grants with a replaceable `VerificationGrantStore` (database store included).
- Filament 5 integration: `RequiresIdentityVerification` trait, `ProtectedAction`, and the `IdentityVerificationAction` modal, which replaces itself with the protected action after a successful verification.
- Server-side enforcement of grants when a protected action is mounted and when it executes.
- Rate limiting of failed attempts, revocation on logout and password reset, and an `identity-verification:prune` command.
- `IdentityVerificationActionPlugin` for per-panel defaults.
- English translations.
- MIT license.
