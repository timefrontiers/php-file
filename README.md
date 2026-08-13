# timefrontiers/php-file

Secure multi-driver object storage for PHP 8.5 applications. Version 1.1
supports local filesystems, AWS S3, and MinIO; provides bounded image handling,
streaming reads, opaque public identities, expiring download tokens, and
recoverable storage/database workflows.

## Requirements

- PHP 8.5 or newer
- `ext-fileinfo`
- `ext-gd`
- `ext-zip` when DOCX, XLSX, or PPTX uploads are allowed
- MariaDB 10.4+ or MySQL 8.0+
- `timefrontiers/php-sql-database` 1.1.1 or newer on the 1.1 line
- `aws/aws-sdk-php` when S3 or MinIO is enabled

## Installation and database

```bash
composer require timefrontiers/php-file:^1.1
```

Use `sql/install.sql` for a new database. To upgrade v1.0.x:

1. Put file writes and token issuance into maintenance mode and take a backup.
2. Run `sql/preflight-v1.1.0.sql`; remediate every reported unsafe path,
   orphan, or duplicate.
3. Run `sql/upgrade-v1.1.0.sql`.
4. Deploy v1.1 and run its test/health gate before enabling token issuance.

All v1.0 download tokens are revoked during migration. Their old signed values
may have been truncated by `CHAR(64)`, so they cannot be migrated safely. See
`sql/rollback-v1.1.0.md` before attempting a rollback.

## Boot configuration

`File::configure()` is a call-once boot facade. Configuration is validated and
frozen, then snapshotted into every file and driver instance.

```php
use TimeFrontiers\File\File;

File::configure(
    base: [
        'default_driver'         => 'local',
        'db_name'                => 'file',
        'path_prefix'            => 'User-Files',
        'service_url'            => 'https://files.example.com',
        'public_url_path'        => 'file',
        'download_url_path'      => 'download',
        'max_size'               => 25 * 1024 * 1024,
        'min_size'               => 0,
        'max_width_px'           => 2000,
        'max_height_px'          => 2000,
        'max_image_pixels'       => 40_000_000,
        'max_image_memory_bytes' => 256 * 1024 * 1024,
        'token_enabled'          => true,
        'token_keys'             => [
            '2026-01' => $_ENV['FILE_TOKEN_KEY_2026_01'],
            '2026-08' => $_ENV['FILE_TOKEN_KEY_2026_08'],
        ],
        'active_token_key_id'    => '2026-08',
    ],
    drivers: [
        'local' => [
            // Physical root only. It must already exist and must not be a symlink.
            'upload_path' => '/srv/linktude-storage',
        ],
        's3' => [
            'bucket' => 'example-files',
            'region' => 'eu-west-1',
            'key'    => $_ENV['AWS_ACCESS_KEY_ID'],
            'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'],
        ],
        'minio' => [
            'endpoint' => 'https://minio.internal.example',
            'bucket'   => 'example-files',
            'region'   => 'us-east-1',
            'key'      => $_ENV['MINIO_ACCESS_KEY'],
            'secret'   => $_ENV['MINIO_SECRET_KEY'],
        ],
    ],
);
```

Token secrets must contain at least 32 bytes. Keep retired keys in `token_keys`
while their unexpired tokens remain valid; only `active_token_key_id` is used
for new tokens. The v1.0 `token_secret` setting remains a deprecated v1.1 shim
and is converted to a one-key keyring.

Unknown drivers and the GCS, OneDrive, and Dropbox stubs are rejected. A custom
`StorageDriverFactoryInterface` may be passed as the optional third argument to
`File::configure()` for injected SDK adapters and deterministic fakes.

## Canonical storage layout

`base.path_prefix` is the logical namespace. A driver's root or bucket is the
physical storage location. The package constructs every key as:

```text
{path_prefix}/{owner}/{optional setPath suffix}/{generated object name}
```

`setPath()` accepts only a canonical relative suffix:

```php
$file = new File($conn);
$file->owner = $userCode;
$file->setPath('avatars/original');
```

Absolute paths, drive/UNC prefixes, backslashes, dot segments, control
characters, empty segments, and NTFS alternate-stream colons are rejected.
For local storage, every existing ancestor is resolved beneath the configured
root and symbolic-link traversal is rejected, including for targets that do
not exist yet.

## HTTP uploads and trusted imports

`upload()` accepts only a valid PHP HTTP upload. It checks the PHP error,
requires `is_uploaded_file()`, stages the bytes, observes the actual size with
the server, detects MIME with `finfo`, and verifies the extension/content pair.
The submitted `size` and `type` fields are never trusted.

