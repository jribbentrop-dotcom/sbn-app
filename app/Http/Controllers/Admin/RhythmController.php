<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class RhythmController extends Controller
{
    public function index()
    {
        $rhythms = DB::table('sbn_rhythm_patterns')
            ->orderBy('name')
            ->paginate(25);

        return view('admin.rhythms.index', compact('rhythms'));
    }
}
