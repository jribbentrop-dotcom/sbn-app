<?php

namespace App\Http\Controllers;

use App\Models\SkillNode;
use App\Services\SkillGradeService;
use Inertia\Inertia;

/**
 * Public skill glossary (/skills): every skill node listed alphabetically with a
 * one-sentence blurb — a dictionary of the curriculum's abilities. Anchored by
 * slug (<div id="{slug}">) so links elsewhere can deep-link to a specific entry.
 * No auth: this is a reference/marketing surface, not per-student data.
 */
class SkillGlossaryController extends Controller
{
    public function index()
    {
        $skills = SkillNode::orderByRaw('LOWER(title)')
            ->get(['id', 'slug', 'title', 'branch', 'sub_branch', 'grade', 'description', 'icon_key', 'icon_path'])
            ->map(fn (SkillNode $n) => [
                'slug'        => $n->slug,
                'title'       => $n->title,
                'branch'      => $n->branch,
                'subBranch'   => $n->sub_branch,
                'grade'       => $n->grade,
                'gradeLabel'  => $n->grade ? SkillGradeService::gradeLabel($n->grade) : null,
                'description' => $n->description,
                'iconKey'     => $n->icon_key,
                'iconPath'    => $n->icon_path,
            ])
            ->values();

        return Inertia::render('Skills/Glossary', [
            'skills' => $skills,
        ]);
    }
}
