<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use App\Models\ProductionOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Client-reference files attached to a job order (pegs, logos, and the ChatGPT
 * design "output" the artist works from). Split out of ProductionOrderController.
 */
class OrderReferenceFileController extends Controller
{
    use AuthorizesOrderAccess;

    /** Who may see a job order reference: the officer who owns it, leaders, or an assigned artist. */
    private function assertCanSeeReference(\App\Models\JobOrderFile $file): void
    {
        $user = auth()->user();
        $order = $file->jobOrder->order;

        $allowed = $user->isLeader()
            || ($user->isSales() && $order->created_by === $user->id)
            || $order->tasks()->where('assigned_to', $user->id)->exists();

        abort_unless($allowed, 403);
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($file->path), 404);
    }

    public function viewReferenceFile(\App\Models\JobOrderFile $file)
    {
        $this->assertCanSeeReference($file);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($file->path, $file->original_name);
    }

    public function downloadReferenceFile(\App\Models\JobOrderFile $file)
    {
        $this->assertCanSeeReference($file);

        return \Illuminate\Support\Facades\Storage::disk('local')->download($file->path, $file->original_name);
    }

    public function uploadReferenceFile(Request $request, ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load('jobOrder');
        abort_unless($order->jobOrder, 404);

        $data = $request->validate([
            'reference_files' => ['required', 'array'],
            'reference_files.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,ai,psd,eps,cdr,zip', 'max:65536'],
            // "output" = the design saved from ChatGPT (what the artist works from).
            'kind' => ['nullable', 'in:peg,logo,output'],
        ], [
            'reference_files.required' => 'Choose at least one file to upload.',
        ]);

        foreach ($request->file('reference_files') as $file) {
            $order->jobOrder->referenceFiles()->create([
                'path' => $file->store('job-order-refs', 'local'),
                'original_name' => $file->getClientOriginalName(),
                'kind' => $data['kind'] ?? null,
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        }

        return back()->with('success', ($data['kind'] ?? null) === 'output'
            ? 'Design uploaded — this is what the artist will work from.'
            : 'File uploaded.');
    }

    /** Mark an already-uploaded file as the ChatGPT design the artist works from. */
    public function markReferenceKind(Request $request, \App\Models\JobOrderFile $file): RedirectResponse
    {
        $order = $file->jobOrder->order;
        $this->assertOrderVisible($order);

        $data = $request->validate([
            'kind' => ['required', 'in:peg,logo,output'],
        ]);

        $file->update(['kind' => $data['kind']]);

        return back()->with('success', $data['kind'] === 'output'
            ? 'Set as the design the artist works from.'
            : 'File updated.');
    }

    public function deleteReferenceFile(\App\Models\JobOrderFile $file): RedirectResponse
    {
        $order = $file->jobOrder->order;
        $this->assertOrderVisible($order);

        \Illuminate\Support\Facades\Storage::disk('local')->delete($file->path);
        $file->delete();

        return back()->with('success', 'Reference file removed.');
    }
}
