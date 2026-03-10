<?php

namespace KalprajSolutions\LaravelWordpressCda\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ContentImageProcessor
{
    /**
     * Cache prefix for processed content.
     */
    protected string $contentCachePrefix = 'wp_processed_content_';

    /**
     * Cache TTL for processed content (in seconds).
     * Default: 7 days
     */
    protected int $cacheTtl = 604800;

    public function __construct(
        private WordPressImageService $imageService
    ) {}

    /**
     * Process HTML content, find all images, download them, and replace URLs.
     *
     * @param string $html The HTML content to process
     * @param string|null $cacheKey Optional cache key for the processed content
     * @return string The processed HTML with local image URLs
     */
    public function process(string $html, ?string $cacheKey = null): string
    {
        // Return early if no HTML content
        if (empty($html)) {
            return $html;
        }

        // Check cache if cache key provided
        if ($cacheKey !== null) {
            $cached = $this->getCachedContent($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Extract all image URLs from the HTML
        $imageUrls = $this->extractImageUrls($html);

        if (empty($imageUrls)) {
            return $html;
        }

        // Step 1: Filter to get unique WordPress media URLs
        $wpMediaUrls = array_filter($imageUrls, function ($url) {
            return $this->imageService->isWordPressMediaUrl($url);
        });

        $wpMediaUrls = array_unique($wpMediaUrls);

        if (empty($wpMediaUrls)) {
            return $html;
        }

        // Step 2: Separate cached URLs from URLs that need downloading
        $cachedMappings = [];
        $urlsToDownload = [];

        foreach ($wpMediaUrls as $url) {
            $cachedPath = $this->imageService->getCachedImage($url);
            if ($cachedPath !== null) {
                $cachedMappings[$url] = $cachedPath;
            } else {
                $urlsToDownload[] = $url;
            }
        }

        // Step 3: If there are URLs that need downloading, use parallel bulk download
        $urlMappings = $cachedMappings;

        if (!empty($urlsToDownload)) {
            $downloadedMappings = $this->imageService->downloadAndCacheMultiple($urlsToDownload);

            // Step 4: Combine the results from cached URLs and newly downloaded URLs
            $urlMappings = array_merge($cachedMappings, $downloadedMappings);
        } else {
            // All URLs were already cached
            $urlMappings = $cachedMappings;
        }

        // Convert local paths to URLs for replacement
        $urlMappingsWithUrls = [];
        $diskName = config('wordpress.image_disk', 'blog-media');
        foreach ($urlMappings as $originalUrl => $localPath) {
            $urlMappingsWithUrls[$originalUrl] = Storage::disk($diskName)->url($localPath);
        }

        // Replace URLs in HTML
        $processedHtml = $this->replaceUrlsInHtml($html, $urlMappingsWithUrls);

        // Cache the processed content if cache key provided
        if ($cacheKey !== null) {
            $this->cacheContent($cacheKey, $processedHtml);
        }

        return $processedHtml;
    }

    /**
     * Extract all image URLs from HTML content.
     *
     * @param string $html The HTML content
     * @return array Array of unique image URLs found in content
     */
    public function extractImageUrls(string $html): array
    {
        $urls = [];

        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        // Wrap in div to ensure proper parsing of HTML fragments
        $wrappedHtml = '<div>' . $html . '</div>';
        // $dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $wrappedHtml,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $dom->encoding = 'UTF-8';

        // Clear any errors
        libxml_clear_errors();

        // Find all img tags
        $images = $dom->getElementsByTagName('img');

        foreach ($images as $img) {
            // Get src attribute
            $src = $img->getAttribute('src');
            if (!empty($src)) {
                $urls[] = $src;
            }

            // Get srcset attribute and parse URLs from it
            $srcset = $img->getAttribute('srcset');
            if (!empty($srcset)) {
                $srcsetUrls = $this->parseSrcset($srcset);
                $urls = array_merge($urls, $srcsetUrls);
            }
        }

        // Return unique URLs
        return array_unique($urls);
    }

    /**
     * Parse srcset attribute to extract image URLs.
     *
     * @param string $srcset The srcset attribute value
     * @return array Array of image URLs
     */
    protected function parseSrcset(string $srcset): array
    {
        $urls = [];

        // Split by comma to get individual entries
        $entries = explode(',', $srcset);

        foreach ($entries as $entry) {
            // Trim whitespace
            $entry = trim($entry);

            // Split by space to separate URL from descriptor
            $parts = preg_split('/\s+/', $entry, 2);

            if (!empty($parts[0])) {
                $urls[] = $parts[0];
            }
        }

        return $urls;
    }

    /**
     * Replace URLs in HTML with their cached counterparts.
     *
     * @param string $html The original HTML
     * @param array $urlMappings Array mapping original URLs to cached URLs
     * @return string The HTML with replaced URLs
     */
    protected function replaceUrlsInHtml(string $html, array $urlMappings): string
    {
        if (empty($urlMappings)) {
            return $html;
        }

        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        // Wrap in div to ensure proper parsing
        $wrappedHtml = '<div>' . $html . '</div>';
        // $dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $wrappedHtml,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $dom->encoding = 'UTF-8';

        // Clear any errors
        libxml_clear_errors();

        // Find all img tags
        $images = $dom->getElementsByTagName('img');

        foreach ($images as $img) {
            // Replace src attribute
            $src = $img->getAttribute('src');
            if (!empty($src) && isset($urlMappings[$src])) {
                $img->setAttribute('src', $urlMappings[$src]);
            }

            // Replace srcset attribute
            $srcset = $img->getAttribute('srcset');
            if (!empty($srcset)) {
                $newSrcset = $this->replaceUrlsInSrcset($srcset, $urlMappings);
                if ($newSrcset !== $srcset) {
                    $img->setAttribute('srcset', $newSrcset);
                }
            }
        }

        // Extract the inner HTML of the wrapper div
        $result = '';
        $root = $dom->documentElement;
        if ($root && $root->childNodes) {
            foreach ($root->childNodes as $node) {
                $result .= $dom->saveHTML($node);
            }
        }

        return $result;
    }

    /**
     * Replace URLs in srcset attribute.
     *
     * @param string $srcset The original srcset value
     * @param array $urlMappings Array mapping original URLs to cached URLs
     * @return string The updated srcset value
     */
    protected function replaceUrlsInSrcset(string $srcset, array $urlMappings): string
    {
        $entries = explode(',', $srcset);
        $newEntries = [];

        foreach ($entries as $entry) {
            $entry = trim($entry);
            $parts = preg_split('/\s+/', $entry, 2);

            if (!empty($parts[0]) && isset($urlMappings[$parts[0]])) {
                $newUrl = $urlMappings[$parts[0]];
                $descriptor = $parts[1] ?? '';
                $newEntries[] = $descriptor ? "{$newUrl} {$descriptor}" : $newUrl;
            } else {
                $newEntries[] = $entry;
            }
        }

        return implode(', ', $newEntries);
    }

    /**
     * Get cached processed content.
     *
     * @param string $cacheKey
     * @return string|null
     */
    protected function getCachedContent(string $cacheKey): ?string
    {
        return Cache::get($this->contentCachePrefix . $cacheKey);
    }

    /**
     * Cache processed content.
     *
     * @param string $cacheKey
     * @param string $content
     * @return void
     */
    protected function cacheContent(string $cacheKey, string $content): void
    {
        Cache::put($this->contentCachePrefix . $cacheKey, $content, $this->cacheTtl);
    }

    /**
     * Clear cached processed content.
     *
     * @param string $cacheKey
     * @return void
     */
    public function clearCachedContent(string $cacheKey): void
    {
        Cache::forget($this->contentCachePrefix . $cacheKey);
    }

    /**
     * Clear all cached processed content.
     *
     * @return void
     */
    public function clearAllCachedContent(): void
    {
        // Note: This requires cache tags or a prefix-based deletion strategy
        // For now, we just log that this was called
        Log::info('Clear all cached processed content requested');
    }
}
