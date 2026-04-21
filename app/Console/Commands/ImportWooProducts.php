<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductTag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use SimpleXMLElement;

class ImportWooProducts extends Command
{
    protected $signature = 'sbn:import-wc-products
                            {path : Path to the WXR XML file}
                            {--dry-run : Show what would be imported without writing to database}
                            {--download-pdfs : Download PDF files from original URLs}
                            {--sleep=1 : Seconds to sleep between PDF downloads (default: 1)}';

    protected $description = 'Import WooCommerce products from WXR XML export';

    private array $stats = [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0,
        'pdfs_downloaded' => 0,
        'pdfs_failed' => 0,
    ];

    private array $attachmentIndex = [];
    private array $termIndex = [];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (!file_exists($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $this->info('Parsing WXR XML file...');

        $xml = simplexml_load_file($path);
        if ($xml === false) {
            $this->error('Failed to parse XML file');
            return self::FAILURE;
        }

        // Register namespaces
        $namespaces = $xml->getNamespaces(true);
        $wp = $namespaces['wp'] ?? 'http://wordpress.org/export/1.2/';
        $content = $namespaces['content'] ?? 'http://purl.org/rss/1.0/modules/content/';
        $excerpt = $namespaces['excerpt'] ?? 'http://wordpress.org/export/1.2/excerpt/';

        // Build term index first (for hierarchical categories)
        $this->buildTermIndex($xml, $wp);

        // Build attachment index
        $this->buildAttachmentIndex($xml, $wp);

        // Process products
        $this->info('Processing products...');
        $bar = $this->output->createProgressBar(count($xml->channel->item));

        foreach ($xml->channel->item as $item) {
            $postType = (string) $item->children($wp)->post_type;
            $status = (string) $item->children($wp)->status;

            // Only process published products
            if ($postType !== 'product' || $status !== 'publish') {
                $this->stats['skipped']++;
                $bar->advance();
                continue;
            }

            $this->processProduct($item, $wp, $content, $excerpt);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // Download PDFs if requested
        if ($this->option('download-pdfs')) {
            $this->downloadPdfs($this->option('sleep'));
        }

        // Print stats
        $this->info("Import complete:");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Products Created', $this->stats['created']],
                ['Products Updated', $this->stats['updated']],
                ['Products Skipped', $this->stats['skipped']],
                ['Products Failed', $this->stats['failed']],
                ['PDFs Downloaded', $this->stats['pdfs_downloaded']],
                ['PDFs Failed', $this->stats['pdfs_failed']],
            ]
        );

        return self::SUCCESS;
    }

    private function buildTermIndex(SimpleXMLElement $xml, string $wp): void
    {
        $this->info('Building term index...');

        if (!isset($xml->channel->children($wp)->term)) {
            return;
        }

        foreach ($xml->channel->children($wp)->term as $term) {
            $termId = (int) $term->term_id;
            $taxonomy = (string) $term->term_taxonomy;
            $slug = (string) $term->term_slug;
            $name = (string) $term->term_name;
            $parent = (int) ($term->term_parent ?? 0);

            $this->termIndex[$taxonomy][$slug] = [
                'id' => $termId,
                'name' => $name,
                'parent' => $parent,
            ];
        }
    }

    private function buildAttachmentIndex(SimpleXMLElement $xml, string $wp): void
    {
        $this->info('Building attachment index...');

        foreach ($xml->channel->item as $item) {
            $postType = (string) $item->children($wp)->post_type;

            if ($postType === 'attachment') {
                $postId = (int) $item->children($wp)->post_id;
                $url = (string) $item->guid;
                $this->attachmentIndex[$postId] = $url;
            }
        }

        $this->info('Found ' . count($this->attachmentIndex) . ' attachments');
    }

