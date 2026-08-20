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
        $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

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
}
