<?php

namespace KalprajSolutions\LaravelWordpressCda;

use Illuminate\Support\ServiceProvider;
use KalprajSolutions\LaravelWordpressCda\Console\Commands\CacheWordPressContent;
use KalprajSolutions\LaravelWordpressCda\Repositories\WordPressPostRepository;
use KalprajSolutions\LaravelWordpressCda\Services\WordPressApiService;
use KalprajSolutions\LaravelWordpressCda\Services\WordPressImageService;
use KalprajSolutions\LaravelWordpressCda\Services\ContentImageProcessor;

class WordPressCdaServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/wordpress.php',
            'wordpress'
        );

        // Register services as singletons
        $this->app->singleton(WordPressApiService::class, function ($app) {
            return new WordPressApiService();
        });

        $this->app->singleton(WordPressImageService::class, function ($app) {
            return new WordPressImageService();
        });

        $this->app->singleton(ContentImageProcessor::class, function ($app) {
            return new ContentImageProcessor(
                $app->make(WordPressImageService::class)
            );
        });

        $this->app->singleton(WordPressPostRepository::class, function ($app) {
            return new WordPressPostRepository(
                $app->make(WordPressApiService::class),
                $app->make(WordPressImageService::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/wordpress.php' => config_path('wordpress.php'),
        ], 'wordpress-blog-config');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                CacheWordPressContent::class,
            ]);
        }
    }
}
