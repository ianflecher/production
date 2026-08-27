<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A shop's worth of orders, so the pages can be looked at with real weight in
 * them.
 *
 * Every officer gets their own hundred, on their own price list, spread across
 * the last few months and the next few weeks — a list of a thousand identical
 * rows all due the same day tells you nothing about whether the paging, the
 * sorting or the capacity warnings actually work.
 *
 *   php artisan demo:orders --per=100
 *   php artisan demo:orders --purge
 *
 * Every order it writes is marked, and --purge removes exactly those and
 * nothing else.
 */
class SeedDemoOrders extends Command
{
    protected $signature = 'demo:orders
        {--per=100 : how many orders per account officer}
        {--purge : delete the demo orders instead of making them}
        {--force : allow this to run against a production environment}';

    protected $description = 'Fill the shop with demo orders for testing';

    /** Written into every demo order, and the only thing --purge goes by. */
    private const MARK = '[demo]';

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

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('This is the production environment. Demo orders do not belong in the');
            $this->error('shop\'s real books — run it against imprint_dev, or pass --force if you');
            $this->error('really mean it.');

            return self::FAILURE;
        }

        $this->line('Database: '.DB::connection()->getDatabaseName());

        if ($this->option('purge')) {
            return $this->purge();
        }

        $officers = User::where('job_role', User::ROLE_SALES)->where('is_active', true)->get();

        if ($officers->isEmpty()) {
            $this->error('No active account officers to write orders for.');

            return self::FAILURE;
        }

        $per = max(1, (int) $this->option('per'));
        $clients = $this->clients();

        $this->info($officers->count().' officers × '.$per.' orders = '.($officers->count() * $per).' to write.');

        // The order number is unique, so the next free one is worked out once
        // here rather than re-counted for every single order.
        $year = now()->format('Y');
        $seq = 1 + (int) (ProductionOrder::where('order_number', 'like', "IC{$year}-%")
            ->pluck('order_number')
            ->map(fn ($n) => (int) preg_replace('/\D/', '', substr((string) $n, 8)))
            ->max() ?? 0);

        $bar = $this->output->createProgressBar($officers->count() * $per);
        $bar->start();

        foreach ($officers as $officer) {
            $list = PricingService::listFor($officer);
            $products = array_keys(PricingService::products($list));

            for ($i = 0; $i < $per; $i++) {
                DB::transaction(function () use ($officer, $clients, $products, $list, &$seq, $year) {
                    $this->makeOrder($officer, $clients->random(), $products, $list, $year, $seq++);
                });

                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Done. '.ProductionOrder::where('description', 'like', '%'.self::MARK.'%')->count()
            .' demo orders in the books.');
        $this->line('Remove them again with: php artisan demo:orders --purge');

        return self::SUCCESS;
    }

    private function makeOrder(User $officer, Client $client, array $products, string $list, string $year, int $seq): void
    {
        $type = $products[array_rand($products)];
        $qty = [10, 12, 18, 24, 30, 36, 48, 60][array_rand([10, 12, 18, 24, 30, 36, 48, 60])];

        // Spread over the last four months and the next month, so the board,
        // the calendar and the "near due" warnings all have something to say.
        $created = now()->subDays(random_int(0, 120))->setTime(random_int(8, 17), random_int(0, 59));
        $due = (clone $created)->addDays(random_int(7, 45));

        $quote = PricingService::quote($type, $qty, false, null, $list);

        $order = ProductionOrder::create([
            'order_number' => sprintf('IC%s-%05d', $year, $seq),
            'client_id' => $client->id,
            'customer_name' => $client->fullName(),
            'product_type' => $type,
            'price_list' => $list,
            'description' => self::MARK.' generated for testing',
            'quantity' => $qty,
            'due_date' => $due->toDateString(),
            'unit_price' => $quote['unit'],
            'total_price' => $quote['total'],
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        // A size breakdown that adds up to the quantity, rather than one row
        // of everything — the sheets and the cutting list read off these.
        $sizes = ['S', 'M', 'L', 'XL'];
        $each = intdiv($qty, count($sizes));
        $rest = $qty - $each * count($sizes);

        foreach ($sizes as $n => $size) {
            $order->items()->create(['size' => $size, 'quantity' => $each + ($n === 0 ? $rest : 0)]);
        }

        $order->forceFill(['created_at' => $created, 'updated_at' => $created])->save();
        $order->refresh()->buildPipeline([], 'manual');

        $this->advance($order, $created);
    }

    /**
     * Push the job some way down the line.
     *
     * Everything sitting on step one would make the pipeline chart a single
     * bar and the station board empty, which is exactly the thing somebody
     * looking at demo data wants to see working.
     */
    private function advance(ProductionOrder $order, \Illuminate\Support\Carbon $created): void
    {
        $roll = random_int(1, 100);

        // A tenth never went anywhere; the rest are somewhere along the line,
        // and about a fifth are finished and off the floor.
        $steps = match (true) {
            $roll <= 10 => 0,
            $roll <= 40 => random_int(1, 3),
            $roll <= 75 => random_int(4, 9),
            $roll <= 80 => 0,
            default => 99,
        };

        if ($roll > 75 && $roll <= 80) {
            $order->update(['status' => 'on_hold']);

            return;
        }

        $tasks = $order->tasks()->orderBy('sequence')->get();
        $done = 0;

        foreach ($tasks as $task) {
            if ($done >= $steps) {
                break;
            }

            $task->forceFill([
                'status' => 'complete',
                'approved_at' => $created->copy()->addDays($done),
                'submitted_at' => $created->copy()->addDays($done),
            ])->save();

            $done++;
        }

        if ($steps === 99) {
            $order->update(['status' => 'complete', 'completed_at' => $created->copy()->addDays(random_int(10, 40))]);

            return;
        }

        // The step the job is actually SITTING on. Without this every task is
        // either finished or still locked, so the station board is empty and
        // the pipeline chart is one flat bar — which is the half of the app a
        // demo is most needed for.
        $next = $tasks->firstWhere('status', 'todo');

        if ($next) {
            $next->forceFill([
                'status' => ['ready', 'in_progress', 'in_progress', 'for_checking'][random_int(0, 3)],
                'submitted_at' => null,
            ])->save();
        }
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

        if ($orders->isEmpty()) {
            $this->info('No demo orders to remove.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Delete '.$orders->count().' demo orders and everything on them?', true)) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($orders) {
            foreach ($orders as $order) {
                $order->tasks()->forceDelete();
                $order->items()->forceDelete();
                $order->forceDelete();
            }
        });

        $this->info('Removed '.$orders->count().' demo orders.');

        return self::SUCCESS;
    }
}
