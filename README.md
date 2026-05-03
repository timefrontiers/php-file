# timefrontiers/php-file

Multi-driver file storage for PHP 8.3+. Handles upload, retrieval, and streaming of files across local and cloud storage backends, with expiring/download-limited tokens, image size enforcement, and file ownership.

---

## Requirements

- PHP 8.3+
- MariaDB 10.4+ / MySQL 8.0+
- `timefrontiers/php-sql-database`
- `timefrontiers/php-database-object`
- `timefrontiers/php-has-errors`
- `timefrontiers/php-pagination`
- `timefrontiers/php-data`
- `timefrontiers/php-validator`
- `gumlet/php-image-resize` _(bundled — used for max-dimension enforcement on upload)_

**Optional:**
- `aws/aws-sdk-php ^3.0` — required when using the S3 or MinIO driver

---

## Installation

```bash
composer require timefrontiers/php-file
```

For S3 or MinIO support:

```bash
composer require aws/aws-sdk-php
```

---

## Database

Run `sql/install.sql` against your `file` database to create the required tables:

```
file_meta      — primary file record
file_tokens    — expiring / download-limited tokens
file_default   — per-owner default file mappings (e.g. avatar, banner)
folders        — named file containers
folder_files   — folder ↔ file pivot
```

**Migrating from `linktude/php-file`?** See [`sql/migrate.sql`](sql/migrate.sql).

---

## Configuration

Call `File::configure()` once in your application bootstrap, before any `File` instance is created.

`base[]` holds global settings. `drivers[]` is a keyed map where each key is a driver name and the value is that driver's config. Only the drivers you declare are available at runtime.

```php
use TimeFrontiers\File\File;

File::configure(
    base: [
        'default_driver' => 'local',                     // which driver to use when none is specified
        'path_prefix'    => 'User-Files',                // logical storage namespace — package appends /{owner}
        'db_name'        => 'file',                      // database name
        'service_url'    => 'https://files.example.com', // base URL for your token download endpoint
        'token_secret'   => 'your-hmac-secret',          // used to sign download tokens
        'max_size'       => 1024 * 1024 * 25,            // 25 MB upload limit
        'min_size'       => 0,                           // 0 = no minimum
        'max_width_px'   => 2000,                        // images wider than this are resized down
        'max_height_px'  => 2000,
        'min_width_px'   => null,                        // null = no minimum dimension check
        'min_height_px'  => null,
    ],
    drivers: [
        'local' => [
            'upload_path' => '/var/www/storage',          // absolute root on disk
            'storage_url' => 'https://cdn.example.com',   // base URL for direct public file access
        ],
        's3' => [
            'bucket'      => 'my-bucket',
            'region'      => 'us-east-1',
            'key'         => 'ACCESS_KEY_ID',
            'secret'      => 'SECRET_ACCESS_KEY',
            'endpoint'    => null,                        // set for S3-compatible stores (not MinIO — use 'minio' driver)
            'storage_url' => '',                          // optional CDN override; if empty → native S3 URL
        ],
        'minio' => [
            'endpoint'    => 'http://localhost:9000',     // MinIO server URL (required)
            'bucket'      => 'my-bucket',                 // bucket name (required)
            'region'      => 'us-east-1',                 // ignored by MinIO but required by the SDK
            'key'         => 'minioadmin',
            'secret'      => 'minioadmin',
            'storage_url' => '',                          // optional CDN/public URL override
        ],
    ]
);
```

> **Validation at configure time:** `default_driver` must match a key in `drivers[]`. An unknown driver, a missing `default_driver`, or a non-array driver entry all throw a `ConfigurationException` immediately.

### `path_prefix` — logical storage namespace

`base.path_prefix` is appended before `/{owner}` by the package automatically, producing a
consistent storage path across **all** drivers:

| Driver | Physical / key location |
|---|---|
| `local` | `{upload_path}/{path_prefix}/{owner}/{filename}` |
| `s3` | Object key: `{path_prefix}/{owner}/{filename}` |
| `minio` | Object key: `{path_prefix}/{owner}/{filename}` |

