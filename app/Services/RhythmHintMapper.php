<?php

namespace App\Services;

class RhythmHintMapper
{
    protected array $map = [
        'bossa'      => 'joao-gilberto-bossa',
        'samba'      => 'samba-basic',
        'ballad'     => 'ballad-quarter',
        'shuffle'    => 'blues-shuffle',
        'swing'      => 'swing-comping',
        'rock'       => 'rock-eighth',
        'pop'        => 'pop-strum',
        'folk'       => 'folk-strum',
        'reggae'     => 'reggae-skank',
        'waltz'      => 'waltz-strum',
    ];

    /**
     * @param ?string $hint LLM rhythm_hint string ('bossa nova', 'shuffle', etc.)
     * @return ?string slug of best-matching RhythmPattern, or null
     */
    public function map(?string $hint): ?string
    {
        if (empty($hint)) {
            return null;
        }

        $lowerHint = strtolower($hint);

        foreach ($this->map as $keyword => $slug) {
            if (str_contains($lowerHint, $keyword)) {
                return $slug;
            }
        }

        return null;
    }
}
