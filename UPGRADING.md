# Upgrading to 1.1

Version 1.1 raises the runtime floor to PHP 8.5 and requires
`timefrontiers/php-sql-database` 1.1.1 or newer plus
`timefrontiers/php-database-object` and `timefrontiers/php-validator` 1.1.

## Required deployment order

1. Deploy or resolve the three prerequisite 1.1 packages.
2. Stop file mutations and download-token issuance.
3. Take a verified database and storage backup.
4. Run `sql/preflight-v1.1.0.sql` and remediate every failure.
5. Run `sql/upgrade-v1.1.0.sql`.
6. Deploy php-file 1.1, run `composer check`, and exercise local plus configured
   remote-driver health checks.
7. Re-enable traffic only after traversal, upload, and atomic token-consumption
   checks pass.

## Intentional hardening changes

- `setPath()` accepts a canonical relative suffix, not a physical or absolute
  path. `path_prefix` and owner are always added by the package.
- `upload()` accepts only PHP-observed HTTP uploads. Use `import()` for trusted
  programmatic sources.
- GCS, OneDrive, Dropbox, unknown drivers, and mutable reconfiguration are
  rejected.
- Public URLs use immutable prefix-`583` codes through the file service. Stored
  object keys and buckets are never URL identities.
- Existing bearer tokens are revoked. New tokens are digest-only and require a
  keyring with an active key ID.
- `maxDownloads` must be null or greater than zero; expiry must be strictly in
  the future and is evaluated in UTC.
- Local path APIs throw for remote records. Remote reads use streams.
- Deletion is soft-state-driven and may require `retryCleanup()` rather than
  losing metadata after a provider or database failure.

## Rollback

Read `sql/rollback-v1.1.0.md` before migration. Token plaintext is deliberately
cleared and cannot be reconstructed without the pre-upgrade backup.
