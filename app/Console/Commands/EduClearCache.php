<?php

namespace App\Console\Commands;

use App\Services\EduContentService;
use Illuminate\Console\Command;

/**
 * Drop the cached `resources/edu/` topic set so freshly-edited markdown
 * surfaces on the next request.
 *
 * In the `local` env the cache is bypassed entirely (the service re-parses
 * every call), so this command is only needed in staging/production after
 * deploying edu content changes.
 */
class EduClearCache extends Command
{
    protected $signature = 'edu:clear-cache';

    protected $description = 'Clear the cached educational content (resources/edu) topic set';

    public function handle(EduContentService $edu): int
    {
        $edu->clearCache();
        $this->info('Edu content cache cleared.');

        return self::SUCCESS;
    }
}
