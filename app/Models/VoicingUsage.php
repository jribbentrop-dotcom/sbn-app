<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoicingUsage extends Model
{
    protected $table = 'sbn_voicing_usage';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'position' => 'integer',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function diagram()
    {
        return $this->belongsTo(ChordDiagram::class, 'chord_diagram_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeForLeadsheet($query, ?int $leadsheetId)
    {
        return $leadsheetId ? $query->where('leadsheet_id', $leadsheetId) : $query;
    }

    public function scopeForDiagram($query, ?int $diagramId)
    {
        return $diagramId ? $query->where('chord_diagram_id', $diagramId) : $query;
    }

    public function scopeCategory($query, ?string $category)
    {
        return $category ? $query->where('voicing_category', $category) : $query;
    }

    public function scopeQuality($query, ?string $quality)
    {
        return $quality ? $query->where('quality', $quality) : $query;
    }
}
