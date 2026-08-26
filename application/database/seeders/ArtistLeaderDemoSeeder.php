<?php

namespace Database\Seeders;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Six jobs on the artists' bench, so the artist leader's side of the app can
 * be walked through by hand.
 *
 * Between them they cover what he is meant to be able to do and what he is
 * not: tech packs waiting on him, one he drew himself (which must never be
 * his own to sign off), steps in every open state so the bench has something
 * to hand over, and one job further down the floor that is the leader's and
 * not his.
 *
 * Demo data only. Run it against imprint_dev — never production:
 *   php artisan db:seed --class=ArtistLeaderDemoSeeder
 */
class ArtistLeaderDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('Refusing to run: this is demo data and this is the production environment.');

            return;
        }

        $this->command->info('Database: '.\DB::connection()->getDatabaseName());

        $sales = User::where('job_role', User::ROLE_SALES)->firstOrFail();
        $lead = User::where('job_role', User::JOB_ARTIST_LEAD)->firstOrFail();

        $artists = User::where('job_role', User::JOB_ARTIST)
            ->where('is_active', true)->orderBy('id')->get();

        abort_if($artists->count() < 4, 500, 'Need at least four artists to make this worth looking at.');

        // Everybody the rotation can reach is in today, otherwise the bench is
        // a list of names nobody can hand anything to.
        foreach ($artists->push($lead) as $person) {
            $person->attendances()->updateOrCreate(
                ['user_id' => $person->id, 'date' => now()->toDateString()],
                ['status' => 'present', 'set_by' => $lead->id],
            );
        }

        /*
         * The artists do three steps, not one — Layout, Final mockup, Tech
         * pack — and all three are his to hand out. Only the tech pack comes
         * back to HIM to check: the layout and the mockup are what the client
         * is shown, so the account officer signs those off. So the bench needs
         * jobs sitting at each of the three, or it reads like tech packs are
         * the whole of an artist's work.
         */
        $jobs = [
            // [client, which step the job is sitting at, who holds it, state]
            ['Cebu Runners Club', 'Tech pack', $artists[0], 'for_checking'],
            ['Mango Tree Cafe', 'Tech pack', $artists[1], 'for_checking'],
            ['Sacred Heart Alumni', 'Layout', $artists[2], 'in_progress'],
            ['Bantayan Dive Shop', 'Layout', null, 'ready'],
            ['Oslob Whale Tours', 'Final mockup', $artists[3], 'in_progress'],
            ['San Carlos Seminary', 'Final mockup', $artists[0], 'for_checking'],
            ['Toledo Bakeshop', 'Tech pack', null, 'ready'],
            // His own work. It must NOT appear in his checking queue, and not
            // on the bench either — this is where he hands work out.
            ['Talisay Little League', 'Tech pack', $lead, 'for_checking'],
        ];

        foreach ($jobs as $i => [$client, $department, $artist, $status]) {
            $order = $this->order($sales, $client, $i + 1);
            $step = $this->artistStep($order, $department);

            $step->update([
                'assigned_to' => $artist?->id,
                'status' => $status,
                'submitted_at' => $status === 'for_checking' ? now()->subHours($i + 1) : null,
            ]);

            // The steps before it are done — this one is where the job is.
            $order->tasks()->where('sequence', '<', $step->sequence)
                ->update(['status' => 'complete', 'assigned_to' => $artist?->id, 'approved_at' => now()->subDay()]);
        }

        // One job further down the floor: the leader's to check, not his.
        $order = $this->order($sales, 'Lapu-Lapu City Hall', 6);
        $sewing = $order->tasks()->where('department', 'Sewing')->orderBy('sequence')->firstOrFail();
        $order->tasks()->where('sequence', '<', $sewing->sequence)
            ->update(['status' => 'complete', 'approved_at' => now()->subDays(2)]);
        $sewing->update(['status' => 'for_checking', 'submitted_at' => now()->subHour()]);

        $this->command->info('Seeded 6 demo jobs. Sign in as '.$lead->name.' ('.$lead->email.').');
    }

    private function order(User $sales, string $client, int $n): ProductionOrder
    {
        $order = ProductionOrder::create([
            'order_number' => sprintf('IC2026-D%03d', $n),
            'customer_name' => $client,
            'product_type' => 'round_neck',
            'quantity' => 24,
            'due_date' => now()->addDays(7 + $n),
            'unit_price' => 450,
            'created_by' => $sales->id,
            'status' => 'active',
        ]);

        $order->items()->create(['size' => 'M', 'quantity' => 12]);
        $order->items()->create(['size' => 'L', 'quantity' => 12]);
        $order->refresh()->buildPipeline([], 'manual');

        return $order->refresh();
    }

    private function techPack(ProductionOrder $order): Task
    {
        return $order->tasks()
            ->where('stage', ProductionOrder::STAGE_MOCKUP)
            ->where('approver_role', 'leader')
            ->orderBy('sequence')
            ->firstOrFail();
    }
}
