<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The heavy pages keep their CSS in their own stylesheet and push it onto the
 * layout's @stack('styles'). If a push or the stack goes missing the page still
 * returns 200 — it just renders unstyled — so it needs asserting explicitly.
 */
class PageStylesheetTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('pages')]
    public function test_the_page_links_its_own_stylesheet(string $path, string $css): void
    {
        $admin = User::factory()->create([
            'job_role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);

        $html = $this->actingAs($admin)->get($path)->assertOk()->getContent();

        $this->assertStringContainsString("css/{$css}.css", $html, "{$path} should link {$css}.css");
        // And the shared sheet is still there.
        $this->assertStringContainsString('css/app.css', $html);
        // The CSS must no longer be inlined into the HTML.
        $this->assertStringNotContainsString('<style>', $html, "{$path} should not inline a <style> block");
    }

    public static function pages(): array
    {
        return [
            'dashboard' => ['/dashboard', 'dashboard'],
            'calendar' => ['/calendar', 'calendar'],
            'orders' => ['/orders', 'orders-index'],
            'inventory' => ['/inventory', 'inventory-index'],
        ];
    }

    #[DataProvider('pages')]
    public function test_the_stylesheet_file_exists_and_is_not_empty(string $path, string $css): void
    {
        $file = public_path("css/{$css}.css");

        $this->assertFileExists($file);
        $this->assertGreaterThan(500, filesize($file), "{$css}.css looks too small to be the real styles");
    }
}
