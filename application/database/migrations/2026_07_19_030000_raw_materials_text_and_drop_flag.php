<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw materials is a free-text spec on the job order (what materials are
     * needed), not a pipeline toggle. Sticker stays a toggle (needs_sticker).
     */
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('job_orders', 'raw_materials')) {
                $table->string('raw_materials')->nullable()->after('fabric');
            }
        });

        Schema::table('production_orders', function (Blueprint $table) {
            if (Schema::hasColumn('production_orders', 'needs_raw_materials')) {
                $table->dropColumn('needs_raw_materials');
            }
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            if (Schema::hasColumn('job_orders', 'raw_materials')) {
                $table->dropColumn('raw_materials');
            }
        });

        Schema::table('production_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('production_orders', 'needs_raw_materials')) {
                $table->boolean('needs_raw_materials')->default(true)->after('cutting_type');
            }
        });
    }
};
