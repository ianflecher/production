<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An expense carries what the books actually need.
 *
 * What was recorded was a date, one of ten homemade buckets, a description,
 * an amount and a method. What the bookkeeper works from is a different
 * sheet entirely: who ordered it, when it was ordered and when it was paid,
 * the invoice number, the supplier and their TIN and address, which of
 * seventy-eight account titles it belongs to, and whether it was VAT or
 * non-VAT.
 *
 * So everything that was being written on paper beside the system is now on
 * the row, and the export comes out in the order the bookkeeper reads.
 *
 * `category` becomes `account_title`. The ten buckets - "Raw materials",
 * "Utilities" - do not map onto the shop's chart of accounts and never did;
 * "COS - Fabric" and "COS - Ink" are both raw materials and are separate
 * lines in the books. Both databases held zero expenses when this ran, so
 * nothing had to be translated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            // Who asked for the money, which is not always who recorded it.
            $table->string('ordered_by')->nullable()->after('id');

            // spent_at is the ORDER date. Paid is a different day and the
            // books care about both - one lands in the month it was ordered,
            // the other in the month the money left.
            $table->date('paid_at')->nullable()->after('spent_at');

            // PCF, PO, RFP or Others - what kind of paper the reference is.
            // The number itself stays in `reference`.
            $table->string('reference_type', 20)->nullable()->after('paid_at');

            $table->string('si_cr_no')->nullable()->after('reference');
            $table->string('supplier')->nullable()->after('si_cr_no');
            $table->string('tin', 40)->nullable()->after('supplier');
            $table->string('business_address')->nullable()->after('tin');

            // VAT or N-VAT. Nullable because a petty-cash coffee run has
            // neither, and forcing a choice there would only produce noise.
            $table->string('vat_status', 10)->nullable()->after('business_address');

            $table->string('account_title')->nullable()->after('vat_status');
        });

        // Both databases are empty, but a rename is written for the case
        // where they are not - and so the intent is on the record.
        \DB::table('expenses')->whereNull('account_title')->update([
            'account_title' => \DB::raw('category'),
        ]);

        Schema::table('expenses', function (Blueprint $table) {
            // The index goes first. MySQL drops it along with the column;
            // SQLite leaves it pointing at a column that is no longer there
            // and every later migration on the table then fails.
            $table->dropIndex('expenses_category_index');
            $table->dropColumn('category');

            // The same index on what replaced it: the account title is what
            // the list groups by and what the search looks through.
            $table->index('account_title');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('category')->nullable();
            $table->dropIndex(['account_title']);
            $table->dropColumn([
                'ordered_by', 'paid_at', 'reference_type', 'si_cr_no',
                'supplier', 'tin', 'business_address', 'vat_status',
                'account_title',
            ]);
            $table->index('category');
        });
    }
};
