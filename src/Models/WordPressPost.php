<?php

namespace KalprajSolutions\LaravelWordpressCda\Models;

use KalprajSolutions\LaravelWordpressCda\Services\ContentImageProcessor;
use KalprajSolutions\LaravelWordpressCda\Services\WordPressImageService;
use ArrayAccess;
use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\ImageManager;
use JsonSerializable;

/**
 * Wrapper class for WordPress post data.
 *
 * This class provides a wrapper around WordPress API post data,
 * compatible with the existing Canvas Post model interface used by blog views.
 * It is NOT an Eloquent model - it simply wraps API response data.
 */
class WordPressPost implements ArrayAccess, Arrayable, Jsonable, JsonSerializable
{
    /**
     * The post ID.
     */
    public int $id;

    /**
     * The post title.
     */
    public string $title;

    /**
     * The post summary/excerpt.
     */
    public ?string $summary;

    /**
     * The post slug.
     */
    public string $slug;

    /**
     * The published date as a Carbon instance.
     */
    public Carbon $published_at;

    /**
     * The featured image URL.
     */
    public ?string $featured_image;

    /**
     * The post status.
     */
    public string $status = 'publish';

    /**
     * The post format.
     */
    public string $format = 'standard';

    /**
     * Whether comments are open.
     */
    public bool $comments_open = true;

    /**
     * The original API response data.
     */
    protected array $originalData = [];

    /**
     * The embedded API response data for lazy loading relationships.
     */
    private array $embeddedData = [];

    /**
     * Meta data array for SEO and other purposes.
     */
    protected array $metaData = [];

    /**
     * The author/user data.
     */
    protected ?WordPressUser $userData = null;

    /**
     * The categories collection.
     *
     * @var Collection<WordPressCategory>
     */
    protected ?Collection $categoriesData = null;

    /**
     * The tags collection.
     *
     * @var Collection<WordPressTag>
     */
    protected ?Collection $tagsData = null;

    /**
     * Cached image paths for different sizes.
     */
    protected array $cachedImagePaths = [];

    /**
     * The raw post body/content (before image processing).
     */
    protected string $rawBody = '';

    /**
     * The processed post body with local image URLs.
     */
    protected ?string $processedBody = null;

    /**
     * Whether to process content images (default: true).
     */
    protected bool $processContentImages = true;

