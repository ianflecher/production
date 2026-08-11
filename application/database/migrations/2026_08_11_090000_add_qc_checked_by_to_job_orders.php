<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Quality Checked By" was worked out from whoever had claimed the station,
 * which meant the checker had to type their name on the way IN, before they
 * had checked anything. They write it on the sheet now, next to what they
 * found, so the name and the finding are one act.
 *
 * Old orders have no column filled, so the sheet still falls back to the
 * station operator for those.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('job_orders', 'qc_checked_by')) {
            return;
        }

        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('qc_checked_by')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('job_orders', 'qc_checked_by')) {
            return;
        }

        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('qc_checked_by');
        });
    }
};
