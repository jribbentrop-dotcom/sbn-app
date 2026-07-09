<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use App\Services\HarmonicContext\DiminishedAsDominantResolver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register PedalDetector with callable for Phase 1 identification
        $this->app->singleton(\App\Services\HarmonicContext\PedalDetector::class, function ($app) {
            return new \App\Services\HarmonicContext\PedalDetector(
                fn(...$args) => $app->make(\App\Services\VoicingCrossref::class)->identifyPhase1Only(...$args)
            );
        });

        // Register HarmonicPatternMatcher with ProgressionDetector dependency
        $this->app->singleton(\App\Services\HarmonicContext\HarmonicPatternMatcher::class, function ($app) {
            return new \App\Services\HarmonicContext\HarmonicPatternMatcher(
                $app->make(\App\Services\ProgressionDetector::class)
            );
        });

        // Register DiminishedAsDominantResolver
        $this->app->singleton(\App\Services\HarmonicContext\DiminishedAsDominantResolver::class, function ($app) {
            return new \App\Services\HarmonicContext\DiminishedAsDominantResolver();
        });

        // Register ContextualReranker as a singleton that resolves VoicingCrossref lazily
        $this->app->singleton(\App\Services\HarmonicContext\ContextualReranker::class, function ($app) {
            return new \App\Services\HarmonicContext\ContextualReranker(
                $app->make(\App\Services\HarmonicContext\DiminishedResolver::class),
                $app->make(\App\Services\HarmonicContext\PedalDetector::class),
                $app->make(\App\Services\HarmonicContext\HarmonicPatternMatcher::class),
                $app->make(\App\Services\HarmonicContext\DiminishedAsDominantResolver::class),
                $app->make(\App\Services\Identifier\TransitionScorer::class),
                // Lazy: VoicingCrossref resolves this reranker, so a direct
                // dependency would be circular. Same pattern as PedalDetector above.
                fn(...$args) => $app->make(\App\Services\VoicingCrossref::class)->identifyWithPinnedRoot(...$args)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Share pending voicing drafts count with the admin layout (sidebar badge)
        View::composer('layouts.admin', function ($view) {
            $count = 0;
            if (Schema::hasTable('sbn_voicing_drafts')) {
                $count = \App\Models\VoicingDraft::pending()->count();
            }
            $view->with('pendingDraftsCount', $count > 0 ? $count : null);

            $unread = null;
            if (Schema::hasTable('messages') && auth()->check()) {
                $unread = \App\Services\AccountService::unreadCountFor(auth()->user());
                $unread = $unread > 0 ? $unread : null;
            }
            $view->with('adminUnreadCount', $unread);
        });
    }
}
