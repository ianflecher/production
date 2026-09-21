<?php

namespace App\Http\Controllers;

use App\Models\SewingOperation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The sewing sheet: what each garment takes, and how long.
 *
 * Reading it is open to everyone signed in — the costing desk prices off these
 * minutes and the account officers quote off them, so walling it into the floor
 * would just mean the spreadsheet staying alive beside it. Writing on it is the
 * sewing line's, which canEditSewingSheet() decides.
 */
class SewingOperationController extends Controller
{
    public function index(Request $request): View
    {
        $sheet = SewingOperation::sheet();

        return view('sewing.operations', [
            'sheet' => $sheet,
            'garment' => self::showing($request, $sheet),
            'canEdit' => $request->user()->canEditSewingSheet(),
        ]);
    }

    /**
     * Which garment the page opens on.
     *
     * Whatever was last added, so somebody writing three operations onto a
     * windbreaker is not put back on the t-shirt between each one; then a
     * garment asked for in the link; then the first on the sheet.
     */
    public static function showing(Request $request, array $sheet): string
    {
        foreach ([session('sewing_garment'), $request->query('garment')] as $wanted) {
            $wanted = $wanted ? SewingOperation::normaliseGarment((string) $wanted) : null;

            if ($wanted && isset($sheet[$wanted])) {
                return $wanted;
            }
        }

        return (string) array_key_first($sheet);
    }

    /**
     * Write a line onto the sheet.
     *
     * The same operation twice on one garment is allowed, because the shop's
     * own sheet does it — a windbreaker jacket carries two WOVEN & TAGS lines
     * with different minutes, and refusing the second would be the system
     * telling the floor it had made a mistake it has not made.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canEditSewingSheet(), 403);

        $data = $request->validate([
            'garment' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:255'],
            // Blank is a real answer: an operation nobody has timed yet is
            // still an operation. Zero minutes is not.
            'sam' => ['nullable', 'numeric', 'gt:0', 'max:9999'],
        ], [
            'sam.gt' => 'A standard allowing minute has to be more than zero — leave it blank if it has not been timed.',
        ]);

        $garment = SewingOperation::normaliseGarment($data['garment']);

        $operation = SewingOperation::create([
            'garment' => $garment,
            'name' => trim(preg_replace('/\s+/', ' ', $data['name'])),
            'sam' => $data['sam'] ?? null,
            'position' => SewingOperation::nextPosition($garment),
            'added_by' => $request->user()->name,
        ]);

        return back()
            ->with('sewing_garment', $garment)
            ->with('success', $operation->name.' added to '.$garment.'.');
    }

    public function destroy(Request $request, SewingOperation $sewingOperation): RedirectResponse
    {
        abort_unless($request->user()->canEditSewingSheet(), 403);

        $garment = $sewingOperation->garment;
        $name = $sewingOperation->name;
        $sewingOperation->delete();

        return back()
            ->with('sewing_garment', $garment)
            ->with('success', $name.' taken off the '.$garment.' sheet.');
    }
}
