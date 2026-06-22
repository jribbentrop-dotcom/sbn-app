<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sbn_leadsheets', function (Blueprint $table) {
            // is_pro: drives the "SBNpro" badge + "Open in viewer" CTA on the public
            // song page, and gates access to the full Viewer/Cinema experience
            // (tab, melody, synced playback). Every song now shows a free
            // chord/progression/rhythm reference page by default (see Show.vue);
            // only is_pro songs additionally expose the full arrangement.
            $table->boolean('is_pro')->default(false)->after('status');

            // license_status: the legal record for this title, kept separate from
            // is_pro on purpose — is_pro is an editorial/monetization switch,
            // license_status is the underlying fact that justifies flipping it.
            // is_pro should only ever be true when this is 'public_domain' or
            // 'cleared'. Not enforced at the DB level — treat as an admin
            // checklist item when reviewing a leadsheet.
            $table->string('license_status', 20)->default('unknown')->after('is_pro');
        });

        // Backfill: every leadsheet that is already publicly published today
        // already exposes its full Viewer/Cinema content with no gating, so
        // mark those is_pro=true to preserve current behavior. New/draft rows
        // (including the existing copyrighted standards kept as drafts) stay
        // is_pro=false until reviewed and explicitly cleared.
        DB::table('sbn_leadsheets')
            ->where('status', 'publish')
            ->update(['is_pro' => true]);
    }

    public function down(): void
    {
        Schema::table('sbn_leadsheets', function (Blueprint $table) {
            $table->dropColumn(['is_pro', 'license_status']);
        });
    }
};
