<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decoration comes off the job order now: the press defaults from the print type
 * (and is overridable on the production-details page), and embroidery is a plain
 * yes/no on the job order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('press', 30)->nullable()->after('printer');
            $table->boolean('needs_embroidery')->default(false)->after('press');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn(['press', 'needs_embroidery']);
        });
    }
};
