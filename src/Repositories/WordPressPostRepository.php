<?php

namespace KalprajSolutions\LaravelWordpressCda\Repositories;

use KalprajSolutions\LaravelWordpressCda\Models\WordPressCategory;
use KalprajSolutions\LaravelWordpressCda\Models\WordPressPost;
use KalprajSolutions\LaravelWordpressCda\Models\WordPressTag;
use KalprajSolutions\LaravelWordpressCda\Services\WordPressApiService;
use KalprajSolutions\LaravelWordpressCda\Services\WordPressImageService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Repository for fetching WordPress posts via the REST API.
 *
 * This repository provides a clean interface for retrieving WordPress posts,
 * categories, and tags, while handling caching and data transformation.
 */
class WordPressPostRepository
{
    /**
     * Cache duration in minutes for repository results.
     */
    protected int $cacheDuration;

    public function __construct(
        private WordPressApiService $apiService,
        private WordPressImageService $imageService
    ) {
        $this->cacheDuration = config('wordpress.cache_duration', 60);
    }

    /**
     * Get all posts with pagination
     *
     * @param int|null $perPage Number of posts per page (null uses config default)
     * @param int $page Current page number
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function getAllPosts(int $perPage = null, int $page = 1): LengthAwarePaginator
    {
        $perPage = $perPage ?? config('wordpress.per_page', 10);
        $authorId = config('wordpress.author_id');

        // Direct API call without caching to ensure pagination works correctly
        // Caching is disabled for paginated results to prevent pagination data issues
        $posts = $this->apiService->fetchPosts([
            'author' => $authorId,
            'status' => 'publish',
            'per_page' => $perPage,
            'page' => $page,
            '_embed' => 'wp:term,wp:featuredmedia,author',
        ]);

        // Get total from API header - THIS IS CRITICAL for pagination
        $total = $this->apiService->getLastTotalPostsCount();

        // If header not available, estimate (fallback)
        if ($total === null || $total === 0) {
            $total = count($posts);
            if ($page > 1) {
                // If we're on page 2+, we need to estimate
                $total = (($page - 1) * $perPage) + count($posts);
            }
        }

        $postModels = collect($posts)->map(fn ($post) => WordPressPost::fromApiResponse($post));

        // Create paginator - ensure total is always correct
        return new LengthAwarePaginator(
            $postModels,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query()
            ]
        );
    }

    /**
     * Get a single post by its slug.
     *
     * @param string $slug The post slug
     * @return WordPressPost|null
     */
    public function getPostBySlug(string $slug): ?WordPressPost
    {
        // Try cache first (used by the cache command)
        $cacheKey = 'wp_post_' . $slug;
        $cached = Cache::get($cacheKey);
        
        if ($cached) {
            return WordPressPost::fromApiResponse($cached);
        }
        
        // Fall back to repository cache
        $repoCacheKey = 'wp_repo_post_slug_' . md5($slug);

        return Cache::remember($repoCacheKey, now()->addMinutes($this->cacheDuration), function () use ($slug) {
            // Fall back to API
            $post = $this->apiService->fetchPostBySlug($slug);
            
            if ($post) {
                return WordPressPost::fromApiResponse($post);
            }
            
            return null;
        });
    }

