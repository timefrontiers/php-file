<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Concerns;

/**
 * MIME type maps and file-type helper methods.
 *
 * All maps are static so they can be used without an instance.
 * The trait uses protected static arrays rather than class constants
 * so that consuming classes can extend/override maps if needed.
 */
trait FileData
{
  // -------------------------------------------------------------------------
  // Maps
  // -------------------------------------------------------------------------

  /** Extension → MIME type */
  protected static array $MIME_TYPES = [
    // text / scripts
    'txt'  => 'text/plain',
    'htm'  => 'text/html',
    'html' => 'text/html',
    'css'  => 'text/css',
    'js'   => 'application/javascript',
    'json' => 'application/json',
    'xml'  => 'application/xml',
    'csv'  => 'text/csv',

    // images
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'jpe'  => 'image/jpeg',
    'gif'  => 'image/gif',
    'bmp'  => 'image/bmp',
    'ico'  => 'image/vnd.microsoft.icon',
    'tiff' => 'image/tiff',
    'tif'  => 'image/tiff',
    'svg'  => 'image/svg+xml',
    'svgz' => 'image/svg+xml',
    'webp' => 'image/webp',
    'avif' => 'image/avif',

    // audio
    'mp3'  => 'audio/mpeg',
    'ogg'  => 'audio/ogg',
    'wav'  => 'audio/wav',
    'aac'  => 'audio/aac',

    // video
    'mp4'  => 'video/mp4',
    'mov'  => 'video/quicktime',
    'qt'   => 'video/quicktime',
    'avi'  => 'video/x-msvideo',
    'webm' => 'video/webm',
    'flv'  => 'video/x-flv',
    'swf'  => 'application/x-shockwave-flash',

    // archives
    'zip'  => 'application/zip',
    'rar'  => 'application/x-rar-compressed',
    '7z'   => 'application/x-7z-compressed',
    'tar'  => 'application/x-tar',
    'gz'   => 'application/gzip',

    // documents
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'rtf'  => 'application/rtf',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'odt'  => 'application/vnd.oasis.opendocument.text',
    'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',

    // adobe / graphic
    'psd'  => 'image/vnd.adobe.photoshop',
    'ai'   => 'application/postscript',
    'eps'  => 'application/postscript',
    'ps'   => 'application/postscript',

    // fonts
    'ttf'  => 'font/ttf',
    'otf'  => 'font/otf',
    'woff' => 'font/woff',
    'woff2'=> 'font/woff2',
    'eot'  => 'application/vnd.ms-fontobject',
  ];

  /** MIME type → type-group label */
  protected static array $MIME_GROUPS = [
    'text/plain'                    => 'text',
    'text/html'                     => 'script',
    'text/css'                      => 'script',
    'application/javascript'        => 'script',
    'application/json'              => 'script',
    'application/xml'               => 'script',
    'text/csv'                      => 'text',

    'image/png'                     => 'image',
    'image/jpeg'                    => 'image',
    'image/gif'                     => 'image',
    'image/bmp'                     => 'image',
    'image/vnd.microsoft.icon'      => 'image',
    'image/tiff'                    => 'image',
    'image/svg+xml'                 => 'image',
    'image/webp'                    => 'image',
    'image/avif'                    => 'image',

    'audio/mpeg'                    => 'audio',
    'audio/ogg'                     => 'audio',
    'audio/wav'                     => 'audio',
    'audio/aac'                     => 'audio',

    'video/mp4'                     => 'video',
    'video/quicktime'               => 'video',
    'video/x-msvideo'               => 'video',
    'video/webm'                    => 'video',
    'video/x-flv'                   => 'flash-video',
    'application/x-shockwave-flash' => 'flash-video',

    'application/zip'               => 'archive',
    'application/x-rar-compressed'  => 'archive',
    'application/x-7z-compressed'   => 'archive',
    'application/x-tar'             => 'archive',
    'application/gzip'              => 'archive',

    'application/pdf'               => 'document',
    'application/msword'            => 'document',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'document',
    'application/rtf'               => 'document',
    'application/vnd.ms-excel'      => 'document',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'document',
    'application/vnd.ms-powerpoint' => 'document',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'document',
    'application/vnd.oasis.opendocument.text'        => 'document',
    'application/vnd.oasis.opendocument.spreadsheet' => 'document',

    'image/vnd.adobe.photoshop'     => 'graphic',
    'application/postscript'        => 'graphic',

    'font/ttf'                      => 'font',
    'font/otf'                      => 'font',
    'font/woff'                     => 'font',
    'font/woff2'                    => 'font',
    'application/vnd.ms-fontobject' => 'font',
  ];