```php
$file = new File($conn, 'local');
$file->owner = $userCode;
$file->caption = 'Profile avatar';
$file->privacy('private');

if (!$file->upload($_FILES['avatar'], creator: $userCode)) {
    $errors = (new InstanceError($file, true))->get('upload', true);
}
```

Use the separate `import()` API for an application-owned local source. It does
not pretend that the source is an HTTP upload and it copies rather than moves
the caller's file:

```php
$file = new File($conn, 's3');
$file->owner = 'SYSTEM';
$ok = $file->import('/srv/generated/monthly-report.pdf', 'report.pdf');
```

Executable/script types and SVG are denied by default. `allowed_extensions`,
`allowed_mime_types`, `denied_extensions`, and `denied_mime_types` customize
the policy. Raster images must decode successfully within the configured
dimension, pixel, and estimated-memory limits. MIME, dimensions, size, and
SHA-512 checksum are recalculated after resizing.

## Reading and writing

`openReadStream()` works with local, S3, and MinIO objects. `readAll()` and
`readLines()` are compatibility helpers bounded by `read_all_max_bytes`.

`write()`, `writeLine()`, and `fullPath()` are local-only. Remote calls are
rejected explicitly. Appends use an exclusive file lock; prepend and line
replacement stream through a same-directory temporary file and replace the
object only after the new content has been flushed. Empty writes are valid.

## Public and private URLs

Storage keys, buckets, paths, and numeric IDs are never public URL material.
For an active public file:

```php
$url = $file->url();
// https://files.example.com/file/583...
```

The service/CDN route resolves immutable prefix-`583` code, checks active/public
state, and streams from the configured driver. `url()` throws for private files.

For a private download:

```php
$bearer = $file->createToken('+24 hours', maxDownloads: 3, createdBy: $userCode);
$url = $file->tokenUrl($bearer);

// Download endpoint:
$file = File::resolveToken($bearer, $conn);
if ($file === false) {
    http_response_code(403);
    exit;
}
$file->forceDownload($bearer);
```

The bearer is returned once. The database stores only a key ID and keyed HMAC
digest. Immediately before response headers/bytes, consumption executes one
atomic update whose predicate includes digest, key ID, requested file ID,
expiry, revocation, and remaining count. Opening the provider stream unsuccessfully
does not consume the token. A disconnect or failure after streaming starts does.
Expiry is UTC and `expires_at <= UTC_TIMESTAMP()` is expired.

## Deletion and relationship recovery

File lifecycle is `pending_upload -> active -> deleting -> deleted`, with
`cleanup_required` as the durable retry state. Storage and SQL do not pretend
to share a transaction. `destroy()` records the state before deleting an
object, then transactionally revokes tokens, removes relationships, and marks
the metadata deleted. Use `retryCleanup()` for a cleanup-required record.

`FileDefault::set()` is transactional. Default-set mode and ordering,
folder membership, public codes, token digests, and storage object identity are
backed by database uniqueness and foreign keys rather than check-then-insert
races.

## Supported drivers

| Driver | Upload | Stream read | Move | Delete | Public storage path exposed |
|---|---:|---:|---:|---:|---:|
| Local | Yes | Yes | Yes | Yes | No |
| AWS S3 | Yes | Yes | Yes | Yes | No |
| MinIO | Yes | Yes | Yes | Yes | No |
| GCS / OneDrive / Dropbox | Rejected stub | No | No | No | No |

## Compatibility notes

- Preserved: `File::configure()`, `new File($conn, $driverOverride)`,
  `setPath()`, `upload()`, `url()`, token/download methods, metadata lookups,
  `FileDefault`, `Folder`, and `FolderFile` entry points.
- Intentional hardening: paths must be relative and canonical; `upload()` no
  longer accepts programmatic files; unknown/stub drivers fail; token limits
  must be positive; configuration cannot be changed after bootstrap.
- `fullPath()` and `DriverException` remain deprecated compatibility surfaces.
  `fullPath()` throws for every non-local record.

## Development gate

```bash
composer check
```

Unit tests need no external services. Database integration tests run against
both MySQLi and PDO-MySQL when `TF_FILE_TEST_HOST`, `TF_FILE_TEST_USER`,
`TF_FILE_TEST_PASSWORD`, and a `TF_FILE_TEST_DATABASE` ending in `_test` or
`_testing` are supplied. Run the same matrix on MySQL and MariaDB before the
release tag.

## License

MIT