    /**
     * Get posts filtered by category slug.
     *
     * @param string $categorySlug The category slug
     * @return Collection<WordPressPost>
     */
    public function getPostsByCategory(string $categorySlug): Collection
    {
        $cacheKey = 'wp_repo_posts_category_' . md5($categorySlug);

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheDuration), function () use ($categorySlug) {
            // First, find the category ID by slug
            $category = $this->getCategoryBySlug($categorySlug);

            if (!$category) {
                return collect();
            }

            $postsData = $this->apiService->fetchPosts([
                'categories' => $category->id,
            ]);

            return collect($postsData)->map(fn (array $post) => WordPressPost::fromApiResponse($post));
        });
    }

    /**
     * Get posts filtered by tag slug.
     *
     * @param string $tagSlug The tag slug
     * @return Collection<WordPressPost>
     */
    public function getPostsByTag(string $tagSlug): Collection
    {
        $cacheKey = 'wp_repo_posts_tag_' . md5($tagSlug);

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheDuration), function () use ($tagSlug) {
            // First, find the tag ID by slug
            $tag = $this->getTagBySlug($tagSlug);

            if (!$tag) {
                return collect();
            }

            $postsData = $this->apiService->fetchPosts([
                'tags' => $tag->id,
            ]);

            return collect($postsData)->map(fn (array $post) => WordPressPost::fromApiResponse($post));
        });
    }

    /**
     * Get recent posts.
     *
     * @param int $limit Number of posts to return
     * @return Collection<WordPressPost>
     */
    public function getRecentPosts(int $limit = 5): Collection
    {
        $cacheKey = 'wp_repo_recent_posts_' . $limit;

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheDuration), function () use ($limit) {
            $postsData = $this->apiService->fetchPosts([
                'per_page' => $limit,
                'orderby' => 'date',
                'order' => 'desc',
            ]);

            return collect($postsData)->map(fn (array $post) => WordPressPost::fromApiResponse($post));
        });
    }

    /**
     * Get featured/pinned posts (if using sticky posts).
     *
     * @param int $limit Number of posts to return
     * @return Collection<WordPressPost>
     */
    public function getFeaturedPosts(int $limit = 3): Collection
    {
        $cacheKey = 'wp_repo_featured_posts_' . $limit;

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheDuration), function () use ($limit) {
            // In WordPress, sticky posts can be fetched with the sticky parameter
            $postsData = $this->apiService->fetchPosts([
                'per_page' => $limit,
                'sticky' => true,
            ]);

            // If no sticky posts, return recent posts
            if (empty($postsData)) {
                return $this->getRecentPosts($limit);
            }

            return collect($postsData)->map(fn (array $post) => WordPressPost::fromApiResponse($post));
        });
    }

    /**
     * Get only categories used by the configured author's posts
     *
     * @return Collection<WordPressCategory>
     */
    public function getAuthorCategories(): Collection
    {
        $cacheKey = 'wp_author_' . config('wordpress.author_id') . '_categories_with_counts';

        return Cache::remember($cacheKey, config('wordpress.cache_duration'), function () {
            $categories = collect();
            $page = 1;
            $perPage = 10; // Smaller pagination to avoid API failures

            // Fetch all posts by author with pagination
            do {
                $posts = $this->apiService->fetchPosts([
                    'author' => config('wordpress.author_id'),
                    'per_page' => $perPage,
                    'page' => $page,
                    '_embed' => 'wp:term',
                ]);

                if (empty($posts)) {
                    break;
                }

                // Extract categories from each post and count them
                foreach ($posts as $post) {
                    if (isset($post['_embedded']['wp:term'])) {
                        foreach ($post['_embedded']['wp:term'] as $terms) {
                            foreach ($terms as $term) {
                                if ($term['taxonomy'] === 'category') {
                                    $categoryId = $term['id'];

                                    // Initialize category if not exists
                                    if (!isset($categories[$categoryId])) {
                                        $category = WordPressCategory::fromApiResponse($term);
                                        $category->count = 0; // Start count at 0
                                        $categories->put($categoryId, $category);
                                    }

                                    // Increment count for this category
                                    $categories[$categoryId]->count++;
                                }
                            }
                        }
                    }
                }

                $page++;
            } while (count($posts) === $perPage);

            return $categories->values();
        });
    }

    /**
     * Get only tags used by the configured author's posts
     *
     * @return Collection<WordPressTag>
     */
    public function getAuthorTags(): Collection
    {
        $cacheKey = 'wp_author_' . config('wordpress.author_id') . '_tags';

        return Cache::remember($cacheKey, config('wordpress.cache_duration'), function () {
            $tags = collect();
            $page = 1;
            $perPage = 10; // Changed from 100

            // Fetch all posts by author with pagination
            do {
                $posts = $this->apiService->fetchPosts([
                    'author' => config('wordpress.author_id'),
                    'per_page' => $perPage,
                    'page' => $page,
                    '_embed' => 'wp:term',
                ]);

                if (empty($posts)) {
                    break;
                }

                // Extract tags from each post
                foreach ($posts as $post) {
                    if (isset($post['_embedded']['wp:term'])) {
                        foreach ($post['_embedded']['wp:term'] as $terms) {
                            foreach ($terms as $term) {
                                if ($term['taxonomy'] === 'post_tag') {
                                    $tags->put($term['id'], WordPressTag::fromApiResponse($term));
                                }
                            }
                        }
                    }
                }

                $page++;
            } while (count($posts) === $perPage);

            return $tags->values();
        });
    }

    /**
     * Clear the author categories cache
     *
     * @return void
     */
    public function clearAuthorCategoriesCache(): void
    {
        Cache::forget('wp_author_' . config('wordpress.author_id') . '_categories_with_counts');
    }

    /**
     * Clear the author tags cache
     *
     * @return void
     */
    public function clearAuthorTagsCache(): void
    {
        Cache::forget('wp_author_' . config('wordpress.author_id') . '_tags');
    }

    /**
     * Get a specific category by slug.
     *
     * @param string $slug
     * @return WordPressCategory|null
     */
    public function getCategoryBySlug(string $slug): ?WordPressCategory
    {
        $cacheKey = 'wp_repo_category_slug_' . md5($slug);

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheDuration), function () use ($slug) {
            try {
                $categories = $this->fetchTerms('categories', ['slug' => $slug]);

                if (empty($categories)) {
                    return null;
                }

                return WordPressCategory::fromApiResponse($categories[0]);
            } catch (\Exception $e) {
                Log::error('Failed to fetch category by slug: ' . $e->getMessage());

                return null;
            }
        });
    }

    /**
     * Get a specific tag by slug.
     *
     * @param string $slug
     * @return WordPressTag|null
     */
    public function getTagBySlug(string $slug): ?WordPressTag
    {
        $cacheKey = 'wp_repo_tag_slug_' . md5($slug);

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheDuration), function () use ($slug) {
            try {
                $tags = $this->fetchTerms('tags', ['slug' => $slug]);

                if (empty($tags)) {
                    return null;
                }

                return WordPressTag::fromApiResponse($tags[0]);
            } catch (\Exception $e) {
                Log::error('Failed to fetch tag by slug: ' . $e->getMessage());

                return null;
            }
        });
    }

    /**
     * Search posts by keyword.
     *
     * @param string $search
     * @param int|null $perPage
     * @return Collection<WordPressPost>
     */
    public function searchPosts(string $search, ?int $perPage = null): Collection
    {
        $cacheKey = 'wp_repo_search_' . md5($search) . '_' . ($perPage ?? 'all');

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheDuration), function () use ($search, $perPage) {
            $filters = [
                'search' => $search,
            ];

            if ($perPage !== null) {
                $filters['per_page'] = $perPage;
            }

            $postsData = $this->apiService->fetchPosts($filters);

            return collect($postsData)->map(fn (array $post) => WordPressPost::fromApiResponse($post));
        });
    }

    /**
     * Get posts by author.
     *
     * @param int $authorId
     * @param int|null $perPage
     * @return Collection<WordPressPost>
     */
    public function getPostsByAuthor(int $authorId, ?int $perPage = null): Collection
    {
        $cacheKey = 'wp_repo_author_' . $authorId . '_' . ($perPage ?? 'all');

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheDuration), function () use ($authorId, $perPage) {
            $filters = [
                'author' => $authorId,
            ];

            if ($perPage !== null) {
                $filters['per_page'] = $perPage;
            }

            $postsData = $this->apiService->fetchPosts($filters);

            return collect($postsData)->map(fn (array $post) => WordPressPost::fromApiResponse($post));
        });
    }

    /**
     * Get related posts based on categories and tags.
     *
     * @param WordPressPost $post
     * @param int $limit
     * @return Collection<WordPressPost>
     */
    public function getRelatedPosts(WordPressPost $post, int $limit = 4): Collection
    {
        $cacheKey = 'wp_repo_related_' . $post->id . '_' . $limit;

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheDuration), function () use ($post, $limit) {
            $categoryIds = $post->categories()->pluck('id')->toArray();
            $tagIds = $post->tags()->pluck('id')->toArray();

            $filters = [
                'per_page' => $limit + 1, // Get one extra to exclude current post
                'exclude' => [$post->id],
            ];

            // Prioritize by categories first
            if (!empty($categoryIds)) {
                $filters['categories'] = implode(',', $categoryIds);
            } elseif (!empty($tagIds)) {
                $filters['tags'] = implode(',', $tagIds);
            }

            $postsData = $this->apiService->fetchPosts($filters);

            return collect($postsData)
                ->reject(fn (array $p) => $p['id'] === $post->id)
                ->take($limit)
                ->map(fn (array $p) => WordPressPost::fromApiResponse($p));
        });
    }

    /**
     * Clear all repository caches.
     *
     * @return void
     */
    public function clearCache(): void
    {
        // Note: In production, you might want to use cache tags
        // for more granular cache clearing
        $this->apiService->clearPostsCache();
    }

    /**
     * Clear cache for a specific post.
     *
     * @param int $postId
     * @return void
     */
    public function clearPostCache(int $postId): void
    {
        $this->apiService->clearPostCache($postId);
    }

    /**
     * Fetch terms (categories/tags) from the WordPress API.
     *
     * @param string $taxonomy 'categories' or 'tags'
     * @param array $filters Additional filters
     * @return array
     */
    protected function fetchTerms(string $taxonomy, array $filters = []): array
    {
        $baseUrl = rtrim(config('wordpress.base_url'), '/');
        $cacheKey = 'wp_terms_' . $taxonomy . '_' . md5(serialize($filters));

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheDuration), function () use ($baseUrl, $taxonomy, $filters) {
            $defaultFilters = [
                'per_page' => 100,
                'hide_empty' => true,
            ];

            $queryParams = array_merge($defaultFilters, $filters);
            $url = $baseUrl . '/' . $taxonomy;

            $response = \Illuminate\Support\Facades\Http::withOptions([
                'timeout' => 30,
                'connect_timeout' => 10,
            ])->get($url, $queryParams);

            if (!$response->successful()) {
                Log::warning("WordPress API {$taxonomy} fetch failed", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            return $response->json() ?? [];
        });
    }
}
