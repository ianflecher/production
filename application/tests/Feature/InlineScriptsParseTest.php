<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Every inline <script> on a page has to be valid JavaScript.
 *
 * One syntax error kills the WHOLE block and the browser says nothing: the
 * page renders, then nothing on it works. A stray newline inside a string
 * literal took out the order form exactly this way — the client fields stopped
 * hiding, the price stopped calculating, the size boxes went dead — and it read
 * as three unrelated bugs rather than one broken script.
 *
 * Every other test looks at the HTML, so none of them could see it.
 *
 * The check asks a real JavaScript engine, because the fault is a parse error
 * and only a parser finds those reliably. A first attempt at this counted
 * quotes and brackets by hand; it flagged perfectly good pages and missed the
 * point, which is why it is not what runs here.
 */
class InlineScriptsParseTest extends TestCase
{
    use RefreshDatabase;

    /** node is used as the parser; without it there is nothing honest to assert. */
    private function requireNode(): void
    {
        if (Process::run('node --version')->failed()) {
            $this->markTestSkipped('node is not available to parse the scripts with');
        }
    }

    /** @return array<int, string> the parse errors in a page's inline scripts */
    private function parseErrors(string $html): array
    {
        preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $m);

        $errors = [];

        foreach ($m[1] as $i => $code) {
            if (trim($code) === '') {
                continue;
            }

            $file = tempnam(sys_get_temp_dir(), 'js').'.js';
            file_put_contents($file, $code);

            // --check parses without running: no fetch, no DOM, no side effects.
            $result = Process::run(['node', '--check', $file]);
            @unlink($file);

            if ($result->failed()) {
                $first = trim(explode("\n", trim($result->errorOutput()))[2] ?? $result->errorOutput());
                $errors[] = 'script block '.($i + 1).': '.$first;
            }
        }

        return $errors;
    }

    public function test_the_check_notices_a_string_broken_over_two_lines(): void
    {
        $this->requireNode();

        // The exact shape of the fault, so this test is known to bite.
        $this->assertNotEmpty($this->parseErrors("<script>\n  var x = 'oops\n\n';\n</script>"));
    }

    public function test_the_check_passes_healthy_script(): void
    {
        $this->requireNode();

        // chr(92) is a backslash. Written as an escape it gets eaten somewhere
        // between here and the file — which is how the original fault got in.
        $esc = chr(92).'n';
        $ok = "<script>
  var x = 'fine{$esc}{$esc}';
  function f() { return [1, 2]; }
</script>";

        $this->assertSame([], $this->parseErrors($ok));
    }


    /**
     * The order form is step two now: it needs the inquiry that carries the
     * client. Everything else on the list is reached directly.
     */
    private function withInquiry(string $path): string
    {
        if ($path !== '/orders/create') {
            return $path;
        }

        $client = \App\Models\Client::create(['name' => 'Smoke', 'last_name' => 'Client']);

        $inquiry = \App\Models\Inquiry::create([
            'client_id' => $client->id,
            'created_by' => auth()->id(),
            'status' => \App\Models\Inquiry::STATUS_OPEN,
        ]);

        return $path.'?inquiry='.$inquiry->id;
    }

    /**
     * The tech pack sheet, in the mode where an artist edits it.
     *
     * Not reachable as a plain URL - it needs an order, an approved mockup and
     * a sent pack behind it - so it is rendered directly. It was the one page
     * this check did not cover, and a dropped ")();" in its script killed every
     * handler on it, including the click that uploads a picture. From the
     * artist's side that is not "a script is broken", it is "I cannot work".
     */
    public function test_the_editable_tech_pack_sheet_parses(): void
    {
        $this->requireNode();

        $officer = \App\Models\User::factory()->create(['job_role' => \App\Models\User::ROLE_SALES, 'is_active' => true]);

        $order = \App\Models\ProductionOrder::create([
            'order_number' => 'IC2026-09999',
            'customer_name' => 'Juan Dela Cruz',
            'client_id' => \App\Models\Client::create([
                'name' => 'Juan', 'last_name' => 'Dela Cruz', 'contact_number' => '0917',
                'office_address' => 'Angeles City', 'delivery_address' => 'Angeles City',
                'created_by' => $officer->id,
            ])->id,
            'product_type' => 'round_neck',
            'quantity' => 10,
            'unit_price' => 500,
            'total_price' => 5000,
            'due_date' => now()->addWeeks(3)->toDateString(),
            'status' => 'active',
            'created_by' => $officer->id,
        ]);

        $this->actingAs($officer);

        $html = view('partials.tech-pack', ['order' => $order->fresh(), 'editable' => true])->render();

        $this->assertSame([], $this->parseErrors($html));
    }

    public static function pages(): array
    {
        return [
            'new order' => ['/orders/create'],
            'my tasks' => ['/my-tasks'],
            'stations' => ['/stations'],
            'calendar' => ['/calendar'],
            'inventory' => ['/inventory'],
            'products' => ['/products'],
            'finance' => ['/finance'],
            'books' => ['/books'],
            'users' => ['/users'],
            'messages' => ['/messages'],
            'artist layouts' => ['/layouts'],
            'material requests' => ['/material-requests'],
            'bottlenecks' => ['/reports/bottlenecks'],
            'dashboard' => ['/dashboard'],
        ];
    }

    /** @dataProvider pages */
    public function test_a_pages_inline_scripts_parse(string $url): void
    {
        $this->requireNode();

        $admin = User::factory()->create(['job_role' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);
        $this->actingAs($admin);

        $html = $this->get($this->withInquiry($url))->assertOk()->getContent();

        $this->assertSame([], $this->parseErrors($html), $url.' has broken inline JavaScript');
    }

    public function test_the_order_edit_page_too(): void
    {
        $this->requireNode();

        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $order = ProductionOrder::create([
            'order_number' => 'IC2026-09200', 'customer_name' => 'Script Co',
            'product_type' => 'round_neck', 'quantity' => 10,
            'due_date' => now()->addMonth(), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        $html = $this->actingAs($sales)->get("/orders/{$order->id}/edit")->assertOk()->getContent();

        $this->assertSame([], $this->parseErrors($html));
    }

    public function test_the_public_client_questionnaire_scripts_parse(): void
    {
        $this->requireNode();

        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $order = ProductionOrder::create([
            'order_number' => 'IC2026-09201', 'customer_name' => 'Public Script Co',
            'product_type' => 'round_neck', 'quantity' => 10,
            'due_date' => now()->addMonth(), 'created_by' => $sales->id, 'status' => 'active',
        ]);
        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);
        $order->regenerateBriefLink();

        $html = $this->get('/imprint-customs/design-questionnaire/'.$order->brief_token)
            ->assertOk()->getContent();

        $this->assertSame([], $this->parseErrors($html));
    }
}
