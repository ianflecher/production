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
        // Production (yellow)
        'print_type',
        'printer',
        'press',            // the DECORATION press (default Heat press)
        'fabric_press',     // the press that merges the print onto the fabric
        'needs_embroidery',
        'embroidery_note',
        'fabric',
        'raw_materials',
        'free_logo_sticker',
        // Sewing (yellow)
        'neck',
        'cuff_arm_sleeves',
        'neck_label',
        'bottom_hem',
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

    /** The decoration press defaults to the heat press. */
    public const DECORATION_PRESS_DEFAULT = 'heat_press';

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
    public const SUGGEST_FIELDS = [
        'fb_viber_gc', 'print_type', 'fabric', 'free_logo_sticker',
        'neck', 'cuff_arm_sleeves', 'neck_label', 'bottom_hem', 'ic_placement', 'packaging',
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
