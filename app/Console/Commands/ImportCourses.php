<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use SimpleXMLElement;

class ImportCourses extends Command
{
    protected $signature = 'sbn:import-courses
        {--courses= : Path to courses WXR file (default: soulbossanova.WordPress.2026-05-07.xml)}
        {--lessons= : Path to lessons WXR file (default: soulbossanova.WordPress.2026-05-07 (1).xml)}
        {--dry-run  : Report what would be imported without writing to DB}';

    protected $description = 'Import courses and lessons from WordPress WXR export files';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $coursesFile = $this->option('courses')
            ?? base_path('soulbossanova.WordPress.2026-05-07.xml');
        $lessonsFile = $this->option('lessons')
            ?? base_path('soulbossanova.WordPress.2026-05-07 (1).xml');

        if (!file_exists($coursesFile)) {
            $this->error("Courses file not found: $coursesFile");
            return 1;
        }
        if (!file_exists($lessonsFile)) {
            $this->error("Lessons file not found: $lessonsFile");
            return 1;
        }

        $this->info("Parsing courses from: " . basename($coursesFile));
        $courseItems = $this->parseWxr($coursesFile, 'sbn_course');
        $this->info("Parsing lessons from: " . basename($lessonsFile));
        $lessonItems = $this->parseWxr($lessonsFile, 'sbn_lesson');

        $this->info("Found {$courseItems->count()} courses, {$lessonItems->count()} lessons");

        if ($dryRun) {
            $this->previewCourses($courseItems);
            $this->previewLessons($lessonItems, $courseItems);
            $this->info('[dry-run] No changes written.');
            return 0;
        }

        $courseMap = $this->importCourses($courseItems);
        $this->importLessons($lessonItems, $courseMap);

