<?php

namespace KalprajSolutions\LaravelWordpressCda\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WordPressImageService
{
    /**
     * The storage disk for cached images.
     */
    protected string $disk;

    /**
     * Cache key prefix for URL mappings.
     */
    protected string $mappingCachePrefix = 'wp_image_mapping_';

    /**
     * JSON file path for persistent URL mappings.
     */
    protected string $mappingFile = 'blog-media-mappings.json';

    /**
     * Whether to preserve the original WordPress folder structure and filenames.
     */
    protected bool $preserveStructure;

    /**
     * WordPress base URLs to check against.
     */
    protected array $wordpressBaseUrls = [];

    public function __construct()
    {
        $this->disk = config('wordpress.image_disk', 'blog-media');
        $this->preserveStructure = config('wordpress.preserve_structure', true);
        $this->wordpressBaseUrls = $this->getWordPressBaseUrls();
    }

    /**
     * Get WordPress base URLs from configuration.
     *
     * @return array
     */
    protected function getWordPressBaseUrls(): array
    {
        $urls = [];

        // Get from config if available
        $baseUrl = config('wordpress.base_url');
        if (!empty($baseUrl)) {
            $urls[] = rtrim($baseUrl, '/');
        }

        // Get from environment or settings
        $wpUrl = config('wordpress.base_url');
        if (!empty($wpUrl)) {
            $urls[] = rtrim($wpUrl, '/');
        }

        // Add common WordPress media patterns
        $urls[] = 'wp-content/uploads';

        return array_unique($urls);
    }

    /**
     * Check if a URL is a WordPress media URL that should be cached.
     *
     * @param string $url The URL to check
     * @return bool True if the URL is a WordPress media URL
     */
    public function isWordPressMediaUrl(string $url): bool
    {
        // Check for WordPress content upload patterns
        $wpPatterns = [
            '/wp-content\/uploads\//i',
            '/wp-includes\//i',
        ];

        foreach ($wpPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }

        // Check against configured WordPress base URLs
        foreach ($this->wordpressBaseUrls as $baseUrl) {
            if (str_starts_with($url, $baseUrl)) {
                return true;
            }
        }

        // Check if URL contains WordPress media indicators
        if (preg_match('/\/uploads\/\d{4}\/\d{2}\//', $url)) {
            return true;
        }

        return false;
    }