  /** MIME type → preferred extension */
  protected static array $MIME_EXT = [
    'text/plain'                    => 'txt',
    'text/html'                     => 'html',
    'text/css'                      => 'css',
    'application/javascript'        => 'js',
    'application/json'              => 'json',
    'application/xml'               => 'xml',
    'text/csv'                      => 'csv',
    'image/png'                     => 'png',
    'image/jpeg'                    => 'jpg',
    'image/gif'                     => 'gif',
    'image/bmp'                     => 'bmp',
    'image/vnd.microsoft.icon'      => 'ico',
    'image/tiff'                    => 'tiff',
    'image/svg+xml'                 => 'svg',
    'image/webp'                    => 'webp',
    'image/avif'                    => 'avif',
    'audio/mpeg'                    => 'mp3',
    'audio/ogg'                     => 'ogg',
    'audio/wav'                     => 'wav',
    'audio/aac'                     => 'aac',
    'video/mp4'                     => 'mp4',
    'video/quicktime'               => 'mov',
    'video/x-msvideo'               => 'avi',
    'video/webm'                    => 'webm',
    'video/x-flv'                   => 'flv',
    'application/zip'               => 'zip',
    'application/x-rar-compressed'  => 'rar',
    'application/x-7z-compressed'   => '7z',
    'application/x-tar'             => 'tar',
    'application/gzip'              => 'gz',
    'application/pdf'               => 'pdf',
    'application/msword'            => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'  => 'docx',
    'application/rtf'               => 'rtf',
    'application/vnd.ms-excel'      => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'        => 'xlsx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation'=> 'pptx',
    'application/vnd.oasis.opendocument.text'        => 'odt',
    'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
    'image/vnd.adobe.photoshop'     => 'psd',
    'application/postscript'        => 'ai',
    'font/ttf'                      => 'ttf',
    'font/otf'                      => 'otf',
    'font/woff'                     => 'woff',
    'font/woff2'                    => 'woff2',
    'application/vnd.ms-fontobject' => 'eot',
  ];

  // -------------------------------------------------------------------------
  // Static lookups
  // -------------------------------------------------------------------------

  public static function mimeForExtension(string $ext): ?string
  {
    return static::$MIME_TYPES[strtolower($ext)] ?? null;
  }

  public static function groupForMime(string $mime): ?string
  {
    return static::$MIME_GROUPS[$mime] ?? null;
  }

  public static function extensionForMime(string $mime): ?string
  {
    return static::$MIME_EXT[$mime] ?? null;
  }

  // -------------------------------------------------------------------------
  // Instance helpers (require $_type and type_group to be set)
  // -------------------------------------------------------------------------

  public function mimeType(): string
  {
    return $this->_type ?? 'application/octet-stream';
  }

  public function typeGroup(): ?string
  {
    return $this->type_group ?? null;
  }

  public function isImage(): bool
  {
    return ($this->type_group ?? null) === 'image';
  }

  public function isVideo(): bool
  {
    return ($this->type_group ?? null) === 'video';
  }

  public function isAudio(): bool
  {
    return ($this->type_group ?? null) === 'audio';
  }

  public function isDocument(): bool
  {
    return ($this->type_group ?? null) === 'document';
  }

  public function isArchive(): bool
  {
    return ($this->type_group ?? null) === 'archive';
  }

  public function isFont(): bool
  {
    return ($this->type_group ?? null) === 'font';
  }
}
