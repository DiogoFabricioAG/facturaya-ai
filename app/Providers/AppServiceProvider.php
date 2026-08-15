<?php

namespace App\Providers;

use App\Contracts\DocumentExtractor;
use App\Services\CompanyContext;
use App\Services\Extraction\DemoDocumentExtractor;
use App\Services\Extraction\OpenAiDocumentExtractor;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DocumentExtractor::class, function ($app): DocumentExtractor {
            return match (config('facturaya.ai.driver')) {
                'demo' => $app->make(DemoDocumentExtractor::class),
                'openai' => $app->make(OpenAiDocumentExtractor::class),
                default => throw new InvalidArgumentException('AI_DOCUMENT_DRIVER debe ser demo u openai.'),
            };
        });

        $this->app->scoped(CompanyContext::class, fn () => new CompanyContext);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
