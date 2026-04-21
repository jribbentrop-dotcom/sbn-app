<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductTag;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // Create sample categories
        $bossaCat = ProductCategory::create([
            'slug' => 'bossa-nova',
            'name' => 'Bossa Nova',
        ]);

        $intermediateCat = ProductCategory::create([
            'slug' => 'intermediate',
            'name' => 'Intermediate',
            'parent_id' => $bossaCat->id,
        ]);

        $beginnerCat = ProductCategory::create([
            'slug' => 'beginner',
            'name' => 'Beginner',
            'parent_id' => $bossaCat->id,
        ]);

        // Create sample tags
        $tabsTag = ProductTag::create([
            'slug' => 'tabs',
            'name' => 'Tabs',
        ]);

        $chordsTag = ProductTag::create([
            'slug' => 'chords',
            'name' => 'Chords',
        ]);

        // Create sample products for testing
        $sampleProducts = [
            [
                'slug' => 'corcovado',
                'title' => 'Corcovado (Quiet Nights) - Guitar Tablature',
                'excerpt' => 'Classic Antonio Carlos Jobim composition with fingerstyle arrangement',
                'price_cents' => 499,
                'thumbnail_path' => null,
                'attributes' => ['notation' => ['tabs', 'chord-grids'], 'pages' => '4'],
                'meta_description' => 'Learn Corcovado with this professional guitar tablature. Bossa Nova classic.',
            ],
            [
                'slug' => 'girl-from-ipanema',
                'title' => 'The Girl from Ipanema - Complete Arrangement',
                'excerpt' => 'The most famous bossa nova song, arranged for solo guitar',
                'price_cents' => 599,
                'thumbnail_path' => null,
                'attributes' => ['notation' => ['tabs', 'chord-grids', 'standard-notation'], 'pages' => '6'],
                'meta_description' => 'Master The Girl from Ipanema with this comprehensive guitar tablature.',
            ],
            [
                'slug' => 'desafinado',
                'title' => 'Desafinado - Guitar Solo',
                'excerpt' => 'Intermediate level arrangement of this Jobim classic',
                'price_cents' => 399,
                'thumbnail_path' => null,
                'attributes' => ['notation' => ['tabs'], 'pages' => '3'],
                'meta_description' => 'Learn Desafinado with this intermediate guitar tablature.',
            ],
            [
                'slug' => 'wave',
                'title' => 'Wave - Bossa Nova Standard',
                'excerpt' => 'Beautiful Jobim composition with walking bass line',
                'price_cents' => 549,
                'thumbnail_path' => null,
                'attributes' => ['notation' => ['tabs', 'chord-grids'], 'pages' => '5'],
                'meta_description' => 'Master Wave by Antonio Carlos Jobim with this detailed tablature.',
            ],
            [
                'slug' => 'how-insensitive',
                'title' => 'How Insensitive (Insensatez) - Guitar Arrangement',
                'excerpt' => 'Emotional bossa nova ballad with sophisticated harmony',
                'price_cents' => 499,
                'thumbnail_path' => null,
                'attributes' => ['notation' => ['tabs', 'chord-grids'], 'pages' => '4'],
                'meta_description' => 'Learn How Insensitive with this professional guitar tablature.',
            ],
        ];

        foreach ($sampleProducts as $productData) {
            $product = Product::create([
                ...$productData,
                'status' => 'published',
                'published_at' => now(),
            ]);

            // Attach categories
            if ($productData['slug'] === 'desafinado') {
                $product->categories()->attach([$bossaCat->id, $intermediateCat->id]);
            } else {
                $product->categories()->attach([$bossaCat->id]);
            }

            // Attach tags
            $product->tags()->attach([$tabsTag->id, $chordsTag->id]);
        }

        $this->command->info('Created ' . count($sampleProducts) . ' sample products');
    }
}
