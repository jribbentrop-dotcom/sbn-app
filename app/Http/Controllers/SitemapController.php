<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Product;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $urls = $this->buildUrls();

        $xml = $this->renderXml($urls);

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    private function buildUrls(): array
    {
        $base = rtrim(config('app.url'), '/');
        $now  = now()->toAtomString();

        $urls = [];

        // ── Static pages ──────────────────────────────────────────────────────
        // NOTE: /library/*, /theory and per-song /library/songs/{slug} are
        // deliberately NOT listed here — those routes sit behind the `auth`
        // middleware (beta account-wall, see routes/web.php + CLAUDE.md
        // "Content access model"). Googlebot hits an auth redirect on them,
        // so submitting them just produces "submitted URL redirected" noise
        // in Search Console. Re-add if/when those routes are opened to guests.
        $statics = [
            ['loc' => $base . '/',                    'priority' => '1.0', 'changefreq' => 'weekly'],
            ['loc' => $base . '/skills',               'priority' => '0.6', 'changefreq' => 'monthly'],
            ['loc' => $base . '/grades',               'priority' => '0.6', 'changefreq' => 'monthly'],
            ['loc' => $base . '/learn',               'priority' => '0.9', 'changefreq' => 'weekly'],
            ['loc' => $base . '/shop',                'priority' => '0.8', 'changefreq' => 'weekly'],
            ['loc' => $base . '/top10/bossa-nova-songs',   'priority' => '0.9', 'changefreq' => 'monthly'],
            ['loc' => $base . '/top10/bossa-nova-chords',  'priority' => '0.9', 'changefreq' => 'monthly'],
            ['loc' => $base . '/top10/latin-jazz-standards','priority' => '0.8', 'changefreq' => 'monthly'],
            ['loc' => $base . '/contact',             'priority' => '0.5', 'changefreq' => 'yearly'],
        ];

        foreach ($statics as $s) {
            $urls[] = array_merge(['lastmod' => $now], $s);
        }

        // ── Courses ─────────────────────────────────────────────────────────────
        // Public catalog + detail live at /learn and /learn/{slug} — NOT /courses
        // (that path is the auth-gated account/admin route, see routes/web.php).
        Course::where('status', 'publish')
            ->select(['slug', 'updated_at'])
            ->chunk(100, function ($rows) use (&$urls, $base) {
                foreach ($rows as $row) {
                    $urls[] = [
                        'loc'        => $base . '/learn/' . $row->slug,
                        'lastmod'    => $row->updated_at?->toAtomString() ?? now()->toAtomString(),
                        'priority'   => '0.8',
                        'changefreq' => 'monthly',
                    ];
                }
            });

        // ── Shop products ─────────────────────────────────────────────────────
        if (class_exists(Product::class)) {
            Product::published()
                ->select(['slug', 'updated_at'])
                ->chunk(100, function ($rows) use (&$urls, $base) {
                    foreach ($rows as $row) {
                        $urls[] = [
                            'loc'        => $base . '/shop/product/' . $row->slug,
                            'lastmod'    => $row->updated_at?->toAtomString() ?? now()->toAtomString(),
                            'priority'   => '0.7',
                            'changefreq' => 'monthly',
                        ];
                    }
                });
        }

        return $urls;
    }

    private function renderXml(array $urls): string
    {
        $lines = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($urls as $u) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . e($u['loc']) . '</loc>';
            $lines[] = '    <lastmod>' . $u['lastmod'] . '</lastmod>';
            $lines[] = '    <changefreq>' . $u['changefreq'] . '</changefreq>';
            $lines[] = '    <priority>' . $u['priority'] . '</priority>';
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines);
    }
}