Set it dynamically at configure-time based on the user's access level:
```php
$path_prefix = get_constant('FILE_ACCESS_SCOPE') === 'USER'
    ? 'User-Files'
    : $session->access_group()->value;  // e.g. 'ADMIN'

File::configure(base: ['path_prefix' => $path_prefix, ...], drivers: [...]);
```

### Local driver options

| Key | Required | Description |
|---|---|---|
| `upload_path` | ✅ | Absolute filesystem root where files are stored |
| `storage_url` | ✅ | Base URL for direct public access to files |

### S3 driver options

| Key | Required | Description |
|---|---|---|
| `bucket` | ✅ | S3 bucket name |
| `region` | — | AWS region (default `us-east-1`) |
| `key` | ✅ | AWS access key ID |
| `secret` | ✅ | AWS secret access key |
| `endpoint` | — | Custom endpoint for S3-compatible stores |
| `storage_url` | — | CDN override; if empty, native S3 URL is used |

### MinIO driver options

| Key | Required | Description |
|---|---|---|
| `endpoint` | ✅ | MinIO server URL (e.g. `http://localhost:9000`) |
| `bucket` | ✅ | Bucket name |
| `region` | — | Ignored by MinIO but required by the SDK (default `us-east-1`) |
| `key` | ✅ | MinIO access key |
| `secret` | ✅ | MinIO secret key |
| `storage_url` | — | CDN/public URL override; if empty, URL is built as `{endpoint}/{bucket}/{path}` |

---

## Usage

### Upload

```php
use TimeFrontiers\File\File;

// Uses base.default_driver — _path is auto-built as /{path_prefix}/{owner}
$file = new File($conn);

// Or select a driver explicitly for this instance
$file = new File($conn, 's3');
$file = new File($conn, 'minio');

$file->privacy('public');    // 'public' | 'private'
$file->owner   = $userCode;
$file->caption = 'Profile photo';

// _path is automatically /{path_prefix}/{userCode} — no setPath() needed
$ok = $file->upload($_FILES['avatar'], owner: $userCode, creator: $userCode);

// Override path explicitly when needed (e.g. system files, shared folders):
// $file->setPath('/System/Shared');

if (!$ok) {
    // $file->getErrors() — HasErrors compatible
}

echo $file->id;           // BIGINT surrogate key
echo $file->code;         // '583...' 15-char human identifier
echo $file->sizeAsText(); // '1.2 MB'
```

### Load an existing file

```php
// by BIGINT id
$file = (new File($conn))->load(42);

// by 15-char code
$file = (new File($conn))->load('583258614696648');

// via QueryBuilder
$files = File::query()
    ->where('owner', $userCode)
    ->where('type_group', 'image')
    ->orderByDesc('_created')
    ->limit(20)
    ->get();
```

### Direct URL (public files only)

```php
// URL exposes only the unique filename — internal folder structure is never revealed
echo $file->url();   // https://cdn.example.com/abc123def456789.jpg
```

### Download tokens

Tokens are HMAC-signed, driver-agnostic, and enforce both time expiry and download limits.

```php
// Mint a token — expires in 24 hours, max 5 downloads
$token = $file->createToken(
    expiresAt:    '+24 hours',
    maxDownloads: 5,
    createdBy:    $userCode
);

// Build the full URL to hand to a client
$url = $file->tokenUrl($token);
// → https://files.example.com/download/<token>
```

**On your download endpoint:**

```php
// Resolve + validate the token (verifies HMAC, checks expiry and counter)
$file = File::resolveToken($token);

if (!$file) {
    http_response_code(403);
    exit();
}

$file->download($token);       // stream inline  (increments counter)
$file->forceDownload($token);  // force attachment download
```

### Update metadata

```php
$file->caption   = 'Updated caption';
$file->nice_name = 'new-name.jpg';
$file->update();
```

### Lock a file