    private function processProduct(
        SimpleXMLElement $item,
        string $wp,
        string $content,
        string $excerpt
    ): void {
        $isDryRun = $this->option('dry-run');

        // Extract basic data
        $title = (string) $item->title;
        $slug = (string) $item->children($wp)->post_name;
        $postId = (int) $item->children($wp)->post_id;
        $description = (string) $item->children($content)->encoded;
        $shortDescription = (string) $item->children($excerpt)->encoded;
        $publishedAt = (string) $item->children($wp)->post_date;

        // Get price from meta
        $price = $this->getPostMeta($item, $wp, '_price');
        $priceCents = $price ? (int) round((float) $price * 100) : 0;

        // Get thumbnail ID
        $thumbnailId = $this->getPostMeta($item, $wp, '_thumbnail_id');
        $thumbnailPath = null;

        // Check if thumbnail exists locally
        if ($thumbnailId && isset($this->attachmentIndex[(int) $thumbnailId])) {
            $possiblePath = 'products/thumbnails/' . $slug . '.webp';
            if (Storage::disk('public')->exists($possiblePath)) {
                $thumbnailPath = $possiblePath;
            }
        }

        // Get downloadable files
        $downloadableFiles = $this->getPostMeta($item, $wp, '_downloadable_files');
        $pdfOriginalUrl = null;
        $pdfFilename = null;

        if ($downloadableFiles) {
            $files = $this->maybeUnserialize($downloadableFiles);
            if (is_array($files) && !empty($files)) {
                $firstFile = reset($files);
                $pdfOriginalUrl = $firstFile['file'] ?? null;
                $pdfFilename = $firstFile['name'] ?? null;
            }
        }

        // Get attributes
        $attributes = [];
        $notation = $this->getPostMeta($item, $wp, 'pa_notation');
        $pages = $this->getPostMeta($item, $wp, 'pa_pages');

        if ($notation) {
            $attributes['notation'] = explode('|', $notation);
        }
        if ($pages) {
            $attributes['pages'] = $pages;
        }

        // Get SEO description if available (Yoast or similar)
        $metaDescription = $this->getPostMeta($item, $wp, '_yoast_wpseo_metadesc');

        // Prepare product data
        $productData = [
            'title' => $title,
            'excerpt' => $shortDescription ?: null,
            'description' => $description ?: null,
            'price_cents' => $priceCents,
            'thumbnail_path' => $thumbnailPath,
            'pdf_filename' => $pdfFilename,
            'pdf_original_url' => $pdfOriginalUrl,
            'attributes' => $attributes ?: null,
            'meta_description' => $metaDescription ?: null,
            'wp_post_id' => $postId,
            'published_at' => $publishedAt,
            'status' => 'published',
        ];

        if ($isDryRun) {
            $this->line("[DRY-RUN] Would import: {$title} ({$slug})");
            return;
        }

        try {
            // Upsert product
            $product = Product::updateOrCreate(
                ['slug' => $slug],
                $productData
            );

            if ($product->wasRecentlyCreated) {
                $this->stats['created']++;
            } else {
                $this->stats['updated']++;
            }

            // Sync categories
            $categoryIds = $this->extractCategoryIds($item, $wp);
            $product->categories()->sync($categoryIds);

            // Sync tags
            $tagIds = $this->extractTagIds($item, $wp);
            $product->tags()->sync($tagIds);

        } catch (\Exception $e) {
            $this->error("Failed to import {$slug}: " . $e->getMessage());
            $this->stats['failed']++;
        }
    }

    private function extractCategoryIds(SimpleXMLElement $item, string $wp): array
    {
        $ids = [];

        foreach ($item->category as $category) {
            $domain = (string) $category['domain'];
            if ($domain !== 'product_cat') {
                continue;
            }

            $slug = (string) $category['nicename'];
            $name = (string) $category;

            // Get parent from term index
            $parentId = null;
            if (isset($this->termIndex['product_cat'][$slug]['parent'])) {
                $wpParentId = $this->termIndex['product_cat'][$slug]['parent'];
                if ($wpParentId > 0) {
                    // Find parent category by wp_post_id
                    $parentCat = ProductCategory::where('wp_post_id', $wpParentId)->first();
                    if ($parentCat) {
                        $parentId = $parentCat->id;
                    }
                }
            }

            $categoryModel = ProductCategory::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'parent_id' => $parentId,
                ]
            );

            $ids[] = $categoryModel->id;
        }

        return $ids;
    }

    private function extractTagIds(SimpleXMLElement $item, string $wp): array
    {
        $ids = [];

        foreach ($item->category as $category) {
            $domain = (string) $category['domain'];
            if ($domain !== 'product_tag') {
                continue;
            }

            $slug = (string) $category['nicename'];
            $name = (string) $category;

            $tag = ProductTag::firstOrCreate(
                ['slug' => $slug],
                ['name' => $name]
            );

            $ids[] = $tag->id;
        }

        return $ids;
    }

    private function getPostMeta(SimpleXMLElement $item, string $wp, string $key): ?string
    {
        foreach ($item->children($wp)->postmeta as $meta) {
            if ((string) $meta->meta_key === $key) {
                return (string) $meta->meta_value;
            }
        }

        return null;
    }

    private function maybeUnserialize(string $data)
    {
        if (empty($data)) {
            return null;
        }

        // Check if it's serialized
        $trimmed = trim($data);
        if (!str_starts_with($trimmed, 'a:') && !str_starts_with($trimmed, 's:') && !str_starts_with($trimmed, 'O:')) {
            return $data;
        }

        $result = @unserialize($data);
        return $result !== false ? $result : $data;
    }

    private function downloadPdfs(int $sleepSeconds): void
    {
        $this->info('Downloading PDFs...');

        $products = Product::whereNotNull('pdf_original_url')
            ->whereNull('pdf_path')
            ->get();

        $bar = $this->output->createProgressBar($products->count());

        foreach ($products as $product) {
            $url = $product->pdf_original_url;
            $filename = $product->slug . '.pdf';
            $path = 'products/pdfs/' . $filename;

            try {
                $response = Http::timeout(30)->get($url);

                if ($response->successful()) {
                    Storage::disk('local')->put($path, $response->body());
                    $product->update(['pdf_path' => $path]);
                    $this->stats['pdfs_downloaded']++;
                } else {
                    $this->stats['pdfs_failed']++;
                    $this->warn("Failed to download PDF for {$product->slug}: HTTP {$response->status()}");
                }
            } catch (\Exception $e) {
                $this->stats['pdfs_failed']++;
                $this->warn("Error downloading PDF for {$product->slug}: " . $e->getMessage());
            }

            $bar->advance();

            if ($sleepSeconds > 0) {
                sleep($sleepSeconds);
            }
        }

        $bar->finish();
        $this->newLine();
    }
}
