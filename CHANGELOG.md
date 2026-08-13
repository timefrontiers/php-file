# Changelog

## 1.1.0 - 2026-08-13

### Security

- Enforce canonical driver-neutral object keys and contained, symlink-safe local paths.
- Verify upload size, MIME/extension agreement, raster decoding, and image resource limits from server-observed bytes.
- Store only keyed token digests, bind tokens to file IDs, and consume download limits atomically.
- Reject unknown and unimplemented drivers instead of falling back to local storage.

### Added

- Immutable validated boot configuration and injectable storage-driver factories.
- Typed storage results/exceptions plus streaming reads for local, S3, and MinIO.
- Recoverable file lifecycle and retryable cleanup state.
- Idempotent preflight/upgrade scripts, rollback guidance, and a v1.0.7 migration fixture.
- PHPUnit coverage for MySQLi, PDO-MySQL, concurrent token consumption, and migration reruns.

### Changed

- Require PHP 8.5, `php-sql-database ^1.1.1`, `php-database-object ^1.1`, and `php-validator ^1.1`.
- Treat `setPath()` as a relative suffix beneath the configured prefix and owner.
- Restrict `upload()` to genuine HTTP uploads; use `import()` for trusted programmatic files.
- Route public URLs through immutable file codes rather than storage paths.
- Make local writer operations atomic and explicitly reject remote records.

### Migration impact

- Existing v1.0 bearer tokens are revoked because the old column could contain truncated values.
- GCS, OneDrive, and Dropbox remain rejected stubs in 1.1.
- Stricter path, upload, token, and configuration validation is intentional.
