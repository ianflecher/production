<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobOrder extends Model
{
    public const PRINTERS = [
        'atexco' => 'Atexco',
        'epson' => 'Epson',
        'epson_eco_solvent' => 'Epson Eco Solvent Printer',
        'uv_printer' => 'UV Printer',
        'dtf_printer' => 'DTF Printer',
        'manual' => 'Sticker Printer',
    ];

    /**
     * Print types and the printer + cutting they default to. The account officer
     * can still override the printer/cutting after picking a print type.
     * cutting values are keys of ProductionOrder::CUTTING_TYPES.
     */
    public const PRINT_TYPES = [
        'full_sublimation' => ['label' => 'Full Sublimation', 'printer' => 'atexco',            'cutting' => 'laser',  'press' => 'roller_press'],
        'dtf'              => ['label' => 'DTF',              'printer' => 'dtf_printer',       'cutting' => 'manual', 'press' => 'heat_press'],
        'uv'               => ['label' => 'UV Print',        'printer' => 'uv_printer',        'cutting' => 'manual', 'press' => null],
        'eco_solvent'      => ['label' => 'Eco Solvent',     'printer' => 'epson_eco_solvent', 'cutting' => 'manual', 'press' => 'heat_press'],
        'vinyl'            => ['label' => 'Vinyl',           'printer' => 'epson_eco_solvent', 'cutting' => 'manual', 'press' => 'heat_press'],
        'embroidery'       => ['label' => 'Embroidery',      'printer' => 'manual',            'cutting' => 'manual', 'press' => null],
        'silkscreen'       => ['label' => 'Silkscreen',      'printer' => 'epson',             'cutting' => 'manual', 'press' => 'small_press'],
    ];

    protected $fillable = [
        'production_order_id',
        'status',
        'fb_viber_gc',
        // Tech pack header
        'design_name',
        'fitting',
        'item_style',
        'print_tech',
        // Production (yellow)
        'print_type',
        'printer',
        'press',            // the ADD-ON press, matched from `addon` (default Heat press)
        'addon',            // embroidery / reflectorized / sublimated / others
        'addon_other',      // free text when addon = others
        'addon_note',       // what the add-on covers — sleeves, left chest, collar
        'addon_price',      // what the add-on is charged at
        'fabric_press',     // the press that merges the print onto the fabric
        'needs_embroidery',
        'fabric',
        'raw_materials',
        'raw_material_quantities',
        'sewing_log',
        'free_logo_sticker',
        // Sewing (yellow) — the four headline seams, each with its size/thread
        'neck',
        'neck_size',
        'cuff_arm_sleeves',
        'cuff_size',
        'neck_label',
        'neck_label_thread',
        'bottom_hem',
        'bottom_hem_thread',
        'thread_color',
        'tshirt_color',
        'stitch_thread',
        'cutting_method',
        'size_range',
        'tag_1_details',
        'tag_2_details',
        'folder_shot_path',
        'folder_shot_name',
        'file_location_notes',
        'artist_name',
        // …then who sewed each seam group and with what thread
        'neckbond_sewer',
        'neckbond_thread',
        'hangtag_woven_sewer',
        'hangtag_woven_thread',
        'flatbed_sewer',
        'flatbed_thread',
        'close_side_sewer',
        'close_side_thread',
        'attached_sleeve_sewer',
        'attached_sleeve_thread',
        'topping_side_sewer',
        'topping_side_thread',
        'pipping_sewer',
        'pipping_thread',
        // …and the spare column, for whatever this garment needed
        'extra_seam_label',
        'extra_seam_note',
        'extra_seam_sewer',
        'sewer_notes',
        'qc_notes',
        'qc_checked_by',
        'ic_placement',
        // Quality check (yellow)
        'packaging',
        // Agent notes
        'special_instructions',
        'reference_note',
        'leader_note',
        'design_brief',
        'client_brief_submitted_at',
        // Sign-offs
        'created_by',
        'sent_to_artist_by',
        'sent_to_artist_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_materials' => 'array',
            'sewing_log' => 'array',
            'raw_material_quantities' => 'array',
            'design_brief' => 'array',
            'sent_to_artist_at' => 'datetime',
            'client_brief_submitted_at' => 'datetime',
        ];
    }

    /** Raw materials as a clean list (empty entries dropped). */
    public function rawMaterialsList(): array
    {
        return array_values(array_filter((array) $this->raw_materials, fn ($v) => filled($v)));
    }

    /**
     * How much of one material this job needs, or null when nobody said.
     *
     * Kept beside the name list rather than inside it, so everything that
     * already reads rawMaterialsList() keeps reading a plain list of names.
     */
    public function rawMaterialQuantity(string $material): ?float
    {
        $map = (array) $this->raw_material_quantities;
        $qty = $map[$material] ?? null;

        return is_numeric($qty) ? (float) $qty : null;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /** Client reference files the artist should copy. */
    public function referenceFiles(): HasMany
    {
        return $this->hasMany(JobOrderFile::class)->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sentToArtistBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_to_artist_by');
    }

    public function printerLabel(): ?string
    {
        return self::PRINTERS[$this->printer] ?? $this->printer;
    }

    /** key => label, for a picker. PRINT_TYPES carries the routing with it. */
    public static function printTypeOptions(): array
    {
        return collect(self::PRINT_TYPES)->map(fn ($c) => $c['label'])->all();
    }

    public function printTypeLabel(): ?string
    {
        return self::printTypeConfig($this->print_type)['label'] ?? $this->print_type;
    }

    /**
     * Resolve a print type by key OR by label — older job orders stored the
     * label ("Full Sublimation") rather than the key ("full_sublimation"), so a
     * plain array lookup silently missed and every default came back null.
     *
     * @return array{label: string, printer: string, cutting: string, press: ?string}|null
     */
    public static function printTypeConfig(?string $printType): ?array
    {
        if (! filled($printType)) {
            return null;
        }

        if (isset(self::PRINT_TYPES[$printType])) {
            return self::PRINT_TYPES[$printType];
        }

        foreach (self::PRINT_TYPES as $config) {
            if (strcasecmp($config['label'], trim($printType)) === 0) {
                return $config;
            }
        }

        return null;
    }

    /** The press this print type normally uses (null = no press). */
    public function defaultPress(): ?string
    {
        return self::printTypeConfig($this->print_type)['press'] ?? null;
    }

    /**
     * The fabric press auto-matches the print type: the press that print type
     * normally uses, EXCEPT an embroidery print type has nothing to merge, so the
     * fabric press is automatically embroidery. Overridable on production details.
     */
    public function defaultFabricPress(): ?string
    {
        $config = self::printTypeConfig($this->print_type);

        if ($config && strcasecmp($config['label'], 'Embroidery') === 0) {
            return 'embroidery';
        }

        return $config['press'] ?? null;
    }

    /** The add-on press defaults to the heat press. */
    public const DECORATION_PRESS_DEFAULT = 'heat_press';

    /**
     * Add-ons the client can order on top of the print, each matched to the
     * press that actually does it — same idea as PRINT_TYPES above. "others"
     * is free text and has no fixed press, so the officer picks one.
     *
     * @var array<string, array{label: string, press: ?string}>
     */
    public const ADDONS = [
        'embroidery'    => ['label' => 'Embroidery',    'press' => 'embroidery'],
        'sublimated'    => ['label' => 'Sublimated',    'press' => 'heat_press'],
        'reflectorized' => ['label' => 'Reflectorized', 'press' => 'roller_press'],
        // Free text — the shop says what it is, and picks the press.
        'others'        => ['label' => 'Others',        'press' => null],
    ];

    /** The press that does a given add-on, or null when it must be chosen. */
    public static function pressForAddon(?string $addon): ?string
    {
        return self::ADDONS[$addon]['press'] ?? null;
    }

    /**
     * The cap press only ever runs caps, so it is noise on a shirt or jacket
     * job. Caps aren't in the price list — they come through as a typed
     * product ("Cap", "Trucker Cap", "bucket cap"), so match on the name.
     */
    public static function orderHasCap(?ProductionOrder $order): bool
    {
        return $order !== null
            && str_contains(strtolower((string) $order->product_type), 'cap');
    }

    /**
     * Press choices for an order: the full list for a cap job, otherwise the
     * same list without the cap press.
     *
     * $selected keeps whatever the order already has, so an existing choice is
     * never silently dropped from the dropdown (and lost on the next save).
     *
     * @return array<string, string>
     */
    public static function pressOptionsFor(?ProductionOrder $order, ?string $selected = null): array
    {
        $options = self::pressOptions();

        if (self::orderHasCap($order) || $selected === 'cap_press') {
            return $options;
        }

        unset($options['cap_press']);

        return $options;
    }

    /** Display label for the chosen add-on ("Others" shows what was typed). */
    public function addonLabel(): ?string
    {
        if (blank($this->addon)) {
            return null;
        }

        if ($this->addon === 'others') {
            return filled($this->addon_other) ? $this->addon_other : 'Others';
        }

        return self::ADDONS[$this->addon]['label'] ?? $this->addon;
    }

    /**
     * Options for BOTH press dropdowns (fabric press and decoration press): the
     * real presses plus embroidery — the client sometimes wants embroidery
     * instead of a press.
     *
     * @return array<string, string>
     */
    public static function pressOptions(): array
    {
        return [
            'cap_press' => 'Cap press',
            'heat_press' => 'Heat press',
            'small_press' => 'Small press',
            'roller_press' => 'Roller press',
            'embroidery' => 'Embroidery',
        ];
    }

    /** The press that merges the print onto the fabric — its display label. */
    public function fabricPressLabel(): ?string
    {
        return self::pressOptions()[$this->fabric_press] ?? $this->fabric_press;
    }

    /** The decoration press — its display label. */
    public function decorationPressLabel(): ?string
    {
        return self::pressOptions()[$this->press] ?? $this->press;
    }

    /** The production-spec (yellow) fields are the minimum an artist needs. */
    public function isReadyToSend(): bool
    {
        return filled($this->print_type) && filled($this->printer) && filled($this->fabric);
    }

    /** Free-text sheet fields whose past entries are offered as suggestions. */
    /**
     * Who fills what, and where.
     *
     * The account officer writes the SPEC when the order is taken: what collar,
     * what hem, what size, how it is packed. Those are decisions, and they are
     * known up front.
     *
     * Everything below is a RECORD of work that has happened — which sewer ran
     * the flatbed, what thread they used, what the checker found. Nobody can
     * know it at order time, so asking the account officer produced a form full
     * of guesses and a sheet that printed blank. It is asked at the station
     * instead, of the person holding the garment.
     *
     * @var array<int, string>
     */
    /**
     * What the sewing station writes when it finishes.
     *
     * It used to be twenty-one boxes named after seams. Every garment is
     * different, so most were blank on most jobs and the ones that mattered
     * were lost somewhere in the grid. Now it is five slots — what was done and
     * who did it — in one JSON column. The old seam columns are still on the
     * table and still readable: a job sewn before this is unchanged.
     */
    public const SEWING_STATION_FIELDS = ['sewing_log', 'sewer_notes'];

    /**
     * The seam columns the sewing record used to live in.
     *
     * Nothing writes them any more, but a job sewn before the log has its
     * record in them — so they are still read, and still belong to the run that
     * filled them. A remake must not inherit them any more than it inherits the
     * log itself.
     */
    public const LEGACY_SEWING_FIELDS = [
        'neck_size', 'cuff_size',
        'neck_label_thread', 'bottom_hem_thread',
        'neckbond_sewer', 'neckbond_thread',
        'hangtag_woven_sewer', 'hangtag_woven_thread',
        'flatbed_sewer', 'flatbed_thread',
        'close_side_sewer', 'close_side_thread',
        'attached_sleeve_sewer', 'attached_sleeve_thread',
        'topping_side_sewer', 'topping_side_thread',
        'pipping_sewer', 'pipping_thread',
        'extra_seam_note', 'extra_seam_sewer',
        'sewer_notes',
    ];

    /** How many people can be named against one sewing run. */
    public const SEWING_LOG_SLOTS = 5;

    /**
     * The sewing log padded to its five slots, so the form always has its rows.
     *
     * @return array<int, array{work: string, name: string}>
     */
    public function sewingLog(): array
    {
        $rows = [];

        foreach (range(0, self::SEWING_LOG_SLOTS - 1) as $i) {
            $row = (array) (($this->sewing_log ?? [])[$i] ?? []);
            $rows[] = [
                'work' => (string) ($row['work'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
            ];
        }

        return $rows;
    }

    /** Everyone named in the sewing log, in order, without the blanks. */
    public function sewers(): array
    {
        return collect($this->sewingLog())
            ->pluck('name')
            ->map(fn ($n) => trim($n))
            ->filter()
            ->unique(fn ($n) => mb_strtolower($n))
            ->values()
            ->all();
    }

    /**
     * The names already written down for a station, if any.
     *
     * Sewing and QC do not ask who is at the machine — the names go down with
     * the work. Until somebody has written one there is nobody to show, and
     * naming the shared account instead would put the wrong person on the board.
     */
    public function namesOnSheet(array $fields): string
    {
        // The sewing record is a log now; the checker is still one name.
        $names = in_array('sewing_log', $fields, true) ? $this->sewers() : [];

        if (in_array('qc_checked_by', $fields, true) && filled($this->qc_checked_by)) {
            $names[] = trim((string) $this->qc_checked_by);
        }

        // A job sewn before the log has its names in the old seam columns.
        foreach ($fields as $field) {
            if (str_ends_with($field, '_sewer') && filled($this->$field ?? null)) {
                $names[] = trim((string) $this->$field);
            }
        }

        return collect($names)
            ->filter()
            ->unique(fn ($n) => mb_strtolower($n))
            ->implode(', ');
    }

    /** Filled by the checker when they close Quality Control. */
    public const QC_STATION_FIELDS = ['qc_checked_by', 'qc_notes'];

    /**
     * Just the sewer and thread pools, for the station board.
     *
     * fieldSuggestions() answers for the whole office form and costs a query
     * per field — fourteen of them, which is most of a station board's budget
     * spent on two dropdowns. This asks once and sorts the answers out in PHP.
     *
     * @return array{sewer: array<int, string>, thread: array<int, string>}
     */
    public static function stationSuggestions(): array
    {
        $query = null;

        foreach (self::SHARED_SUGGEST as $kind => $columns) {
            foreach ($columns as $column) {
                $part = self::query()
                    ->selectRaw('? as kind, '.$column.' as v', [$kind])
                    ->whereNotNull($column)
                    ->where($column, '!=', '');

                $query = $query ? $query->union($part) : $part;
            }
        }

        $rows = $query ? $query->get() : collect();

        $pool = fn (string $kind) => $rows
            ->where('kind', $kind)
            ->pluck('v')
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->unique(fn ($v) => mb_strtolower($v))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->take(200)
            ->values()
            ->all();

        // The sewing record is a log now, so the names people actually type
        // are in there rather than in the old seam columns. Both feed the same
        // pool: a job sewn last month still offers its people.
        $logged = self::query()
            ->whereNotNull('sewing_log')
            ->pluck('sewing_log')
            ->flatMap(fn ($log) => collect(is_array($log) ? $log : (json_decode((string) $log, true) ?: []))
                ->pluck('name'))
            ->all();

        $sewers = collect($pool('sewer'))
            ->merge($logged)
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->unique(fn ($v) => mb_strtolower($v))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->take(200)
            ->values()
            ->all();

        return ['sewer' => $sewers, 'thread' => $pool('thread')];
    }

    public const SUGGEST_FIELDS = [
        'fb_viber_gc', 'print_type', 'fabric', 'free_logo_sticker',
        'neck', 'cuff_arm_sleeves', 'neck_label', 'bottom_hem', 'ic_placement', 'packaging',
        'neck_size', 'cuff_size',
    ];

    /**
     * The sewing block asks for a sewer and a thread nine times over. Suggesting
     * each box only its own past values would be both nine extra queries and
     * worse: the same handful of people work every seam, and the same thread
     * codes go through all of them. So they share one pool each.
     *
     * @var array<string, array<int, string>>
     */
    public const SHARED_SUGGEST = [
        'sewer' => [
            'neckbond_sewer', 'hangtag_woven_sewer', 'flatbed_sewer', 'close_side_sewer',
            'attached_sleeve_sewer', 'topping_side_sewer', 'pipping_sewer', 'extra_seam_sewer',
        ],
        'thread' => [
            'neck_label_thread', 'bottom_hem_thread',
            'neckbond_thread', 'hangtag_woven_thread', 'flatbed_thread', 'close_side_thread',
            'attached_sleeve_thread', 'topping_side_thread', 'pipping_thread',
        ],
    ];

    /**
     * Distinct past values for each suggestable field, so the form can offer them
     * as a dropdown — type once, pick next time.
     *
     * @return array<string, array<int, string>>
     */
    public static function fieldSuggestions(): array
    {
        $out = [];

        foreach (self::SUGGEST_FIELDS as $field) {
            $out[$field] = self::query()
                ->whereNotNull($field)
                ->where($field, '!=', '')
                ->distinct()
                ->orderBy($field)
                ->limit(100)
                ->pluck($field)
                ->all();
        }

        // One pool of sewers and one of thread codes, gathered across every
        // seam column in a single pass each.
        foreach (self::SHARED_SUGGEST as $name => $columns) {
            $query = null;

            foreach ($columns as $column) {
                $part = self::query()
                    ->selectRaw("$column as v")
                    ->whereNotNull($column)
                    ->where($column, '!=', '');

                $query = $query ? $query->union($part) : $part;
            }

            $out[$name] = $query
                ->pluck('v')
                ->map(fn ($v) => trim((string) $v))
                ->filter()
                ->unique(fn ($v) => mb_strtolower($v))
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                ->take(200)
                ->values()
                ->all();
        }

        // Raw materials live as a JSON array — flatten every entry ever used.
        $out['raw_materials'] = self::query()
            ->whereNotNull('raw_materials')
            ->pluck('raw_materials')
            ->flatMap(fn ($v) => (array) $v)
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $out;
    }
}
