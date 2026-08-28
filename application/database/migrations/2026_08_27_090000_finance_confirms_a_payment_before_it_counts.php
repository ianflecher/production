<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A payment counts once Finance says it landed.
 *
 * The account officer records what the client says they have paid and uploads
 * the slip. That is a claim, not money in the account — and it was enough to
 * start the shop drawing. Finance is the desk that watches the bank, so they
 * are the ones who confirm it, and the job waits until they have.
 *
 * Everything already recorded is treated as confirmed. Those jobs are drawn,
 * some are on the floor, and re-opening a gate behind them would stop work
 * that is already happening on money that was already checked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('paid_at');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')
                ->constrained('users')->nullOnDelete();
        });

        // Confirmed as of now, by nobody in particular: these predate the desk
        // having a say, and a name here would be a name that never looked.
        DB::table('payments')->whereNull('confirmed_at')->update([
            'confirmed_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn('confirmed_at');
        });
    }
};
