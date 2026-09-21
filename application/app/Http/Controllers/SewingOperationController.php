<?php

namespace App\Http\Controllers;

use App\Models\SewingOperation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Writing on the sewing sheet.
 *
 * The sheet itself has no page of its own. It is drawn where it is used — in
 * the sewing block of the job order sheet, beside the boxes that record the
 * work — so this holds only the two things that change it.
 */
class SewingOperationController extends Controller
{
    /**
     * Which product the sewing record is laid out against.
     *
     * What they just picked, which is this request; then what the job order
     * already says it is, because that is a decision somebody made at the
     * machine and everybody who opens the job afterwards should see the same
     * list; then whatever was last added to, so writing three operations onto
     * a windbreaker does not put them back on the t-shirt between each one.
     *
     * Failing all of that, nothing. A job whose product nobody has chosen is
     * not a t-shirt because t-shirts are first on the sheet, and laying out a
     * t-shirt's operations against it would be the system answering a question
     * it was not asked.
     */
    public static function showing(Request $request, array $sheet, ?string $saved = null): string
    {
        // Asked for, and asked for as nothing: they picked the blank option.
        if ($request->has('garment') && trim((string) $request->query('garment')) === '') {
            return '';
        }

        foreach ([$request->query('garment'), $saved, session('sewing_garment')] as $wanted) {
            $wanted = $wanted ? SewingOperation::normaliseGarment((string) $wanted) : null;

            if ($wanted && isset($sheet[$wanted])) {
                return $wanted;
            }
        }

        return '';
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
