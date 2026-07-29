<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Files placed on the sheet itself: the contract, payment proof and the
        // signed copy of the quotation.
        Schema::table('order_documents', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('fields');
        });
    }

    public function down(): void
    {
        Schema::table('order_documents', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
    }
};
