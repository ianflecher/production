<?php

namespace Tests\Feature;

use App\Models\TaskFile;
use App\Services\ServerIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Artist file paths must survive the server's DHCP address changing on a
 * restart: a path pointing at this machine is stored with a marker and always
 * read back with the current IP.
 */
class ServerIpPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_current_ip_is_a_real_private_address(): void
    {
        $ip = ServerIp::current();

        $this->assertNotNull($ip, 'the server should know its own LAN address');
        $this->assertTrue(ServerIp::isPrivate($ip));
        $this->assertStringStartsNotWith('127.', $ip, 'loopback is not reachable by other PCs');
    }

    public function test_loopback_and_public_addresses_are_not_treated_as_the_office_network(): void
    {
        $this->assertFalse(ServerIp::isPrivate('127.0.0.1'));
        $this->assertFalse(ServerIp::isPrivate('8.8.8.8'));
        $this->assertTrue(ServerIp::isPrivate('192.168.1.5'));
        $this->assertTrue(ServerIp::isPrivate('10.0.0.9'));
    }

    public function test_a_path_pointing_at_this_server_is_stored_without_the_address(): void
    {
        $ip = ServerIp::current();
        $file = new TaskFile();
        $file->external_path = "\\\\{$ip}\\Designs\\IC2026-00002.ai";

        // What actually goes into the column has the marker, not the address.
        $this->assertStringContainsString(ServerIp::TOKEN, $file->getAttributes()['external_path']);
        $this->assertStringNotContainsString($ip, $file->getAttributes()['external_path']);
    }

    public function test_the_path_reads_back_with_the_current_address(): void
    {
        $ip = ServerIp::current();
        $file = new TaskFile();
        $file->external_path = "\\\\{$ip}\\Designs\\IC2026-00002.ai";

        $this->assertSame("\\\\{$ip}\\Designs\\IC2026-00002.ai", $file->external_path);
    }

    public function test_a_path_saved_under_an_old_address_comes_back_with_the_new_one(): void
    {
        // Simulate what is already in the column after the IP moved.
        $file = new TaskFile();
        $file->setRawAttributes(['external_path' => '\\\\'.ServerIp::TOKEN.'\\Designs\\job.ai']);

        $this->assertSame(
            '\\\\'.ServerIp::current().'\\Designs\\job.ai',
            $file->external_path,
            'the stored marker should expand to the address the server has now'
        );
    }

    public function test_a_path_on_a_DIFFERENT_machine_is_left_alone(): void
    {
        $other = '192.168.150.77';
        $this->assertNotSame($other, ServerIp::current(), 'test needs an address that is not ours');

        $file = new TaskFile();
        $file->external_path = "\\\\{$other}\\NAS\\artwork.psd";

        // Stored verbatim…
        $this->assertStringContainsString($other, $file->getAttributes()['external_path']);
        // …and read back verbatim. We must not redirect it at our own server.
        $this->assertSame("\\\\{$other}\\NAS\\artwork.psd", $file->external_path);
    }

    public function test_a_web_link_is_untouched(): void
    {
        $file = new TaskFile();
        $file->external_path = 'https://drive.google.com/file/d/abc123/view';

        $this->assertSame('https://drive.google.com/file/d/abc123/view', $file->external_path);
    }

    public function test_an_empty_path_stays_empty(): void
    {
        $file = new TaskFile();
        $file->external_path = null;

        $this->assertNull($file->external_path);
    }

    // ---- The artist's own PC ----------------------------------------------

    public function test_login_records_the_address_the_person_signed_in_from(): void
    {
        $artist = \App\Models\User::factory()->create([
            'email' => 'artist@example.com',
            'password' => 'imprint123',
            'job_role' => \App\Models\User::JOB_ARTIST,
            'is_active' => true,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.150.44'])
            ->post('/login', ['email' => 'artist@example.com', 'password' => 'imprint123']);

        $this->assertSame('192.168.150.44', $artist->fresh()->last_login_ip);
    }

    public function test_a_path_follows_the_artist_when_they_move_to_another_pc(): void
    {
        $artist = \App\Models\User::factory()->create([
            'job_role' => \App\Models\User::JOB_ARTIST,
            'is_active' => true,
            'last_login_ip' => '192.168.150.44',
        ]);

        // The artist records a file sitting on the PC they are using today.
        $this->actingAs($artist);
        $file = new TaskFile();
        $file->uploaded_by = $artist->id;
        $file->external_path = '\\\\192.168.150.44\\Designs\\job.ai';

        // Their address is not baked into the column…
        $this->assertStringNotContainsString('192.168.150.44', $file->getAttributes()['external_path']);

        // …so tomorrow, on a different PC, the path points at the new machine.
        $artist->forceFill(['last_login_ip' => '192.168.150.77'])->save();
        $file->setRelation('uploader', $artist->fresh());

        $this->assertSame('\\\\192.168.150.77\\Designs\\job.ai', $file->external_path);
    }

    public function test_a_path_falls_back_to_the_server_when_the_artist_has_never_logged_in(): void
    {
        $artist = \App\Models\User::factory()->create([
            'job_role' => \App\Models\User::JOB_ARTIST,
            'is_active' => true,
            'last_login_ip' => null,
        ]);

        $file = new TaskFile();
        $file->setRelation('uploader', $artist);
        $file->setRawAttributes(['external_path' => '\\\\'.ServerIp::TOKEN.'\\Designs\\job.ai']);

        $this->assertSame(
            '\\\\'.ServerIp::current().'\\Designs\\job.ai',
            $file->external_path
        );
    }
}
