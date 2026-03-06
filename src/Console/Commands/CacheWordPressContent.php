<?php

namespace KalprajSolutions\LaravelWordpressCda\Console\Commands;

use Illuminate\Console\Command;
use KalprajSolutions\LaravelWordpressCda\Services\WordPressApiService;
use KalprajSolutions\LaravelWordpressCda\Services\WordPressImageService;
use KalprajSolutions\LaravelWordpressCda\Services\ContentImageProcessor;
use KalprajSolutions\LaravelWordpressCda\Repositories\WordPressPostRepository;
use Illuminate\Support\Facades\Cache;

class CacheWordPressContent extends Command
{
    protected $signature = 'wp:cache 
                            {--pages=all : Number of pages to cache (or "all")}
                            {--images : Also cache all images}
                            {--clean : Clean files and cache before caching}';

    protected $description = 'Cache WordPress blog content including posts, categories, and images';

    public function __construct(
        private WordPressApiService $apiService,
        private WordPressPostRepository $repository,
        private WordPressImageService $imageService,
        private ContentImageProcessor $contentProcessor
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Starting WordPress content caching...');

        if ($this->option('clean')) {
            $this->clean();
        }

        
        // 1. Cache categories and tags
        $this->cacheTaxonomies();
        
        // 2. Cache all posts with pagination
        $this->cachePosts();
        
        // 3. Cache images if requested
        if ($this->option('images')) {
            $this->cacheImages();
        }
        
        $this->info('WordPress content caching completed!');
        return 0;
    }

    public function clean(): void
    {
        $this->info('Cleaning WordPress content cache...');

        // 1. Clear all WordPress posts
        $this->repository->clearCache();

        // 2. Clear all WordPress images
        $this->imageService->clearAllCachedImages();

        $this->info('WordPress content cache cleaned!');
    }

    private function cacheTaxonomies(): void
    {
        $this->info('Caching categories...');
        $categories = $this->repository->getAuthorCategories();
        $this->info("Cached {$categories->count()} categories");
        
        $this->info('Caching tags...');
        $tags = $this->repository->getAuthorTags();
        $this->info("Cached {$tags->count()} tags");
    }

    private function cachePosts(): void
    {
        $this->info('Caching posts...');
        
        $page = 1;
        $perPage = 10;
        $totalCached = 0;
        $maxPages = $this->option('pages') === 'all' ? null : (int) $this->option('pages');
        
        do {
            $this->info("Fetching page {$page}...");
            
            $posts = $this->apiService->fetchPosts([
                'author' => config('wordpress.author_id'),
                'status' => 'publish',
                'per_page' => $perPage,
                'page' => $page,
                '_embed' => 'wp:term,wp:featuredmedia,author',
            ]);
            
            if (empty($posts)) {
                break;
            }
            
            // Cache each individual post
            foreach ($posts as $postData) {
                $this->cacheSinglePost($postData);
                $totalCached++;
            }
            
            $this->info("Cached page {$page} with " . count($posts) . " posts");
            
            $page++;
            
            // Stop if we've reached max pages
            if ($maxPages && $page > $maxPages) {
                break;
            }
            
        } while (count($posts) === $perPage);
        
        $this->info("Total posts cached: {$totalCached}");
    }

    private function cacheSinglePost(array $postData): void
    {
        $slug = $postData['slug'];
        
        // Cache post by slug
        $cacheKey = 'wp_post_' . $slug;
        Cache::put($cacheKey, $postData, now()->addMinutes(config('wordpress.cache_duration')));
        
        // Process and cache images in content if enabled
        if (isset($postData['content']['rendered'])) {
            $this->contentProcessor->process($postData['content']['rendered'], $cacheKey . '_content');
        }
        
        // Cache featured image
        if (isset($postData['_embedded']['wp:featuredmedia'][0]['source_url'])) {
            $this->imageService->getOrDownload(
                $postData['_embedded']['wp:featuredmedia'][0]['source_url']
            );
        }
    }

    private function cacheImages(): void
    {
        $this->info('Caching all images...');
        
        $page = 1;
        $perPage = 10;
        $totalImages = 0;
        
        do {
            $posts = $this->apiService->fetchPosts([
                'author' => config('wordpress.author_id'),
                'status' => 'publish',
                'per_page' => $perPage,
                'page' => $page,
                '_embed' => 'wp:term,wp:featuredmedia,author',
            ]);
            
            if (empty($posts)) {
                break;
            }
            
            foreach ($posts as $postData) {
                // Cache featured image
                if (isset($postData['_embedded']['wp:featuredmedia'][0]['source_url'])) {
                    $this->imageService->getOrDownload(
                        $postData['_embedded']['wp:featuredmedia'][0]['source_url']
                    );
                    $totalImages++;
                }
                
                // Cache content images
                if (isset($postData['content']['rendered'])) {
                    $urls = $this->contentProcessor->extractImageUrls($postData['content']['rendered']);
                    foreach ($urls as $url) {
                        $this->imageService->getOrDownload($url);
                        $totalImages++;
                    }
                }
            }
            
            $page++;
        } while (count($posts) === $perPage);
        
        $this->info("Total images cached: {$totalImages}");
    }
}
