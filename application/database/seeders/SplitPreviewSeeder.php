<?php

namespace Database\Seeders;

use App\Models\ProductionOrder;
use App\Models\TechPack;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * A job to look at, for the preview copy of the shop.
 *
 * One order carrying a three-piece team kit, far enough along that both sheets
 * exist: the sample the client approved, and the batch sheet opened from it
 * with all three designs on the mockup carousel.
 *
 * Preview data only. This seeder is never run against the shop's own database.
 */
class SplitPreviewSeeder extends Seeder
{
    public function run(): void
    {
        $officer = User::where('job_role', User::ROLE_SALES)->firstOrFail();
        $artist = User::where('job_role', User::JOB_ARTIST)->firstOrFail();

        $order = ProductionOrder::updateOrCreate(
            ['order_number' => 'IC2026-08001'],
            [
                'customer_name' => 'Team Lewy',
                'product_type' => 'riding_jersey',
                'quantity' => 12,
                'due_date' => now()->addWeeks(2),
                'created_by' => $officer->id,
                'status' => 'active',
            ],
        );

        $order->items()->delete();
        foreach (['L' => 6, 'XL' => 6] as $size => $qty) {
            $order->items()->create(['size' => $size, 'quantity' => $qty]);
        }

        $order->jobOrder()->updateOrCreate(
            ['production_order_id' => $order->id],
            [
                'status' => 'sent_to_artist',
                'created_by' => $officer->id,
                'print_type' => 'full_sublimation',
                'printer' => 'atexco',
                'fabric' => 'Quiana',
                'neck' => 'Round neck',
                'cuff_arm_sleeves' => 'Tupi',
                'neck_label' => 'IC woven label',
                'packaging' => 'Polybag',
                'bottom_hem' => 'Straight hem',
                'free_logo_sticker' => 'IC sticker',
            ],
        );

        $order->tasks()->delete();
        $order->buildPipeline([], null);

        // The sample has been drawn, approved and held by the client.
        $order->tasks()->whereIn('stage', [1, 2])->update([
            'status' => 'complete', 'approved_at' => now(), 'assigned_to' => $artist->id,
        ]);

        // The batch sheet is the artist's now.
        $batch = $order->tasks()
            ->where('department', ProductionOrder::STEP_TECH_PACK_MASSPROD)
            ->first();

        $batch?->update([
            'assigned_to' => $artist->id,
            'status' => 'in_progress',
            'released_at' => now(),
        ]);

        // The sample's own deadline: three days, four because this is a jersey.
        $order->forceFill(['sample_due_date' => now()->addDays($order->sampleLeadDays())])->save();

        $spec = [
            'design_name' => 'Team Lewy',
            'fitting' => 'Original fit',
            'item_style' => 'Riding jersey',
            'tshirt_color' => 'Navy',
            'thread_color' => 'White',
            'zipper_type' => 'Nylon',
            'lip_pocket_color' => 'N/A',
            'placing_title' => 'Standard sublimation placing for the kit',
            'tag_1_details' => 'IC woven on right body hem',
            'file_location_notes' => 'FOR PRINT\\IC2026-08001\\TEAM LEWY',
        ];

        $sample = $order->openTechPack(TechPack::PHASE_SAMPLE);
        $sample->fill($spec + ['image_uploads' => [
            'front_mockup' => $this->design('jersey', 'JERSEY'),
        ]])->save();

        // Three designs on one job, which is what the carousel is for.
        $batchPack = $order->fresh()->openTechPack(TechPack::PHASE_MASSPROD);
        $batchPack->fill(['image_uploads' => [
            'front_mockup' => $this->design('jersey', 'JERSEY'),
            'mockup_2' => $this->design('jacket', 'JACKET'),
            'mockup_3' => $this->design('shorts', 'SHORTS'),
        ]])->save();
    }

    /** A stand-in garment picture, written to the same private disk an upload uses. */
    private function design(string $key, string $label): array
    {
        $art = match ($key) {
            'jacket' => '<path d="M75 60 L120 40 L150 70 L180 40 L225 60 L245 115 L215 130 L215 305 L85 305 L85 130 L55 115 Z"'
                .' fill="#111827" stroke="#111" stroke-width="3"/>'
                .'<line x1="150" y1="70" x2="150" y2="305" stroke="#dc2626" stroke-width="5"/>',
            'shorts' => '<path d="M85 90 L215 90 L215 250 L165 250 L150 170 L135 250 L85 250 Z"'
                .' fill="#1e3a8a" stroke="#111" stroke-width="3"/>',
            default => '<path d="M80 60 L120 40 L150 60 L180 40 L220 60 L240 110 L210 125 L210 300 L90 300 L90 125 L60 110 Z"'
                .' fill="#1e3a8a" stroke="#111" stroke-width="3"/>'
                .'<rect x="120" y="150" width="60" height="45" fill="#dc2626"/>',
        };

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 380" width="300" height="380">'
            .'<rect width="300" height="380" fill="#f3f4f6"/>'.$art
            .'<text x="150" y="352" font-family="Arial" font-size="20" font-weight="bold"'
            .' text-anchor="middle" fill="#111">'.$label.'</text></svg>';

        $path = 'tech-pack-images/preview-'.$key.'.svg';
        Storage::disk('local')->put($path, $svg);

        return ['path' => $path, 'name' => $key.'.svg'];
    }
}
