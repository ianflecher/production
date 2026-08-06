<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The file-path box on an export step is pre-filled with the address of the PC
 * the artist is sitting at, so they only type the folder and file after it.
 *
 * That address is read from the connection each time the page is drawn. It used
 * to come from a list of names and addresses typed into the template, which was
 * right on the day it was written and maintained by nobody: the router hands out
 * addresses and changes them, and a stale one sends production to a machine that
 * isn't the artist's.
 */
class ArtistFilePathPrefillTest extends TestCase
{
    use RefreshDatabase;

    /** An artist part-way through an export step, which is what asks for a path. */
    private function artistAtWork(): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-04400',
            'customer_name' => 'Path Co',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2),
            'status' => 'active',
            'created_by' => $sales->id,
        ]);

        $task = $order->tasks()->create([
            'sequence' => 1,
            'stage' => 3,
            'department' => 'Export',
            'status' => 'in_progress',
            'approver_role' => 'leader',
            'assigned_to' => $artist->id,
        ]);

        return [$artist, $task];
    }

    /** Ask for the page as though the browser were on a given address. */
    private function pageFrom(User $artist, Task $task, string $ip)
    {
        return $this->actingAs($artist)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get("/my-tasks/{$task->id}");
    }

    public function test_it_offers_the_address_of_the_pc_asking_for_the_page(): void
    {
        [$artist, $task] = $this->artistAtWork();

        $this->pageFrom($artist, $task, '192.168.150.233')
            ->assertOk()
            ->assertSee('\\\\192.168.150.233\\', false);
    }

    public function test_the_same_artist_at_a_different_pc_gets_that_pc(): void
    {
        [$artist, $task] = $this->artistAtWork();

        // Somebody moves desks, or the router hands out a new address. Nothing
        // is remembered, so there is nothing to go stale.
        $this->pageFrom($artist, $task, '192.168.150.240')
            ->assertOk()
            ->assertSee('\\\\192.168.150.240\\', false)
            ->assertDontSee('\\\\192.168.150.233\\', false);
    }

    public function test_no_addresses_are_baked_into_the_page(): void
    {
        [$artist, $task] = $this->artistAtWork();

        $body = $this->pageFrom($artist, $task, '192.168.150.201')->getContent();

        // The old template carried a name-to-address list. Any address other
        // than the one asking means something is hardcoded again.
        foreach (['192.168.150.232', '192.168.150.238', '192.168.150.252',
            '192.168.150.249', '192.168.150.242', '192.168.150.227'] as $old) {
            $this->assertStringNotContainsString($old, $body, "$old is hardcoded in the page");
        }
    }

    public function test_reaching_it_from_outside_offers_nothing_rather_than_something_wrong(): void
    {
        [$artist, $task] = $this->artistAtWork();

        // Through the tunnel the server sees the tunnel, not the artist's PC.
        $response = $this->pageFrom($artist, $task, '104.28.14.7')->assertOk();

        $response->assertSee('\\\\server\\FolderName\\file...', false);
        $response->assertDontSee('\\\\104.28.14.7\\', false);
    }

    public function test_loopback_is_not_offered_either(): void
    {
        [$artist, $task] = $this->artistAtWork();

        // Nobody else on the network can open \\127.0.0.1\...
        $this->pageFrom($artist, $task, '127.0.0.1')
            ->assertOk()
            ->assertDontSee('\\\\127.0.0.1\\', false);
    }

    public function test_the_artist_can_still_type_over_it(): void
    {
        [$artist, $task] = $this->artistAtWork();

        // It is a prefill, not a fixed value — the box stays editable.
        $this->pageFrom($artist, $task, '192.168.150.233')
            ->assertOk()
            ->assertSee('name="paths[', false);
    }
}
