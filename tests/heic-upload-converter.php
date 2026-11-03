<?php

declare(strict_types=1);

use MaeStorm\Eats\HeicUploadConverter;

final class WP_Error
{
}

final class FakeImageEditor
{
    public function save(string $path, string $mime): array|WP_Error
    {
        if ($GLOBALS['saveFails']) {
            return new WP_Error();
        }

        $image = imagecreatetruecolor(4, 3);
        imagewebp($image, $path);

        return ['path' => $path, 'mime-type' => $mime];
    }
}

function add_filter(string $hook, callable $callback, int $priority, int $arguments): void
{
    $GLOBALS['registeredFilter'] = [$hook, $callback, $priority, $arguments];
}

function wp_get_image_editor(string $path): FakeImageEditor|WP_Error
{
    unset($path);
    return $GLOBALS['loadFails'] ? new WP_Error() : new FakeImageEditor();
}

function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

function wp_unique_filename(string $directory, string $filename): string
{
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $candidate = $filename;
    for ($number = 1; is_file($directory . '/' . $candidate); ++$number) {
        $candidate = $base . '-' . $number . '.' . $extension;
    }

    return $candidate;
}

function wp_getimagesize(string $path): array|false
{
    return getimagesize($path);
}

function wp_delete_file(string $path): bool
{
    if ($GLOBALS['deleteFails'] && str_ends_with($path, '.heic')) {
        return false;
    }

    return unlink($path);
}

function trailingslashit(string $path): string
{
    return rtrim($path, '/') . '/';
}

function wp_basename(string $path): string
{
    return basename($path);
}

function __(string $message, string $domain): string
{
    unset($domain);
    return $message;
}

function expect(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

require_once __DIR__ . '/../plugins/mae-storm-eats/src/ServiceProviderInterface.php';
require_once __DIR__ . '/../plugins/mae-storm-eats/src/HeicUploadConverter.php';

$directory = sys_get_temp_dir() . '/mae-heic-upload-' . bin2hex(random_bytes(6));
mkdir($directory);
$GLOBALS['loadFails'] = false;
$GLOBALS['saveFails'] = false;
$GLOBALS['deleteFails'] = false;

try {
    $converter = new HeicUploadConverter();
    $converter->register();
    expect($GLOBALS['registeredFilter'][0] === 'wp_handle_upload' && $GLOBALS['registeredFilter'][3] === 2, 'Upload filter is registered with the context argument.');

    $source = $directory . '/photo.heic';
    file_put_contents($source, 'heic source');
    file_put_contents($directory . '/photo.webp', 'existing attachment');
    $upload = ['file' => $source, 'url' => 'https://example.test/uploads/photo.heic', 'type' => 'image/heic'];
    $result = $converter->convert($upload, 'upload');
    expect($result['file'] === $directory . '/photo-1.webp', 'Existing WebP name is preserved.');
    expect($result['url'] === 'https://example.test/uploads/photo-1.webp', 'Attachment URL points to WebP.');
    expect($result['type'] === 'image/webp', 'Attachment MIME type is WebP.');
    expect(! is_file($source) && getimagesize($result['file'])['mime'] === 'image/webp', 'Only a valid WebP is retained.');
    expect(file_get_contents($directory . '/photo.webp') === 'existing attachment', 'Existing attachment is unchanged.');

    $source = $directory . '/another.heif';
    file_put_contents($source, 'heif source');
    $heif = $converter->convert(['file' => $source, 'url' => 'https://example.test/uploads/another.heif', 'type' => 'image/heif'], 'upload');
    expect($heif['type'] === 'image/webp' && ! is_file($source), 'HEIF uploads use the same conversion path.');

    $source = $directory . '/failed.heic';
    file_put_contents($source, 'heic source');
    $GLOBALS['saveFails'] = true;
    $failed = $converter->convert(['file' => $source, 'url' => 'https://example.test/uploads/failed.heic', 'type' => 'image/heic'], 'upload');
    expect(isset($failed['error']) && ! is_file($source), 'Failed conversion returns an error and removes its source.');
    $GLOBALS['saveFails'] = false;

    $source = $directory . '/blocked.heic';
    file_put_contents($source, 'heic source');
    $GLOBALS['deleteFails'] = true;
    $blocked = $converter->convert(['file' => $source, 'url' => 'https://example.test/uploads/blocked.heic', 'type' => 'image/heic'], 'upload');
    expect(isset($blocked['error']) && ! is_file($directory . '/blocked.webp'), 'Source removal failure cancels the WebP attachment.');
    $GLOBALS['deleteFails'] = false;

    $source = $directory . '/ordinary.jpg';
    file_put_contents($source, 'jpeg source');
    $ordinary = ['file' => $source, 'url' => 'https://example.test/uploads/ordinary.jpg', 'type' => 'image/jpeg'];
    expect($converter->convert($ordinary, 'upload') === $ordinary, 'Ordinary image uploads pass through.');
    expect($converter->convert($upload, 'sideload') === $upload, 'Sideloads pass through.');
} finally {
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}

echo "HEIC upload converter checks passed.\n";