        $this->info('Done. Courses: ' . Course::count() . ', Lessons: ' . Lesson::count());
        return 0;
    }

    // =========================================================================
    // PARSING
    // =========================================================================

    private function parseWxr(string $path, string $postType): \Illuminate\Support\Collection
    {
        $xml = simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NOCDATA);
        $xml->registerXPathNamespace('wp', 'http://wordpress.org/export/1.2/');
        $xml->registerXPathNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
        $xml->registerXPathNamespace('excerpt', 'http://wordpress.org/export/1.2/excerpt/');

        $items = collect();
        foreach ($xml->channel->item as $item) {
            $item->registerXPathNamespace('wp', 'http://wordpress.org/export/1.2/');
            $item->registerXPathNamespace('content', 'http://purl.org/rss/1.0/modules/content/');

            $type = (string) $item->xpath('wp:post_type')[0];
            if ($type !== $postType) {
                continue;
            }

            $meta = [];
            foreach ($item->xpath('wp:postmeta') as $m) {
                $m->registerXPathNamespace('wp', 'http://wordpress.org/export/1.2/');
                $key = (string) $m->xpath('wp:meta_key')[0];
                $val = (string) $m->xpath('wp:meta_value')[0];
                $meta[$key] = $val;
            }

            $terms = [];
            foreach ($item->category as $cat) {
                $domain   = (string) $cat['domain'];
                $nicename = (string) $cat['nicename'];
                $terms[$domain][] = $nicename;
            }

            $encoded = $item->xpath('content:encoded');
            $content  = $encoded ? (string) $encoded[0] : '';
            $excerptNodes = $item->xpath('excerpt:encoded');
            $excerpt = $excerptNodes ? (string) $excerptNodes[0] : '';

            $items->push([
                'wp_id'       => (int) (string) $item->xpath('wp:post_id')[0],
                'title'       => (string) $item->title,
                'slug'        => (string) $item->xpath('wp:post_name')[0],
                'status'      => (string) $item->xpath('wp:status')[0],
                'menu_order'  => (int) (string) $item->xpath('wp:menu_order')[0],
                'content'     => $content,
                'excerpt'     => $excerpt,
                'meta'        => $meta,
                'terms'       => $terms,
            ]);
        }

        return $items;
    }

    // =========================================================================
    // IMPORT
    // =========================================================================

    /** Returns map of WP post ID → local Course model */
    private function importCourses(\Illuminate\Support\Collection $items): array
    {
        // WC product ID → sbn_products.id lookup (only if wc_id column exists)
        $productsByWcId = \Illuminate\Support\Facades\Schema::hasColumn('sbn_products', 'wc_id')
            ? Product::whereNotNull('wc_id')->pluck('id', 'wc_id')->all()
            : [];

        $courseMap = []; // wp_id => Course

        foreach ($items as $item) {
            $meta   = $item['meta'];
            $terms  = $item['terms'];
            $genres = $terms['course_genre'] ?? [];
            $levels = $terms['course_level'] ?? [];

            $wcProductId = $meta['_sbn_product_id'] ?? null;
            $productId   = ($wcProductId && isset($productsByWcId[$wcProductId]))
                ? $productsByWcId[$wcProductId]
                : null;

            $topics = array_filter(
                explode(',', $meta['_sbn_topics'] ?? ''),
                fn($t) => $t !== ''
            );

            $isFree = !empty($meta['_sbn_is_free']) || empty($wcProductId);

            $course = Course::updateOrCreate(
                ['wp_id' => $item['wp_id']],
                [
                    'slug'       => $item['slug'] ?: Str::slug($item['title']),
                    'title'      => $item['title'],
                    'excerpt'    => $item['excerpt'] ?: null,
                    'description' => $item['content'] ?: null,
                    'genres'     => $genres ?: null,
                    'levels'     => $levels ?: null,
                    'style'      => $meta['_sbn_style'] ?? null,
                    'level'      => $meta['_sbn_level'] ?? null,
                    'topics'     => $topics ?: null,
                    'is_free'    => $isFree,
                    'product_id' => $productId,
                    'status'     => $item['status'],
                ]
            );

            $courseMap[$item['wp_id']] = $course;
            $this->line("  Course [{$item['status']}] {$item['title']} (wp_id={$item['wp_id']})");
        }

        return $courseMap;
    }

    private function importLessons(\Illuminate\Support\Collection $items, array $courseMap): void
    {
        // Secondary lookup: course slug → Course model
        $coursesBySlug = collect($courseMap)->keyBy(fn($c) => $c->slug);

        $linked   = 0;
        $unlinked = 0;

        foreach ($items as $item) {
            $meta = $item['meta'];

            // Strategy 1: match by _sbn_course_id (WP post ID)
            $wpCourseId = $meta['_sbn_course_id'] ?? null;
            $course = $wpCourseId ? ($courseMap[(int)$wpCourseId] ?? null) : null;

            // Strategy 2: match by _sbn_course_slug
            if (!$course) {
                $courseSlug = $meta['_sbn_course_slug'] ?? null;
                $course = $courseSlug ? ($coursesBySlug[$courseSlug] ?? null) : null;
            }

            if (!$course) {
                $this->warn("  UNLINKED lesson: {$item['title']} (wp_id={$item['wp_id']})");
                $unlinked++;
                continue;
            }

            // Clean Gutenberg block comment wrappers from content
            $content = $this->cleanGutenbergContent($item['content']);

            Lesson::updateOrCreate(
                ['wp_id' => $item['wp_id']],
                [
                    'course_id'     => $course->id,
                    'slug'          => $item['slug'] ?: Str::slug($item['title']),
                    'title'         => $item['title'],
                    'content'       => $content,
                    'section_title' => $meta['_sbn_section_title'] ?? null ?: null,
                    'is_preview'    => !empty($meta['_sbn_is_preview']),
                    'sort_order'    => $item['menu_order'],
                    'status'        => $item['status'],
                ]
            );

            $linked++;
            $this->line("  Lesson [{$item['status']}] {$item['title']} → {$course->slug}");
        }

        $this->info("Lessons linked: $linked, unlinked: $unlinked");
    }

    // =========================================================================
    // DRY RUN PREVIEW
    // =========================================================================

    private function previewCourses(\Illuminate\Support\Collection $items): void
    {
        $this->table(
            ['WP ID', 'Status', 'Title', 'Slug', 'Genres', 'Levels', 'Free'],
            $items->map(fn($i) => [
                $i['wp_id'],
                $i['status'],
                $i['title'],
                $i['slug'],
                implode(',', $i['terms']['course_genre'] ?? []),
                implode(',', $i['terms']['course_level'] ?? []),
                !empty($i['meta']['_sbn_is_free']) ? 'yes' : '',
            ])
        );
    }

    private function previewLessons(
        \Illuminate\Support\Collection $lessons,
        \Illuminate\Support\Collection $courses
    ): void {
        $coursesByWpId   = $courses->keyBy('wp_id');
        $coursesBySlug   = $courses->keyBy('slug');

        $rows = $lessons->map(function ($l) use ($coursesByWpId, $coursesBySlug) {
            $meta     = $l['meta'];
            $wpCourse = $meta['_sbn_course_id'] ?? null;
            $slugRef  = $meta['_sbn_course_slug'] ?? null;
            $course   = ($wpCourse ? $coursesByWpId[$wpCourse] ?? null : null)
                     ?? ($slugRef  ? $coursesBySlug[$slugRef]  ?? null : null);

            return [
                $l['wp_id'],
                $l['status'],
                $l['title'],
                $course ? $course['slug'] : '⚠ UNLINKED',
                $l['menu_order'],
            ];
        });

        $this->table(['WP ID', 'Status', 'Title', 'Course', 'Order'], $rows);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function cleanGutenbergContent(string $content): string
    {
        // Strip <!-- wp:... --> and <!-- /wp:... --> block comments
        $content = preg_replace('/<!--\s*\/?\s*wp:[^>]*-->/s', '', $content);
        return trim($content);
    }
}
