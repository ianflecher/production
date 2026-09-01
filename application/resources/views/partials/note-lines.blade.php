{{--
    A note, read back as the list of instructions it is.

    One item stays a plain line — a bullet on its own reads like a fragment of
    a list somebody forgot to finish. Two or more become the list.
--}}
@php $noteItems = \App\Services\NoteLines::bullets($note ?? null); @endphp

@if (count($noteItems) > 1)
    <ul class="note-lines">
        @foreach ($noteItems as $item)
            <li>{{ $item }}</li>
        @endforeach
    </ul>
@elseif (count($noteItems) === 1)
    <p class="note-lines-single">{{ $noteItems[0] }}</p>
@endif
