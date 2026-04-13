<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function index()
    {
        // Safe count helper — returns 0 if table doesn't exist
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
            'leadsheets'   => $count('sbn_leadsheets'),
            'chords'       => $count('sbn_chord_diagrams'),
            'progressions' => $count('sbn_chord_progressions'),
            'rhythms'      => $count('sbn_rhythm_patterns'),
            'voicing_usage'=> $count('sbn_voicing_usage'),
            'drafts'       => $count('sbn_voicing_drafts', "status = 'pending'"),
        ];

        return view('admin.dashboard.index', compact('stats'));
    }
}
