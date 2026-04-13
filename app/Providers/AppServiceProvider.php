<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
        });
    }
}
