<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two named people sign off the tech pack.
 *
 * The final approval on a tech pack was open to the leader ROLE, and the
 * supervisors read as leaders in this app - Boying, Khaye, Ann and Madel all
 * resolve to it. They run parts of the floor; none of them is who the shop
 * means when it says the tech pack has been checked. The artist leader could
 * sign one off as well, by virtue of being the artist leader.
 *
 * The shop means two people: Carla and Rommel. So it is written down as two
 * people rather than inferred from a job title, the same way Carla's order
 * desk is - anyone else who needs it later is a column update, not a change
 * of role.
 *
 * This is the tech pack ONLY. Everything else those leaders approve today,
 * they still approve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_approve_tech_packs')->default(false)->after('can_create_orders');
        });

        // The two the shop means. Matched on the names it knows them by; a
        // shop that renames somebody grants it again, which is a column
        // update rather than a migration.
        foreach (['%Carla%', '%Rommel%'] as $who) {
            DB::table('users')->where('name', 'like', $who)->update(['can_approve_tech_packs' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('can_approve_tech_packs'));
    }
};
