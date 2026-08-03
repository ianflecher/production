<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * The users table has no `role` column.
         * All access/job values are therefore stored in `job_role`.
         * The `id` column is auto-incrementing, so this seeder matches users
         * by their unique email address instead of forcing IDs.
         */
        $accounts = [
            // System accounts
            [
                'name' => 'Super Admin',
                'email' => 'admin@imprintcustoms.ph',
                'job_role' => User::ROLE_SUPER_ADMIN,
                'team' => null,
            ],
            [
                'name' => 'Maam Carla',
                'email' => 'leader@imprintcustoms.ph',
                'job_role' => User::ROLE_LEADER,
                'team' => null,
            ],
            [
                'name' => 'Sir Boying',
                'email' => 'Supervisor@imprintcustoms.ph',
                'job_role' => "Supervisor",
                'team' => null,
            ],

            // Artists
            [
                'name' => 'Maru',
                'email' => 'artist1@imprintcustoms.ph',
                'job_role' => User::JOB_ARTIST,
                'team' => null,
            ],
            [
                'name' => 'Ian',
                'email' => 'artist2@imprintcustoms.ph',
                'job_role' => User::JOB_ARTIST,
                'team' => null,
            ],
            [
                'name' => 'Mick',
                'email' => 'artist3@imprintcustoms.ph',
                'job_role' => User::JOB_ARTIST,
                'team' => null,
            ],
            [
                'name' => 'JC',
                'email' => 'artist4@imprintcustoms.ph',
                'job_role' => User::JOB_ARTIST,
                'team' => null,
            ],
            [
                'name' => 'Dave',
                'email' => 'artist5@imprintcustoms.ph',
                'job_role' => User::JOB_ARTIST,
                'team' => null,
            ],
            [
                'name' => 'Rommel',
                'email' => 'artist6@imprintcustoms.ph',
                'job_role' => User::JOB_ARTIST,
                'team' => null,
            ],
            [
                'name' => 'Cristal',
                'email' => 'artist7@imprintcustoms.ph',
                'job_role' => User::JOB_ARTIST,
                'team' => null,
            ],

            // Supply chain
            [
                'name' => 'Ton Ton',
                'email' => 'inventory@imprintcustoms.ph',
                'job_role' => 'Inventory',
                'team' => null,
            ],
            [
                'name' => 'Rowena',
                'email' => 'qc@imprintcustoms.ph',
                'job_role' => 'Quality Control',
                'team' => null,
            ],
            [
                'name' => 'Louiza',
                'email' => 'mover@imprintcustoms.ph',
                'job_role' => 'Mover',
                'team' => null,
            ],
            [
                'name' => 'Geneline',
                'email' => 'sewing@imprintcustoms.ph',
                'job_role' => 'Sewing',
                'team' => null,
            ],
            [
                'name' => 'Moises',
                'email' => 'embroidery@imprintcustoms.ph',
                'job_role' => 'Embroidery',
                'team' => null,
            ],
            [
                'name' => 'Supply Chain 6',
                'email' => 'supply6@imprintcustoms.ph',
                'job_role' => User::JOB_SUPPLY_CHAIN,
                'team' => null,
            ],

            // Production
            [
                'name' => 'Maam Khaye',
                'email' => 'rm@imprintcustoms.ph',
                'job_role' => 'Raw Materials',
                'team' => null,
            ],
            [
                'name' => 'PJ & Uno',
                'email' => 'rp@imprintcustoms.ph',
                'job_role' => 'Roller Press',
                'team' => null,
            ],
            [
                'name' => 'Heat Blast',
                'email' => 'hp@imprintcustoms.ph',
                'job_role' => 'Heat Press',
                'team' => null,
            ],
            [
                'name' => 'Cap Blast',
                'email' => 'cp@imprintcustoms.ph',
                'job_role' => 'Cap Press',
                'team' => null,
            ],
            [
                'name' => 'Jm & Jet',
                'email' => 'sp@imprintcustoms.ph',
                'job_role' => 'Small Press',
                'team' => null,
            ],
            [
                'name' => 'Jully',
                'email' => 'lc@imprintcustoms.ph',
                'job_role' => 'Laser Cutting',
                'team' => null,
            ],
            [
                'name' => 'Jully',
                'email' => 'mc@imprintcustoms.ph',
                'job_role' => 'Manual Cutting',
                'team' => null,
            ],
            [
                'name' => 'Jully',
                'email' => 'pairing@imprintcustoms.ph',
                'job_role' => 'Pairing',
                'team' => null,
            ],
            [
                'name' => 'Rommie',
                'email' => 'printer1@imprintcustoms.ph',
                'job_role' => 'Printer',
                'team' => null,
            ],
            [
                'name' => 'Jaymer',
                'email' => 'printer2@imprintcustoms.ph',
                'job_role' => 'Printer',
                'team' => null,
            ],
            [
                'name' => 'Jonathan',
                'email' => 'printer3@imprintcustoms.ph',
                'job_role' => 'Printer',
                'team' => null,
            ],

            // Sales agents
            [
                'name' => 'Nasser',
                'email' => 'sales1@imprintcustoms.ph',
                'job_role' => User::ROLE_SALES,
                'team' => 'meta',
            ],
            [
                'name' => 'Ysabel',
                'email' => 'sales2@imprintcustoms.ph',
                'job_role' => User::ROLE_SALES,
                'team' => 'meta',
            ],
            [
                'name' => 'Kyson',
                'email' => 'sales3@imprintcustoms.ph',
                'job_role' => User::ROLE_SALES,
                'team' => 'meta',
            ],
            [
                'name' => 'Paula',
                'email' => 'sales4@imprintcustoms.ph',
                'job_role' => User::ROLE_SALES,
                'team' => 'vip',
            ],
            [
                'name' => 'Patricia',
                'email' => 'sales5@imprintcustoms.ph',
                'job_role' => User::ROLE_SALES,
                'team' => 'vip',
            ],
            [
                'name' => 'Ave',
                'email' => 'sales6@imprintcustoms.ph',
                'job_role' => User::ROLE_SALES,
                'team' => 'vip',
            ],

            // Finance
            [
                'name' => 'Rey',
                'email' => 'finance@imprintcustoms.ph',
                // Use the constant, not the literal "Finance": the permission
                // role is derived from this value.
                'job_role' => User::ROLE_FINANCE,
                'team' => null,
            ],
        ];

        foreach ($accounts as $account) {
            $email = strtolower(trim($account['email']));

            $user = User::withTrashed()
                ->where('email', $email)
                ->first();

            $details = [
                'name' => $account['name'],
                'email' => $email,
                'job_role' => $account['job_role'],
                'team' => $account['team'],
                'is_active' => true,
            ];

            if ($user) {
                // Re-enable a matching soft-deleted account, if applicable.
                if ($user->trashed()) {
                    $user->restore();
                }

                // Do not reset an existing user's password when the seeder reruns.
                $user->forceFill($details)->save();

                continue;
            }

            $user = new User();
            $user->forceFill($details + [
                // Same default the Users page resets an account back to.
                'password' => Hash::make(User::DEFAULT_PASSWORD),
            ])->save();
        }
    }
}