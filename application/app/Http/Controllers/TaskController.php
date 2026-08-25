<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TaskController extends Controller
{
    /* ==================== Agent side ==================== */

    public function mine(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        // Searched in the database so it reaches every order the artist holds,
        // not the ones that happen to be on screen. Order number or client —
        // the two things anybody is told over the phone.
        $matching = fn ($q) => $q->when($search !== '', fn ($w) => $w
            ->whereHas('order', fn ($o) => $o
                ->where('order_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhereHas('client', fn ($c) => $c
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%"))));

        // Grouped by order (newest first). Only the ACTIVE step shows — finished
        // (complete) and locked (todo) steps are hidden so there's no redundancy.
        $tasks = Task::with('order.client')
            ->where('assigned_to', $request->user()->id)
            ->whereNotIn('status', ['todo', 'complete', 'cancelled'])
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->tap($matching)
            ->orderByDesc('production_order_id')
            ->orderBy('sequence')
            ->get();

        $orders = $tasks->groupBy(fn ($t) => $t->order->order_number);

        // Orders where this artist finished the layout but the next artist step
        // (mockup) is still locked — it waits on the account officer to collect
        // the downpayment and send the job order. Kept visible so the artist
        // knows the project is still theirs and what it's waiting on.
        $waiting = \App\Models\ProductionOrder::query()
            ->where('status', 'active')
            ->with(['jobOrder', 'tasks.files'])
            ->whereHas('tasks', fn ($q) => $q->where('assigned_to', $request->user()->id)
                ->where('team', \App\Models\User::JOB_ARTIST)
                ->where('status', 'complete'))
            ->whereHas('tasks', fn ($q) => $q->where('team', \App\Models\User::JOB_ARTIST)
                ->where('status', 'todo'))
            ->whereDoesntHave('tasks', fn ($q) => $q->where('assigned_to', $request->user()->id)
                ->whereIn('status', ['ready', 'in_progress', 'for_checking', 'revision_required']))
            ->get();

        // Finished work stays visible (collapsed) so the artist can look back at
        // what they submitted.
        $completed = Task::with('order.client')
            ->where('assigned_to', $request->user()->id)
            ->where('status', 'complete')
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->tap($matching)
            ->orderByDesc('approved_at')
            ->limit(20)
            ->get();

        return view('tasks.mine', [
            'orders' => $orders,
            'waiting' => $waiting,
            'completed' => $completed,
            'search' => $search,
        ], [
            'tech_pack_images.*.max' => 'Each Tech Pack image must be 40 MB or smaller.',
            'folder_shot.max' => 'The image must be 40 MB or smaller.',
        ]);
    }

    public function showMine(Request $request, int $taskId): View
    {
        // Scoped to the signed-in agent: someone else's task id gives 404,
        // so URLs cannot be guessed to reach unauthorized tasks.
        $task = Task::with(['order.jobOrder.referenceFiles', 'order.creator', 'order.tasks.files', 'assignee', 'files'])
            ->where('assigned_to', $request->user()->id)
            ->findOrFail($taskId);

        // The artist's next released step on this order (e.g. Template after the
        // approved Design layout) — shown on the same page so they continue here.
        $nextTask = Task::with('files')
            ->where('assigned_to', $request->user()->id)
            ->where('production_order_id', $task->production_order_id)
            ->where('sequence', '>', $task->sequence)
            ->whereNotIn('status', ['todo', 'complete', 'cancelled'])
            ->orderBy('sequence')
            ->first();

        return view('tasks.show', ['task' => $task, 'nextTask' => $nextTask]);
    }

    /** The artist opens the job order for a task assigned to them. */
    public function jobOrder(Request $request, int $taskId): View
    {
        $task = Task::where('assigned_to', $request->user()->id)->findOrFail($taskId);

        $order = $task->order->load(['jobOrder.referenceFiles', 'items', 'client', 'creator', 'tasks.assignee', 'tasks.files']);

        // Not shared during the layout step — the job order is only released to
        // the artist once the account officer has filled and sent it.
        abort_unless($order->jobOrder?->status === 'sent_to_artist', 403);

        // The artist fills the pack in place, the way the floor fills the seam
        // record — so the task comes with it, to post the answers back to.
        return view('orders.job-order', ['order' => $order, 'techPackTask' => $task]);
    }

    /** The artist opens the client reference for a task assigned to them. */
    public function references(Request $request, int $taskId): View
    {
        $task = Task::where('assigned_to', $request->user()->id)->findOrFail($taskId);

        $order = $task->order->load('jobOrder.referenceFiles');

        return view('orders.references', ['order' => $order]);
    }

    /** The pack field a text slot writes into, or null if it is a picture. */
    private function textFieldForSlot(string $slot): ?string
    {
        return match ($slot) {
            'text_tag_1' => 'tag_1_details',
            'text_tag_2' => 'tag_2_details',
            'text_banner' => 'placing_title',
            default => null,
        };
    }

    /** True when a text slot still has something written in it. */
    private function slotCarriesText(\App\Models\TechPack $pack, string $slot): bool
    {
        $field = $this->textFieldForSlot($slot);

        return $field !== null && filled($pack->$field);
    }

    /**
     * The artist's own part of the tech pack.
     *
     * Design name, colourways and the actual printed size of each placement:
     * things that only exist once the artwork does, so the account officer
     * cannot answer them at intake.
     *
     * Scoped to the artist's own task, like every other action on this page —
     * the task id in the URL is not a permission.
     */
    public function saveTechPack(Request $request, int $taskId): RedirectResponse
    {
        $task = Task::where('assigned_to', $request->user()->id)->findOrFail($taskId);

        $order = $task->order;
        $jobOrder = $order->jobOrder;
        abort_unless($jobOrder, 404);

        $data = $request->validate([
            // The tech pack's own fields, named as the shop's template names them.
            'design_name' => ['nullable', 'string', 'max:120'],
            'fitting' => ['nullable', 'string', 'max:60'],
            'item_style' => ['nullable', 'string', 'max:100'],
            'quality' => ['nullable', 'string', 'max:60'],
            'print_tech' => ['nullable', 'string', 'max:60'],
            'placing_title' => ['nullable', 'string', 'max:160'],
            'tshirt_color' => ['nullable', 'string', 'max:60'],
            // Their own rows now, not two halves of a dropdown.
            'print_label' => ['nullable', 'string', 'max:120'],
            'thread_color' => ['nullable', 'string', 'max:60'],
            'stitch_thread' => ['nullable', 'string', 'max:60'],
            'cutting_method' => ['nullable', 'string', 'max:60'],
            'size_range' => ['nullable', 'string', 'max:60'],
            'zipper_type' => ['nullable', 'string', 'max:60'],
            'lip_pocket_color' => ['nullable', 'string', 'max:60'],
            'tag_1_details' => ['nullable', 'string', 'max:120'],
            'tag_2_details' => ['nullable', 'string', 'max:120'],
            'file_location_notes' => ['nullable', 'string', 'max:200'],
            'file_location_tail' => ['nullable', 'string', 'max:200'],
            'file_location_host' => ['nullable', 'string', 'max:63'],
            'artist_name' => ['nullable', 'string', 'max:100'],
            'bottom_text' => ['nullable', 'string', 'max:1000'],
            'bottom_image_width' => ['nullable', 'integer', 'min:120', 'max:900'],
            'bottom_image_height' => ['nullable', 'integer', 'min:100', 'max:700'],
            'bottom_text_width' => ['nullable', 'integer', 'min:120', 'max:900'],
            'bottom_text_height' => ['nullable', 'integer', 'min:80', 'max:700'],
            'folder_shot' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:40960'],
            'remove_image' => ['nullable', 'string', 'in:'.implode(',', \App\Models\TechPack::removableSlots())],
            'add_image_box' => ['nullable'],
            'restore_image_box' => ['nullable', 'string', 'in:'.implode(',', \App\Models\TechPack::removableSlots())],
            // Text blocks the artist added themselves.
            'add_note_box' => ['nullable'],
            'remove_note' => ['nullable', 'integer', 'min:0'],
            'extra_notes' => ['nullable', 'array', 'max:12'],
            'extra_notes.*' => ['nullable', 'string', 'max:200'],
            // What the artist dragged each picture box to, as a share of the
            // sheet's width.
            // Where each box was dragged to, as a share of the sheet's width.
            // Where each detail box points to on the garment.
            'callouts' => ['nullable', 'array'],
            'callouts.*.fx' => ['nullable', 'numeric', 'min:-50', 'max:150'],
            'callouts.*.fy' => ['nullable', 'numeric', 'min:-50', 'max:150'],
            'callouts.*.tx' => ['nullable', 'numeric', 'min:-50', 'max:150'],
            'callouts.*.ty' => ['nullable', 'numeric', 'min:-50', 'max:150'],
            // The garment end inside the approved mockup. Unlike a whole-page
            // point, this stays identical between Artist and Display copies.
            'callouts.*.mx' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'callouts.*.my' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // A line saved before both ends were movable.
            'callouts.*.x' => ['nullable', 'numeric', 'min:-50', 'max:150'],
            'callouts.*.y' => ['nullable', 'numeric', 'min:-50', 'max:150'],
            'remove_callout' => ['nullable', 'string', 'max:40'],
            'box_positions' => ['nullable', 'array'],
            'box_positions.*.x' => ['nullable', 'numeric', 'min:-200', 'max:200'],
            'box_positions.*.y' => ['nullable', 'numeric', 'min:-200', 'max:200'],
            // A text block's nudge from its own place, as a share of its own
            // box. A block carried right across the sheet is a lot of its own
            // widths away, so the range is wide.
            'box_positions.*.ox' => ['nullable', 'numeric', 'min:-5000', 'max:5000'],
            'box_positions.*.oy' => ['nullable', 'numeric', 'min:-5000', 'max:5000'],
            'image_sizes' => ['nullable', 'array'],
            'image_sizes.*.w' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'image_sizes.*.h' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'tech_pack_images' => ['nullable', 'array'],
            'tech_pack_images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:40960'],

            // These belong to the JOB ORDER and keep living there — the pack
            // shows them so they can be corrected on the sheet the floor reads,
            // not so they get a second home.
            'fabric' => ['nullable', 'string', 'max:255'],
            'bottom_hem' => ['nullable', 'string', 'max:255'],
            'neck' => ['nullable', 'string', 'max:100'],
            'cuff_arm_sleeves' => ['nullable', 'string', 'max:100'],
            'neck_label' => ['nullable', 'string', 'max:120'],
            'packaging' => ['nullable', 'string', 'max:120'],
            'free_logo_sticker' => ['nullable', 'string', 'max:120'],
            'print_type' => ['nullable', 'string', 'max:60'],
        ]);

        // The spec rows are the officer's and are not in this list, so an artist
        // posting them by hand changes nothing. The lock on the sheet is a lock
        // here too, not just a box they cannot click into.
        $packFields = collect($data)->only([
            'placing_title',
            'tag_1_details', 'tag_2_details',
            'file_location_notes', 'artist_name',
            'bottom_text', 'bottom_image_width', 'bottom_image_height',
            'bottom_text_width', 'bottom_text_height',
        ])->all();

        $pack = $order->techPack()->firstOrNew([]);

        $imageUploads = $pack->image_uploads ?? [];

        // The sample panel grows and shrinks with the garment: the × takes a box
        // away, the + adds one. The picture goes with the box — leaving the file
        // behind would fill the disk with images nothing points at.
        $boxes = $pack->sampleBoxes();

        $hidden = $pack->hiddenBoxes();

        if ($drop = $data['remove_image'] ?? null) {
            // The × empties the box first and only takes the box away on the
            // second press.
            //
            // It used to do both at once, which meant one stray click on the
            // file-location picture removed the whole panel — and the way to
            // get it back was a chip under the sheet that is easy to miss. From
            // where the artist sits that is not "I removed a box", it is "I can
            // no longer add a picture here". Emptying is the common thing and
            // it is now the reversible one: upload again and it is back.
            $hadPicture = filled($imageUploads[$drop]['path'] ?? null);
            $hasText = $this->slotCarriesText($pack, $drop);

            if ($hadPicture) {
                Storage::disk('local')->delete($imageUploads[$drop]['path']);
                unset($imageUploads[$drop]);
            } elseif ($hasText) {
                // A text block empties the same way before it goes.
                $packFields[$this->textFieldForSlot($drop)] = null;
            } else {
                // Already empty: now the box itself goes. The sample panel is a
                // list of the boxes it HAS, so one comes off that list; every
                // other box is part of the sheet, so it is named as one this
                // pack does without.
                if (in_array($drop, $boxes, true)) {
                    $boxes = array_values(array_diff($boxes, [$drop]));
                } else {
                    $hidden[] = $drop;
                }
            }
        }

        // Putting one back.
        if ($restore = $data['restore_image_box'] ?? null) {
            $hidden = array_values(array_diff($hidden, [$restore]));
        }

        $packFields['hidden_boxes'] = array_values(array_unique($hidden)) ?: null;

        // Text blocks: what was typed into the ones already there, then one
        // added or one taken away. Kept as a list, so the note that was second
        // is still second when the sheet is read back.
        if ($request->has('extra_notes') || $request->filled('add_note_box') || $request->has('remove_note')) {
            $notes = $data['extra_notes'] ?? $pack->extraNotes();

            if ($request->has('remove_note')) {
                unset($notes[(int) $data['remove_note']]);
            }

            $notes = array_values(array_filter(array_map('trim', $notes), fn ($n) => $n !== ''));

            if ($request->filled('add_note_box') && count($notes) < 12) {
                $notes[] = '';
            }

            $packFields['extra_notes'] = $notes ?: null;
        }

        // The leader lines: whichever pins were moved, and any that were
        // double-clicked away.
        if ($request->has('callouts') || $request->has('remove_callout')) {
            // Read in the shape it is stored in, so the end nobody touched is
            // not lost when the other end moves.
            $lines = (array) ($pack->callouts ?? []);

            foreach (($data['callouts'] ?? []) as $slot => $at) {
                $was = (array) ($lines[$slot] ?? []);

                $tx = $at['tx'] ?? $at['x'] ?? ($was['tx'] ?? $was['x'] ?? null);
                $ty = $at['ty'] ?? $at['y'] ?? ($was['ty'] ?? $was['y'] ?? null);

                if (! is_numeric($tx) || ! is_numeric($ty)) {
                    continue;
                }

                $line = ['tx' => round((float) $tx, 2), 'ty' => round((float) $ty, 2)];

                $fx = $at['fx'] ?? ($was['fx'] ?? null);
                $fy = $at['fy'] ?? ($was['fy'] ?? null);

                if (is_numeric($fx) && is_numeric($fy)) {
                    $line['fx'] = round((float) $fx, 2);
                    $line['fy'] = round((float) $fy, 2);
                }

                $mx = $at['mx'] ?? ($was['mx'] ?? null);
                $my = $at['my'] ?? ($was['my'] ?? null);

                if (is_numeric($mx) && is_numeric($my)) {
                    $line['mx'] = round(max(0, min(100, (float) $mx)), 2);
                    $line['my'] = round(max(0, min(100, (float) $my)), 2);
                }

                $lines[$slot] = $line;
            }

            if ($gone = ($data['remove_callout'] ?? null)) {
                unset($lines[$gone]);
            }

            $packFields['callouts'] = $lines ?: null;
        }

        // Only the boxes that were actually dragged come back, so the rest keep
        // where they were rather than snapping home.
        if ($moved = ($data['box_positions'] ?? [])) {
            $places = $pack->box_positions ?? [];

            foreach ($moved as $slot => $at) {
                if (is_numeric($at['x'] ?? null) && is_numeric($at['y'] ?? null)) {
                    $places[$slot] = ['x' => round((float) $at['x'], 2), 'y' => round((float) $at['y'], 2)];

                    foreach (['ox', 'oy'] as $axis) {
                        if (is_numeric($at[$axis] ?? null)) {
                            $places[$slot][$axis] = round((float) $at[$axis], 2);
                        }
                    }
                }
            }

            $packFields['box_positions'] = $places ?: null;
        }

        if ($request->filled('add_image_box') && ($next = $pack->nextSampleSlot())) {
            $boxes[] = $next;
        }

        $packFields['image_boxes'] = $boxes;

        // Sizes arrive only for the boxes that were actually dragged, so the
        // rest keep whatever they had rather than being reset to the default.
        if ($sizes = ($data['image_sizes'] ?? [])) {
            $kept = $pack->image_sizes ?? [];

            foreach ($sizes as $slot => $size) {
                if (! in_array($slot, \App\Models\TechPack::imageSlots(), true)) {
                    continue;
                }

                if (is_numeric($size['w'] ?? null) && is_numeric($size['h'] ?? null)) {
                    $kept[$slot] = ['w' => round((float) $size['w'], 2), 'h' => round((float) $size['h'], 2)];
                }
            }

            // A box that was removed takes its size with it.
            $packFields['image_sizes'] = array_intersect_key($kept, array_flip(
                array_merge($boxes, \App\Models\TechPack::imageSlots())
            )) ?: null;
        }

        // The machine and the folder are two boxes on the sheet, so the path is
        // put back together here.
        if ($request->has('file_location_tail') || $request->has('file_location_host')) {
            // The machine NAME leads: \\IC-SERVER\FOR PRINT survives the router
            // handing that PC a different address, which an IP path does not.
            // What was typed wins, then this machine's name, and an address only
            // if there is no name to be had — a path is no use with nothing on
            // the front of it.
            $host = trim((string) ($data['file_location_host'] ?? ''))
                ?: (string) (\App\Services\ServerIp::deviceName()
                    ?: (\App\Services\ServerIp::isPrivate((string) $request->ip())
                        ? $request->ip()
                        : (string) \App\Services\ServerIp::current()));

            $tail = ltrim((string) ($data['file_location_tail'] ?? ''), '\\');

            $packFields['file_location_notes'] = (filled($host) && filled($tail))
                ? '\\\\'.$host.'\\'.$tail
                : (filled($tail) ? $tail : null);
        }

        foreach (\App\Models\TechPack::imageSlots() as $slot) {
            if (! $request->hasFile("tech_pack_images.{$slot}")) {
                continue;
            }

            if (filled($imageUploads[$slot]['path'] ?? null)) {
                Storage::disk('local')->delete($imageUploads[$slot]['path']);
            }

            $file = $request->file("tech_pack_images.{$slot}");
            $imageUploads[$slot] = [
                'path' => $file->store('tech-pack-images', 'local'),
                'name' => $file->getClientOriginalName(),
            ];
        }
        $packFields['image_uploads'] = $imageUploads ?: null;

        if ($request->hasFile('folder_shot')) {
            // Replace rather than accumulate: the old picture is of a folder
            // that has since changed, which is the reason for a new one.
            if ($pack->folder_shot_path) {
                Storage::disk('local')->delete($pack->folder_shot_path);
            }

            $file = $request->file('folder_shot');
            $packFields['folder_shot_path'] = $file->store('folder-shots', 'local');
            $packFields['folder_shot_name'] = $file->getClientOriginalName();
        }

        $pack->fill($packFields);
        $order->techPack()->save($pack);

        // Nothing of the job order's is written here any more: fabric, neck,
        // cuff, the labels, the hem, packaging and the sticker row are all the
        // officer's half of the sheet.

        // Clicking the explicit Save button means the Artist is finished with
        // the sheet and is ready for its next action. Image uploads use this
        // same endpoint for background auto-save, but they do not carry
        // finish_editing and must not throw the Artist out after every picture.
        if ($request->boolean('finish_editing')) {
            return redirect(route('tasks.show', $task->id).'#task-action-'.$task->id)
                ->with('success', 'Tech Pack saved. It is ready to submit for checking.');
        }

        return back()->with('success', 'Tech pack saved.');
    }

    /**
     * Correct the network path on an export step that has already been sent.
     *
     * The step completes the moment the path is handed over, so a typo, or a
     * file that has since been moved or renamed, used to leave production
     * pointed at nothing with no way for the artist to put it right. They can
     * now edit it and send it again; the step stays complete, because the work
     * was done — only its address changed.
     */
    public function updatePath(Request $request, int $taskId): RedirectResponse
    {
        $user = $request->user();

        $task = Task::with('files')
            ->when(! $user->isLeader(), fn ($q) => $q->where('assigned_to', $user->id))
            ->findOrFail($taskId);

        abort_unless($task->usesFilePath(), 404);

        $slots = $task->fileSlots();

        $rules = [];
        $messages = [];
        foreach ($slots as $key => $label) {
            $rules["paths.$key"] = ['required', 'string', 'max:1024', new \App\Rules\NetworkFilePath];
            $messages["paths.$key.required"] = "Enter the file path for the {$label}.";
        }
        $request->validate($rules, $messages);

        $changed = [];

        foreach ($slots as $key => $label) {
            $path = trim((string) $request->input("paths.$key"));

            // The newest file carrying this label is the one production reads.
            $file = $task->files()->where('label', $label)->orderByDesc('id')->first();

            if (! $file) {
                $task->files()->create([
                    'path' => null,
                    'external_path' => $path,
                    'original_name' => basename(str_replace('\\', '/', $path)) ?: $path,
                    'label' => $label,
                    'round' => (int) $task->revision_count + 1,
                    'uploaded_by' => $user->id,
                ]);
                $changed[] = $label;

                continue;
            }

            if ($file->external_path !== $path) {
                $file->update(['external_path' => $path, 'uploaded_by' => $user->id]);
                $changed[] = $label;
            }
        }

        if ($changed === []) {
            return back()->with('success', 'Nothing changed — the paths are the same as before.');
        }

        return back()->with('success', 'Sent again: '.implode(', ', $changed).'. Production sees the new location.');
    }

    public function start(Request $request, int $taskId): RedirectResponse
    {
        $task = Task::where('assigned_to', $request->user()->id)->findOrFail($taskId);
        $task->start($request->user());

        if ($task->isTechPackStep()) {
            return redirect()->route('tasks.job-order', $task->id)
                ->with('success', 'Tech Pack opened.');
        }

        return back()->with('success', $task->department.' is now IN PROGRESS.');
    }

    public function hold(Request $request, int $taskId): RedirectResponse
    {
        $task = Task::where('assigned_to', $request->user()->id)->findOrFail($taskId);
        $task->putOnHold($request->user());

        return back()->with('success', $task->department.' is now ON HOLD. Resume it when you continue.');
    }

    public function resume(Request $request, int $taskId): RedirectResponse
    {
        $task = Task::where('assigned_to', $request->user()->id)->findOrFail($taskId);
        $task->resumeWork($request->user());

        return back()->with('success', $task->department.' resumed — back IN PROGRESS.');
    }

    public function submit(Request $request, int $taskId): RedirectResponse
    {
        $task = Task::where('assigned_to', $request->user()->id)->findOrFail($taskId);

        // Each step declares the files it must hand over (mockup = 2 files).
        $slots = $task->fileSlots();

        // Design/production files are referenced by a network path, not uploaded.
        if ($task->usesFilePath()) {
            return $this->submitByPath($request, $task, $slots);
        }

        $mimes = 'mimes:jpg,jpeg,png,webp,pdf,ai,psd,eps,cdr,zip,tiff,tif';
        $rules = [];
        $messages = [];

        if ($slots !== []) {
            foreach ($slots as $key => $label) {
                $rules[$key] = ['required', 'file', $mimes];
                $messages[$key.'.required'] = "Upload the {$label} — it must be attached before this can be checked.";
            }
        } else {
            $rules['file'] = ['nullable', 'file', $mimes];
        }

        $request->validate($rules, $messages);

        $round = (int) $task->revision_count + 1;
        $store = function ($uploaded, ?string $label) use ($task, $request, $round) {
            $task->files()->create([
                'path' => $uploaded->store('task-files', 'local'),
                'original_name' => $uploaded->getClientOriginalName(),
                'label' => $label,
                'mime' => $uploaded->getClientMimeType(),
                'size' => $uploaded->getSize(),
                'round' => $round,
                'uploaded_by' => $request->user()->id,
            ]);
        };

        if ($slots !== []) {
            foreach ($slots as $key => $label) {
                $store($request->file($key), $label);
            }
        } elseif ($request->hasFile('file')) {
            $store($request->file('file'), null);
        }

        // Export steps need no approval — handing over the file completes the step
        // and releases the production step waiting on it (printer / sticker).
        if ($task->isExportStep()) {
            $task->forceComplete();

            return back()->with('success', $task->department.' uploaded — production can now proceed.');
        }

        $task->submitForChecking($request->user());

        $who = $task->approver_role === 'sales' ? 'The sales agent' : 'Your leader';

        return back()->with('success', $task->department.' submitted FOR CHECKING. '.$who.' will review it.');
    }

    /**
     * Submit a design/production step by recording the file's location on the
     * shared drive (a network path or link) instead of uploading it.
     */
    private function submitByPath(Request $request, Task $task, array $slots): RedirectResponse
    {
        $rules = [];
        $messages = [];
        foreach ($slots as $key => $label) {
            // The box arrives pre-filled with the PC's address, so "not empty"
            // was never enough — it has to point at an actual file.
            $rules["paths.$key"] = ['required', 'string', 'max:1024', new \App\Rules\NetworkFilePath];
            $messages["paths.$key.required"] = "Enter the file path for the {$label}.";
        }
        // One design is not always one file - a front and a back, a sleeve
        // done separately. Anything added with "Add another file" arrives as
        // extra_1, extra_2... optional, but a real path if it was filled in.
        $extras = collect($request->input('paths', []))
            ->filter(fn ($v, $k) => str_starts_with((string) $k, 'extra_') && filled($v));

        foreach ($extras as $key => $ignored) {
            $rules["paths.$key"] = ['nullable', 'string', 'max:1024', new \App\Rules\NetworkFilePath];
        }

        $request->validate($rules, $messages);

        $round = (int) $task->revision_count + 1;

        // The declared slot(s) first, then the artist's extras, numbered so
        // production can tell them apart on the sheet.
        $toStore = collect($slots)->map(fn ($label, $key) => [$key, $label])->values();
        $n = 0;
        foreach ($extras->keys() as $key) {
            $n++;
            $toStore->push([$key, ($slots ? reset($slots) : 'Export file').' ('.($n + 1).')']);
        }

        foreach ($toStore as [$key, $label]) {
            $path = trim((string) $request->input("paths.$key"));

            if ($path === '') {
                continue;
            }
            $task->files()->create([
                'path' => null,
                'external_path' => $path,
                'original_name' => basename(str_replace('\\', '/', $path)) ?: $path,
                'label' => $label,
                'round' => $round,
                'uploaded_by' => $request->user()->id,
            ]);
        }

        // The tech pack step also carries the mockup's location across on submit.
        if ($task->isTechPackStep()) {
            $design = $task->order->tasks->first(fn ($t) => str_starts_with($t->department, 'Final mockup')
                || str_starts_with($t->department, 'Design layout')
                || str_starts_with($t->department, 'Design sample'));
            $mockup = $design?->latestFile();
            if ($mockup) {
                $task->files()->create([
                    'path' => $mockup->path,
                    'external_path' => $mockup->external_path,
                    'original_name' => $mockup->original_name,
                    'label' => 'Mockup (from final mockup)',
                    'mime' => $mockup->mime,
                    'size' => $mockup->size,
                    'round' => $round,
                    'uploaded_by' => $request->user()->id,
                ]);
            }
        }

        if ($task->isExportStep()) {
            $task->forceComplete();

            return back()->with('success', $task->department.' file path saved — production can now proceed.');
        }

        $task->submitForChecking($request->user());
        $who = $task->approver_role === 'sales' ? 'The sales agent' : 'Your leader';

        return back()->with('success', $task->department.' submitted FOR CHECKING. '.$who.' will review it.');
    }

    /** Who may see a submitted file: the assignee, the approver, leaders, admins. */
    private function assertCanSeeFile(Request $request, \App\Models\TaskFile $file): void
    {
        $user = $request->user();
        $task = $file->task;

        // The floor has to see the approved design for the job it is making —
        // station operators, the raw-materials desk and the products desk.
        $onTheFloor = $user->canUseStations()
            || $user->canManageInventory()
            || $user->canManageProducts();

        $allowed = $user->isLeader()                                  // leader + super admin
            || ($user->isSales()                                      // the account officer…
                && $task->order->created_by === $user->id)            // …for their own order (layout, mockup, template)
            || $task->assigned_to === $user->id                       // the artist who made it
            || ($onTheFloor && $task->order->layoutApproved());       // production, once the design is approved

        abort_unless($allowed, 403);
        // External (network-path) files have nothing stored — the check below is
        // only for real uploads.
        abort_unless($file->isExternal() || \Illuminate\Support\Facades\Storage::disk('local')->exists($file->path), 404);
    }

    /** Download a submitted file (sales sends this to the client). */
    public function downloadFile(Request $request, \App\Models\TaskFile $file)
    {
        $this->assertCanSeeFile($request, $file);

        if ($file->isExternal()) {
            return $file->isWebLink()
                ? redirect()->away($file->external_path)
                : response()->view('files.external-path', ['file' => $file]);
        }

        return \Illuminate\Support\Facades\Storage::disk('local')->download($file->path, $file->original_name);
    }

    /** Show a submitted file in the browser (leader checks it on screen). */
    public function viewFile(Request $request, \App\Models\TaskFile $file)
    {
        $this->assertCanSeeFile($request, $file);

        if ($file->isExternal()) {
            return $file->isWebLink()
                ? redirect()->away($file->external_path)
                : response()->view('files.external-path', ['file' => $file]);
        }

        return \Illuminate\Support\Facades\Storage::disk('local')->response($file->path, $file->original_name);
    }

    /** Sales: samples waiting for the client's decision. */
    public function sampleReview(Request $request): View
    {
        $tasks = Task::with(['order.client', 'order.jobOrder', 'assignee', 'files'])
            ->where('approver_role', 'sales')
            ->where('status', 'for_checking')
            ->whereHas('order', function ($q) use ($request) {
                $q->where('status', 'active');
                // Account officers only review samples for their own orders.
                if ($request->user()->isSales()) {
                    $q->where('created_by', $request->user()->id);
                }
            })
            ->orderBy('submitted_at')
            ->get();

        return view('tasks.sample-review', ['tasks' => $tasks]);
    }

    /* ==================== Leader side ==================== */

    public function approvals(Request $request): View
    {
        $mockup = \App\Models\ProductionOrder::STAGE_MOCKUP;

        // The mockup + template of one order are a single job package. Show it as
        // one row only when the WHOLE package is for_checking (if one item is out
        // for revision, the package waits) and no job-order fix is pending.
        $packages = Task::with(['order.jobOrder', 'order.items', 'assignee', 'files'])
            ->where('stage', $mockup)
            ->where('approver_role', 'leader')
            ->whereHas('order', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->groupBy('production_order_id')
            ->filter(fn ($group) => $group->every(fn ($t) => $t->status === 'for_checking')
                && blank($group->first()->order->jobOrder?->leader_note));

        // Everything else (pairing, sewing, QC…) — one row each.
        $singles = Task::with(['order', 'assignee', 'files'])
            ->where('status', 'for_checking')
            ->where('approver_role', 'leader')
            ->where('stage', '!=', $mockup)
            ->whereHas('order', fn ($q) => $q->where('status', 'active'))
            ->orderBy('submitted_at')
            ->get();

        // The artist leader checks the artists' work and nothing else — the
        // rest of the floor still answers to the leader. And he draws packs
        // himself, so his own never appear here: those wait for Maam Carla.
        if ($request->user()->isArtistLead()) {
            $singles = $singles->take(0);
            $packages = $packages->reject(
                fn ($group) => $group->contains('assigned_to', $request->user()->id)
            );
        }

        // Two people share this queue, so a pack that one of them signs off
        // must not simply vanish for the other — it drops into "already
        // checked" with the time it was approved, and stays there for a week.
        $checked = Task::with(['order.client', 'assignee'])
            ->where('stage', $mockup)
            ->where('approver_role', 'leader')
            ->where('status', 'complete')
            ->where('approved_at', '>=', now()->subWeek())
            ->whereHas('order', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->groupBy('production_order_id')
            ->sortByDesc(fn ($group) => $group->max('approved_at'));

        return view('tasks.approvals', [
            'packages' => $packages,
            'singles' => $singles,
            'checked' => $checked,
        ]);
    }

    public function assign(Request $request, Task $task): RedirectResponse
    {
        $data = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $userId = $data['assigned_to'] ?: null;

        // One job at a time: don't hand a task to someone who already has an open one.
        if ($userId) {
            $busy = Task::where('assigned_to', $userId)
                ->where('id', '!=', $task->id)
                ->whereNotIn('status', ['complete', 'cancelled'])
                ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled'))
                ->exists();

            if ($busy) {
                return back()->withErrors(['assigned_to' => \App\Models\User::find($userId)?->name.' already has an open task. Finish or reassign that first.']);
            }
        }

        $task->assignTo($userId);

        return back()->with('success', $task->department.' assignment updated.');
    }

    /**
     * Samples are decided by the client via sales; every other step by the
     * leader. Super admin can always step in.
     */
    private function assertApprover(Request $request, Task $task): void
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return;
        }

        $ok = match ($task->approver_role) {
            'sales' => $user->isSales(),
            // Handing the goods over is the products desk's job — they are the
            // ones holding the stock and facing the client at the counter.
            'inventory' => $user->canManageProducts(),
            // The artist leader signs off the tech pack only, and never the
            // one he drew himself.
            default => $user->isArtistLead()
                ? $task->stage === \App\Models\ProductionOrder::STAGE_MOCKUP
                    && $task->assigned_to !== $user->id
                : $user->isLeader(),
        };

        abort_unless($ok, 403);

        // An account officer may only act on samples for their own orders. This
        // is about whose client it is, so it does not apply to the products
        // desk, who release for the whole shop.
        if ($task->approver_role === 'sales' && $user->isSales() && $task->order->created_by !== $user->id) {
            abort(403);
        }
    }

    public function approve(Request $request, Task $task): RedirectResponse
    {
        $this->assertApprover($request, $task);

        // Nothing goes out of the door on an unpaid balance. This is the last
        // step before the client has the goods, so it is the last chance to
        // catch it — after this the only leverage left is asking nicely.
        if ($task->department === 'Release to client' && ! $task->order->isFullyPaid()) {
            $balance = $task->order->balance();

            return back()->with('error', $balance === null
                ? 'Cannot release — this order has no total price set, so there is no way to tell if it is paid. Set the price and record the payment first.'
                : 'Cannot release — ₱'.number_format($balance, 2).' is still unpaid. Record the full payment before handing over to the client.');
        }

        // Who physically handed the goods over. The products desk is a shared
        // login, so the account says nothing — and this step used to record
        // nobody at all, leaving the last line of the pipeline reading "—" on
        // the one movement where somebody signed for the goods.
        if ($task->department === 'Release to client') {
            $data = $request->validate(
                ['operator_name' => ['required', 'string', 'max:100']],
                ['operator_name.required' => 'Enter the name of the person handing the order over.']
            );

            $task->update(['operator_name' => trim($data['operator_name'])]);
        }

        // Every other step closed by an approval had the same hole: nobody
        // works them at a station, so nothing ever wrote a name and the
        // pipeline showed "—" for who did it. The account officer's login IS
        // a person, unlike the shared desks, so it can answer for itself
        // without asking.
        //
        // Only where the line would otherwise be blank. A step with a worker
        // on it already names them, and the pipeline reads operator_name FIRST
        // — so stamping the approver here would quietly replace the artist who
        // drew the layout with the officer who nodded at it.
        if (blank($task->operator_name) && $task->assigned_to === null) {
            $task->update(['operator_name' => $request->user()->name]);
        }

        $task->approve();

        // The client approved the first physical sample — count that one piece
        // into finished-products stock so it's the first unit in inventory.
        if ($task->department === 'Produce sample for client') {
            $task->order->stockFirstSample();
        }

        // Approving the design sample unlocks the Mockup & template step (which
        // the artist makes from the already-filled job order).
        $order = $task->order->fresh(['tasks']);
        $justReady = $order->tasks->where('status', 'ready')->pluck('department')->join(', ');
        $note = $justReady !== '' ? ' Now ready: '.$justReady.'.' : '';

        // The client just approved the LAYOUT — the next step is the downpayment,
        // recorded on the order's job-order details page. Send the account officer
        // straight there (to the payment section) instead of back to the now-empty
        // review list, so they don't have to hunt for the order.
        if ($task->stage === \App\Models\ProductionOrder::STAGE_LAYOUT && ! $order->hasDownpayment()) {
            return redirect()
                ->to(route('orders.show', $order).'#payment-section')
                ->with('success', $task->department.' approved — now record the downpayment to move the order into the job order.');
        }

        return back()->with('success', $task->department.' approved and marked COMPLETE.'.$note);
    }

    public function requestRevision(Request $request, Task $task): RedirectResponse
    {
        $this->assertApprover($request, $task);

        $data = $request->validate([
            'revision_note' => ['required', 'string', 'max:2000'],
        ]);

        $task->requestRevision($data['revision_note']);
        $task->refresh();

        $left = $task->revisionsLeft();
        $note = $left > 0
            ? " Revision {$task->revision_count} of ".Task::MAX_REVISIONS." — {$left} left."
            : ' Revision limit ('.Task::MAX_REVISIONS.') now reached — the next submission must be approved or escalated to the leader.';

        return back()->with('success', $task->department.' sent back: REVISION REQUIRED.'.$note);
    }

    /**
     * The client rejected the physical sample. Unlike a layout, this doesn't go
     * back to an artist — it goes back to a production step (printing, cutting,
     * pairing, sewing…), and everything from there onwards runs again.
     */
    public function returnSampleToStage(Request $request, Task $task): RedirectResponse
    {
        $this->assertApprover($request, $task);
        abort_unless($task->department === 'Produce sample for client', 404);

        $order = $task->order;

        $data = $request->validate([
            'department' => ['required', 'string', Rule::in(
                $order->tasks()->whereBetween('stage', [3, 8])->pluck('department')->all()
            )],
            'revision_note' => ['required', 'string', 'max:2000'],
        ], [
            'department.required' => 'Choose which step has to be done again.',
            'revision_note.required' => 'Tell them what needs fixing.',
        ]);

        $target = $order->tasks()->where('department', $data['department'])->firstOrFail();

        DB::transaction(function () use ($order, $task, $target, $data) {
            // Everything from the failed step onwards has to run again.
            $order->tasks()
                ->where('stage', '>', $target->stage)
                ->update(['status' => 'todo', 'submitted_at' => null, 'approved_at' => null]);

            $task->update([
                'status' => 'todo',
                'submitted_at' => null,
                'revision_note' => $data['revision_note'],
                'revision_count' => $task->revision_count + 1,
            ]);

            // Reopen the step itself and put it back on its station.
            $order->tasks()->where('stage', $target->stage)->update([
                'status' => 'todo',
                'approved_at' => null,
                'revision_note' => $data['revision_note'],
            ]);

            $order->unlockStage($target->stage);
        });

        \App\Models\AppNotification::toRole(
            $target->team ?: \App\Models\User::JOB_PRODUCTION,
            '↩ Sample sent back',
            "{$order->order_number} — {$target->department} has to be done again: ".\Illuminate\Support\Str::limit($data['revision_note'], 80),
            route('stations.index'),
        );

        return back()->with('success',
            "Sent back to {$target->department}. Everything after it will run again once that step is finished.");
    }

    /** The mockup + template are one job package — approve both at once. */
    public function approvePackage(Request $request, \App\Models\ProductionOrder $order): RedirectResponse
    {
        abort_unless($request->user()->isLeader() || $request->user()->isArtistLead(), 403);

        $tasks = $this->packageTasks($order);
        abort_if($tasks->isEmpty(), 404);
        $this->assertNotOwnPack($request, $tasks);

        foreach ($tasks as $t) {
            $t->approve();
        }

        return back()->with('success', 'Design package (mockup + template) approved for '.$order->order_number.'.');
    }

    /**
     * Send the package back for revision. The leader says who fixes it:
     *   - artist  → the DESIGN is wrong; the same mockup/template go back to the
     *               artist to redo, then resubmit to the leader.
     *   - officer → the JOB ORDER is wrong; the account officer corrects it and
     *               the (unchanged) package returns to the leader once fixed —
     *               the artist is NOT involved.
     *   - both    → both of the above happen in parallel.
     *
     * The mockup/template tasks are never deleted or rewound to TODO, so no
     * second pipeline is ever created and it always comes back as ONE package.
     */
    public function revisePackage(Request $request, \App\Models\ProductionOrder $order): RedirectResponse
    {
        abort_unless($request->user()->isLeader() || $request->user()->isArtistLead(), 403);

        $data = $request->validate([
            'revision_note' => ['required', 'string', 'max:2000'],
            // Exactly which parts are wrong: the mockup, the template (both go to
            // the artist), and/or the job order (goes to the account officer).
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['in:mockup,template,job_order'],
        ], ['items.required' => 'Tick at least one thing that needs fixing.']);

        $tasks = $this->packageTasks($order);
        abort_if($tasks->isEmpty(), 404);
        $this->assertNotOwnPack($request, $tasks);
        $order->loadMissing('jobOrder');
        $items = $data['items'];
        $fixed = [];

        // Design fixes — only the flagged item(s) go back, so the artist knows
        // exactly which one is the problem.
        foreach (['mockup' => 'Final mockup', 'template' => 'Tech pack'] as $key => $dept) {
            if (in_array($key, $items, true)) {
                $t = $key === 'template'
                    ? $tasks->first(fn ($x) => $x->isTechPackStep())
                    : $tasks->firstWhere('department', $dept);
                if ($t && $t->canRequestRevision()) {
                    $t->requestRevision($data['revision_note']);
                    $fixed[] = $dept;
                }
            }
        }

        // The officer's half of the TECH PACK — the spec rows they fill in.
        // There is no job order sheet any more, so the note goes back to the
        // person who wrote those rows and the package waits off the leader's
        // queue until they have corrected it. The artist isn't pulled in.
        //
        // 'job_order' is still accepted so a form opened before this still
        // posts something the leader meant.
        if (array_intersect(['officer_half', 'job_order'], $items)) {
            $order->jobOrder?->update(['leader_note' => $data['revision_note']]);
            \App\Models\AppNotification::toUser($order->created_by,
                '↩ Leader wants the tech pack fixed',
                $order->order_number.' — '.\Illuminate\Support\Str::limit($data['revision_note'], 90),
                route('orders.show', $order));
            $fixed[] = "the officer's half of the tech pack";
        }

        return back()->with('success', 'Sent back for revision on '.$order->order_number.': '.implode(', ', $fixed).'.');
    }

    /**
     * Nobody signs off their own drawing. The artist leader is on the artist
     * rotation, so a pack he made waits for a leader instead.
     */
    private function assertNotOwnPack(Request $request, $tasks): void
    {
        abort_if(
            $request->user()->isArtistLead()
                && $tasks->contains('assigned_to', $request->user()->id),
            403
        );
    }

    /** The stage-2 leader-check tasks (mockup + template) awaiting checking. */
    private function packageTasks(\App\Models\ProductionOrder $order)
    {
        return $order->tasks()
            ->where('stage', \App\Models\ProductionOrder::STAGE_MOCKUP)
            ->where('status', 'for_checking')
            ->where('approver_role', 'leader')
            ->get();
    }

    public function unlock(Task $task): RedirectResponse
    {
        $task->unlock();

        return back()->with('success', $task->department.' unlocked early (dependency override). It is now READY.');
    }

    /**
     * Leader override: close a step without the agent submitting it.
     *
     * Releasing goods that have not been paid for goes through here too, and
     * that one is different from the rest. Every other step can be re-run if it
     * was closed too early; money that left with the client cannot. So the
     * override stays — it is there to unstick a real job — but it has to be
     * deliberate, and it has to leave a trace on the order rather than
     * happening silently behind a confirm dialog.
     */
    public function forceComplete(Request $request, Task $task): RedirectResponse
    {
        $order = $task->order;
        $unpaidRelease = $task->department === 'Release to client' && ! $order->isFullyPaid();
        $reason = null;

        if ($unpaidRelease) {
            $reason = trim((string) $request->validate([
                'override_reason' => ['required', 'string', 'min:5', 'max:500'],
            ], [
                'override_reason.required' => 'Say why this order is being released before it is paid — it is recorded on the order.',
                'override_reason.min' => 'Give a real reason — it is recorded on the order.',
            ])['override_reason']);
        }

        $balance = $order->balance();

        $task->forceComplete();

        if ($unpaidRelease) {
            // On the conversation, not in a log file: this is the thread the
            // people who chase the money actually read.
            $order->messages()->create([
                'sender_id' => $request->user()->id,
                'body' => sprintf(
                    "RELEASED WITHOUT FULL PAYMENT.\n%s outstanding at release.\nAuthorised by %s.\nReason: %s",
                    $balance === null ? 'No total price set — amount unknown' : '₱'.number_format($balance, 2),
                    $request->user()->name,
                    $reason
                ),
            ]);

            return back()->with('success', $task->department.' marked COMPLETE by leader override — '
                .'the unpaid release has been recorded on the order conversation.');
        }

        return back()->with('success', $task->department.' marked COMPLETE by leader override.');
    }
}
