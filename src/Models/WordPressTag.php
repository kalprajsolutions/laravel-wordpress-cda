<?php

namespace KalprajSolutions\LaravelWordpressCda\Models;

/**
 * Wrapper class for WordPress tag data.
 *
 * This class provides a simple wrapper around WordPress tag data
 * returned from the REST API. In WordPress, tags are non-hierarchical
 * taxonomies used to label posts.
 */
class WordPressTag
{
    /**
     * The tag ID.
     */
    public int $id;

    /**
     * The tag name.
     */
    public string $name;

    /**
     * The tag slug.
     */
    public string $slug;

    /**
     * The tag description.
     */
    public ?string $description;

    /**
     * Number of posts with this tag.
     */
    public int $count = 0;

    /**
     * The taxonomy type (always 'post_tag' for tags).
     */
    public string $taxonomy = 'post_tag';

    /**
     * Additional meta data.
     */
    public array $meta = [];

    /**
     * Create a new WordPressTag instance from API data.
     *
     * @param array $data The tag data from WordPress API
     * @return static
     */
    public static function fromApiResponse(array $data): self
    {
        $tag = new self();

        $tag->id = $data['id'] ?? 0;
        $tag->name = $data['name'] ?? '';
        $tag->slug = $data['slug'] ?? '';
        $tag->description = $data['description'] ?? null;
        $tag->count = $data['count'] ?? 0;
        $tag->taxonomy = $data['taxonomy'] ?? 'post_tag';
        $tag->meta = $data['meta'] ?? [];

        return $tag;
    }

    /**
     * Get the tag URL.
     *
     * @return string
     */
    public function getUrl(): string
    {
        return route('blog.tag', ['slug' => $this->slug]);
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
