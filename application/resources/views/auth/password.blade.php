@extends('layouts.app')

@section('title', 'My Account — Imprint Production')
@section('page-title', 'My Account')

@section('content')

<div class="page-head">
    <div class="grow">
        <h1>My account</h1>

        <p class="muted">
            Update your profile picture, display name, or password.
        </p>
    </div>
</div>

<div
    style="
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 1.4rem;
        max-width: 1000px;
    "
>
    {{-- Profile picture --}}
    <div class="card panel">
        <h2>Profile picture</h2>

        <p class="sub">
            This picture can be displayed beside your name throughout
            the production system.
        </p>

        <div
            style="
                display: flex;
                align-items: center;
                gap: 1rem;
                margin-bottom: 1.2rem;
            "
        >
            <div
                style="
                    width: 96px;
                    height: 96px;
                    border-radius: 50%;
                    overflow: hidden;
                    flex-shrink: 0;
                    background: linear-gradient(
                        135deg,
                        #2563eb,
                        #38bdf8
                    );
                    display: grid;
                    place-items: center;
                    color: white;
                    font-size: 1.7rem;
                    font-weight: 700;
                    border: 3px solid white;
                    box-shadow:
                        0 0 0 1px var(--border),
                        0 8px 22px rgba(15, 23, 42, 0.15);
                "
            >
                @if (auth()->user()->profile_photo_path)
                    <img
                        id="profilePhotoPreview"
                        src="{{ asset(
                            'storage/' .
                            auth()->user()->profile_photo_path
                        ) }}"
                        alt="{{ auth()->user()->name }}"
                        style="
                            width: 100%;
                            height: 100%;
                            object-fit: cover;
                            display: block;
                        "
                    >
                @else
                    <img
                        id="profilePhotoPreview"
                        src=""
                        alt=""
                        style="
                            width: 100%;
                            height: 100%;
                            object-fit: cover;
                            display: none;
                        "
                    >

                    <span id="profileInitials">
                        {{ strtoupper(
                            substr(auth()->user()->name, 0, 1)
                        ) }}

                        @php
                            $nameParts = preg_split(
                                '/\s+/',
                                trim(auth()->user()->name)
                            );
                        @endphp

                        @if (count($nameParts) > 1)
                            {{ strtoupper(
                                substr(end($nameParts), 0, 1)
                            ) }}
                        @endif
                    </span>
                @endif
            </div>

            <div>
                <div style="font-weight: 700;">
                    {{ auth()->user()->name }}
                </div>

                <div
                    style="
                        color: var(--ink-3);
                        font-size: 0.8rem;
                        margin-top: 0.15rem;
                    "
                >
                    JPG, PNG, or WebP. Maximum 2 MB.
                </div>
            </div>
        </div>

        <form
            method="POST"
            action="{{ route('account.photo') }}"
            enctype="multipart/form-data"
        >
            @csrf

            <div class="field">
                <label for="profile_photo">
                    Choose profile picture
                </label>

                <input
                    id="profile_photo"
                    type="file"
                    name="profile_photo"
                    accept="image/jpeg,image/png,image/webp"
                    required
                >
            </div>

            <button type="submit" class="btn btn-primary">
                Save profile picture
            </button>
        </form>

        @if (auth()->user()->profile_photo_path)
            <form
                method="POST"
                action="{{ route('account.photo.delete') }}"
                style="margin-top: 0.7rem;"
                onsubmit="return confirm(
                    'Remove your profile picture?'
                );"
            >
                @csrf
                @method('DELETE')

                <button
                    type="submit"
                    class="btn btn-ghost btn-sm"
                >
                    Remove picture
                </button>
            </form>
        @endif
    </div>


    {{-- Display name --}}
    <div class="card panel">
        <h2>Your name</h2>

        <p class="sub">
            This is the name shown on tasks, orders and approvals.
        </p>

        <form
            method="POST"
            action="{{ route('account.name') }}"
        >
            @csrf

            <div class="field">
                <label for="name">Full name</label>

                <input
                    id="name"
                    type="text"
                    name="name"
                    value="{{ old(
                        'name',
                        auth()->user()->name
                    ) }}"
                    required
                    maxlength="255"
                >
            </div>

            <div class="field">
                <label>Email used to log in</label>

                <input
                    type="text"
                    value="{{ auth()->user()->email }}"
                    disabled
                    style="
                        background: var(--bg);
                        color: var(--ink-3);
                    "
                >

                <div
                    style="
                        font-size: 0.75rem;
                        color: var(--ink-3);
                        margin-top: 0.3rem;
                    "
                >
                    Ask a leader or administrator to change your email.
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                Save name
            </button>
        </form>
    </div>


    {{-- Password --}}
    <div class="card panel">
        <h2>Change password</h2>

        <p class="sub">
            Pick something strong that you do not use anywhere else.
        </p>

        <form
            id="passwordForm"
            method="POST"
            action="{{ route('password.update') }}"
        >
            @csrf

            <div class="field">
                <label for="current_password">
                    Current password
                </label>

                <input
                    id="current_password"
                    type="password"
                    name="current_password"
                    required
                    autocomplete="current-password"
                >
            </div>

            <div class="field">
                <label for="password">
                    New password, minimum 8 characters
                </label>

                <input
                    id="password"
                    type="password"
                    name="password"
                    required
                    minlength="8"
                    autocomplete="new-password"
                >
            </div>

            <div class="field">
                <label for="password_confirmation">
                    Repeat new password
                </label>

                <input
                    id="password_confirmation"
                    type="password"
                    name="password_confirmation"
                    required
                    minlength="8"
                    autocomplete="new-password"
                >
            </div>

            <button type="submit" class="btn btn-primary">
                Save new password
            </button>
        </form>
    </div>
</div>

<script>
    (function () {
        const input = document.getElementById('profile_photo');
        const preview = document.getElementById(
            'profilePhotoPreview'
        );
        const initials = document.getElementById(
            'profileInitials'
        );

        if (!input || !preview) {
            return;
        }

        input.addEventListener('change', function () {
            const file = input.files && input.files[0];

            if (!file) {
                return;
            }

            if (!file.type.startsWith('image/')) {
                input.value = '';
                alert('Please select an image file.');
                return;
            }

            if (file.size > 2 * 1024 * 1024) {
                input.value = '';
                alert('The image must not be larger than 2 MB.');
                return;
            }

            const reader = new FileReader();

            reader.onload = function (event) {
                preview.src = event.target.result;
                preview.style.display = 'block';

                if (initials) {
                    initials.style.display = 'none';
                }
            };

            reader.readAsDataURL(file);
        });
    })();
</script>

<script>
    /* Add a Show/Hide toggle to every password box on the account page. */
    (function () {
        const form = document.getElementById('passwordForm');

        if (!form) {
            return;
        }

        form.querySelectorAll('input[type="password"]').forEach(function (input) {
            const wrap = document.createElement('div');
            wrap.style.position = 'relative';
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);
            input.style.paddingRight = '3rem';

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = 'Show';
            btn.setAttribute('aria-label', 'Show password');
            btn.setAttribute('aria-pressed', 'false');
            btn.style.cssText =
                'position:absolute;right:0.35rem;top:50%;transform:translateY(-50%);' +
                'background:none;border:none;cursor:pointer;color:var(--ink-3);' +
                'font-size:0.75rem;font-weight:600;padding:0.35rem 0.5rem;border-radius:6px;';

            btn.addEventListener('click', function () {
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.textContent = show ? 'Hide' : 'Show';
                btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            });

            wrap.appendChild(btn);
        });
    })();
</script>

@endsection