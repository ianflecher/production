<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The sewing line's operation breakdown, off the shop's own sheet.
 *
 * Every garment the shop makes has a known list of operations and a standard
 * allowing minute against each one, worked out on the floor and kept in a
 * spreadsheet nobody at a machine can open. A sewer picking up a polo had to
 * know from memory what a polo takes.
 *
 * Eighteen garments, 336 operations, exactly as the sheet has them — including
 * the four on SHORT that were never timed, which stay blank rather than being
 * guessed at, and the two WOVEN & TAGS lines on WINDBREAKER JACKET that carry
 * different minutes.
 *
 * Seeded here rather than in a seeder because it is a reference the floor reads
 * on day one, not sample data: a shop that has run its migrations has its sheet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sewing_operations', function (Blueprint $table) {
            $table->id();
            $table->string('garment');
            $table->string('name');
            // Blank where the shop has not timed it. A zero would read as
            // "takes no time", which is a different claim.
            $table->decimal('sam', 8, 4)->nullable();
            $table->unsignedInteger('position')->default(0);
            // Who added it, for the ones written at a machine rather than
            // brought in off the sheet.
            $table->string('added_by')->nullable();
            $table->timestamps();

            $table->index(['garment', 'position']);
        });

        $now = now();
        $rows = [];

        foreach ($this->sheet() as $garment => $operations) {
            foreach (array_values($operations) as $i => [$name, $sam]) {
                $rows[] = [
                    'garment' => $garment,
                    'name' => $name,
                    'sam' => $sam,
                    'position' => $i,
                    'added_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('sewing_operations')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sewing_operations');
    }

    /**
     * The sheet, garment by garment, in the order the shop wrote it.
     *
     * @return array<string, array<int, array{0: string, 1: float|null}>>
     */
    private function sheet(): array
    {
        return [
            'REGULAR T-SHIRT' => [
                ['NECK BOND / JOIN SHOULDER', 1.763],
                ['TAPPING NECK', 0.525],
                ['SIDE CLOSE', 0.904],
                ['CLOSE SLEEVE', 0.3939],
                ['ATTACH SLEEVE', 1.858],
                ['FLATBED', 0.937],
                ['PIPPING SLEEVE', 0.922],
                ['PIPPING (HEM)', 0.773],
                ['WOVEN & TAGS', 1.152],
                ['ATTACH POCKET', 3.004],
                ['PATCHES ATTACH', 1.452],
                ['FLATBED BIAS CUTTING PER ROLL', 4.77],
            ],
            'POLO SHIRT' => [
                ['PLACKET PREP W/ MARKINGS', 0.6008],
                ['ATTACHED PLACKET', 3.854],
                ['JOIN SHOULDER', 0.392],
                ['COLLAR PREP', 3.656],
                ['COLLAR ATTACHED', 6.5],
                ['CLOSE SLEEVE', 0.728],
                ['ATTACH SLEEVE', 1.858],
                ['PIPPING SLEEVE', 0.922],
                ['PIPPING (HEM)', 0.896],
                ['WOVEN & TAGS', 1.152],
                ['SLEEVE TAPPING', 1.643],
                ['SIDE TAPPING', 1.288],
                ['SIDE CLOSE', 0.596],
                ['ZIPPER PREP', 1.8],
                ['ZIPPER ATTACHED', 5.858],
                ['TOPPING ZIPPER', 0.61],
                ['SLEEVE TAPPING', 1.453],
                ['CHINESE COLLAR ASSBLY', 3.817],
                ['ATTACH CHINESE COLLAR', 3.122],
                ['ATTACH NECK TAPE AND TAPPING', 4.0078],
            ],
            'POLO (BUTTON / PLACKET)' => [
                ['PLACKET PREP W/ MARKINGS', 0.6008],
                ['ATTACHED PLACKET', 3.854],
                ['JOIN SHOULDER', 0.392],
                ['COLLAR PREP', 1.499],
                ['COLLAR ATTACHED', 3.845],
                ['CLOSE SLEEVE', 0.728],
                ['ATTACH SLEEVE', 1.858],
                ['PIPPING SLEEVE', 0.922],
                ['PIPPING (HEM)', 0.896],
                ['BUTTON MARKINGS AND COLLAR', 1.939],
                ['BUTTON HOLE SEWING', 1.52],
                ['BUTTON SEW', 1.585],
                ['WOVEN & TAGS', 1.152],
                ['SLEEVE TAPPING', 1.643],
                ['SIDE TAPPING', 1.288],
            ],
            'POLO LONG SLEEVE' => [
                ['WELT POCKET', 5.309],
                ['JOIN SHOULDER', 0.392],
                ['TAPPING SHOULDER', 0.392],
                ['CLOSE POCKET BAG OL', 0.326],
                ['TACKING POCKET BAG', 1.027],
                ['CUFF PLACKET ASSBLY (L/R)', 3.8425],
                ['CUFF ASSEMBLY', 4.805],
                ['ATTACH CUFF', 4.042],
                ['COLLAR ASSEMBLY', 7.958],
                ['ATTACH COLLAR', 3.845],
                ['TAPPING FRONT BODY (BUTTON DOWN)', 3.8425],
                ['SIDE CLOSE', 0.596],
                ['ATTACH SLEEVE', 1.858],
                ['PIPPING (HEMMING)', 0.896],
                ['BUTTON MARKINGS AND COLLAR', 1.939],
                ['BUTTON HOLE SEWING', 1.734],
                ['BUTTON HOLE COLLAR', 0.703],
                ['BUTTON SEW', 1.585],
                ['SLEEVE TAPPING', 1.397],
            ],
            'POLO NATURE HARVEST' => [
                ['PLACKET PREP W/ MARKINGS', 0.6008],
                ['ATTACHED PLACKET', 3.854],
                ['JOIN SHOULDER AND BACK PANEL', 1.587],
                ['TAPPING SHOULDER', 0.493],
                ['TAPPING SHOULDER PANEL', 0.783],
                ['COLLAR PREP', 3.656],
                ['COLLAR ATTACHED', 6.5],
                ['CLOSE SLEEVE', 0.728],
                ['ATTACH SLEEVE', 1.858],
                ['TACKING SLEEVE', 0.56],
                ['PIPPING (HEM)', 0.896],
                ['WOVEN & TAGS', 1.152],
                ['SLEEVE CLOSING', 0.799],
                ['SLEEVE TAPPING', 1.643],
                ['SIDE TAPPING 4X', 1.982],
                ['SIDE CLOSE', 2.769],
                ['ATTACH SIDE PANGITI', 2.0375],
                ['SERVICE SIDE SEWING', 1.673],
                ['ATTACH ARM KNITTING', 1.191],
                ['CLOSED PLACKET', 1.0],
            ],
            'REGULAR JERSEY' => [
                ['NECK BOND / JOIN SHOULDER', 1.763],
                ['TAPPING NECK', 0.525],
                ['SIDE CLOSE', 0.596],
                ['CLOSE SLEEVE', 0.728],
                ['ATTACH SLEEVE', 1.858],
                ['ATTACH CUFF', 1.496],
                ['FLATBED', 0.937],
                ['PIPPING SLEEVE', 0.922],
                ['PIPPING (HEM)', 0.896],
                ['WOVEN & TAGS', 1.152],
                ['SLEEVE TAPPING', 1.643],
                ['SIDE TAPPING', 1.288],
                ['SLIT', 3.367],
                ['ATTACH RIBBINGS', 1.178],
                ['VNECK ATTACH W/ V-COMBINATION', 2.105],
                ['V-NECK RIBBINGS PREP', 1.49],
                ['ATTACH V-NECK RIBBINGS', 0.96],
                ['TAPPING NECK-TAPE', 1.043],
                ['HANGTAG', 0.5505],
            ],
            'ANDRES JERSEY BLK/WHT' => [
                ['JOIN UPPER&LOWER BODY FRONT & BACK', 2.003],
                ['TAPPING UPPER&LOWER BODY FRONT& BACK', 1.397],
                ['NECK BOND / JOIN SHOULDER', 1.763],
                ['TAPPING NECK', 0.525],
                ['SLEEVE COMB. OVERLOCK', 1.357],
                ['SLEEVE TAPPING', 0.841],
                ['ATTACH SLEEVE', 1.299],
                ['ATTACH CUFF', 1.496],
                ['FLATBED', 0.937],
                ['PIPPING (HEM)', 0.896],
                ['WOVEN & TAGS', 1.152],
                ['SIDE TAPPING', 1.288],
                ['ATTACHED PAD 4X', 4.666],
                ['PADDING OVERLOCK 4X', 0.754],
                ['PAD SEWING', 0.614],
                ['SIDE CLOSE', 1.432],
                ['SLEEVE TAPPING ARMHOLE', 1.397],
            ],
            'SANDO' => [
                ['NECK BOND / JOIN SHOULDER', 1.422],
                ['FLATBED', 0.937],
                ['TAPPING NECK', 0.525],
                ['SIDE CLOSE', 1.001],
                ['ATTACH RIBBINGS ARMHOLE', 2.589],
                ['TAPPING ARMHOLE', 0.751],
                ['PIPPING (HEM)', 0.896],
                ['WOVEN & TAGS', 1.152],
                ['SIDE TAPPING', 1.288],
            ],
            'GARRETE SHIRT' => [
                ['JOIN SHOULDER', 0.681],
                ['TAPPING NECK', 0.525],
                ['SIDE CLOSE', 1.432],
                ['FLATBED', 0.937],
                ['PIPPING SLEEVE', 0.922],
                ['PIPPING (HEMMING)', 0.896],
                ['WOVEN & TAGS', 1.152],
                ['SLIT', 3.367],
            ],
            'SHORT' => [
                ['SEAMING F&B', null],
                ['TAPPING  F&B', 0.754],
                ['SEAMING LINING F&B', null],
                ['SIDE WELT POCKET ASSBLY', 13.896],
                ['ATTACH LINING SHORT', 2.618],
                ['WAISTBAND PREP', 2.128],
                ['CUTTING GARTER', null],
                ['GARTER PREP', 0.506],
                ['PIPPING BOTOM HEM', null],
                ['BUTTON HOLE', 0.76],
                ['MARKING BUTTON HOLE', 0.509],
                ['SLIT AND TAPPING HEM  1/4', 3.759],
                ['WAISTBAND CLOSING', 0.957],
                ['ATTACH WAISTBAND', 3.655],
                ['OVERLOCK WAISTBAND', 1.722],
                ['GARTERING', 1.67],
            ],
            'HOODY JACKET' => [
                ['HOOD PREP. 4 THREADS', 0.5039],
                ['ATTACHED POCKET', 2.7],
                ['JOIN SHOLDER', 0.392],
                ['TAPPING SHOULDER', 0.493],
                ['SIDE CLOSE', 0.603],
                ['HOOD PREP. 4 THREADS', 0.543],
                ['HOOD TOPPING 1 INCH', 1.659],
                ['BUTTON HOLE', 0.76],
                ['ATTACHED HOOD', 0.76],
                ['TAPPING NECK HOOD', 1.1],
                ['ATTACH WAISTBAND', 1.542],
                ['TAPPING WAISTBAND', 0.886],
                ['ATTACHED CUFF 5 THREADS', 1.609],
                ['TAPPING CUFF', 1.081],
                ['WOVEN & TAGS', 1.152],
                ['ATTACHED SLEEVE', 1.956],
                ['SLEEVE TAPPING', 1.643],
                ['SLEEVE CLOSING OL', 0.728],
                ['POCKET OVERLOCK', 0.491],
                ['POCKET PREP', 0.917],
                ['TAPPING POCKET 1/16- 1/4', 0.631],
                ['OL POCKET', 0.311],
                ['PIPPING POCKET', 0.395],
            ],
            'HOODY JACKET RAGLAN' => [
                ['ATTACHED LIP POCKET', 0.5697],
                ['ATTACHED POCKET', 2.7],
                ['SIDE CLOSE', 0.596],
                ['HOOD PREP. 4 THREADS', 0.5039],
                ['HOOD TOPPING 1 INCH', 1.659],
                ['BUTTON HOLE', 0.76],
                ['ATTACHED HOOD', 0.76],
                ['TAPPING NECK HOOD', 1.1],
                ['ATTACH WAISTBAND', 1.27],
                ['TAPPING WAISTBAND', 0.886],
                ['ATTACH WRISTBAND', 1.609],
                ['TAPPING CUFF', 1.081],
                ['WOVEN & TAGS', 1.152],
                ['ATTACHED SLEEVE', 1.791],
                ['SLEEVE TAPPING', 1.643],
                ['LIP POCKET OVERLOCK', 0.491],
                ['ATTACHED CUFF 5 THREADS', 1.609],
            ],
            'WINDBREAKER JACKET' => [
                ['WOVEN & TAGS', 1.152],
                ['WELT POCKET ASSEMBLY', 21.06],
                ['ZIPPER ASSEMBLY', 15.271],
                ['JOIN SHOULDER', 0.84],
                ['JOIN INNER AND OUTER HOOD', 2.15],
                ['TAPPING HOOD 1/16 - 1/4', 1.835],
                ['TAPPING NECK AND BASTING', 4.9155],
                ['TAPPING COLLAR', 7.9],
                ['ATTACH HOOD BODY', 2.0],
                ['ATTACH HOOD LINING', 2.0],
                ['CLOSE SLEEVE', 0.728],
                ['ATTACH GARTER', 3.0],
                ['COLLAR HOOD TAPPING', 0.507],
                ['JOIN OUTERHOOD COMB AND COLLAR', 2.782],
                ['JOIN INNER HOOD COMB. AND COLLAR', 2.782],
                ['TAPPING CENTER HOOD OUTER', 1.291],
                ['TAPPING CENTER HOOD INNER', 1.291],
                ['ATTACHED SLEEVE', 1.858],
                ['SIDE CLOSE', 1.2045],
                ['BIAS WAIST BODY', 2.411],
                ['CLOSE BIAS', 2.453],
                ['WOVEN & TAGS', 0.8076],
                ['garter cutting', 0.229],
                ['GARTER PREP SEWING', 0.35],
                ['BASTING NECK', 0.738],
                ['BASTING HEM', 0.8375],
                ['TAPPING ZIPPER', 6.857],
            ],
            'WINDBREAKER JACKET COLLAR' => [
                ['COLLAR PREP', 0.995],
                ['WELT POCKET ASSEMBLY', 21.06],
                ['ZIPPER ASSBLY AND LINING', 12.461],
                ['ATTACH COLLAR', 1.02],
                ['TAP COLLAR', 1.0],
                ['CLOSED COLLAR', 7.9],
                ['TAPPING ZIPPER', 6.857],
                ['JOIN SHOULDER', 0.84],
                ['CLOSE SLEEVE', 0.728],
                ['ATTACH GARTER', 3.0],
                ['BASTING HEM', 0.8375],
                ['ATTACHED SLEEVE', 1.858],
                ['SIDE CLOSE', 1.2045],
                ['BIAS WAIST BODY', 2.411],
                ['CLOSE BIAS', 2.453],
                ['WOVEN & TAGS', 1.152],
                ['garter cutting', 0.229],
                ['GARTER PREP SEWING', 0.35],
                ['BASTING NECK', 0.738],
            ],
            'WINDBREAKER ACP' => [
                ['WELT POCKET ASSEMBLY', 21.06],
                ['ZIPPER ASSBLY AND LINING', 14.018],
                ['TAPPING COLLAR', 7.9],
                ['TAPPING ZIPPER', 6.857],
                ['JOIN SHOULDER', 0.84],
                ['CLOSE SLEEVE', 0.728],
                ['ATTACHED SLEEVE', 1.858],
                ['COLLAR RIBBINGS PREP', 0.955],
                ['TAP COLLAR RIBBINGS', 1.0],
                ['ATTACH COLLAR RIBBINGS', 1.02],
                ['TAPPING WAISTBAND END', 0.79],
                ['ATTACH CUFF', 1.609],
                ['SIDE CLOSE', 1.2045],
                ['ATTACH WAISTBAND RIBBINGS', 1.102],
                ['TAP WAISTBAND WITH SERVICE SEWING', 1.439],
                ['WOVEN & TAGS', 1.152],
                ['TRIM WAISTBAND (BLACK LINE EDGE)', 1.218],
            ],
            'WINDBREAKER (BOA)' => [
                ['LOGO PREP AND ATTACH', 2.015],
                ['WELT POCKET ASSEMBLY', 21.06],
                ['ZIPPER ATTACH BODY AND LINING', 9.7085],
                ['TAPPING ZIPPER', 5.5625],
                ['TAPPING WAISTBAND 1/16 OUTER', 5.248],
                ['JOIN INNER AND OUTER HOOD', 2.15],
                ['ATTACH WAISTBAND TO LINING', 1.702],
                ['ATTACH WAISTBAND TO BODY', 1.702],
                ['JOIN BASTING LINING AND BODY', 1.329],
                ['WAISTBAND BASTING LINING', 1.365],
                ['WAISTBAND BASTING BODY', 1.365],
                ['JOIN LINING AND BODY (WAISTBAND)', 1.818],
                ['JOIN SHOULDER', 0.681],
                ['JOIN OUTERHOOD COMB. AND COLLAR', 2.782],
                ['JOIN INNER HOOD COMB. AND COLLAR', 2.782],
                ['COLLAR HOOD TAPPING', 0.507],
                ['TAPPING CENTER HOOD OUTER', 1.291],
                ['TAPPING CENTER HOOD INNER', 1.291],
                ['JOIN LINING SLEEVE TO BODY', 5.135],
                ['ATTACH HOOD BODY', 2.0],
                ['ATTACH HOOD LINING', 2.0],
                ['HOOD CLOSING', 2.15],
                ['TAPPING HOOD 1/16', 1.835],
                ['STRAP ASSBLY (2X) FOR CUFF', 1.893],
                ['ATTACH CUFF', 7.841],
                ['STRAP ATTACH TO CUFF', 4.146],
                ['STRAP ASSBLY (2X) FOR WAISTBAND', 1.893],
                ['STRAP ATTACH TO WAISBAND', 3.617],
                ['CLOSE SLEEVE', 0.728],
                ['CUFF PREP', 1.568],
                ['ATTACHED SLEEVE', 1.858],
                ['SIDE CLOSE', 1.2045],
                ['WOVEN & TAGS', 1.152],
                ['KEYTAGS', 1.0022],
            ],
            'EVO VEST' => [
                ['ATTACH PANEL FRONT', 3.43],
                ['OL PATCH PANEL FRONT', 0.273],
                ['OL PATCH PANEL BACK', 0.273],
                ['ATTACH REFLECTOR GREEN', 1.983],
                ['POCKET HEMMING & ATT. VELCRO HOOK', 1.093],
                ['ATTACH REFLECTOR SILVER FRONT', 1.644],
                ['ATTACH POCKET AND VELCRO LOOP', 6.516],
                ['BACK PANEL ATTACH', 1.748],
                ['BACK REFLERTOR GREEN  ATTACH', 0.969],
                ['BACK REFLECTOR SILVER ATTACH', 0.969],
                ['JOIN SHOULDER', 0.414],
                ['ATTACH REFLECTOR TO SHOULDER SILVER', 1.581],
                ['ATTACH NAMES/FLAG PATCH', 2.291],
                ['ATTACH ZIPPER', 2.062],
                ['BIAS WHOLE BODY', 7.018],
                ['ATTACH VEST HOLDER', 3.683],
            ],
            'THIAGO/MATEO/THUGS' => [
                ['JOIN SHOULDER', 0.681],
                ['TAPPING SHOULDER', 0.493],
                ['WELT POCKET ASSBLY', 5.8615],
                ['LIP POCKET OL', 0.541],
                ['SLEEVE  COMBINATION OL', 1.451],
                ['SLEEVE TAPPING COMBINATION', 1.294],
                ['SLEEVE CLOSING', 1.542],
                ['JOIN BACK COMBI UPPER & LOWER 5 THREADS', 1.344],
                ['BACK COMBINATION TAPPING LOWER AND UPPER', 0.944],
                ['FRONT COMBINATION UPPER', 1.272],
                ['TAPPING FRONT COMBINATION UPPER', 0.472],
                ['JOIN LOWER FRONT COMBINATION', 1.364],
                ['TAPPING LOWER FRONT COMBI', 0.695],
                ['BASTING LOWER POCKET BACK / LIP TAPPING', 2.556],
                ['COLLAR ASSBLY / TAPPING', 3.758],
                ['ATTACH COLLAR OL', 1.55],
                ['TAPPING NECK', 1.665],
                ['ZIPPER ASSBLY', 5.789],
                ['SIDE CLOSE', 0.596],
                ['CUTTING EXCESS POCKET BAG', 0.494],
                ['ATTACH SLEEVE', 1.956],
                ['TAPPING ZIPPER AND COLLAR', 3.0765],
                ['SLEEVE TAPPING', 1.643],
                ['ATTACHED CUFF 5 THREADS', 1.609],
                ['TAPPING CUFF', 1.081],
                ['ATTACH WAISTBAND', 1.542],
                ['TAPPING WAISTBAND', 0.886],
                ['WOVEN & TAGS', 1.152],
            ],
        ];
    }
};
