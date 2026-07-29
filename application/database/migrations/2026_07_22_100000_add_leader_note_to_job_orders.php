<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the leader returns the package to the account officer (a job-order
        // problem rather than a design one), their note is shown on the order.
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('leader_note', 2000)->nullable()->after('reference_note');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('leader_note');
        });
    }
};
