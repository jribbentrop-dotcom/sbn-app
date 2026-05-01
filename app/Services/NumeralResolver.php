<?php

namespace App\Services;

class NumeralResolver
{
    protected HarmonicContext $context;

    public function __construct(HarmonicContext $context)
    {
        $this->context = $context;
    }

    /**
     * Check if a token is a Roman numeral.
     */
    public function isNumeral(string $token): bool
    {
        return (bool) preg_match('/^(b|#)?(III|iii|VII|vii|II|ii|IV|iv|VI|vi|I|i|V|v)(m|maj|dim|aug|sus|7|9|11|13|b|#|\/|\(|\)|add|[0-9])*$/', $token);
    }


    /**
     * Resolve all Roman numerals in a sequence of items.
     */
    public function resolveSequenceItems(array $items, string $key, bool $isBars): array
    {
        if ($isBars) {
            foreach ($items as $barIdx => $barChords) {
                if (!is_array($barChords)) continue;
                foreach ($barChords as $chordIdx => $chord) {
                    if ($this->isNumeral($chord)) {
                        $resolved = $this->context->numeralToChordName($chord, $key);
                        $items[$barIdx][$chordIdx] = $resolved ?: $chord;
                    }
                }
            }
        } else {
            foreach ($items as $chordIdx => $chord) {
                if ($this->isNumeral($chord)) {
                    $resolved = $this->context->numeralToChordName($chord, $key);
                    $items[$chordIdx] = $resolved ?: $chord;
                }
            }
        }

        return $items;
    }
}
