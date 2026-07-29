<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the client submits the shared questionnaire link, we stamp the time
     * here so the link becomes single-use (it stops working afterward until an
     * account officer reactivates it).
     */
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->timestamp('client_brief_submitted_at')->nullable()->after('design_brief');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('client_brief_submitted_at');
        });
    }
};
