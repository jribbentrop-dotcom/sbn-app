<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ContentHealthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function index(ContentHealthService $health)
    {
        $count = function (string $table, ?string $where = null) {
            if (!Schema::hasTable($table)) {
                return 0;
            }
            $query = DB::table($table);
            if ($where) {
                $query->whereRaw($where);
            }
            return $query->count();
        };

        $stats = [
            'leadsheets'    => $count('sbn_leadsheets'),
            'chords'        => $count('sbn_chord_diagrams'),
            'progressions'  => $count('sbn_chord_progressions'),
            'rhythms'       => $count('sbn_rhythm_patterns'),
            'voicing_usage' => $count('sbn_voicing_usage'),
            'drafts'        => $count('sbn_voicing_drafts', "status = 'pending'"),
            'courses'       => $count('sbn_courses'),
            'lessons'       => $count('sbn_lessons'),
            'skill_nodes'   => $count('sbn_skill_nodes'),
        ];

        $recentlyEdited = $this->recentlyEdited();
        $healthSummary  = $health->summary();

        return view('admin.dashboard.index', compact('stats', 'recentlyEdited', 'healthSummary'));
    }

    private function recentlyEdited(): array
    {
        $sources = [
            [
                'table'    => 'sbn_leadsheets',
                'type'     => 'Leadsheet',
                'name_col' => 'title',
                'route'    => 'admin.leadsheets.edit',
                'param'    => 'leadsheet',
            ],
            [
                'table'    => 'sbn_rhythm_patterns',
                'type'     => 'Rhythm',
                'name_col' => 'name',
                'route'    => 'admin.rhythms.edit',
                'param'    => 'rhythm',
            ],
            [
                'table'    => 'sbn_chord_diagrams',
                'type'     => 'Chord',
                'name_col' => 'name',
                'route'    => 'admin.chords.edit',
                'param'    => 'chord',
            ],
            [
                'table'    => 'sbn_courses',
                'type'     => 'Course',
                'name_col' => 'title',
                'route'    => 'admin.courses.edit',
                'param'    => 'course',
            ],
            [
                'table'    => 'sbn_lessons',
                'type'     => 'Lesson',
                'name_col' => 'title',
                'route'    => 'admin.lessons.edit',
                'param'    => 'lesson',
            ],
            [
                'table'    => 'sbn_skill_nodes',
                'type'     => 'Skill Node',
                'name_col' => 'title',
                'route'    => 'admin.skill-nodes.edit',
                'param'    => 'skillNode',
            ],
            [
                'table'    => 'sbn_chord_progressions',
                'type'     => 'Progression',
                'name_col' => 'name',
                'route'    => 'admin.progressions.edit',
                'param'    => 'progression',
            ],
        ];

        $items = [];

        foreach ($sources as $src) {
            if (!Schema::hasTable($src['table'])) {
                continue;
            }
            if (!Schema::hasColumn($src['table'], 'updated_at')) {
                continue;
            }

            $rows = DB::table($src['table'])
                ->select('id', $src['name_col'] . ' as title', 'slug', 'updated_at')
                ->orderByDesc('updated_at')
                ->limit(12)
                ->get();

            foreach ($rows as $row) {
                $items[] = [
                    'type'       => $src['type'],
                    'title'      => $row->title,
                    'slug'       => $row->slug ?? null,
                    'updated_at' => $row->updated_at,
                    'edit_url'   => route($src['route'], [$src['param'] => $row->id]),
                ];
            }
        }

        usort($items, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));

        return array_slice($items, 0, 12);
    }
}
