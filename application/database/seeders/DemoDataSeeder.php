<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\JobOrder;
use App\Models\Message;
use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A shop's worth of believable data for showing the system to someone.
 *
 * Orders are pushed through the real pipeline (unlockStage / handleTaskCompleted)
 * rather than written straight into the tables, so the board, the approvals list
 * and the stock requests all line up the way they would in daily use.
 *
 * Safe to run more than once: it clears the business tables it fills first, and
 * never touches the staff accounts. See truncate-business-data.sql for the
 * heavier reset.
 */
class DemoDataSeeder extends Seeder
{
    /**
     * Where each demo order should stop along the pipeline.
     *
     * The third number only spreads the generated repeats apart — due dates are
     * set in makeOrders() from the deadline group, not from this.
     */
    private const PLAN = [
        // [product, qty spread, spread hint, how far it got]
        ['round_neck',    ['S' => 4, 'M' => 8, 'L' => 6, 'XL' => 2],   3, 'new'],
        ['polo',          ['M' => 10, 'L' => 10, 'XL' => 4],           4, 'layout'],
        ['jacket_hoodie', ['M' => 6, 'L' => 8, 'XL' => 4, '2XL' => 2], 5, 'design'],
        ['round_neck',    ['S' => 12, 'M' => 20, 'L' => 18, 'XL' => 10], 2, 'production'],
        ['riding_jersey', ['M' => 8, 'L' => 8, 'XL' => 4],            6, 'production'],
        ['polo',          ['S' => 6, 'M' => 14, 'L' => 12, 'XL' => 6], 3, 'sample'],
        ['round_neck',    ['M' => 16, 'L' => 14, 'XL' => 8],           1, 'massprod'],
        ['jacket_hoodie', ['L' => 10, 'XL' => 6, '2XL' => 4],          2, 'massprod'],
        ['round_neck',    ['S' => 8, 'M' => 12, 'L' => 10],            8, 'complete'],
        ['polo',          ['M' => 12, 'L' => 12],                      9, 'complete'],
        ['riding_jersey', ['M' => 6, 'L' => 6, 'XL' => 3],            10, 'complete'],
        ['round_neck',    ['S' => 10, 'M' => 18, 'L' => 16, 'XL' => 6], 11, 'complete'],
        ['jacket_hoodie', ['M' => 4, 'L' => 6, 'XL' => 2],             7, 'complete'],
        ['polo',          ['M' => 8, 'L' => 8, 'XL' => 4],             4, 'hold'],
        ['round_neck',    ['M' => 6, 'L' => 6],                        5, 'cancelled'],
        ['round_neck',    ['S' => 5, 'M' => 9, 'L' => 7, 'XL' => 3],   2, 'design'],
        ['polo',          ['M' => 20, 'L' => 16, 'XL' => 8],           6, 'layout'],
        ['jacket_hoodie', ['L' => 8, 'XL' => 6],                       3, 'production'],
        ['riding_jersey', ['M' => 10, 'L' => 10, 'XL' => 5],           7, 'complete'],
        ['round_neck',    ['S' => 6, 'M' => 10, 'L' => 8, 'XL' => 4],  4, 'new'],
    ];

    /** Clients: name, surname, company, contact, address. */
    private const CLIENTS = [
        ['Maria', 'Santos', 'Angeles Riders Club', '0917-412-8890', 'Sto. Rosario St., Angeles City'],
        ['Jose', 'Dela Cruz', 'JDC Trucking Services', '0918-224-1173', 'MacArthur Hwy, San Fernando, Pampanga'],
        ['Ana', 'Reyes', null, '0920-778-3341', 'Balibago, Angeles City'],
        ['Ramon', 'Bautista', 'Bautista Hardware', '0917-556-9021', 'Dau, Mabalacat, Pampanga'],
        ['Grace', 'Mendoza', 'Holy Angel Alumni Assoc.', '0995-330-4417', 'Sto. Domingo, Angeles City'],
        ['Nelson', 'Aquino', 'Aquino Farms', '0916-889-2205', 'Magalang, Pampanga'],
        ['Cecilia', 'Villanueva', 'CV Catering', '0927-114-7788', 'Malabanias, Angeles City'],
        ['Rodel', 'Garcia', 'Garcia Motorworks', '0939-670-1132', 'Friendship Hwy, Angeles City'],
        ['Liza', 'Domingo', null, '0908-445-6612', 'Pandan, Angeles City'],
        ['Arnel', 'Tolentino', 'Tolentino Construction', '0917-903-2258', 'Porac, Pampanga'],
        ['Divina', 'Ocampo', 'Ocampo Dental Clinic', '0922-661-8874', 'Nepo Mall, Angeles City'],
        ['Ferdinand', 'Lazaro', 'FL Security Agency', '0947-228-0093', 'Clark Freeport Zone, Pampanga'],
        ['Rosalie', 'Pineda', 'Pineda Bakeshop', '0912-778-4406', 'Apalit, Pampanga'],
        ['Antonio', 'Salazar', 'Salazar Sports League', '0930-556-1194', 'Sta. Ana, Pampanga'],
    ];

    /**
     * How many orders to end up with. The hand-written PLAN above supplies the
     * first few and sets the tone; the rest are cycled from it so the list is
     * long enough to page through and to search against — which is the only way
     * to see the order list behave the way it will after a busy year.
     */
    private const TOTAL_ORDERS = 130;

    /**
     * How many orders to build. Defaults to TOTAL_ORDERS; set DEMO_ORDERS to
     * load the shop up with a few years' work and see how the screens hold:
     *
     *     DEMO_ORDERS=1000 php artisan db:seed --class=DemoDataSeeder
     */
    private function totalOrders(): int
    {
        $requested = (int) getenv('DEMO_ORDERS');

        return $requested > 0 ? $requested : self::TOTAL_ORDERS;
    }

