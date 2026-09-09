<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Give every design its own instructions instead of one note for a whole brief. */
    public function up(): void
    {
        Schema::table('inquiry_designs', function (Blueprint $table) {
            $table->text('description')->nullable()->after('files');
        });

        // Existing briefs had one shared note. Copy it to each existing design
        // so no artist loses instructions during the changeover.
        DB::table('inquiry_designs')
            ->join('inquiries', 'inquiries.id', '=', 'inquiry_designs.inquiry_id')
            ->whereNull('inquiry_designs.description')
            ->whereNotNull('inquiries.layout_reference_note')
            ->where('inquiries.layout_reference_note', '!=', '')
            ->select('inquiry_designs.id', 'inquiries.layout_reference_note')
            ->orderBy('inquiry_designs.id')
            ->each(fn ($design) => DB::table('inquiry_designs')->where('id', $design->id)->update([
                'description' => $design->layout_reference_note,
            ]));
    }

    public function down(): void
    {
        Schema::table('inquiry_designs', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
