<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw materials can be more than one item, so store a list (JSON) instead
     * of a single string.
     */
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            if (Schema::hasColumn('job_orders', 'raw_materials')) {
                $table->dropColumn('raw_materials');
            }
        });

        Schema::table('job_orders', function (Blueprint $table) {
            $table->json('raw_materials')->nullable()->after('fabric');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            if (Schema::hasColumn('job_orders', 'raw_materials')) {
                $table->dropColumn('raw_materials');
            }
        });

        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('raw_materials')->nullable()->after('fabric');
        });
    }
};