    /**
     * Create a new WordPressPost instance from API response data.
     *
     * @param array $data The post data from WordPress API
     * @return static
     */
    public static function fromApiResponse(array $data): self
    {
        $post = new self();
        $post->originalData = $data;

        // Store the entire embedded data for later lazy loading
        $post->embeddedData = $data['_embedded'] ?? [];

        // Basic post data
        $post->id = $data['id'] ?? 0;
        $post->title = html_entity_decode(
            strip_tags($data['title']['rendered'] ?? $data['title'] ?? ''),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $post->rawBody = html_entity_decode(
            $data['content']['rendered'] ?? $data['content'] ?? '',
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $post->slug = $data['slug'] ?? '';
        $post->status = $data['status'] ?? 'publish';
        $post->format = $data['format'] ?? 'standard';

        // Summary/excerpt
        $excerpt = $data['excerpt']['rendered'] ?? $data['excerpt'] ?? '';
        $post->summary = $excerpt ? html_entity_decode(
            strip_tags($excerpt),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ) : null;

        // Published date - parse as Carbon
        $dateString = $data['date'] ?? null;
        $post->published_at = $dateString ? Carbon::parse($dateString) : now();

        // Comments status
        $post->comments_open = ($data['comment_status'] ?? 'open') === 'open';

        // Featured image from _embedded
        $post->featured_image = $post->extractFeaturedImageUrl($data);

        // Parse author data if available
        if (isset($data['_embedded']['author'][0])) {
            $post->userData = WordPressUser::fromApiResponse($data['_embedded']['author'][0]);
        }

        // Parse categories from _embedded
        if (isset($data['_embedded']['wp:term'][0])) {
            $post->categoriesData = collect($data['_embedded']['wp:term'][0])
                ->filter(fn ($term) => ($term['taxonomy'] ?? '') === 'category')
                ->map(fn ($term) => WordPressCategory::fromApiResponse($term));
        } else {
            $post->categoriesData = collect();
        }

        // Parse tags from _embedded
        if (isset($data['_embedded']['wp:term'][1])) {
            $post->tagsData = collect($data['_embedded']['wp:term'][1])
                ->filter(fn ($term) => ($term['taxonomy'] ?? '') === 'post_tag')
                ->map(fn ($term) => WordPressTag::fromApiResponse($term));
        } else {
            $post->tagsData = collect();
        }

        // Parse Yoast SEO meta if available
        $post->metaData = $post->extractMetaData($data);

        return $post;
    }

    /**
     * Extract the featured image URL from API response.
     *
     * @param array $data
     * @return string|null
     */
    protected function extractFeaturedImageUrl(array $data): ?string
    {
        // Try embedded featured media first
        if (isset($data['_embedded']['wp:featuredmedia'][0])) {
            $media = $data['_embedded']['wp:featuredmedia'][0];

            // Return the full size source URL
            return $media['source_url'] ?? null;
        }

        // Fallback to featured_media ID (would require additional API call)
        if (!empty($data['featured_media'])) {
            // In a real scenario, you might want to fetch this asynchronously
            // or the API service should have already embedded it
        }

        return null;
    }

    /**
     * Extract meta data from API response (Yoast SEO, etc.).
     *
     * @param array $data
     * @return array
     */
    protected function extractMetaData(array $data): array
    {
        $meta = [];

        // Check for Yoast SEO data in various formats
        if (isset($data['yoast_head_json'])) {
            $yoast = $data['yoast_head_json'];
            $meta['title'] = $yoast['title'] ?? null;
            $meta['description'] = $yoast['description'] ?? null;
            $meta['canonical_link'] = $yoast['canonical'] ?? null;
            $meta['og_title'] = $yoast['og_title'] ?? null;
            $meta['og_description'] = $yoast['og_description'] ?? null;
            $meta['og_image'] = $yoast['og_image'][0]['url'] ?? null;
            $meta['twitter_title'] = $yoast['twitter_title'] ?? null;
            $meta['twitter_description'] = $yoast['twitter_description'] ?? null;
            $meta['twitter_image'] = $yoast['twitter_image'] ?? null;
        }

        // Alternative: Check for meta in different format
        if (isset($data['meta'])) {
            $meta = array_merge($meta, $data['meta']);
        }

        // Set defaults if not present
        if (empty($meta['title'])) {
            $meta['title'] = $this->title;
        }
        if (empty($meta['description'])) {
            $meta['description'] = $this->summary ? strip_tags($this->summary) : substr(strip_tags($this->rawBody), 0, 160);
        }

        return $meta;
    }

    /**
     * Get the post author/user.
     *
     * @return WordPressUser|null
     */
    public function user(): ?WordPressUser
    {
        // If user is already set, return it
        if ($this->userData !== null) {
            return $this->userData;
        }

        // Try to get user from embedded data (lazy loading)
        if (isset($this->embeddedData['author'][0])) {
            $this->userData = WordPressUser::fromApiResponse($this->embeddedData['author'][0]);
            return $this->userData;
        }

        return null;
    }

    /**
     * Get the categories relationship.
     *
     * @return Collection<WordPressCategory>
     */
    public function categories(): Collection
    {
        return $this->categoriesData ?? collect();
    }

    /**
     * Get the primary category (topic).
     * This provides compatibility with existing views that use $post->topic.
     *
     * @return WordPressCategory|null
     */
    public function topic(): ?WordPressCategory
    {
        return $this->categories()->first();
    }

    /**
     * Get the tags relationship.
     *
     * @return Collection<WordPressTag>
     */
    public function tags(): Collection
    {
        return $this->tagsData ?? collect();
    }

    /**
     * Get comments for this post.
     * Since this is a wrapper class, comments are handled separately.
     *
     * @return Collection
     */
    public function comments(): Collection
    {
        // In a real implementation, you might fetch comments from WordPress
        // or use a comment service. For now, return an empty collection.
        // If you need actual comments, you could:
        // 1. Fetch from WordPress API (_embedded['replies'])
        // 2. Use a separate comment system
        // 3. Return comments stored locally if synced

        if (isset($this->originalData['_embedded']['replies'])) {
            return collect($this->originalData['_embedded']['replies']);
        }

        return collect();
    }

    /**
     * Check if the post has comments.
     *
     * @return bool
     */
    public function hasComments(): bool
    {
        return $this->comments()->isNotEmpty();
    }

    /**
     * Get the comment count.
     *
     * @return int
     */
    public function commentCount(): int
    {
        return (int) ($this->originalData['comment_count'] ?? $this->comments()->count());
    }

    /**
     * Get and format the featured image using Intervention Image v3.
     *
     * @param string $size The size identifier (thumbnail, half, full, original)
     * @return string|null
     */
    public function getFormattedImage(string $size = 'original'): ?string
    {
        if (empty($this->featured_image)) {
            return asset('images/blog-default.jpg'); // Fallback image
        }

        // If already cached locally, return as-is
        if (str_starts_with($this->featured_image, '/blog-media/')) {
            return $this->featured_image;
        }

        // Download and cache if not already local
        $imageService = app(WordPressImageService::class);
        $localUrl = $imageService->getOrDownload($this->featured_image);

        if ($localUrl) {
            $this->featured_image = $localUrl;
            return $localUrl;
        }

        // Fallback to original if caching fails
        return $this->featured_image;
    }

    /**
     * Resize an image and cache it locally.
     *
     * @param string $imageUrl The original image URL
     * @param int|null $width The target width
     * @param int|null $height The target height
     * @param string $size The size identifier
     * @return string|null
     */
    protected function resizeImage(string $imageUrl, ?int $width, ?int $height, string $size): ?string
    {
        $disk = Storage::disk('wordpress');
        $filename = $this->generateImageFilename($imageUrl, $size);
        $resizedPath = "images/{$filename}";

        // Check if already cached
        if ($disk->exists($resizedPath)) {
            return $disk->url($resizedPath);
        }

        // Download the original image if not already local
        $originalPath = $this->downloadImage($imageUrl);
        if (!$originalPath) {
            return $imageUrl;
        }

        try {
            // Create image manager with Imagick driver (v3 syntax)
            $manager = new ImageManager(new Driver());
            $image = $manager->read($disk->path($originalPath));

            // Scale the image
            if ($width && $height) {
                $image->scale($width, $height);
            } elseif ($width) {
                $image->scale(width: $width);
            } elseif ($height) {
                $image->scale(height: $height);
            }

            // Save the resized image
            $encoded = $image->encode();
            $disk->put($resizedPath, $encoded);

            return $disk->url($resizedPath);
        } catch (\Throwable $th) {
            Log::error('Image resize failed: ' . $th->getMessage());

            return $imageUrl;
        }
    }

    /**
     * Download an image from URL to local storage.
     *
     * @param string $imageUrl
     * @return string|null The local path
     */
    protected function downloadImage(string $imageUrl): ?string
    {
        $disk = Storage::disk('wordpress');
        $filename = 'original_' . hash('sha256', $imageUrl) . '.' . pathinfo($imageUrl, PATHINFO_EXTENSION);
        if (empty(pathinfo($imageUrl, PATHINFO_EXTENSION))) {
            $filename .= '.jpg';
        }

        $localPath = "images/{$filename}";

        // Return if already exists
        if ($disk->exists($localPath)) {
            return $localPath;
        }

        try {
            $userAgent = config('wordpress.user_agent', 'Laravel-WordPress-CDA/1.0');
            $response = Http::withHeaders([
                'User-Agent' => $userAgent,
            ])
            ->timeout(60)
            ->withoutVerifying()
            ->get($imageUrl);

            if (!$response->successful()) {
                return null;
            }

            $imageData = $response->body();
            $disk->put($localPath, $imageData);

            return $localPath;
        } catch (\Throwable $th) {
            Log::warning('Failed to download image: ' . $th->getMessage());

            return null;
        }
    }

    /**
     * Generate a filename for resized images.
     *
     * @param string $imageUrl
     * @param string $size
     * @return string
     */
    protected function generateImageFilename(string $imageUrl, string $size): string
    {
        $hash = hash('sha256', $imageUrl);
        $extension = pathinfo($imageUrl, PATHINFO_EXTENSION) ?: 'jpg';

        return "{$size}_{$hash}.{$extension}";
    }

    /**
     * Get the post URL.
     *
     * @return string
     */
    public function getUrl(): string
    {
        return route(config('wordpress.blog_view_route'), ['slug' => $this->slug]);
    }

    /**
     * Get the post's published date in a human-readable format.
     *
     * @return string
     */
    public function getPublishedDate(): string
    {
        return $this->published_at->format('F j, Y');
    }

    /**
     * Get the post's published date in a relative format.
     *
     * @return string
     */
    public function getPublishedDateDiff(): string
    {
        return $this->published_at->diffForHumans();
    }

    /**
     * Get the estimated reading time.
     *
     * @param int $wordsPerMinute
     * @return int
     */
    public function getReadingTime(int $wordsPerMinute = 200): int
    {
        $wordCount = str_word_count(strip_tags($this->getBodyAttribute()));

        return max(1, ceil($wordCount / $wordsPerMinute));
    }

    // ============================================================================
    // Body Content Processing with Image Caching
    // ============================================================================

    /**
     * Get the processed body content with local image URLs.
     *
     * This method processes the raw body content, downloads any WordPress images,
     * and replaces the URLs with local cached versions.
     *
     * @return string
     */
    public function getBodyAttribute(): string
    {
        // Return raw body if image processing is disabled
        if (!$this->processContentImages) {
            return $this->rawBody;
        }

        // Return cached processed body if available
        if ($this->processedBody !== null) {
            return $this->processedBody;
        }

        // Process the content and cache the result
        try {
            $processor = app(ContentImageProcessor::class);
            $this->processedBody = $processor->process(
                $this->rawBody,
                $this->getContentCacheKey()
            );
        } catch (\Throwable $th) {
            Log::warning('Failed to process post content images', [
                'post_id' => $this->id,
                'message' => $th->getMessage(),
            ]);

            // Return raw body on failure
            $this->processedBody = $this->rawBody;
        }

        return $this->processedBody;
    }

    /**
     * Get the raw body content without image processing.
     *
     * @return string
     */
    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Get decoded content with proper emoji support.
     *
     * @return string
     */
    public function getDecodedBody(): string
    {
        return $this->body; // Already decoded via html_entity_decode in fromApiResponse
    }

    /**
     * Set whether to process content images.
     *
     * @param bool $process
     * @return $this
     */
    public function setProcessContentImages(bool $process): self
    {
        $this->processContentImages = $process;

        return $this;
    }

    /**
     * Get whether content images will be processed.
     *
     * @return bool
     */
    public function getProcessContentImages(): bool
    {
        return $this->processContentImages;
    }

    /**
     * Get the cache key for processed content.
     *
     * @return string
     */
    protected function getContentCacheKey(): string
    {
        return "post_{$this->id}_" . md5($this->rawBody);
    }

    /**
     * Clear the cached processed body.
     *
     * @return $this
     */
    public function clearProcessedBodyCache(): self
    {
        $this->processedBody = null;

        try {
            $processor = app(ContentImageProcessor::class);
            $processor->clearCachedContent($this->getContentCacheKey());
        } catch (\Throwable $th) {
            // Silently ignore
        }

        return $this;
    }

    // ============================================================================
    // ArrayAccess Implementation for meta data access
    // ============================================================================

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->metaData[$offset]) || property_exists($this, $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->metaData[$offset] ?? $this->$offset ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (property_exists($this, $offset)) {
            $this->$offset = $value;
        } else {
            $this->metaData[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->metaData[$offset]);
    }

    // ============================================================================
    // Magic Methods for meta and property access
    // ============================================================================

    /**
     * Magic getter for meta data and other properties.
     *
     * @param string $key
     * @return mixed
     */
    public function __get(string $key): mixed
    {
        // Handle body property - return processed content
        if ($key === 'body') {
            return $this->getBodyAttribute();
        }

        // Handle featured_image property with caching
        if ($key === 'featured_image') {
            // If featured_image is already a local URL, return it
            if ($this->featured_image && str_starts_with($this->featured_image, '/blog-media/')) {
                return $this->featured_image;
            }

            // If it's a WordPress URL, download and cache it
            if ($this->featured_image) {
                $imageService = app(WordPressImageService::class);
                $localUrl = $imageService->getOrDownload($this->featured_image);

                if ($localUrl) {
                    $this->featured_image = $localUrl;
                }
            }

            return $this->featured_image;
        }

        // Check meta data first
        if (isset($this->metaData[$key])) {
            return $this->metaData[$key];
        }

        // Check for relationship methods
        if ($key === 'user') {
            return $this->user();
        }

        if ($key === 'categories') {
            return $this->categories();
        }

        if ($key === 'tags') {
            return $this->tags();
        }

        if ($key === 'topic') {
            return $this->topic();
        }

        if ($key === 'meta') {
            return $this->metaData;
        }

        // Check if it's a property
        if (property_exists($this, $key)) {
            return $this->$key;
        }

        // Check original data
        return $this->originalData[$key] ?? null;
    }

    /**
     * Magic setter for meta data.
     *
     * @param string $key
     * @param mixed $value
     */
    public function __set(string $key, mixed $value): void
    {
        if (property_exists($this, $key)) {
            $this->$key = $value;
        } else {
            $this->metaData[$key] = $value;
        }
    }

    /**
     * Magic isset for meta data.
     *
     * @param string $key
     * @return bool
     */
    public function __isset(string $key): bool
    {
        return isset($this->metaData[$key]) ||
               property_exists($this, $key) ||
               isset($this->originalData[$key]);
    }

    // ============================================================================
    // Arrayable, Jsonable, JsonSerializable Implementation
    // ============================================================================

    /**
     * Convert the model to an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            'body' => $this->getBodyAttribute(),
            'slug' => $this->slug,
            'published_at' => $this->published_at->toIso8601String(),
            'featured_image' => $this->featured_image,
            'status' => $this->status,
            'format' => $this->format,
            'meta' => $this->metaData,
            'user' => $this->userData?->toArray(),
            'categories' => $this->categoriesData?->toArray(),
            'tags' => $this->tagsData?->toArray(),
        ];
    }

    /**
     * Convert the model to JSON.
     *
     * @param int $options
     * @return string
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Specify data which should be serialized to JSON.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Get the original API response data.
     *
     * @return array
     */
    public function getOriginalData(): array
    {
        return $this->originalData;
    }
}
