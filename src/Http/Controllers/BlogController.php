<?php

namespace KalprajSolutions\LaravelWordpressCda\Http\Controllers;

use KalprajSolutions\LaravelWordpressCda\Repositories\WordPressPostRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Blog Controller
 *
 * Handles all blog-related routes using the WordPress API repository.
 * Replaces the previous Canvas-based implementation.
 */
class BlogController extends Controller
{
    public function __construct(
        private WordPressPostRepository $repository
    ) {}

    /**
     * Display a listing of all blog posts.
     *
     * @param Request $request
     * @return View
     */
    public function index(Request $request): View
    {
        $page = $request->get('page', 1);
        $perPage = 10; // 10 posts per page

        // Timing for getAllPosts()
        $startTime = microtime(true);
        $posts = $this->repository->getAllPosts($perPage, $page);
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        Log::info("getAllPosts took: {$duration}ms");

        // Timing for getAuthorCategories()
        $startTime = microtime(true);
        $categories = $this->repository->getAuthorCategories();
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        Log::info("getAuthorCategories took: {$duration}ms");

        // Timing for getAuthorTags()
        $startTime = microtime(true);
        $tags = $this->repository->getAuthorTags();
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        Log::info("getAuthorTags took: {$duration}ms");

        // Timing for getRecentPosts()
        $startTime = microtime(true);
        $recent_posts = $this->repository->getRecentPosts(5);
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        Log::info("getRecentPosts took: {$duration}ms");

        return view('blog.index', compact('posts', 'categories', 'tags', 'recent_posts'));
    }

    /**
     * Display posts filtered by category.
     *
     * @param string $slug
     * @return View
     */
    public function byCategory(string $slug): View
    {
        // Get posts by category
        $allPosts = $this->repository->getPostsByCategory($slug);
        
        // If no posts found, category doesn't exist
        if ($allPosts->isEmpty()) {
            abort(404);
        }

        // Paginate the collection
        $posts = $this->paginateCollection($allPosts, 10, request());

        // Get sidebar data
        $categories = $this->repository->getAuthorCategories();
        $tags = $this->repository->getAuthorTags();
        $recent_posts = $this->repository->getRecentPosts(3);

        $type = 'category';

        return view('blog.index', compact('posts', 'categories', 'tags', 'type', 'slug', 'recent_posts'));
    }

    /**
     * Display posts filtered by tag.
     *
     * @param string $slug
     * @return View
     */
    public function byTag(string $slug): View
    {
        // Get posts by tag
        $allPosts = $this->repository->getPostsByTag($slug);
        
        // If no posts found, tag doesn't exist
        if ($allPosts->isEmpty()) {
            abort(404);
        }

        // Paginate the collection
        $posts = $this->paginateCollection($allPosts, 10, request());

        // Get sidebar data
        $categories = $this->repository->getAuthorCategories();
        $tags = $this->repository->getAuthorTags();
        $recent_posts = $this->repository->getRecentPosts(3);

        $type = 'tag';

        return view('blog.index', compact('posts', 'categories', 'tags', 'type', 'slug', 'recent_posts'));
    }

    /**
     * Display a single blog post.
     *
     * @param string $slug
     * @return View
     */
    public function show(string $slug): View
    {
        // Get the post by slug
        $post = $this->repository->getPostBySlug($slug);

        if (!$post) {
            abort(404);
        }

        // Get sidebar data
        $categories = $this->repository->getAuthorCategories();
        // Use post's specific tags when viewing a single post
        $tags = $post->tags();
        $recent_posts = $this->repository->getRecentPosts(3);

        // Note: View tracking is skipped for now as per task instructions
        // In the future, a custom event could be dispatched here if needed

        return view('blog.show', compact('post', 'categories', 'tags', 'recent_posts'));
    }

    /**
     * Paginate a collection manually.
     *
     * @param \Illuminate\Support\Collection $collection
     * @param int $perPage
     * @param Request $request
     * @return LengthAwarePaginator
     */
    private function paginateCollection($collection, int $perPage, Request $request): LengthAwarePaginator
    {
        $currentPage = $request->get('page', 1);
        $currentItems = $collection->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $currentItems,
            $collection->count(),
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }
}
