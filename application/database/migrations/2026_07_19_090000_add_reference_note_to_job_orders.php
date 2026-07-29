<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('job_orders', 'reference_note')) {
                $table->longText('reference_note')->nullable()->after('special_instructions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('reference_note');
        });
    }
};
