<?php

use App\Models\TechPack;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Older orders were created before Tech Packs were split into a sample
     * sheet and a separate mass-production sheet. Preserve the original as
     * the sample record and give those orders an independent batch copy.
     */
    public function up(): void
    {
        TechPack::query()
            ->where('phase', TechPack::PHASE_SAMPLE)
            ->with('order:id,skip_sample')
            ->orderBy('id')
            ->eachById(function (TechPack $sample): void {
                // Orders that intentionally skip sampling do not need a
                // sample-to-mass-production hand-off.
                if ($sample->order?->skip_sample) {
                    return;
                }

                $alreadyHasMassProductionSheet = TechPack::query()
                    ->where('production_order_id', $sample->production_order_id)
                    ->where('phase', TechPack::PHASE_MASSPROD)
                    ->exists();

                if (! $alreadyHasMassProductionSheet) {
                    TechPack::openMassprodFrom($sample);
                }
            });
    }

    public function down(): void
    {
        // The copies are production records; do not delete them on rollback.
    }
};
