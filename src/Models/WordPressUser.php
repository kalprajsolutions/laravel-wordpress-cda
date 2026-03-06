<?php

namespace KalprajSolutions\LaravelWordpressCda\Models;

/**
 * Wrapper class for WordPress author data.
 *
 * This class provides a simple wrapper around WordPress author data
 * returned from the REST API, compatible with the existing user
 * relationship expected by blog views.
 */
class WordPressUser
{
    /**
     * The user's ID.
     */
    public int $id;

    /**
     * The user's display name.
     */
    public string $name;

    /**
     * The user's URL/slug.
     */
    public string $slug;

    /**
     * The user's URL.
     */
    public ?string $url;

    /**
     * The user's description/bio.
     */
    public ?string $description;

    /**
     * The user's avatar URLs.
     *
     * @var array<string, string>
     */
    public array $avatarUrls = [];

    /**
     * Additional meta data.
     */
    public array $meta = [];

    /**
     * Create a new WordPressUser instance from API data.
     *
     * @param array $data The author data from WordPress API
     * @return static
     */
    public static function fromApiResponse(array $data): self
    {
        $user = new self();

        $user->id = $data['id'] ?? 0;
        $user->name = $data['name'] ?? '';
        $user->slug = $data['slug'] ?? '';
        $user->url = $data['url'] ?? null;
        $user->description = $data['description'] ?? null;
        $user->avatarUrls = $data['avatar_urls'] ?? [];
        $user->meta = $data['meta'] ?? [];

        return $user;
    }

    /**
     * Get the user's avatar URL.
     *
     * @param string $size The avatar size (24, 48, 96)
     * @return string|null
     */
    public function getAvatarUrl(string $size = '96'): ?string
    {
        return $this->avatarUrls[$size] ?? $this->avatarUrls['96'] ?? null;
    }

    /**
     * Get the user's profile URL.
     *
     * @return string
     */
    public function getProfileUrl(): string
    {
        return $this->url ?? '#';
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
