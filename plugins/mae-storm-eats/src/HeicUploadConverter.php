<?php

declare(strict_types=1);

namespace MaeStorm\Eats;

final class HeicUploadConverter implements ServiceProviderInterface
{
    public function register(): void
    {
        add_filter('wp_handle_upload', [$this, 'convert'], 10, 2);
    }

    /** @param array<string, mixed> $upload @return array<string, mixed> */
    public function convert(array $upload, string $context): array
    {
        if ($context !== 'upload' || ! in_array($upload['type'] ?? null, ['image/heic', 'image/heif'], true)) {
            return $upload;
        }

        $source = $upload['file'] ?? null;
        $url = $upload['url'] ?? null;
        if (! is_string($source) || ! is_file($source)) {
            return ['error' => __('The HEIC/HEIF upload could not be read.', 'mae-storm-eats')];
        }
        if (! is_string($url) || $url === '') {
            return $this->fail($source);
        }

        $directory = dirname($source);
        $filename = pathinfo($source, PATHINFO_FILENAME) . '.webp';
        $destination = $directory . '/' . wp_unique_filename($directory, $filename);
        $editor = wp_get_image_editor($source);

        if (is_wp_error($editor)) {
            return $this->fail($source);
        }

        $saved = $editor->save($destination, 'image/webp');
        if (is_wp_error($saved)) {
            return $this->fail($source, $destination);
        }

        $converted = $saved['path'] ?? null;
        $dimensions = is_string($converted) && is_file($converted) ? wp_getimagesize($converted) : false;
        if ($converted !== $destination || ($saved['mime-type'] ?? null) !== 'image/webp'
            || ! is_array($dimensions) || ($dimensions['mime'] ?? null) !== 'image/webp'
            || ($dimensions[0] ?? 0) <= 0 || ($dimensions[1] ?? 0) <= 0) {
            return $this->fail($source, $destination);
        }

        if (! wp_delete_file($source) || is_file($source)) {
            wp_delete_file($destination);
            return ['error' => __('The HEIC/HEIF source could not be removed, so the upload was canceled.', 'mae-storm-eats')];
        }

        $upload['file'] = $destination;
        $upload['url'] = trailingslashit(dirname($url)) . wp_basename($destination);
        $upload['type'] = 'image/webp';

        return $upload;
    }

    /** @return array{error:string} */
    private function fail(string $source, ?string $destination = null): array
    {
        if ($destination !== null && is_file($destination)) {
            wp_delete_file($destination);
        }
        wp_delete_file($source);

        return ['error' => __('This HEIC/HEIF image could not be converted to WebP. Please export it as JPEG or WebP and upload it again.', 'mae-storm-eats')];
    }
}