Locking computes a SHA-512 checksum and prevents further updates.

```php
$file->lock();

echo $file->locked();    // true
echo $file->checksum();  // sha-512 hex string
```

### Delete a file

```php
// Refuses if the file is set as a default anywhere
$file->destroy();

// Force removal even if set as a default
$file->destroy(force: true);
```

---

## Default files

Map an owner string to one or more named file slots.

```php
use TimeFrontiers\File\FileDefault;

// Single default — replaces any existing default for 'avatar'
FileDefault::set($conn, user: $userCode, setKey: 'avatar', fileId: $file->id);

// Multi default — appends to a gallery list
FileDefault::set($conn, user: $userCode, setKey: 'gallery', fileId: $file->id, multiSet: true);

// Retrieve
$default = FileDefault::get($conn, user: $userCode, setKey: 'avatar');  // FileDefault|false
$gallery = FileDefault::getAll($conn, user: $userCode, setKey: 'gallery'); // FileDefault[]

// Remove one entry
FileDefault::remove($conn, user: $userCode, setKey: 'gallery', fileId: $file->id);

// Clear all defaults for a slot
FileDefault::clearSet($conn, user: $userCode, setKey: 'gallery');
```

---

## Folders

```php
use TimeFrontiers\File\Folder;
use TimeFrontiers\File\FolderFile;

// Create
$folder          = new Folder($conn);
$folder->name    = 'profile-assets';
$folder->title   = 'Profile Assets';
$folder->owner   = $userCode;
$folder->_author = $userCode;
$folder->create();

// List all folders for an owner
$folders = Folder::forOwner($conn, $userCode);

// Add / remove files
FolderFile::add($conn, folderId: $folder->id, fileId: $file->id);
FolderFile::remove($conn, folderId: $folder->id, fileId: $file->id);

// Files in a folder
$pivots = FolderFile::forFolder($conn, $folder->id);

// Delete folder (removes all pivot rows automatically)
$folder->destroy();
```

---

## Storage drivers

| Driver | Status | Notes |
|--------|--------|-------|
| `local` | ✅ Full | Default. Files stored under `drivers.local.upload_path`. |
| `s3` | ✅ Full | Requires `aws/aws-sdk-php`. Standard AWS S3 or S3-compatible endpoint. |
| `minio` | ✅ Full | Requires `aws/aws-sdk-php`. Path-style endpoint enforced. `endpoint` is required. |
| `gcs` | 🔲 Stub | Throws `DriverException` — implementation coming. |
| `onedrive` | 🔲 Stub | Throws `DriverException` — implementation coming. |
| `dropbox` | 🔲 Stub | Throws `DriverException` — implementation coming. |

The driver used to store a file is recorded in `file_meta.storage_driver`. When a file is loaded from the database, the correct driver is automatically resolved for streaming and deletion — regardless of the current `default_driver` setting.

---

## Image constraints

Configured in `base[]`. Applied automatically on every upload before the file reaches storage.

| Key | Effect |
|-----|--------|
| `max_width_px` | Images wider than this are resized down (aspect ratio preserved) |
| `max_height_px` | Images taller than this are resized down |
| `min_width_px` | Images narrower than this are **rejected** |
| `min_height_px` | Images shorter than this are **rejected** |

Set any value to `null` to disable that constraint. Non-image files pass through all checks untouched.

> **Note:** Full image manipulation (crop, rotate, watermark) is planned for `timefrontiers/php-image`.

---

## Ownership

All ownership fields are `VARCHAR(64)` strings — not foreign keys — so they can hold user codes, system constants, or any arbitrary identifier.

| Table | Column | Example values |
|-------|--------|----------------|
| `file_meta` | `owner` | `'08744307265'`, `'SYSTEM'`, `'SYSTEM.HIDDEN'` |
| `file_default` | `user` | `'08744307265'`, `'SYSTEM'` |
| `folders` | `owner` | `'08744307265'`, `'SYSTEM'` |

---

## License

MIT — see [LICENSE](LICENSE).
