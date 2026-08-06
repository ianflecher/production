<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_LEADER = 'leader';
    public const ROLE_SALES = 'sales';
    public const ROLE_FINANCE = 'finance';
    public const ROLE_AGENT = 'agent';

    /** Walks the floor and chases progress; reads job orders, changes nothing. */
    public const ROLE_MOVER = 'mover';

    /**
     * What "Reset password" puts an account back to, and what a seeded account
     * starts with. Staff are told this and change it themselves — deliberate
     * for an internal office tool where a leader resets accounts in person.
     */
    public const DEFAULT_PASSWORD = 'imprint123';

    public const ROLES = [
        self::ROLE_SUPER_ADMIN,
        self::ROLE_LEADER,
        self::ROLE_SALES,
        self::ROLE_FINANCE,
        self::ROLE_AGENT,
    ];

    /** Which production team an agent works in. */
    public const JOB_ARTIST = 'artist';
    public const JOB_SUPPLY_CHAIN = 'supply_chain';
    public const JOB_PRODUCTION = 'production';

    public const JOB_ROLES = [
        self::JOB_ARTIST => 'Artist (design, mockup)',
        self::JOB_SUPPLY_CHAIN => 'Supply chain (raw materials, printer, sticker)',
        self::JOB_PRODUCTION => 'Production (cutting, pairing, sewing, QC, inventory)',
    ];

    /** Account-officer team (shown on the job order). */
    public const TEAMS = [
        'vip' => 'VIP',
        'meta' => 'META',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'job_role',
        'team',
        'is_active',
        'last_login_at',
        'last_login_ip',
        'last_auto_assigned_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'last_auto_assigned_at' => 'datetime',
        ];
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Present today = leader override for today if one exists, otherwise
     * whether the user logged in at some point today.
     */
    public function isPresentToday(): bool
    {
        $override = $this->attendances->firstWhere('date', now()->toDateString())
            ?? Attendance::where('user_id', $this->id)->whereDate('date', now()->toDateString())->first();

        if ($override) {
            return $override->status === 'present';
        }

        return $this->last_login_at !== null && $this->last_login_at->isToday();
    }

    /**
     * The permission role is DERIVED from job_role — there is no role column.
     * super_admin / leader / sales are job roles; anything else is an agent.
     */
    public function getRoleAttribute(): string
    {
        // Job roles are free text, so "Finance" and "finance" must mean the same
        // thing. match() is strict, and an unmatched value silently falls through
        // to ROLE_AGENT — which handed the finance desk the station operator UI.
        // Normalise first, the way isSupervisor() already does.
        $jobRole = strtolower(trim((string) $this->job_role));

        return match ($jobRole) {
            self::ROLE_SUPER_ADMIN => self::ROLE_SUPER_ADMIN,
            self::ROLE_LEADER => self::ROLE_LEADER,
            self::ROLE_SALES => self::ROLE_SALES,
            self::ROLE_FINANCE => self::ROLE_FINANCE,
            // A supervisor runs part of the floor, so they get the leader's
            // permissions. Without this they came out as ROLE_AGENT while
            // isLeader() said true — the `role:` middleware trusts this value,
            // so every leader page (approvals, users, orders, calendar) 403'd
            // even though UserController already scopes the user list for them.
            'supervisor' => self::ROLE_LEADER,
            // The mover walks the floor chasing progress, so she needs to READ
            // every job order and see where each one is stuck. She gets the
            // viewing pages only — nothing that changes an order.
            'mover' => self::ROLE_MOVER,
            default => self::ROLE_AGENT,
        };
    }

    /** Query scope: production staff (everyone who isn't admin/leader/officer/finance). */
    public function scopeAgents($query)
    {
        return $query->whereNotIn('job_role', [self::ROLE_SUPER_ADMIN, self::ROLE_LEADER, self::ROLE_SALES, self::ROLE_FINANCE]);
    }

    public function isSuperAdmin(): bool
    {
        // Compare against the DERIVED role, which normalises case/whitespace —
        // job_role is free text, so "Finance" must behave like "finance".
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * A supervisor oversees part of the floor. They get the leader UI (orders,
     * pipeline, approvals) rather than the station operator UI, but their
     * user-management view is scoped to the part they supervise.
     */
    public function isSupervisor(): bool
    {
        return strtolower(trim((string) $this->job_role)) === 'supervisor';
    }

    /**
     * The slice of the floor a supervisor oversees, from their job role:
     *   design      → the account officers and artists (agent → artist)
     *   production  → the floor from printing through QC (printer → QC)
     * Leaders / super admins oversee everyone (null = no limit).
     */
    public function supervisorScope(): ?string
    {
        if (! $this->isSupervisor()) {
            return null;
        }

        // Sir Boying supervises production; any other supervisor defaults to design.
        return str_contains(strtolower($this->name), 'boying') ? 'production' : 'design';
    }

    /**
     * Which supervision domain a job role belongs to:
     *   design      → account officers & artists (agent → artist)
     *   production  → the floor from printing through QC (printer → QC)
     *   admin       → leaders / supervisors / finance / super admin (overhead)
     */
    public static function roleDomain(?string $role): string
    {
        $r = strtolower(trim((string) $role));

        if (in_array($r, [self::ROLE_SUPER_ADMIN, self::ROLE_LEADER, self::ROLE_FINANCE, 'supervisor'], true)) {
            return 'admin';
        }

        if (in_array($r, [self::ROLE_SALES, self::ROLE_AGENT, self::JOB_ARTIST, 'sales', 'agent', 'artist'], true)) {
            return 'design';
        }

        return 'production';
    }

    /**
     * A coarse department label for a job role — used for the department-mix
     * chart on the Users page.
     */
    public static function department(?string $role): string
    {
        $r = strtolower(trim((string) $role));

        return match (true) {
            in_array($r, [self::ROLE_SUPER_ADMIN, self::ROLE_LEADER, 'supervisor', self::ROLE_FINANCE], true) => 'Management',
            in_array($r, [self::ROLE_SALES, self::ROLE_AGENT], true) => 'Sales',
            $r === self::JOB_ARTIST => 'Design',
            $r === self::JOB_SUPPLY_CHAIN || $r === 'printer' || str_contains($r, 'raw material') => 'Supply / Printing',
            str_contains($r, 'press') || $r === 'embroidery' => 'Add-ons',
            str_contains($r, 'cutting') => 'Cutting',
            in_array($r, ['pairing', 'sewing', 'quality control', 'mover'], true) => 'Production Line',
            str_contains($r, 'inventory') || str_contains($r, 'product') => 'Inventory',
            default => 'Other',
        };
    }

    /**
     * The staff domain this user oversees in the Users page (null = everyone).
     * Maam Carla oversees the design side; Sir Boying the production side.
     */
    public function managementScope(): ?string
    {
        if ($this->isSuperAdmin()) {
            return null;
        }

        if (str_contains(strtolower((string) $this->name), 'carla')) {
            return 'design';
        }

        if ($this->isSupervisor()) {
            return $this->supervisorScope();
        }

        return null;   // other leaders manage everyone
    }

    /**
     * Super admins and supervisors can do everything a leader can in the UI
     * (see all orders, the pipeline, and approvals).
     */
    public function isLeader(): bool
    {
        return $this->role === self::ROLE_LEADER || $this->isSuperAdmin() || $this->isSupervisor();
    }

    public function isSales(): bool
    {
        return $this->role === self::ROLE_SALES;
    }

    public function isFinance(): bool
    {
        return $this->role === self::ROLE_FINANCE;
    }

    public function isMover(): bool
    {
        return $this->role === self::ROLE_MOVER;
    }

    /** Finance, leaders and super admins may see the payments ledger. */
    public function canManageFinance(): bool
    {
        return $this->isFinance() || $this->isLeader();
    }

    public function isAgent(): bool
    {
        return $this->role === self::ROLE_AGENT;
    }

    public function isArtist(): bool
    {
        return strtolower(trim((string) $this->job_role)) === self::JOB_ARTIST;
    }

    public function jobRoleLabel(): ?string
    {
        return self::JOB_ROLES[$this->job_role] ?? null;
    }

    /** Short label for tables/badges — custom job roles show as typed. */
    public function jobRoleShort(): ?string
    {
        return match ($this->job_role) {
            self::JOB_ARTIST => 'Artist',
            self::JOB_SUPPLY_CHAIN => 'Supply chain',
            self::JOB_PRODUCTION => 'Production',
            null => null,
            default => ucwords(str_replace('_', ' ', $this->job_role)),
        };
    }

    /**
     * Order intake is a sales job; the super admin can always do it too.
     */
    public function canCreateOrders(): bool
    {
        return $this->isSales() || $this->isSuperAdmin();
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            self::ROLE_SUPER_ADMIN => 'Super Admin',
            self::ROLE_LEADER => 'Leader',
            self::ROLE_SALES => 'Account Officer',
            self::ROLE_FINANCE => 'Finance',
            self::ROLE_MOVER => 'Mover',
            default => 'Agent',
        };
    }

    public function teamLabel(): ?string
    {
        return self::TEAMS[$this->team] ?? null;
    }

    /**
     * The raw-materials inventory: leaders/admins plus the supply-chain team
     * (including accounts whose job role is literally "Raw materials").
     */
    public function canManageInventory(): bool
    {
        if ($this->isLeader()) {
            return true;
        }

        // Raw materials belong to the supply-chain team (any role named for raw
        // materials). Finished products are a separate desk — canManageProducts().
        $role = strtolower((string) $this->job_role);

        return in_array($role, [self::JOB_SUPPLY_CHAIN, 'raw materials'], true)
            || str_contains($role, 'raw material');
    }

    /**
     * Finished-products inventory: leaders/admins plus the products desk — a
     * free-typed job role such as "Inventory".
     */
    public function canManageProducts(): bool
    {
        if ($this->isLeader()) {
            return true;
        }

        $role = strtolower((string) $this->job_role);

        // Drop "production" before matching: the factory-floor team's name
        // contains the substring "product", which would otherwise misread them
        // as the finished-products desk (and lock them out of the station
        // board). A role like "Production Inventory" still matches "inventory".
        $role = trim(str_replace('production', '', $role));

        return in_array($role, ['inventory', 'products', 'finished goods'], true)
            || str_contains($role, 'inventory')
            || str_contains($role, 'product');
    }

    /**
     * The station board is for the people who actually run machines — supply
     * chain (printers) and production (presses, cutting, sewing, QC) — plus
     * leaders/admin. Artists and account officers don't use it.
     */
    public function canUseStations(): bool
    {
        if ($this->isLeader()) {
            return true;
        }

        // The raw-materials and finished-products desks work from their own
        // inventory pages, not the machine station board.
        if ($this->canManageInventory() || $this->canManageProducts()) {
            return false;
        }

        return ! empty(\App\Services\Stations::forUser($this));
    }

    /**
     * One human label for what this person is: agents show their production
     * team (Artist / Supply chain / Production), everyone else their role.
     */
    public function positionLabel(): string
    {
        if ($this->isAgent()) {
            return $this->jobRoleShort() ?? 'Agent';
        }

        return $this->roleLabel();
    }
}