    /**
     * Download an image from WordPress and cache it locally.
     *
     * @param string $imageUrl The original WordPress image URL
     * @return string|null The local path to the cached image, or null on failure
     */
    public function downloadAndCache(string $imageUrl): ?string
    {
        // Check if already cached
        $cachedPath = $this->getCachedImage($imageUrl);
        if ($cachedPath !== null) {
            return $cachedPath;
        }

        try {
            // Download the image
            $response = Http::withHeaders([
                'User-Agent' => config('wordpress.user_agent', 'Laravel-WordPress-CDA/1.0'),
            ])->withOptions([
                'timeout' => 60,
                'connect_timeout' => 10,
            ])->get($imageUrl);

            if (!$response->successful()) {
                Log::warning('Failed to download WordPress image', [
                    'url' => $imageUrl,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $imageData = $response->body();
            $contentType = $response->header('Content-Type');

            // Determine file extension
            $extension = $this->getExtensionFromContentType($contentType) ?? $this->getExtensionFromUrl($imageUrl) ?? 'jpg';

            // Generate path based on configuration
            if ($this->preserveStructure) {
                $path = $this->generateSeoFriendlyPath($imageUrl, $extension);
            } else {
                // Fallback to hashed filename
                $filename = $this->generateFilename($imageUrl, $extension);
                $path = 'images/' . $filename;
            }

            // Store the file
            $disk = Storage::disk($this->disk);

            if (!$disk->put($path, $imageData)) {
                Log::error('Failed to store WordPress image', [
                    'url' => $imageUrl,
                    'path' => $path,
                ]);

                return null;
            }

            // Store the mapping
            $this->storeMapping($imageUrl, $path);

            Log::info(__METHOD__ .'WordPress image cached successfully', [
                'original_url' => $imageUrl,
                'local_path' => $path,
            ]);

            return $path;
        } catch (\Exception $e) {
            Log::error('Exception while downloading WordPress image', [
                'url' => $imageUrl,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the cached image path if it exists.
     *
     * @param string $originalUrl The original WordPress image URL
     * @return string|null The local path to the cached image, or null if not cached
     */
    public function getCachedImage(string $originalUrl): ?string
    {
        $mapping = $this->getMapping($originalUrl);

        if ($mapping === null) {
            return null;
        }

        // Verify the file still exists
        if (!Storage::disk($this->disk)->exists($mapping)) {
            // File was deleted, remove the mapping
            $this->removeMapping($originalUrl);

            return null;
        }

        return $mapping;
    }

    /**
     * Get the public URL for a cached image.
     *
     * @param string $originalUrl The original WordPress image URL
     * @return string|null The public URL to the cached image, or null if not cached
     */
    public function getCachedImageUrl(string $originalUrl): ?string
    {
        $path = $this->getCachedImage($originalUrl);

        if ($path === null) {
            return null;
        }

        return Storage::disk($this->disk)->url($path);
    }

    /**
     * Get or download an image, returning the local URL.
     *
     * @param string $imageUrl The original WordPress image URL
     * @return string|null The local URL to the image, or the original URL if caching fails
     */
    public function getOrDownload(string $imageUrl): ?string
    {
        // First try to get the cached version
        $cachedUrl = $this->getCachedImageUrl($imageUrl);

        if ($cachedUrl !== null) {
            return $cachedUrl;
        }

        // Try to download and cache
        $localPath = $this->downloadAndCache($imageUrl);

        if ($localPath !== null) {
            return Storage::disk($this->disk)->url($localPath);
        }

        // Return the original URL as fallback
        return $imageUrl;
    }

    /**
     * Delete a cached image.
     *
     * @param string $originalUrl The original WordPress image URL
     * @return bool
     */
    public function deleteCachedImage(string $originalUrl): bool
    {
        $mapping = $this->getMapping($originalUrl);

        if ($mapping === null) {
            return false;
        }

        $deleted = Storage::disk($this->disk)->delete($mapping);

        if ($deleted) {
            $this->removeMapping($originalUrl);
        }

        return $deleted;
    }

    /**
     * Clear all cached images.
     *
     * @return void
     */
    public function clearAllCachedImages(): void
    {
        $disk = Storage::disk($this->disk);

        // Get all files recursively (handles both old 'images/' folder and new year/month structure)
        $allFiles = $disk->allFiles('/');

        // Filter out the mappings file itself
        $filesToDelete = array_filter($allFiles, function ($file) {
            return $file !== $this->mappingFile && !str_starts_with($file, '.');
        });

        if (!empty($filesToDelete)) {
            $disk->delete($filesToDelete);
        }

        // Clear all mappings
        $this->clearAllMappings();

        //remove folders as well
        $folders = $disk->allDirectories('/');
        foreach ($folders as $folder) {
            $disk->deleteDirectory($folder);
        }


        Log::info('All WordPress cached images cleared');
    }

    /**
     * Generate a filename for the cached image.
     *
     * @param string $url The original URL
     * @param string $extension The file extension
     * @return string
     */
    protected function generateFilename(string $url, string $extension): string
    {
        $hash = hash('sha256', $url);
        $timestamp = now()->format('Ymd_His');
        $random = Str::random(8);

        return "{$hash}_{$timestamp}_{$random}.{$extension}";
    }

    /**
     * Generate an SEO-friendly path preserving original WordPress structure.
     *
     * Parses WordPress URLs like:
     * - http://localhost:8000/wp-content/uploads/2026/03/image.png
     * - http://localhost:8000/wp-content/uploads/2026/03/image-300x200.png
     *
     * And returns paths like:
     * - 2026/03/image.png
     * - 2026/03/image-300x200.png
     *
     * @param string $url The original WordPress URL
     * @param string $extension The file extension
     * @return string
     */
    protected function generateSeoFriendlyPath(string $url, string $extension): string
    {
        $parsedPath = parse_url($url, PHP_URL_PATH);

        if ($parsedPath === null) {
            // Fallback to hashed filename if URL parsing fails
            return 'images/' . $this->generateFilename($url, $extension);
        }

        // Try to extract year/month/filename from WordPress upload pattern
        // Pattern: /wp-content/uploads/YYYY/MM/filename.ext
        if (preg_match('/\/uploads\/(\d{4})\/(\d{2})\/([^\/]+)$/', $parsedPath, $matches)) {
            $year = $matches[1];
            $month = $matches[2];
            $filename = $matches[3];

            // Ensure the filename has the correct extension
            $filenameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
            $cleanFilename = $filenameWithoutExt . '.' . $extension;

            return "{$year}/{$month}/{$cleanFilename}";
        }

        // If no date structure found, extract just the filename
        $basename = pathinfo($parsedPath, PATHINFO_BASENAME);
        if (!empty($basename) && str_contains($basename, '.')) {
            // Ensure correct extension
            $filenameWithoutExt = pathinfo($basename, PATHINFO_FILENAME);
            $cleanFilename = $filenameWithoutExt . '.' . $extension;

            return $cleanFilename;
        }

        // Final fallback to hashed filename
        return 'images/' . $this->generateFilename($url, $extension);
    }

    /**
     * Get file extension from Content-Type header.
     *
     * @param string|null $contentType
     * @return string|null
     */
    protected function getExtensionFromContentType(?string $contentType): ?string
    {
        if ($contentType === null) {
            return null;
        }

        $mappings = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
        ];

        // Handle content type with charset
        $contentType = strtolower(trim(explode(';', $contentType)[0]));

        return $mappings[$contentType] ?? null;
    }

    /**
     * Get file extension from URL.
     *
     * @param string $url
     * @return string|null
     */
    protected function getExtensionFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if ($path === null) {
            return null;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension ?: null;
    }

    /**
     * Store a URL to local path mapping.
     *
     * @param string $originalUrl
     * @param string $localPath
     * @return void
     */
    protected function storeMapping(string $originalUrl, string $localPath): void
    {
        $cacheKey = $this->getMappingCacheKey($originalUrl);

        // Store in cache
        Cache::forever($cacheKey, $localPath);

        // Also store in persistent file
        $this->updatePersistentMapping($originalUrl, $localPath);
    }

    /**
     * Get the local path from a URL mapping.
     *
     * @param string $originalUrl
     * @return string|null
     */
    protected function getMapping(string $originalUrl): ?string
    {
        $cacheKey = $this->getMappingCacheKey($originalUrl);

        // Try cache first
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        // Fall back to persistent file
        return $this->getPersistentMapping($originalUrl);
    }

    /**
     * Remove a URL mapping.
     *
     * @param string $originalUrl
     * @return void
     */
    protected function removeMapping(string $originalUrl): void
    {
        $cacheKey = $this->getMappingCacheKey($originalUrl);
        Cache::forget($cacheKey);

        $this->removePersistentMapping($originalUrl);
    }

    /**
     * Get the cache key for a URL mapping.
     *
     * @param string $url
     * @return string
     */
    protected function getMappingCacheKey(string $url): string
    {
        return $this->mappingCachePrefix . hash('sha256', $url);
    }

    /**
     * Update the persistent mapping file.
     *
     * @param string $originalUrl
     * @param string $localPath
     * @return void
     */
    protected function updatePersistentMapping(string $originalUrl, string $localPath): void
    {
        try {
            $disk = Storage::disk($this->disk);
            $mappings = [];

            if ($disk->exists($this->mappingFile)) {
                $content = $disk->get($this->mappingFile);
                $mappings = json_decode($content, true) ?? [];
            }

            $mappings[hash('sha256', $originalUrl)] = [
                'original_url' => $originalUrl,
                'local_path' => $localPath,
                'cached_at' => now()->toIso8601String(),
            ];

            $disk->put($this->mappingFile, json_encode($mappings, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            Log::warning('Failed to update persistent image mapping', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get mapping from persistent file.
     *
     * @param string $originalUrl
     * @return string|null
     */
    protected function getPersistentMapping(string $originalUrl): ?string
    {
        try {
            $disk = Storage::disk($this->disk);

            if (!$disk->exists($this->mappingFile)) {
                return null;
            }

            $content = $disk->get($this->mappingFile);
            $mappings = json_decode($content, true) ?? [];
            $hash = hash('sha256', $originalUrl);

            return $mappings[$hash]['local_path'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Remove mapping from persistent file.
     *
     * @param string $originalUrl
     * @return void
     */
    protected function removePersistentMapping(string $originalUrl): void
    {
        try {
            $disk = Storage::disk($this->disk);

            if (!$disk->exists($this->mappingFile)) {
                return;
            }

            $content = $disk->get($this->mappingFile);
            $mappings = json_decode($content, true) ?? [];
            $hash = hash('sha256', $originalUrl);

            unset($mappings[$hash]);

            $disk->put($this->mappingFile, json_encode($mappings, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            // Silently ignore
        }
    }

    /**
     * Clear all persistent mappings.
     *
     * @return void
     */
    protected function clearAllMappings(): void
    {
        try {
            $disk = Storage::disk($this->disk);

            if ($disk->exists($this->mappingFile)) {
                $disk->delete($this->mappingFile);
            }

            // Clear cache mappings with our prefix
            // Note: This requires cache tags or a different approach in production
        } catch (\Exception $e) {
            // Silently ignore
        }
    }

/**
 * Download and cache multiple images in parallel using Laravel's HTTP pool.
 *
 * @param array $urls Array of image URLs to download
 * @return array Array of URL to local path mappings for all URLs (both cached and newly downloaded)
 */
public function downloadAndCacheMultiple(array $urls): array
{
    $results = [];
    $uncachedUrls = [];

    // First pass: check which URLs are already cached
    foreach ($urls as $url) {
        $cachedPath = $this->getCachedImage($url);
        if ($cachedPath !== null) {
            $results[$url] = $cachedPath;
        } else {
            $uncachedUrls[] = $url;
        }
    }

    // If all URLs are cached, return early
    if (empty($uncachedUrls)) {
        return $results;
    }

    // Use Http::pool to execute requests in parallel
    $userAgent = config('wordpress.user_agent', 'Laravel-WordPress-CDA/1.0');
    $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($uncachedUrls, $userAgent) {
        foreach ($uncachedUrls as $url) {
            $pool->withHeaders([
                'User-Agent' => $userAgent,
            ])->withOptions([
                'timeout' => 60,
                'connect_timeout' => 10,
            ])->get($url);
        }
    });

    // Process each response (responses are returned in the same order as requests)
    $newMappings = [];

    foreach ($responses as $index => $response) {
        $url = $uncachedUrls[$index] ?? null;

        // Skip if URL not found (shouldn't happen, but safety check)
        if (!$url) {
            continue;
        }

        // Skip null responses (failed requests)
        if ($response === null) {
            Log::warning('Failed to download WordPress image in batch - null response', [
                'url' => $url,
            ]);
            continue;
        }

        // Check if the request was successful
        if (!$response->successful()) {
            Log::warning('Failed to download WordPress image in batch', [
                'url' => $url,
                'status' => $response->status(),
            ]);
            continue;
        }

        try {
            $imageData = $response->body();
            $contentType = $response->header('Content-Type');

            // Determine file extension using existing methods
            $extension = $this->getExtensionFromContentType($contentType) 
                ?? $this->getExtensionFromUrl($url) 
                ?? 'jpg';

            // Generate path based on configuration
            if ($this->preserveStructure) {
                $path = $this->generateSeoFriendlyPath($url, $extension);
            } else {
                // Fallback to hashed filename
                $filename = $this->generateFilename($url, $extension);
                $path = 'images/' . $filename;
            }

            // Store the file using the configured disk
            $disk = Storage::disk($this->disk);

            if (!$disk->put($path, $imageData)) {
                Log::error('Failed to store WordPress image in batch', [
                    'url' => $url,
                    'path' => $path,
                ]);
                continue;
            }

            // Add to results and new mappings
            $results[$url] = $path;
            $newMappings[$url] = $path;

            Log::info(__METHOD__ . ' - WordPress image cached successfully via batch', [
                'original_url' => $url,
                'local_path' => $path,
            ]);
        } catch (\Exception $e) {
            Log::error('Exception while processing WordPress image in batch', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);
            continue;
        }
    }

    // Update all mappings (both cache and JSON file) after all downloads complete
    foreach ($newMappings as $url => $path) {
        $this->storeMapping($url, $path);
    }

    return $results;
}

}