    /** Print type / add-on combinations, cycled across the orders. */
    private const ROUTING = [
        ['full_sublimation', 'laser', null, null],
        ['dtf', 'manual', 'embroidery', 3500],
        ['silkscreen', 'manual', null, null],
        ['eco_solvent', 'manual', 'reflectorized', 2800],
        ['vinyl', 'manual', null, null],
        ['dtf', 'manual', 'sublimated', 1800],
        ['full_sublimation', 'laser', 'embroidery', 4200],
    ];

    public function run(): void
    {
        $sales = $this->staff(User::ROLE_SALES) ?? $this->staff(User::ROLE_SUPER_ADMIN);
        $finance = $this->staff(User::ROLE_FINANCE) ?? $sales;
        $leader = $this->staff(User::ROLE_LEADER) ?? $sales;

        if (! $sales) {
            $this->command?->error('No staff accounts found — run the UserSeeder first.');

            return;
        }

        $this->wipe();

        // Attendance comes first: artist work is only handed to someone who is
        // marked present, so without it every design task lands unassigned.
        $this->makeAttendance($leader);

        $clients = $this->makeClients($sales);
        $orders = $this->makeOrders($clients, $sales, $leader);

        $this->makePayments($orders, $finance);
        $this->makeExpenses($finance);
        $this->makeStock();
        $this->makeMessages($orders, [$sales, $leader, $finance]);
        $this->makeShopFloorRecords($orders, $leader);

        $this->command?->info(sprintf(
            'Demo data ready: %d clients, %d orders, %d payments, %d expenses, %d stock items.',
            count($clients),
            count($orders),
            \App\Models\Payment::count(),
            Expense::count(),
            InventoryItem::count()
        ));
    }

    /**
     * Pick an active staff member for a role. The permission role is derived
     * from the free-text job_role, so this filters in PHP rather than SQL.
     */
    private function staff(string $role): ?User
    {
        return User::where('is_active', true)->get()->first(fn ($u) => $u->role === $role);
    }

