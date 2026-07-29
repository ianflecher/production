<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The flatlay photo shown on the sheet beside the product mockup. The upload
 * handler already existed but had nowhere to store the reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_documents', function (Blueprint $table) {
            $table->json('flatlay')->nullable()->after('attachments');
        });
    }

    public function down(): void
    {
        Schema::table('order_documents', function (Blueprint $table) {
            $table->dropColumn('flatlay');
        });
    }
};
