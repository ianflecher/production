<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Client details needed for delivery and invoicing.
        Schema::table('clients', function (Blueprint $table) {
            $table->string('office_address')->nullable()->after('company');
            $table->string('delivery_address')->nullable()->after('office_address');
            $table->string('tin', 50)->nullable()->after('delivery_address');
        });

        // 12% VAT toggle + a peso discount / sponsorship off the TOTAL.
        Schema::table('production_orders', function (Blueprint $table) {
            $table->boolean('vat_inclusive')->default(false)->after('total_price');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('vat_inclusive');
            $table->string('discount_note')->nullable()->after('discount_amount');
        });

        // "Others" is a typed size name (e.g. "Kids 8"), so 10 chars is too tight.
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('size', 50)->change();
        });

        // Structured client design questionnaire (answers as JSON).
        Schema::table('job_orders', function (Blueprint $table) {
            $table->json('design_brief')->nullable()->after('reference_note');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['office_address', 'delivery_address', 'tin']);
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn(['vat_inclusive', 'discount_amount', 'discount_note']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('size', 10)->change();
        });

        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('design_brief');
        });
    }
};
