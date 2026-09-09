<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->boolean('downpayment_waived')->default(false)->after('discount_note');
            $table->string('downpayment_waiver_note', 500)->nullable()->after('downpayment_waived');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn(['downpayment_waived', 'downpayment_waiver_note']);
        });
    }
};
