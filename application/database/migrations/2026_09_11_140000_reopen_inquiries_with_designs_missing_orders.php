<?php

use App\Models\Inquiry;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Older multi-design inquiries could be marked ordered as soon as their
     * first order was created. Reopen only those with approved designs that
     * still have no order, so they return to the follow-up list.
     */
    public function up(): void
    {
        Inquiry::query()
            ->where('status', Inquiry::STATUS_ORDERED)
            ->with('designs.order')
            ->orderBy('id')
            ->eachById(function (Inquiry $inquiry): void {
                if ($inquiry->designsAwaitingAnOrder()->isNotEmpty()) {
                    $inquiry->update([
                        'status' => Inquiry::STATUS_OPEN,
                        'closed_at' => null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Do not hide inquiries again on rollback.
    }
};
