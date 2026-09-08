<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One named person may also take orders.
 *
 * Taking a job belongs to the account officers: they hold the client, they
 * quote it, and the order is theirs to answer for. The leader runs the floor
 * and does not take jobs, so the whole order desk - enquiries, the order form,
 * payments, the client brief - is closed to that role.
 *
 * Carla does both. Handing every leader the order desk to let one person take
 * a job would open it to the supervisors as well, who have no business in it;
 * moving her to the account officer role would take away the approvals she
 * runs the floor with. So the permission is given to the PERSON rather than
 * the role, which is also how it reads on the users page: this one may take
 * orders too.
 *
 * The column is the grant. Anyone else who needs it later is a checkbox, not
 * another migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_create_orders')->default(false)->after('is_active');
        });

        // The grant this was written for. Matched on the name the shop knows
        // her by AND on the role, so it cannot land on somebody else who is
        // named Carla later.
        DB::table('users')
            ->where('name', 'like', '%Carla%')
            ->whereIn(DB::raw('LOWER(TRIM(job_role))'), ['leader'])
            ->update(['can_create_orders' => true]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('can_create_orders'));
    }
};