    /** Clear only the business tables, so the seeder can be re-run freely. */
    private function wipe(): void
    {
        // Everything in truncate-business-data.sql except push_subscriptions —
        // those are real browser registrations belonging to staff, and wiping
        // them would quietly unsubscribe people from their alerts.
        $tables = [
            'message_reads', 'message_files', 'message_mentions', 'messages',
            'task_files', 'tasks', 'order_documents', 'payments',
            'material_requests', 'order_items', 'job_order_files', 'job_orders',
            'product_receipts', 'product_movements', 'product_items',
            'stock_movements', 'inventory_items',
            // Station sessions point at orders. Left behind, a re-seed leaves
            // operators "running" jobs that no longer exist.
            'station_sessions',
            'production_orders', 'clients', 'expenses', 'app_notifications',
            'attendances',
        ];

        $mysql = \DB::getDriverName() === 'mysql';

        // Truncate ignores foreign keys, so they have to stand down first.
        if ($mysql) {
            \DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        foreach ($tables as $table) {
            $mysql ? \DB::table($table)->truncate() : \DB::table($table)->delete();
        }

        if ($mysql) {
            \DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /** Two working weeks of attendance, almost everyone in most days. */
    private function makeAttendance(User $leader): void
    {
        $staff = User::where('is_active', true)->get();

        for ($back = 13; $back >= 0; $back--) {
            $day = now()->subDays($back);

            if ($day->isSunday()) {
                continue;
            }

            foreach ($staff as $person) {
                \App\Models\Attendance::create([
                    'user_id' => $person->id,
                    'date' => $day->toDateString(),
                    // Today everyone is in, so work can be handed out; earlier
                    // days carry the odd absence.
                    'status' => ($back > 0 && rand(1, 14) === 1) ? 'absent' : 'present',
                    'set_by' => $leader->id,
                ]);
            }
        }
    }

    /** Roughly how many orders each client places, on average, over the period. */
    private const ORDERS_PER_CLIENT = 7;

    /** Given names and surnames used to fill the book out beyond the regulars. */
    private const FIRST_NAMES = [
        'Marites', 'Ronnel', 'Jaypee', 'Kristine', 'Dennis', 'Michelle', 'Alvin', 'Jocelyn',
        'Erwin', 'Sheila', 'Bernard', 'Cherry', 'Noel', 'Analyn', 'Rodrigo', 'Jenny',
        'Christian', 'Rowena', 'Edgar', 'Melody', 'Renato', 'Katrina', 'Wilfredo', 'Charmaine',
        'Efren', 'Lorna', 'Jayson', 'Girlie', 'Marlon', 'Precious',
    ];

    private const LAST_NAMES = [
        'Manalo', 'Espiritu', 'Quiambao', 'Sicat', 'Punzalan', 'Dizon', 'Guevarra', 'Yabut',
        'Lacsamana', 'Canlas', 'Baluyut', 'Tiglao', 'Sarmiento', 'Vergara', 'Bulaon', 'Maniago',
        'Simbulan', 'Gozun', 'Mercado', 'Nucum', 'Feliciano', 'Pangilinan', 'Serrano', 'Tayag',
    ];

    /** Kinds of outfit that order printing, used to name the generated companies. */
    private const COMPANY_KINDS = [
        'Trading', 'Enterprises', 'Construction', 'Motorworks', 'Catering Services',
        'Security Agency', 'Riders Club', 'Sports League', 'Dental Clinic', 'Bakeshop',
        'Hardware', 'Farms', 'Water Refilling', 'Auto Supply', 'Laundry Shop',
        'Printing Press', 'Travel & Tours', 'Basketball Team', 'Alumni Association',
        'Barangay Council',
    ];

    private const TOWNS = [
        'Sto. Rosario St., Angeles City', 'Balibago, Angeles City', 'Malabanias, Angeles City',
        'Pandan, Angeles City', 'Dau, Mabalacat, Pampanga', 'Magalang, Pampanga',
        'Porac, Pampanga', 'Apalit, Pampanga', 'Sta. Ana, Pampanga', 'Guagua, Pampanga',
        'San Fernando, Pampanga', 'Clark Freeport Zone, Pampanga', 'Arayat, Pampanga',
        'Mexico, Pampanga', 'Lubao, Pampanga',
    ];

    /**
     * The client book. The hand-written regulars come first, then enough
     * generated names that the shop has a believable spread of customers —
     * fourteen clients sharing a thousand orders would mean every customer
     * ordered seventy times.
     *
     * @return array<int, Client>
     */
    private function makeClients(User $sales): array
    {
        $clients = [];

        foreach (self::CLIENTS as [$first, $last, $company, $contact, $address]) {
            $clients[] = $this->makeClient($sales, $first, $last, $company, $contact, $address);
        }

        $wanted = max(count($clients), intdiv($this->totalOrders(), self::ORDERS_PER_CLIENT));

        for ($n = count($clients); $n < $wanted; $n++) {
            $first = self::FIRST_NAMES[$n % count(self::FIRST_NAMES)];
            $last = self::LAST_NAMES[intdiv($n, 3) % count(self::LAST_NAMES)];

            // Most printing is ordered by a business; the rest by individuals
            // buying shirts for a family reunion or a team.
            $company = $n % 3 === 2
                ? null
                : $last.' '.self::COMPANY_KINDS[$n % count(self::COMPANY_KINDS)];

            $clients[] = $this->makeClient(
                $sales,
                $first,
                $last,
                $company,
                sprintf('09%02d-%03d-%04d', $n % 100, 100 + ($n * 7) % 900, 1000 + ($n * 37) % 9000),
                self::TOWNS[$n % count(self::TOWNS)]
            );
        }

        return $clients;
    }

    private function makeClient(User $sales, string $first, string $last, ?string $company, string $contact, string $address): Client
    {
        return Client::create([
            'name' => $first,
            'last_name' => $last,
            'company' => $company,
            'contact_number' => $contact,
            'office_address' => $address,
            'delivery_address' => $address,
            'tin' => $company ? sprintf('%03d-%03d-%03d-000', rand(100, 999), rand(100, 999), rand(100, 999)) : null,
            'created_by' => $sales->id,
        ]);
    }

    /** How many live jobs sit past their due date, and how many are due today. */
    private const DELAYED_ORDERS = 5;

    private const AT_RISK_ORDERS = 5;

    /**
     * Stops that leave a job ACTIVE. Only an active job chases its deadline —
     * a held, cancelled or finished one never reads as delayed — so the late
     * and due-today jobs have to be drawn from these.
     */
    private const LIVE_STOPS = ['new', 'layout', 'design', 'production', 'sample', 'massprod'];

    /**
     * One stop per station, used for the jobs that are behind. A shop in trouble
     * is stuck in ten different places, not ten times in the same place, so each
     * late and at-risk job is halted somewhere else along the line.
     *
     * These run in pipeline order, and each leaves a different department as the
     * job's current step.
     */
    private const STUCK_STATIONS = [
        'layout',            // Layout
        'design',            // Final mockup
        'at_export',         // Export
        'at_cutting',        // Cutting
        'at_pairing',        // Pairing
        'at_sewing',         // Sewing
        'at_qc',             // Quality control
        'at_sample_review',  // Produce sample for client
        'at_massprod',       // Mass production
        'at_inventory',      // Inventory
    ];

    /**
     * A single date booked to the daily ceiling, so the order form can be seen
     * refusing more work. Kept off the spread below by using an even day offset
     * (the spread walks odd ones), so nothing else lands on it.
     */
    private const FULL_DAY_OFFSET = 30;

    private const FULL_DAY_ORDERS = 4;

    /**
     * How far the deadlines reach, in days. Orders are fanned out ACROSS these
     * windows rather than stepped a fixed gap apart — a fixed step means the
     * more orders there are the further out they reach, which at a thousand
     * orders put finished work in 2018 and left the calendar looking empty.
     *
     * Fanning them out instead keeps a year of history and a quarter of work
     * ahead whatever the shop's size; a busier shop simply stacks more jobs on
     * each day, which is what a busy shop actually looks like.
     */
    private const PAST_WINDOW_DAYS = 365;

    private const AHEAD_WINDOW_DAYS = 90;

    /**
     * The full run of orders: the hand-written PLAN first, then cycled repeats
     * to reach TOTAL_ORDERS.
     *
     * The repeats lean towards finished and cancelled work, because that is what
     * a back catalogue actually looks like — a shop has one screen of live jobs
     * and years of closed ones behind it. Quantities are nudged per round so the
     * rows aren't visibly identical copies.
     *
     * Each entry also carries how its deadline should fall — 'late', 'today',
     * 'ahead' or 'past'. Assigning that here, rather than letting it fall out of
     * an index trick, is what makes the counts exact.
     *
     * @return array<int, array{0: string, 1: array<string, int>, 2: int, 3: string, 4: string}>
     */
    private function plan(): array
    {
        $plan = self::PLAN;
        $backlog = ['complete', 'complete', 'complete', 'complete', 'cancelled', 'hold', 'production', 'massprod'];

        // The full-capacity day is part of the requested total, not an extra on
        // top of it — asking for 1000 orders should give exactly 1000.
        $upTo = max(count($plan), $this->totalOrders() - self::FULL_DAY_ORDERS);

        for ($i = count($plan); $i < $upTo; $i++) {
            [$product, $sizes, $weeks] = self::PLAN[$i % count(self::PLAN)];

            $round = intdiv($i, count(self::PLAN));

            $plan[] = [
                $product,
                array_map(fn ($n) => max(1, $n + (($i * 7 + $round * 3) % 9) - 4), $sizes),
                $weeks + $round * 4,
                $backlog[$i % count($backlog)],
            ];
        }

        // Finished and cancelled work was due back when it was made; everything
        // still live is due ahead, until the two groups below are carved out.
        foreach ($plan as $i => $entry) {
            $plan[$i][4] = in_array($entry[3], ['complete', 'cancelled'], true) ? 'past' : 'ahead';
        }

        // Carve the late and due-today jobs out of the live ones, taken from the
        // end so the hand-written openers at the top keep their intended shape.
        // Each gets its own station, so the two groups together cover the line.
        $live = array_reverse(array_keys(array_filter(
            $plan,
            fn ($entry) => in_array($entry[3], self::LIVE_STOPS, true)
        )));

        $behind = array_slice($live, 0, self::DELAYED_ORDERS + self::AT_RISK_ORDERS);

        foreach ($behind as $n => $i) {
            $plan[$i][3] = self::STUCK_STATIONS[$n % count(self::STUCK_STATIONS)];
            $plan[$i][4] = $n < self::DELAYED_ORDERS ? 'late' : 'today';
        }

        // Finally, the jobs that fill one date to the daily ceiling. Appended
        // after the carve-out above so they are never picked as late work.
        $perOrder = intdiv(ProductionOrder::DAILY_CAPACITY, self::FULL_DAY_ORDERS);

        for ($n = 0; $n < self::FULL_DAY_ORDERS; $n++) {
            // Split into sizes that add up exactly, so the day lands on the cap
            // rather than near it.
            $half = intdiv($perOrder, 2);

            $plan[] = [
                ['round_neck', 'polo', 'jacket_hoodie', 'riding_jersey'][$n % 4],
                ['M' => $half, 'L' => $perOrder - $half],
                4,
                ['at_pairing', 'at_sewing', 'at_export', 'at_massprod'][$n % 4],
                'capacity',
            ];
        }

        return $plan;
    }

    /**
     * Which client placed the nth order.
     *
     * Weighted rather than round-robin: a shop has a handful of regulars who
     * reorder constantly — the uniform club, the trucking company — and a long
     * tail who came once for a reunion shirt. Squaring an even spread bunches
     * the picks towards the front of the book, which is where the regulars are.
     */
    private function clientFor(int $nth, int $count): int
    {
        // Deterministic, so re-running the seeder tells the same story.
        $spread = (($nth * 2654435761) % 1000) / 1000;

        return min($count - 1, (int) floor($spread * $spread * $count));
    }

    /**
     * Where the nth of $of live jobs falls in the coming quarter.
     *
     * The full-capacity day is stepped over: it is booked to exactly the daily
     * ceiling on purpose, and one more job landing on it would tip it into
     * "over capacity" and lose what it is there to show.
     */
    private function aheadOffset(int $nth, int $of): int
    {
        $offset = 1 + intdiv($nth * (self::AHEAD_WINDOW_DAYS - 1), $of);

        return $offset === self::FULL_DAY_OFFSET ? $offset + 1 : $offset;
    }

    /** @return array<int, ProductionOrder> */
    private function makeOrders(array $clients, User $sales, User $leader): array
    {
        $orders = [];

        $plan = $this->plan();

        // How many orders are in each deadline group, so each group can be fanned
        // out evenly across its own window however many there turn out to be.
        $groupTotals = array_count_values(array_column($plan, 4));
        $groupNth = [];

        $lateNth = 0;
        $today = now()->startOfDay();

        // First pass: work out when each job was taken in and when it is due.
        foreach ($plan as $i => [, , , , $deadline]) {
            // This order's position within its own group, and how big that group
            // is — together they place it proportionally along the group's window.
            $nth = $groupNth[$deadline] = ($groupNth[$deadline] ?? -1) + 1;
            $of = max(1, $groupTotals[$deadline] ?? 1);

            $due = match ($deadline) {
                // Past the date and still on the floor: 2, 4, 6… days over, so
                // the list shows a range of "days overdue" rather than one value.
                'late' => $today->copy()->subDays(2 + 2 * $lateNth++),
                // Due today — "may be delayed" while it is still being worked.
                'today' => $today->copy(),
                // A year of finished work behind the shop.
                'past' => $today->copy()->subDays(14 + intdiv($nth * (self::PAST_WINDOW_DAYS - 14), $of)),
                // The one date deliberately booked out to the daily ceiling.
                'capacity' => $today->copy()->addDays(self::FULL_DAY_OFFSET),
                // Live work fanned across the coming quarter.
                default => $today->copy()->addDays($this->aheadOffset($nth, $of)),
            };

            $plan[$i]['due'] = $due;
            // Lead time is not the same on every job: a plain reorder is booked a
            // fortnight out, a big sublimation run two months.
            $plan[$i]['placed'] = $due->copy()->subDays([14, 21, 28, 35, 42, 56][$i % 6]);
        }

        // The order book runs in the order work came in, so the numbering and the
        // ids climb with time — otherwise the newest order by id could be due last
        // year, and "latest first" on the list would mean nothing.
        uasort($plan, fn ($a, $b) => $a['placed']->timestamp <=> $b['placed']->timestamp);

        $perYear = [];

        foreach ($plan as $i => [$product, $sizes, $weeks, $stop, $deadline]) {
            $client = $clients[$this->clientFor($i, count($clients))];
            [$printType, $cutting, $addon, $addonPrice] = self::ROUTING[$i % count(self::ROUTING)];

            $qty = array_sum($sizes);
            $quote = \App\Services\PricingService::quote($product, $qty);

            $due = $plan[$i]['due'];
            $placed = $plan[$i]['placed'];

            // Numbered per the year the job was taken in, the way an order book is.
            $orderYear = $placed->year;
            $sequence = $perYear[$orderYear] = ($perYear[$orderYear] ?? 0) + 1;

            // Every fourth job is a rush, and a couple carry a discount.
            $rush = $i % 4 === 1;
            $rushFee = $rush ? [1500, 2000, 2500, 3000, 3500][intdiv($i, 4) % 5] : null;
            $discount = $i % 6 === 3 ? 1000.0 : 0.0;
            $vat = $client->company !== null && $i % 3 === 0;

            // The options an officer ticks on the form. Each changes something
            // real -- the price, or the shape of the pipeline -- so a demo that
            // never ticks them leaves those paths unseen.
            $supportsPocket = (bool) ($quote['supports_pocket'] ?? false);
            $backPocket = $supportsPocket && $i % 3 === 1;
            // Sometimes every piece, sometimes only part of the run.
            $backPocketQty = $backPocket ? ($i % 10 === 2 ? max(1, intdiv($qty, 2)) : $qty) : null;
            $pocketAmount = $backPocket
                ? $backPocketQty * (float) \App\Services\PricingService::backPocketFee()
                : 0.0;

            // A repeat the client already approved: no first sample, straight
            // to the full run.
            $skipSample = $i % 9 === 4;
            // Jumped up the queue by the leader.
            $massprod = $i % 11 === 6;
            // Embroidery is stitched after sewing, so it adds its own step.
            $embroidery = $addon === 'embroidery' || $i % 8 === 5;

            $unit = $quote['unit'] ?? 700.0;
            $total = ProductionOrder::computeTotal($unit, $qty, $discount, $vat, $pocketAmount, (float) $rushFee);

            $order = ProductionOrder::createJobOrder([
                'order_number' => sprintf('IC%d-%05d', $orderYear, $sequence),
                'client_id' => $client->id,
                'customer_name' => $client->fullName(),
                'product_type' => $product,
                'description' => $this->brief($client, $product),
                'quantity' => $qty,
                'due_date' => $due,
                'rush' => $rush,
                'rush_fee' => $rushFee,
                'back_pocket' => $backPocket,
                'back_pocket_qty' => $backPocketQty,
                'skip_sample' => $skipSample,
                'massprod_priority' => $massprod,
                'unit_price' => $unit,
                'total_price' => $total,
                'vat_inclusive' => $vat,
                'discount_amount' => $discount,
                'discount_note' => $discount > 0 ? 'Repeat client courtesy discount' : null,
                'created_by' => $sales->id,
                'status' => 'active',
                'created_at' => $placed,
                'updated_at' => $placed,
                // The cutting type comes from the routing table above. Passing it
                // is what puts a Cutting step in the pipeline — without it the
                // line skips straight from printing to pairing.
            ], [], $cutting);

            foreach ($sizes as $size => $n) {
                $order->items()->create(['size' => $size, 'quantity' => $n]);
            }

            $order->jobOrder()->create([
                'status' => 'draft',
                'created_by' => $sales->id,
                'print_type' => $printType,
                'printer' => JobOrder::PRINT_TYPES[$printType]['printer'],
                'press' => $addon ? JobOrder::pressForAddon($addon) : null,
                'fabric_press' => JobOrder::PRINT_TYPES[$printType]['press'],
                'addon' => $addon,
                'addon_price' => $addonPrice,
                'needs_embroidery' => $embroidery,
                'fabric' => $this->fabric($product),
                'raw_materials' => $this->materialsFor($product),
                'neck' => 'Ribbed collar, same colour as body',
                'packaging' => 'Individually folded, 1 pc per plastic',
                'special_instructions' => $rush ? 'RUSH — client is picking up on the due date itself.' : null,
            ]);

            // The add-on is priced on the job order, so the total is only final
            // once that exists — same as when the officer saves the sheet.
            $order->refresh()->recomputeTotal();

            $this->advance($order, $stop, $leader);
            $orders[] = $order->fresh();
        }

        return $orders;
    }

    /**
     * Walk the order along the real pipeline until it reaches $stop, so the
     * board and the approvals list show what they would in daily use.
     */
    private function advance(ProductionOrder $order, string $stop, User $leader): void
    {
        if ($stop === 'new') {
            return; // still sitting on the agent's desk
        }

        // Design starts once the layout is released to an artist.
        $order->unlockStage(ProductionOrder::STAGE_LAYOUT);

        if ($stop === 'layout') {
            return;
        }

        // The client approved the layout and paid, so the job order was sent.
        $this->completeStage($order, ProductionOrder::STAGE_LAYOUT);
        $order->jobOrder->update([
            'status' => 'sent_to_artist',
            'sent_to_artist_by' => $leader->id,
            'sent_to_artist_at' => now()->subDays(rand(3, 20)),
        ]);
        $order->unlockStage(2);

        if ($stop === 'design') {
            return;
        }

        // Cancelled and held jobs stop mid-flight, which is where they realistically stall.
        if ($stop === 'cancelled') {
            $order->cancel();

            return;
        }

        $this->completeStage($order, 2);

        // How far down the line the job got. A held job stops part-way — running
        // it to the end would finish it, and hold() does nothing to a job that
        // is already complete.
        //
        // The at_* stops halt one stage short of a named station, so that station
        // is left as the job's current step — that is how a stuck job is made to
        // be stuck somewhere in particular.
        $until = match ($stop) {
            'at_export' => 2,           // waiting at Export
            'at_cutting' => 3,          // waiting at Cutting
            'production' => 5,
            'at_pairing' => 5,          // waiting at Pairing
            'at_sewing' => 6,           // waiting at Sewing
            'at_qc' => 7,               // waiting at Quality control
            'at_sample_review' => 8,    // waiting on the client's sample
            'sample' => 9,
            'at_massprod' => 9,         // waiting at Mass production
            'hold' => 10,
            'massprod' => 11,
            'at_inventory' => 14,       // waiting to be counted into stock
            default => 16,
        };

        for ($stage = 3; $stage <= $until; $stage++) {
            if (! $order->tasks()->where('stage', $stage)->exists()) {
                continue;
            }
            $this->completeStage($order, $stage);
        }

        if ($stop === 'hold') {
            $order->hold();
        }
    }

    /** Mark every task in a stage complete, letting the model open the next one. */
    private function completeStage(ProductionOrder $order, int $stage): void
    {
        $tasks = $order->tasks()->where('stage', $stage)->get();

        foreach ($tasks as $task) {
            // A task can only be worked once it has been released; unlockStage
            // does that for the first stage, handleTaskCompleted for the rest.
            if ($task->status === 'todo') {
                $task->status = 'ready';
            }

            $task->fill([
                'status' => 'complete',
                'submitted_at' => now()->subDays(rand(1, 14)),
                'approved_at' => now()->subDays(rand(0, 1)),
            ])->save();

            $order->refresh()->handleTaskCompleted($task);
        }
    }

    /**
     * What each client put down. Fifty percent is the shop's rule, but not every
     * job follows it — regulars are trusted with less, walk-ins sometimes pay the
     * lot up front — so the ledger is not a column of identical halves.
     */
    private const DOWNPAYMENT_SHARES = [0.5, 0.5, 0.5, 0.5, 0.3, 0.6, 0.4, 1.0, 0.5, 0.75];

    /**
     * The paper trail a shop leaves behind once work has actually run.
     *
     * Orders alone make a tidy-looking database that has never been used: no
     * print file anybody could open, no record of who was on which machine, no
     * finished pieces counted in, no quotation ever printed. Each of these is a
     * screen in the app, and each looks broken when it is empty.
     */
    private function makeShopFloorRecords(array $orders, User $leader): void
    {
        $stations = array_values(array_filter(
            array_keys(\App\Services\Stations::all()),
            fn ($key) => str_starts_with($key, 'printer_') || str_starts_with($key, 'sewing_')
        ));

        $operators = ['Jully', 'Rommie', 'Maru', 'Carla', 'Ton Ton', 'Mick'];
        $i = 0;

        // One image on disk, referenced by every design file. A demo needs the
        // pages to render, not a hundred different pictures.
        [$placeholder, $placeholderSize] = $this->placeholderDesign();

        foreach ($orders as $order) {
            $i++;

            // 1. The print files the artist handed over, as network paths --
            //    which is what the floor opens and what "edit and resend" edits.
            foreach ($order->tasks->where('department', 'Export') as $export) {
                if (! in_array($export->status, ['complete', 'in_progress'], true)) {
                    continue;
                }

                foreach ($export->fileSlots() as $label) {
                    $export->files()->create([
                        'external_path' => sprintf(
                            '\\192.168.150.%d\Designs\%s\%s.tif',
                            230 + ($i % 20),
                            $order->created_at?->format('Y-m') ?? date('Y-m'),
                            $order->order_number
                        ),
                        'original_name' => $order->order_number.'.tif',
                        'label' => $label,
                        'round' => 1,
                        'uploaded_by' => $export->assigned_to ?? $leader->id,
                    ]);
                }
            }

            // 1b. The layout, mockup and template the artist handed in.
            //     Without a design FILE there is no design package to open, and
            //     the button for it never appears on the order — which makes a
            //     finished-looking job look like nothing was ever drawn.
            foreach (['Layout', 'Final mockup', 'Production template'] as $department) {
                $task = $order->tasks->firstWhere('department', $department);

                if (! $task || $task->status !== 'complete') {
                    continue;
                }

                foreach ($task->fileSlots() ?: ['file' => $department] as $label) {
                    $task->files()->create([
                        'path' => $placeholder,
                        'original_name' => $order->order_number.' '.strtolower($department).'.jpg',
                        'label' => $label,
                        'mime' => 'image/jpeg',
                        'size' => $placeholderSize,
                        'round' => 1,
                        'uploaded_by' => $task->assigned_to ?? $leader->id,
                    ]);
                }
            }

            // 2. Who was on which machine, and when they came off. The station
            //    board's handover log is this.
            if ($stations !== [] && $i % 3 === 0) {
                $station = $stations[$i % count($stations)];
                $started = ($order->created_at ?? now())->copy()->addDays(3)->setTime(8 + ($i % 6), 0);

                \App\Models\StationSession::create([
                    'station' => $station,
                    'user_id' => $leader->id,
                    'operator_name' => $operators[$i % count($operators)],
                    'production_order_id' => $order->id,
                    'started_at' => $started,
                    'ended_at' => $started->copy()->addHours(2 + ($i % 4)),
                    'end_reason' => ['done', 'break', 'shift_change'][$i % 3],
                    'note' => $i % 5 === 0 ? 'Handed over mid-run.' : null,
                ]);
            }

            // 3. The finished pieces, counted in at the inventory desk. Without
            //    this the products page is empty however much work has shipped.
            if ($order->status === 'complete') {
                foreach (\App\Models\ProductReceipt::where('production_order_id', $order->id)
                    ->where('status', 'pending')->get() as $receipt) {
                    $product = \App\Models\ProductItem::firstOrCreate(
                        ['name' => $receipt->name],
                        ['unit' => $receipt->unit ?: 'pcs', 'quantity' => 0]
                    );

                    $qty = (float) $receipt->expected_quantity;

                    if ($qty > 0) {
                        $product->recordMovement(
                            $qty,
                            'received',
                            'Received from order '.$order->order_number,
                            $order->id,
                            $operators[$i % count($operators)],
                        );
                    }

                    $receipt->update([
                        'status' => 'received',
                        'received_quantity' => $qty,
                        'received_by' => $leader->id,
                        'received_at' => $order->completed_at ?? now(),
                    ]);
                }
            }

            // 4. The client's paperwork. A delivered job that never produced a
            //    quotation is not a delivered job anybody got paid for.
            if ($order->status === 'complete' && $i % 2 === 0) {
                $type = $order->vat_inclusive
                    ? \App\Models\OrderDocument::TYPE_PQ
                    : \App\Models\OrderDocument::TYPE_DR;

                $defaults = \App\Models\OrderDocument::defaultsFor($order, $type);

                $order->documents()->create([
                    'type' => $type,
                    'number' => $defaults['number'],
                    'items' => $defaults['items'],
                    'fields' => $defaults['fields'],
                    'created_by' => $order->created_by,
                ]);
            }
        }
    }

    /**
     * A single stand-in design image, stored once and pointed at by every
     * layout, mockup and template in the demo.
     *
     * @return array{0: string, 1: int} storage path, size in bytes
     */
    private function placeholderDesign(): array
    {
        $path = 'task-files/demo-design.jpg';

        if (! \Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
            $img = imagecreatetruecolor(900, 900);
            imagefill($img, 0, 0, imagecolorallocate($img, 244, 246, 250));

            // Something recognisable rather than a blank square, so the mockup
            // on the sheet reads as artwork at a glance.
            imagefilledrectangle($img, 250, 300, 650, 520, imagecolorallocate($img, 227, 27, 35));
            imagefilledellipse($img, 450, 250, 220, 220, imagecolorallocate($img, 37, 99, 235));
            imagestring($img, 5, 330, 600, 'IMPRINT CUSTOMS', imagecolorallocate($img, 30, 41, 59));
            imagestring($img, 3, 360, 630, 'sample artwork', imagecolorallocate($img, 100, 116, 139));

            ob_start();
            imagejpeg($img, null, 82);
            $bytes = (string) ob_get_clean();
            imagedestroy($img);

            \Illuminate\Support\Facades\Storage::disk('local')->put($path, $bytes);
        }

        return [$path, (int) \Illuminate\Support\Facades\Storage::disk('local')->size($path)];
    }

    private function makePayments(array $orders, User $finance): void
    {
        foreach ($orders as $index => $order) {
            if ($order->status === 'cancelled' || $order->total_price === null) {
                continue;
            }

            $total = (float) $order->total_price;
            $method = \App\Models\Payment::METHODS[array_rand(\App\Models\Payment::METHODS)];

            // Nothing is produced without a downpayment, so anything past layout has one.
            $started = $order->tasks()->where('stage', '>', 1)->where('status', 'complete')->exists();
            if (! $started) {
                continue;
            }

            $share = self::DOWNPAYMENT_SHARES[$index % count(self::DOWNPAYMENT_SHARES)];
            $down = round($total * $share, 2);

            $order->payments()->create([
                'amount' => $down,
                'method' => $method,
                'kind' => $share >= 1.0 ? 'full' : 'downpayment',
                'reference' => $method === 'Cash' ? null : strtoupper(bin2hex(random_bytes(4))),
                // Some pay on the spot, some a few days later once the layout is
                // approved and they are sure the job is going ahead.
                'paid_at' => $order->created_at?->copy()->addDays($index % 6) ?? now()->subMonth(),
                'recorded_by' => $finance->id,
            ]);

            // The balance lands when the job is finished and released.
            if ($order->status === 'complete' && $down < $total) {
                $order->payments()->create([
                    'amount' => round($total - $down, 2),
                    'method' => $method,
                    'kind' => 'full',
                    'reference' => $method === 'Cash' ? null : strtoupper(bin2hex(random_bytes(4))),
                    'paid_at' => $order->completed_at ?? now()->subDays(rand(1, 30)),
                    'recorded_by' => $finance->id,
                ]);
            }
        }
    }

    /** Roughly four months of shop spending, so the books have something to show. */
    private function makeExpenses(User $finance): void
    {
        // Kept a little under what the orders bring in, so the books show a
        // working shop rather than one running at a loss.
        $recurring = [
            ['rent', 'Shop rent', 22000, 1],
            ['salaries', 'Staff payroll', 24000, 15],
            ['salaries', 'Staff payroll', 24000, 28],
            ['utilities', 'Meralco', 9800, 8],
            ['utilities', 'Water district', 1150, 8],
            ['utilities', 'Converge fibre', 2699, 5],
            ['taxes', 'BIR monthly percentage tax', 2400, 20],
        ];

        $occasional = [
            ['raw_materials', 'Sublimation paper, 5 rolls', 8500],
            ['raw_materials', 'Cotton shirt stock — assorted sizes', 16400],
            ['raw_materials', 'DTF film and hot melt powder', 6200],
            ['raw_materials', 'Polo shirt stock restock', 12900],
            ['supplies', 'Packaging plastic and tape', 1300],
            ['supplies', 'Thermal paper for labels', 850],
            ['equipment', 'Heat press maintenance', 2500],
            ['equipment', 'Atexco printhead cleaning kit', 3400],
            ['equipment', 'Sewing machine servicing', 1800],
            ['delivery', 'Lalamove — client deliveries', 1750],
            ['delivery', 'Fuel, shop van', 2200],
            ['marketing', 'Facebook ads boost', 3000],
            ['marketing', 'Tarpaulin and flyers', 1600],
            ['other', 'Office pantry and supplies', 1400],
        ];

        // Costs run as far back as the order history does, so the books compare
        // like with like — a year of income against four months of rent and
        // wages would show a shop that cannot possibly be losing money.
        $months = (int) ceil(self::PAST_WINDOW_DAYS / 30);

        for ($monthsAgo = $months; $monthsAgo >= 0; $monthsAgo--) {
            $month = now()->subMonths($monthsAgo);

            foreach ($recurring as [$category, $what, $amount, $day]) {
                $when = $month->copy()->day(min($day, $month->daysInMonth));
                if ($when->isFuture()) {
                    continue;
                }

                Expense::create([
                    'category' => $category,
                    'description' => $what.' — '.$when->format('F Y'),
                    'amount' => $amount,
                    'spent_at' => $when,
                    'method' => $category === 'salaries' ? 'Cash' : 'Bank Transfer',
                    'recorded_by' => $finance->id,
                ]);
            }

            // A handful of one-off purchases scattered through the month.
            foreach (array_rand($occasional, 5) as $pick) {
                [$category, $what, $amount] = $occasional[$pick];
                $when = $month->copy()->day(rand(1, min(28, $month->daysInMonth)));
                if ($when->isFuture()) {
                    continue;
                }

                Expense::create([
                    'category' => $category,
                    'description' => $what,
                    'amount' => $amount + rand(-500, 500),
                    'spent_at' => $when,
                    'method' => ['Cash', 'GCash', 'Bank Transfer'][rand(0, 2)],
                    'recorded_by' => $finance->id,
                ]);
            }
        }
    }

    /** Raw-material stock, shaped like the shop's own stock sheet. */
    private function makeStock(): void
    {
        $sizes = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL'];
        $colors = ['White', 'Black', 'Navy Blue', 'Red', 'Royal Blue', 'Gray', 'Maroon'];

        foreach (['COTTON SHIRT', 'POLO SHIRT', 'HOODIE', 'JACKET', 'LONGSLEEVE', 'SANDO'] as $category) {
            $label = InventoryItem::CATEGORIES[$category];

            foreach ($colors as $color) {
                foreach ($sizes as $size) {
                    // Not every colour is stocked in every size, same as real life.
                    if (rand(1, 10) === 1) {
                        continue;
                    }

                    $qty = rand(0, 90);
                    InventoryItem::create([
                        'name' => "$label $color $size",
                        'category' => $category,
                        'color' => $color,
                        'size' => $size,
                        'unit' => 'pc',
                        'quantity' => $qty,
                        'beginning_stock' => $qty + rand(0, 40),
                    ]);
                }
            }
        }

        // Consumables, counted by their own units rather than by size.
        foreach ([
            ['BOND PAPER HARD COPY', 'Sublimation paper roll', 'roll', 26],
            ['BOND PAPER HARD COPY', 'DTF film roll', 'roll', 12],
            ['HOT MELT', 'Hot melt powder', 'kg', 34],
            ['PLASTIC', 'Packaging plastic', 'pack', 58],
            ['TAPE', 'Packing tape', 'pc', 41],
            ['BOX', 'Shipping box, medium', 'pc', 120],
            ['PAPER BAG', 'Paper bag with handle', 'pc', 210],
            ['THERMAL PAPER', 'Thermal label roll', 'roll', 18],
            ['MUGS', 'Sublimation mug, 11oz', 'pc', 96],
            ['MOUSE PAD', 'Sublimation mouse pad', 'pc', 74],
            ['ECO BAG', 'Eco bag, natural', 'pc', 150],
            ['CANVASS BAG', 'Canvass tote bag', 'pc', 88],
            ['TOWEL', 'Sublimation towel', 'pc', 63],
            ['UMBRELLA', 'Printable umbrella', 'pc', 45],
        ] as [$category, $name, $unit, $qty]) {
            InventoryItem::create([
                'name' => $name,
                'category' => $category,
                'unit' => $unit,
                'quantity' => $qty,
                'beginning_stock' => $qty + rand(5, 60),
            ]);
        }
    }

    /** Share of orders that have been talked about at all. */
    private const TALKED_ABOUT_SHARE = 3;

    /**
     * Job conversations. Roughly one order in three has been discussed, which is
     * what the inbox looks like in daily use: a wall of recent threads with the
     * quiet jobs behind them.
     *
     * Most threads are marked read, so the unread badge means something — seeding
     * every message unread would put a four-figure number on the sidebar and make
     * the badge worth ignoring.
     */
    private function makeMessages(array $orders, array $people): void
    {
        $people = array_values(array_filter($people));

        $threads = [
            ['Client confirmed the layout. Sizes are final, no more changes.', 'Noted. Sending to production today.'],
            ['Client is asking if we can move the pickup one day earlier.', 'Doable if the press finishes tonight. Let me check with the floor.', 'Confirmed, we can release a day early.'],
            ['Downpayment received, 50%. Balance on pickup.', 'Thanks — releasing the job order now.'],
            ['Reminder: this one is a rush. Please prioritise on the press.', 'Copy, queued it next after the current batch.'],
            ['Stock check: do we still have navy blue in XL?', 'Yes, 12 pcs left. Reserved for this order.'],
            ['Client wants the logo 1cm bigger on the front. Artwork re-sent.', 'Got it, updating the layout now.', 'Reuploaded. Please check before we export.'],
            ['Sample is ready for pickup at the front desk.', 'Client is coming Saturday morning.'],
            ['Two pcs failed QC — stitching on the collar. Redoing them.', 'Noted, adjust the count on the release.'],
            ['Delivery van is booked for Friday afternoon.', 'Thanks. I told the client 3pm onwards.'],
            ['Client asked for an official receipt this time.', 'Will prepare it with the balance payment.'],
            ['Please double check the size breakdown before cutting.', 'Checked against the JO, it matches.'],
            ['Fabric came in a shade lighter than the swatch.', 'Client approved it over Viber, proceed.', 'Copy, running it today.'],
        ];

        foreach ($orders as $i => $order) {
            if ($i % self::TALKED_ABOUT_SHARE !== 0) {
                continue;
            }

            $thread = $threads[$i % count($threads)];

            // Recent orders were talked about recently; older ones long ago, so
            // the inbox sorts into something believable.
            $age = max(1, (int) min(120, abs($i - count($orders)) / 8));
            $started = now()->subDays(rand(1, $age));

            $senders = [];

            foreach ($thread as $n => $body) {
                $sender = $people[($i + $n) % count($people)];
                $senders[$sender->id] = $sender;

                Message::create([
                    'production_order_id' => $order->id,
                    'sender_id' => $sender->id,
                    'body' => $body,
                    'created_at' => $started->copy()->addMinutes($n * 7),
                ]);
            }

            // Every fifth thread is left unread, so the badge has something real
            // to count; the rest are caught up.
            if ($i % (self::TALKED_ABOUT_SHARE * 5) === 0) {
                continue;
            }

            foreach ($senders as $person) {
                Message::markRead($person, $order->id);
            }
        }
    }

    private function brief(Client $client, string $product): string
    {
        $who = $client->company ?: $client->fullName();
        $what = \App\Services\PricingService::label($product);

        return "$what for $who. Logo in front, name at the back. Client sent the artwork by Viber.";
    }

    private function fabric(string $product): string
    {
        return match ($product) {
            'polo' => 'Honeycomb pique, colour per approved layout',
            'jacket_hoodie' => 'Fleece, brushed inside',
            'riding_jersey' => 'Dri-fit micro mesh',
            default => 'Cotton combed 24s',
        };
    }

    /** @return array<int, string> */
    private function materialsFor(string $product): array
    {
        return match ($product) {
            'polo' => ['Polo shirt blank', 'Sublimation paper roll', 'Neck label'],
            'jacket_hoodie' => ['Hoodie blank', 'DTF film roll', 'Hot melt powder', 'Zipper'],
            'riding_jersey' => ['Dri-fit fabric', 'Sublimation paper roll', 'Hot melt powder'],
            default => ['Cotton shirt blank', 'Sublimation paper roll', 'Neck label'],
        };
    }
}
