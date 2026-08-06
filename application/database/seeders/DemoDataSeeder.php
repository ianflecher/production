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
    /** Where each demo order should stop along the pipeline. */
    private const PLAN = [
        // [product, qty spread, weeks until due, how far it got, notes]
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

    /** @return array<int, Client> */
    private function makeClients(User $sales): array
    {
        $clients = [];

        foreach (self::CLIENTS as [$first, $last, $company, $contact, $address]) {
            $clients[] = Client::create([
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

        return $clients;
    }

    /** @return array<int, ProductionOrder> */
    private function makeOrders(array $clients, User $sales, User $leader): array
    {
        $orders = [];
        $year = now()->year;

        foreach (self::PLAN as $i => [$product, $sizes, $weeks, $stop]) {
            $client = $clients[$i % count($clients)];
            [$printType, $cutting, $addon, $addonPrice] = self::ROUTING[$i % count(self::ROUTING)];

            $qty = array_sum($sizes);
            $quote = \App\Services\PricingService::quote($product, $qty);

            // Finished jobs were ordered in the past; live ones are due ahead.
            $done = in_array($stop, ['complete', 'cancelled'], true);
            $due = $done ? now()->subWeeks($weeks - 6)->startOfDay() : now()->addWeeks($weeks)->startOfDay();

            // A real shop is never all green: leave a couple of live jobs sitting
            // on or past their date so the deadline alerts have something to show.
            if (! $done) {
                if ($i % 7 === 3) {
                    $due = now()->subDays(2 + ($i % 4))->startOfDay();   // already late
                } elseif ($i % 7 === 5) {
                    $due = now()->startOfDay();                          // due today
                }
            }

            $placed = (clone $due)->subWeeks(4);

            // Every fourth job is a rush, and a couple carry a discount.
            $rush = $i % 4 === 1;
            $rushFee = $rush ? [1500, 2000, 2500, 3000, 3500][intdiv($i, 4) % 5] : null;
            $discount = $i % 6 === 3 ? 1000.0 : 0.0;
            $vat = $client->company !== null && $i % 3 === 0;

            $unit = $quote['unit'] ?? 700.0;
            $total = ProductionOrder::computeTotal($unit, $qty, $discount, $vat, 0, (float) $rushFee);

            $order = ProductionOrder::createJobOrder([
                'order_number' => sprintf('IC%d-%05d', $year, $i + 1),
                'client_id' => $client->id,
                'customer_name' => $client->fullName(),
                'product_type' => $product,
                'description' => $this->brief($client, $product),
                'quantity' => $qty,
                'due_date' => $due,
                'rush' => $rush,
                'rush_fee' => $rushFee,
                'unit_price' => $unit,
                'total_price' => $total,
                'vat_inclusive' => $vat,
                'discount_amount' => $discount,
                'discount_note' => $discount > 0 ? 'Repeat client courtesy discount' : null,
                'created_by' => $sales->id,
                'status' => 'active',
                'created_at' => $placed,
                'updated_at' => $placed,
            ], [], null);

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

        // How far down the line the job got.
        $until = match ($stop) {
            'production' => 5,
            'sample' => 9,
            'massprod' => 11,
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

    private function makePayments(array $orders, User $finance): void
    {
        foreach ($orders as $order) {
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

            $order->payments()->create([
                'amount' => round($total * 0.5, 2),
                'method' => $method,
                'kind' => 'downpayment',
                'reference' => $method === 'Cash' ? null : strtoupper(bin2hex(random_bytes(4))),
                'paid_at' => $order->created_at?->copy()->addDays(2) ?? now()->subMonth(),
                'recorded_by' => $finance->id,
            ]);

            // The balance lands when the job is finished and released.
            if ($order->status === 'complete') {
                $order->payments()->create([
                    'amount' => round($total - round($total * 0.5, 2), 2),
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

        for ($monthsAgo = 3; $monthsAgo >= 0; $monthsAgo--) {
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

    /** A few job conversations, so the message tab isn't empty. */
    private function makeMessages(array $orders, array $people): void
    {
        $people = array_values(array_filter($people));
        $threads = [
            ['Client confirmed the layout. Sizes are final, no more changes.', 'Noted. Sending to production today.'],
            ['Client is asking if we can move the pickup one day earlier.', 'Doable if the press finishes tonight. Let me check with the floor.', 'Confirmed, we can release a day early.'],
            ['Downpayment received, 50%. Balance on pickup.', 'Thanks — releasing the job order now.'],
            ['Reminder: this one is a rush. Please prioritise on the press.', 'Copy, queued it next after the current batch.'],
            ['Stock check: do we still have navy blue in XL?', 'Yes, 12 pcs left. Reserved for this order.'],
        ];

        foreach (array_slice($orders, 0, 10) as $i => $order) {
            $thread = $threads[$i % count($threads)];

            foreach ($thread as $n => $body) {
                Message::create([
                    'production_order_id' => $order->id,
                    'sender_id' => $people[($i + $n) % count($people)]->id,
                    'body' => $body,
                    'created_at' => now()->subDays(rand(1, 15))->subMinutes($n * 7),
                ]);
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
