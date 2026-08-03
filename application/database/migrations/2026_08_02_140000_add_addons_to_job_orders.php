<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            // Which add-on the client wants: embroidery / reflectorized /
            // sublimated / others. The matched press still lives in `press`,
            // so the production routing is unchanged.
            $table->string('addon')->nullable()->after('press');
            // Free text when the add-on is "Others".
            $table->string('addon_other')->nullable()->after('addon');
            // What the add-on is charged at.
            $table->decimal('addon_price', 12, 2)->nullable()->after('addon_other');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn(['addon', 'addon_other', 'addon_price']);
        });
    }
};
