<?php

namespace KalprajSolutions\LaravelWordpressCda\Models;

/**
 * Wrapper class for WordPress category data.
 *
 * This class provides a simple wrapper around WordPress category data
 * returned from the REST API. In WordPress, categories are hierarchical
 * taxonomies used to organize posts.
 */
class WordPressCategory
{
    /**
     * The category ID.
     */
    public int $id;

    /**
     * The category name.
     */
    public string $name;

    /**
     * The category slug.
     */
    public string $slug;

    /**
     * The category description.
     */
    public ?string $description;

    /**
     * The parent category ID (0 if top-level).
     */
    public int $parent = 0;

    /**
     * Number of posts in this category.
     */
    public int $count = 0;

    /**
     * The taxonomy type (always 'category' for categories).
     */
    public string $taxonomy = 'category';

    /**
     * Additional meta data.
     */
    public array $meta = [];

    /**
     * Create a new WordPressCategory instance from API data.
     *
     * @param array $data The category data from WordPress API
     * @return static
     */
    public static function fromApiResponse(array $data): self
    {
        $category = new self();

        $category->id = $data['id'] ?? 0;
        $category->name = $data['name'] ?? '';
        $category->slug = $data['slug'] ?? '';
        $category->description = $data['description'] ?? null;
        $category->parent = $data['parent'] ?? 0;
        $category->count = $data['count'] ?? 0;
        $category->taxonomy = $data['taxonomy'] ?? 'category';
        $category->meta = $data['meta'] ?? [];

        return $category;
    }

    /**
     * Get the category URL.
     *
     * @return string
     */
    public function getUrl(): string
    {
        return route('blog.category', ['slug' => $this->slug]);
    }

    /**
     * Check if this is a top-level category.
     *
     * @return bool
     */
    public function isTopLevel(): bool
    {
        return $this->parent === 0;
    }

    /**
     * Magic getter for compatibility.
     *
     * @param string $key
     * @return mixed
     */
    public function __get(string $key): mixed
    {
        return $this->meta[$key] ?? null;
    }

    /**
     * Magic isset for compatibility.
     *
     * @param string $key
     * @return bool
     */
    public function __isset(string $key): bool
    {
        return isset($this->meta[$key]);
    }
}
