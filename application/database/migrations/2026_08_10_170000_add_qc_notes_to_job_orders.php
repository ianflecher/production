<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Notes from QC" has been printed on the job order sheet all along as an empty
 * box. Nothing ever filled it, because there was nowhere to put the answer —
 * the checker writes on the paper and it is lost when the sheet is filed.
 *
 * Text, not a string: it is the one field on the sheet where somebody explains
 * what they found, and 255 characters runs out mid-sentence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('job_orders', 'qc_notes')) {
            return;
        }

        Schema::table('job_orders', function (Blueprint $table) {
            $table->text('qc_notes')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('job_orders', 'qc_notes')) {
            return;
        }

        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('qc_notes');
        });
    }
};
