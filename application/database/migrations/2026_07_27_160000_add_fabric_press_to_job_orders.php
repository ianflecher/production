<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            // The press that merges the print onto the fabric (after printing).
            // Separate from `press`, which is now the DECORATION press. Nullable
            // at the DB level for older rows; the form requires it going forward.
            $table->string('fabric_press', 50)->nullable()->after('press');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('fabric_press');
        });
    }
};
