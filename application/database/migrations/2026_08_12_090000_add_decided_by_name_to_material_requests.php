<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who actually issued the materials.
 *
 * The form has always asked "Issued by" — the supply desk is a shared login,
 * so the person types their own name. It was passed to the stock movement and
 * then dropped, and the decisions list showed the account instead. Two people
 * on one login became one name, which is the opposite of what the box was for.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('material_requests', 'decided_by_name')) {
            return;
        }

        Schema::table('material_requests', function (Blueprint $table) {
            $table->string('decided_by_name', 100)->nullable()->after('decided_by');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('material_requests', 'decided_by_name')) {
            return;
        }

        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropColumn('decided_by_name');
        });
    }
};
