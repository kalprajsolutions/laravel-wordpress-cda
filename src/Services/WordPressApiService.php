<?php

namespace KalprajSolutions\LaravelWordpressCda\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WordPressApiService
{
    /**
     * The base URL for the WordPress REST API.
     */
    protected string $baseUrl;

    /**
     * The author ID to filter posts by.
     */
    protected int $authorId;

    /**
     * Number of posts per page.
     */
    protected int $perPage;

    /**
     * Cache duration in minutes.
     */
    protected int $cacheDuration;

    /**
     * Whether authentication is enabled.
     */
    protected bool $authEnabled;

    /**
     * Authentication credentials.
     */
    protected ?array $authCredentials = null;

    /**
     * Last total posts count from API response headers.
     */
    private ?int $lastTotalPostsCount = null;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('wordpress.base_url'), '/');
        $this->authorId = config('wordpress.author_id', 3);
        $this->perPage = config('wordpress.per_page', 100);
        $this->cacheDuration = config('wordpress.cache_duration', 60);
        $this->authEnabled = config('wordpress.auth.enabled', false);

        if ($this->authEnabled) {
            $username = config('wordpress.auth.username');
            $password = config('wordpress.auth.password');

            if ($username && $password) {
                $this->authCredentials = [$username, $password];
            }
        }
    }

    /**
     * Get the last total posts count from API response headers.
     *
     * @return int|null
     */
    public function getLastTotalPostsCount(): ?int
    {
        return $this->lastTotalPostsCount;
    }

    /**
     * Fetch posts from WordPress API with optional filters.
     *
     * @param array $filters Additional filters for the API request
     * @return array
     */
    public function fetchPosts(array $filters = []): array
    {
        $defaultFilters = [
            'author' => $this->authorId,
            'status' => 'publish',
            'per_page' => $this->perPage,
            'orderby' => 'date',
            'order' => 'desc',
        ];

        // If _fields is provided, use minimal fields and don't include _embed
        // This significantly improves API performance
        if (isset($filters['_fields'])) {
            $defaultFilters['_fields'] = $filters['_fields'];
            // Remove _embed from filters if present, since we're using minimal fields
            unset($filters['_fields']);
        } else {
            // Default _embed only when not using minimal fields
            $defaultFilters['_embed'] = 'wp:term,wp:featuredmedia,author';
            $defaultFilters['_fields'] = 'id,date,date_gmt,guid,modified,modified_gmt,slug,status,type,link,title,excerpt,author,featured_media,comment_status,ping_status,sticky,template,format,meta,categories,tags,class_list,yoast_head,yoast_head_json,uagb_featured_image_src,uagb_author_info,uagb_comment_info,uagb_excerpt,_links';
        }

        $queryParams = array_merge($defaultFilters, $filters);
        $cacheKey = 'wp_posts_' . md5(serialize($queryParams));

        $cached = Cache::get($cacheKey);

        // If we have cached data, restore the total count and return posts
        if ($cached !== null) {
            $this->lastTotalPostsCount = $cached['total'] ?? null;
            return $cached['posts'] ?? [];
        }

        // Make the API request
        try {
            $response = $this->makeRequest('GET', '/posts', $queryParams);

            if ($response->successful()) {
                // IMPORTANT: Capture headers BEFORE calling json()
                $this->lastTotalPostsCount = (int) $response->header('X-WP-Total');
                $totalPages = (int) $response->header('X-WP-TotalPages');

                $posts = $response->json() ?? [];

                // Store both posts and total count in cache
                Cache::put($cacheKey, [
                    'posts' => $posts,
                    'total' => $this->lastTotalPostsCount,
                    'totalPages' => $totalPages,
                ], now()->addMinutes($this->cacheDuration));

                return $posts;
            }

            Log::warning('WordPress API posts fetch failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        } catch (\Exception $e) {
            Log::error('WordPress API posts fetch exception', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Fetch a single post by ID.
     *
     * @param int $id The post ID
     * @return array|null
     */
    public function fetchPost(int $id): ?array
    {
        $cacheKey = "wp_post_{$id}";

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheDuration), function () use ($id) {
            try {
                $response = $this->makeRequest('GET', "/posts/{$id}", [
                    '_embed' => 'wp:term,wp:featuredmedia,author',
                ]);

                if ($response->successful()) {
                    return $response->json();
                }

                if ($response->status() === 404) {
                    return null;
                }

                Log::warning('WordPress API post fetch failed', [
                    'post_id' => $id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            } catch (\Exception $e) {
                Log::error('WordPress API post fetch exception', [
                    'post_id' => $id,
                    'message' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    /**
     * Fetch a single post by slug.
     *
     * @param string $slug The post slug
     * @return array|null
     */
    public function fetchPostBySlug(string $slug): ?array
    {
        $cacheKey = 'wp_post_api_' . $slug;

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheDuration), function () use ($slug) {
            try {
                $response = $this->makeRequest('GET', '/posts', [
                    'slug' => $slug,
                    '_embed' => 'wp:term,wp:featuredmedia,author',
                ]);

                if ($response->successful()) {
                    $posts = $response->json();
                    return $posts[0] ?? null;
                }

                if ($response->status() === 404) {
                    return null;
                }

                Log::warning('WordPress API post by slug fetch failed', [
                    'slug' => $slug,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            } catch (\Exception $e) {
                Log::error('WordPress API post by slug fetch exception', [
                    'slug' => $slug,
                    'message' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    /**
     * Fetch media by ID.
     *
     * @param int $id The media ID
     * @return array|null
     */
    public function fetchMedia(int $id): ?array
    {
        $cacheKey = "wp_media_{$id}";

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheDuration), function () use ($id) {
            try {
                $response = $this->makeRequest('GET', "/media/{$id}");

                if ($response->successful()) {
                    return $response->json();
                }

                if ($response->status() === 404) {
                    return null;
                }

                Log::warning('WordPress API media fetch failed', [
                    'media_id' => $id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            } catch (\Exception $e) {
                Log::error('WordPress API media fetch exception', [
                    'media_id' => $id,
                    'message' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    /**
     * Clear the cache for posts.
     *
     * @return void
     */
    public function clearPostsCache(): void
    {
        // Note: This is a simplified cache clearing approach.
        // In production, you might want to use cache tags if supported.
        Cache::flush();
    }

    /**
     * Clear the cache for a specific post.
     *
     * @param int $id The post ID
     * @return void
     */
    public function clearPostCache(int $id): void
    {
        Cache::forget("wp_post_{$id}");
    }

    /**
     * Make an HTTP request to the WordPress API.
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint (relative to base URL)
     * @param array $params Query parameters
     * @return Response
     */
    protected function makeRequest(string $method, string $endpoint, array $params = []): Response
    {
        $url = $this->baseUrl . $endpoint;

        $request = Http::withOptions([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        // Add authentication if enabled
        if ($this->authEnabled && $this->authCredentials) {
            $request = $request->withBasicAuth(
                $this->authCredentials[0],
                $this->authCredentials[1]
            );
        }

        if (strtoupper($method) === 'GET') {
            return $request->get($url, $params);
        }

        return $request->post($url, $params);
    }

    /**
     * Get the featured image URL from a post.
     *
     * @param array $post The post data from API
     * @param string $size The image size (thumbnail, medium, large, full)
     * @return string|null
     */
    public function getFeaturedImageUrl(array $post, string $size = 'full'): ?string
    {
        if (!isset($post['_embedded']['wp:featuredmedia'][0])) {
            return null;
        }

        $media = $post['_embedded']['wp:featuredmedia'][0];

        if ($size === 'full') {
            return $media['source_url'] ?? null;
        }

        return $media['media_details']['sizes'][$size]['source_url'] ?? $media['source_url'] ?? null;
    }

    /**
     * Get the author name from a post.
     *
     * @param array $post The post data from API
     * @return string|null
     */
    public function getAuthorName(array $post): ?string
    {
        if (isset($post['_embedded']['author'][0]['name'])) {
            return $post['_embedded']['author'][0]['name'];
        }

        return $post['author'] ?? null;
    }

    /**
     * Get post categories.
     *
     * @param array $post The post data from API
     * @return array
     */
    public function getCategories(array $post): array
    {
        return $post['_embedded']['wp:term'][0] ?? [];
    }

    /**
     * Get post tags.
     *
     * @param array $post The post data from API
     * @return array
     */
    public function getTags(array $post): array
    {
        return $post['_embedded']['wp:term'][1] ?? [];
    }

    /**
     * Fetch all categories directly from the categories endpoint.
     * This is much more efficient than extracting from posts.
     *
     * @param int $perPage Number of categories per page
     * @return array
     */
    public function fetchCategories(int $perPage = 100): array
    {
        $cacheKey = 'wp_all_categories_' . $perPage;

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheDuration), function () use ($perPage) {
            try {
                $categories = [];
                $page = 1;

                do {
                    $response = $this->makeRequest('GET', '/categories', [
                        'per_page' => $perPage,
                        'page' => $page,
                        'hide_empty' => false,
                    ]);

                    if ($response->successful()) {
                        $pageCategories = $response->json() ?? [];
                        $categories = array_merge($categories, $pageCategories);
                        $page++;
                    } else {
                        break;
                    }
                } while (count($pageCategories) === $perPage);

                return $categories;
            } catch (\Exception $e) {
                Log::error('WordPress API categories fetch exception', [
                    'message' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * Fetch all tags directly from the tags endpoint.
     * This is much more efficient than extracting from posts.
     *
     * @param int $perPage Number of tags per page
     * @return array
     */
    public function fetchTags(int $perPage = 100): array
    {
        $cacheKey = 'wp_all_tags_' . $perPage;

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheDuration), function () use ($perPage) {
            try {
                $tags = [];
                $page = 1;

                do {
                    $response = $this->makeRequest('GET', '/tags', [
                        'per_page' => $perPage,
                        'page' => $page,
                        'hide_empty' => false,
                        '_fields' => 'id,name,slug,count'
                    ]);

                    if ($response->successful()) {
                        $pageTags = $response->json() ?? [];
                        $tags = array_merge($tags, $pageTags);
                        $page++;
                    } else {
                        break;
                    }
                } while (count($pageTags) === $perPage);

                return $tags;
            } catch (\Exception $e) {
                Log::error('WordPress API tags fetch exception', [
                    'message' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }
}
