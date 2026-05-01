<?php

namespace App\Console\Commands;

use App\Services\VoicingCrossref;
use Illuminate\Console\Command;

class VerifyGm7 extends Command
{
    protected $signature = 'sbn:verify-gm7';
    protected $description = 'Verify Gm7(11) chord analysis';

    public function handle(VoicingCrossref $crossref): int
    {
        $this->info("=== Verifying Gm7(11) chord analysis ===");
        
        $this->info("Gm7(11) should contain:");
        $this->info("Root: G");
        $this->info("Minor third: Bb");
        $this->info("Fifth: D");
        $this->info("Minor seventh: F");
        $this->info("Eleventh: C");
        $this->info("");
        
        $this->info("Notes in xx5768: G3 D4 F4 C5");
        $this->info("Pitch classes: [7, 2, 5, 0]");
        $this->info("");
        
        $this->info("Analysis:");
        $this->info("- G (root): ✓ Present");
        $this->info("- D (fifth): ✓ Present");
        $this->info("- F (minor seventh): ✓ Present");
        $this->info("- C (eleventh): ✓ Present");
        $this->info("- Bb (minor third): ✗ MISSING!");
        $this->info("");
        
        $this->info("Without Bb, this cannot be Gm7(11).");
        $this->info("The correct interpretation is Csus4(9)/G:");
        $this->info("- C (root): C + F + G + D = Csus4");
        $this->info("- 9th extension: D");
        $this->info("- G bass: /G");
        $this->info("");
        
        $result = $crossref->identifyFromFrets('xx5768');
        $this->info("Current result: {$result['name']}");
        
        return self::SUCCESS;
    }
}
