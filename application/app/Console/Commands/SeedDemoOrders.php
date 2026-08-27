<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A shop's worth of work, taken in the way the shop actually takes it in.
 *
 * Every job walks the three steps: the client is written down, the design goes
 * to an artist and comes back, the client approves it, and only then is the
 * order written. Jobs are left standing at each of those points, because a
 * hundred finished orders tell you nothing about whether the follow-up list,
 * the artists' queue or the approval gate work.
 *
 * The layouts are spread across every artist, so each of them has a queue
 * rather than one person holding all of it.
 *
 *   php artisan demo:orders --per=100
 *   php artisan demo:orders --purge
 *
 * Everything it writes is marked, and --purge removes exactly that.
 */
class SeedDemoOrders extends Command
{
    protected $signature = 'demo:orders
        {--per=100 : how many jobs per account officer}
        {--purge : delete the demo data instead of making it}
        {--force : allow this to run against a production environment}';

    protected $description = 'Fill the shop with demo jobs, walked through the real steps';

    /** Written into everything this makes, and the only thing --purge goes by. */
    private const MARK = '[demo]';

    /** The two pictures in public/ — one stands in for the ChatGPT output, one for the artist's layout. */
    private const BRIEF_IMAGE = 'artemis.png';

    private const LAYOUT_IMAGE = 'claret.png';

    private const CLIENTS = [
        ['Cebu Runners Club', 'Miguel', 'Santos'],
        ['Mango Tree Cafe', 'Liza', 'Reyes'],
        ['Sacred Heart Alumni', 'Paolo', 'Cruz'],
        ['Bantayan Dive Shop', 'Ana', 'Villanueva'],
        ['Oslob Whale Tours', 'Marco', 'Dizon'],
        ['Talisay Little League', 'Bea', 'Ocampo'],
        ['Lapu-Lapu City Hall', 'Ramon', 'Aquino'],
        ['Mactan Riders', 'Jose', 'Fernandez'],
        ['Colon Street Barbers', 'Rico', 'Mendoza'],
        ['Banilad Fun Run', 'Grace', 'Lim'],
    ];

    /** Active staff by job role, worked out once. */
    private ?array $floor = null;

    /** The stored demo drawing, hung on whatever gets submitted for checking. */
    private ?array $layoutImage = null;

    private const WANTS = [
        '30 riding jerseys for the club',
        '40 school uniforms',
        'polo shirts for the office',
        'event giveaway shirts',
        'team hoodies for the season',
        'caps and tubemasks for the ride',
    ];

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('This is the production environment. Demo jobs do not belong in the');
            $this->error("shop's real books — run it against imprint_dev, or pass --force if you");
            $this->error('really mean it.');

