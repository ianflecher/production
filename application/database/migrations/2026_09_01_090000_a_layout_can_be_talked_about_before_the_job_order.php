<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A message can belong to an inquiry as well as to a job order.
 *
 * Everything said while a layout is being drawn happened before the job order
 * existed, so it had nowhere to go and ended up in Viber. The thread starts on
 * the inquiry, and when the job order is finally written the same messages are
 * stamped with it — the conversation carries over instead of starting again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('inquiry_id')->nullable()->after('production_order_id')
                ->constrained('inquiries')->nullOnDelete();
            $table->index(['inquiry_id', 'id']);
        });

        // The order is not known until it is written, so its column has to be
        // allowed to be empty. change() covers MySQL and the SQLite the tests
        // run on; a raw MODIFY would only have covered the first, and the test
        // database would have kept refusing the insert.
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('production_order_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['inquiry_id']);
            $table->dropIndex(['inquiry_id', 'id']);
            $table->dropColumn('inquiry_id');
        });
    }
};