            return self::FAILURE;
        }

        $this->line('Database: '.DB::connection()->getDatabaseName());

        if ($this->option('purge')) {
            return $this->purge();
        }

        $officers = User::where('job_role', User::ROLE_SALES)->where('is_active', true)->get();

        // The artist leader draws at the bench too, so he takes his turn.
        $artists = User::whereIn('job_role', [User::JOB_ARTIST, User::JOB_ARTIST_LEAD])
            ->where('is_active', true)->orderBy('id')->get();

        if ($officers->isEmpty() || $artists->isEmpty()) {
            $this->error('Need at least one active account officer and one artist.');

            return self::FAILURE;
        }

        $images = $this->storeImages();

        if ($images === null) {
            return self::FAILURE;
        }

        $this->layoutImage = $images['layout'];

        $per = max(1, (int) $this->option('per'));
        $clients = $this->clients();
        $total = $officers->count() * $per;

        $this->info($officers->count().' officers × '.$per.' = '.$total.' jobs, across '.$artists->count().' artists.');

        $year = now()->format('Y');
        $seq = 1 + (int) (ProductionOrder::where('order_number', 'like', "IC{$year}-%")
            ->pluck('order_number')
            ->map(fn ($n) => (int) preg_replace('/\D/', '', substr((string) $n, 8)))
            ->max() ?? 0);

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        // Round-robin, so the queues come out even rather than however the
        // random numbers happened to fall.
        $nextArtist = 0;
        $stood = ['asked' => 0, 'drawing' => 0, 'to approve' => 0, 'ordered' => 0];

        foreach ($officers as $officer) {
            $list = PricingService::listFor($officer);
            $products = array_keys(PricingService::products($list));

            for ($i = 0; $i < $per; $i++) {
                $artist = $artists[$nextArtist++ % $artists->count()];

                DB::transaction(function () use ($officer, $artist, $clients, $products, $list, $images, &$seq, $year, $i, &$stood) {
                    $stood[$this->makeJob($officer, $artist, $clients->random(), $products, $list, $images, $seq, $year, $i)]++;
                });

                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);

        foreach ($stood as $where => $count) {
            $this->line(str_pad($count, 5, ' ', STR_PAD_LEFT).'  '.$where);
        }

        $this->newLine();
        $this->line('Remove it all again with: php artisan demo:orders --purge');

        return self::SUCCESS;
    }

    /**
     * One job, walked as far along as this one is meant to get.
     *
     * @return string where it was left standing
     */
    private function makeJob(User $officer, User $artist, Client $client, array $products, string $list, array $images, int &$seq, string $year, int $n): string
    {
        $asked = now()->subDays(random_int(0, 120))->setTime(random_int(8, 17), random_int(0, 59));

        $inquiry = Inquiry::create([
            'client_id' => $client->id,
            'created_by' => $officer->id,
            'team' => $officer->team,
            'status' => Inquiry::STATUS_OPEN,
            'what_they_want' => self::WANTS[array_rand(self::WANTS)].' '.self::MARK,
        ]);

        $inquiry->forceFill(['created_at' => $asked, 'updated_at' => $asked])->save();

        // Step 1 only: they asked and nothing has been drawn. These are the
        // follow-up list.
        if ($n % 10 === 0) {
            return 'asked';
        }

        // Step 2: the officer's reference goes on, and it goes to an artist.
        $inquiry->update([
            'layout_files' => [$images['brief']],
            'layout_reference_note' => 'Keep the team colours, logo bigger on the back. '.self::MARK,
            'layout_brief_completed_at' => $asked->copy()->addHours(2),
            'layout_artist_id' => $artist->id,
            'layout_sent_at' => $asked->copy()->addHours(2),
            'layout_status' => Inquiry::LAYOUT_WITH_ARTIST,
        ]);

        // Standing in the artist's queue, still to draw.
        if ($n % 10 === 1 || $n % 10 === 2) {
            return 'drawing';
        }

        // The artist hands it back.
        $inquiry->update([
            'layout_files' => [$images['brief'], $images['layout'] + ['uploaded_by' => $artist->id]],
            'layout_status' => Inquiry::LAYOUT_SUBMITTED,
            'layout_submitted_at' => $asked->copy()->addDay(),
        ]);

        // Drawn, and the officer has not shown it to the client yet.
        if ($n % 10 === 3) {
            return 'to approve';
        }

        $inquiry->update([
            'layout_status' => Inquiry::LAYOUT_APPROVED,
            'layout_approved_at' => $asked->copy()->addDays(2),
        ]);

        $this->writeOrder($inquiry, $officer, $artist, $products, $list, $seq++, $year, $asked);

        return 'ordered';
    }

    private function writeOrder(Inquiry $inquiry, User $officer, User $artist, array $products, string $list, int $seq, string $year, \Illuminate\Support\Carbon $asked): void
    {
        $type = $products[array_rand($products)];
        $qty = [10, 12, 18, 24, 30, 36, 48, 60][random_int(0, 7)];
        $written = $asked->copy()->addDays(3);
        $quote = PricingService::quote($type, $qty, false, null, $list);

        $order = ProductionOrder::create([
            'order_number' => sprintf('IC%s-%05d', $year, $seq),
            'client_id' => $inquiry->client_id,
            'customer_name' => $inquiry->client->fullName(),
            'product_type' => $type,
            'price_list' => $list,
            'description' => self::MARK.' generated for testing',
            'quantity' => $qty,
            'due_date' => $written->copy()->addDays(random_int(7, 45))->toDateString(),
            'unit_price' => $quote['unit'],
            'total_price' => $quote['total'],
            'layout_approved_at' => $inquiry->layout_approved_at,
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        $sizes = ['S', 'M', 'L', 'XL'];
        $each = intdiv($qty, count($sizes));
        $rest = $qty - $each * count($sizes);

        foreach ($sizes as $i => $size) {
            $order->items()->create(['size' => $size, 'quantity' => $each + ($i === 0 ? $rest : 0)]);
        }

        $order->forceFill(['created_at' => $written, 'updated_at' => $written])->save();
        $order->refresh()->buildPipeline([], 'manual');

        // The layout was drawn and approved before this order existed, so it
        // arrives done — the same as the real flow does it.
        $order->tasks()->where('stage', ProductionOrder::STAGE_LAYOUT)->get()
            ->each(fn ($t) => $t->forceFill([
                'assigned_to' => $artist->id,
                'status' => 'complete',
                'submitted_at' => $inquiry->layout_submitted_at,
                'approved_at' => $inquiry->layout_approved_at,
            ])->save());

        $inquiry->markOrdered($order);

        $this->advance($order, $written, $artist);
    }

    /** Push the job some way down the line, so the floor has work on it. */
    private function advance(ProductionOrder $order, \Illuminate\Support\Carbon $from, User $artist): void
    {
        $roll = random_int(1, 100);

        if ($roll > 92) {
            $order->update(['status' => 'on_hold']);

            return;
        }

        $steps = match (true) {
            $roll <= 35 => random_int(1, 3),
            $roll <= 75 => random_int(4, 9),
            default => 99,
        };

        $tasks = $order->tasks()->where('status', '!=', 'complete')->orderBy('sequence')->get();
        $done = 0;

        foreach ($tasks as $task) {
            if ($done >= $steps) {
                break;
            }

            $task->forceFill([
                'status' => 'complete',
                'assigned_to' => $task->assigned_to ?? ($task->team === User::JOB_ARTIST ? $artist->id : null),
                'submitted_at' => $from->copy()->addDays($done),
                'approved_at' => $from->copy()->addDays($done),
            ])->save();

            $done++;
        }

        if ($steps === 99) {
            $order->update(['status' => 'complete', 'completed_at' => $from->copy()->addDays(random_int(10, 40))]);

            return;
        }

        // Whatever it is sitting on now is live work, not a locked step.
        $next = $order->tasks()->where('status', 'todo')->orderBy('sequence')->first();

        if (! $next) {
            return;
        }

        $who = $next->assigned_to
            ?? ($next->team === User::JOB_ARTIST ? $artist->id : $this->someoneOn($next->team));

        // Only the steps the shop itself can put there.
        //
        // An artist submits their work from My Tasks, and the client sample
        // goes to the account officer because the client has to see it. The
        // floor does neither: finishing at a station closes the step outright
        // (StationController::finish), so a printing or sewing step waiting on
        // somebody's approval is a state the app never writes — and seeding it
        // filled the leader's queue with rows nobody could have produced.
        $canBeChecked = $who
            && ($next->team === User::JOB_ARTIST || $next->approver_role === 'sales');

        $status = $canBeChecked
            ? ['ready', 'in_progress', 'in_progress', 'for_checking'][random_int(0, 3)]
            : ['ready', 'in_progress', 'in_progress'][random_int(0, 2)];

        $next->forceFill([
            'status' => $status,
            'assigned_to' => $who,
            'submitted_at' => $status === 'for_checking' ? $from->copy()->addDays($done) : null,
        ])->save();

        // Something to actually open. A queue of rows with an empty "submitted
        // work" column cannot be approved by anyone looking at it.
        if ($status === 'for_checking' && $this->layoutImage) {
            $next->files()->create([
                'path' => $this->layoutImage['path'],
                'original_name' => $this->layoutImage['original_name'],
                'mime' => $this->layoutImage['mime'],
                'size' => $this->layoutImage['size'],
                'round' => (int) $next->revision_count + 1,
                'uploaded_by' => $who,
            ]);
        }
    }

    /** Somebody who actually works that team, for the demo to hang a name on. */
    private function someoneOn(?string $team): ?int
    {
        if (blank($team)) {
            return null;
        }

        $this->floor ??= User::where('is_active', true)->get()
            ->groupBy(fn ($u) => mb_strtolower(trim((string) $u->job_role)))
            ->map(fn ($group) => $group->pluck('id')->all())
            ->all();

        $onTeam = $this->floor[mb_strtolower(trim($team))] ?? [];

        return $onTeam === [] ? null : $onTeam[array_rand($onTeam)];
    }

    /**
     * The two demo pictures, copied into storage once and shared by every job.
     *
     * A copy per inquiry would be seven hundred copies of a two-megabyte file
     * for no gain — nothing in the app writes to them.
     *
     * @return array{brief: array, layout: array}|null
     */
    private function storeImages(): ?array
    {
        $made = [];

        foreach (['brief' => self::BRIEF_IMAGE, 'layout' => self::LAYOUT_IMAGE] as $slot => $name) {
            $source = public_path($name);

            if (! is_file($source)) {
                $this->error("Missing demo picture: public/{$name}");

                return null;
            }

            $path = 'inquiry-layouts/demo-'.$slot.'-'.pathinfo($name, PATHINFO_FILENAME).'.png';

            if (! Storage::disk('local')->exists($path)) {
                Storage::disk('local')->put($path, file_get_contents($source));
            }

            $made[$slot] = [
                'path' => $path,
                'original_name' => $name,
                'mime' => 'image/png',
                'size' => filesize($source),
                'uploaded_by' => null,
                'kind' => $slot === 'brief' ? 'output' : 'layout',
            ];
        }

        return $made;
    }

    /** Ten regulars, made once and shared across every officer. */
    private function clients()
    {
        return collect(self::CLIENTS)->map(fn ($c) => Client::firstOrCreate(
            ['name' => $c[1], 'last_name' => $c[2]],
            [
                'company' => $c[0],
                'contact_number' => '0917-'.random_int(1000000, 9999999),
                'office_address' => 'Cebu City',
                'delivery_address' => 'Cebu City',
            ],
        ));
    }

    private function purge(): int
    {
        $orders = ProductionOrder::where('description', 'like', '%'.self::MARK.'%')->get();
        $inquiries = Inquiry::where('what_they_want', 'like', '%'.self::MARK.'%')->get();

        if ($orders->isEmpty() && $inquiries->isEmpty()) {
            $this->info('No demo data to remove.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Delete '.$orders->count().' demo orders and '.$inquiries->count().' demo inquiries?', true)) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($orders, $inquiries) {
            foreach ($inquiries as $inquiry) {
                $inquiry->followUps()->delete();
                $inquiry->delete();
            }

            foreach ($orders as $order) {
                $order->tasks()->forceDelete();
                $order->items()->forceDelete();
                $order->forceDelete();
            }
        });

        $this->info('Removed '.$orders->count().' orders and '.$inquiries->count().' inquiries.');

        return self::SUCCESS;
    }
}
