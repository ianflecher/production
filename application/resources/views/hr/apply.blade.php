<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply — Imprint Customs</title>
    @include('partials.fonts')
    <style>
        :root {
            /* Mirrors css/app.css — this page renders outside the app shell. */
            --font-body: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            --font-head: 'Space Grotesk', 'Inter', system-ui, sans-serif;
            --bg: #F4F6F9; --surface: #fff; --border: #E5E9F0; --ink: #17202E;
            --ink-2: #566172; --ink-3: #94A0AE; --brand: #E31B23; --brand-hover: #B5141A;
            --accent-soft: #eff6ff; --success: #15803d; --success-soft: #f0fdf4;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: var(--font-body);
            background: var(--bg); color: var(--ink); font-size: 16px;
            -webkit-font-smoothing: antialiased; line-height: 1.5;
        }
        .wrap { max-width: 680px; margin: 0 auto; padding: 1.5rem 1.1rem 4rem; }
        .brand { display: flex; align-items: center; gap: 0.65rem; padding: 0.5rem 0 1.25rem; }
        .brand .mark {
            width: 38px; height: 38px; border-radius: 9px; background: var(--brand);
            color: #fff; font-weight: 800; display: grid; place-items: center; font-size: 0.95rem;
        }
        .brand b { font-family: var(--font-head); font-size: 1.02rem; letter-spacing: -0.01em; }
        .brand small { display: block; color: var(--ink-3); font-size: 0.68rem; letter-spacing: 0.14em; }
        .card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 14px; padding: 1.4rem 1.2rem; margin-bottom: 1.1rem;
        }
        h1 { font-family: var(--font-head); font-size: 1.35rem; letter-spacing: -0.02em; margin-bottom: 0.3rem; }
        .sub { color: var(--ink-2); font-size: 0.9rem; margin-bottom: 1.2rem; }
        label { display: block; font-size: 0.82rem; font-weight: 600; margin-bottom: 0.3rem; }
        .opt { font-weight: 400; color: var(--ink-3); }
        input[type=text], input[type=tel], input[type=email], input[type=date], select, textarea {
            width: 100%; padding: 0.6rem 0.7rem; border: 1px solid var(--border);
            border-radius: 9px; font: inherit; background: #fff; color: var(--ink);
        }
        input:focus, select:focus, textarea:focus { outline: 2px solid var(--brand); outline-offset: -1px; }
        textarea { min-height: 96px; resize: vertical; }
        .row { display: flex; gap: 0.8rem; flex-wrap: wrap; margin-bottom: 0.9rem; }
        .row > div { flex: 1 1 220px; }
        .btn {
            border: 0; border-radius: 9px; padding: 0.7rem 1.1rem; font: inherit;
            font-weight: 600; cursor: pointer; background: var(--brand); color: #fff;
        }
        .btn:hover { background: var(--brand-hover); }
        .btn-ghost { background: #fff; color: var(--ink); border: 1px solid var(--border); }
        .err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;
               border-radius: 10px; padding: 0.7rem 0.9rem; margin-bottom: 1rem; font-size: 0.88rem; }
        /* Camera */
        .shot { display: grid; gap: 0.7rem; }
        .stage {
            position: relative; width: 100%; max-width: 320px; aspect-ratio: 3 / 4;
            background: #0d1117; border-radius: 12px; overflow: hidden;
            display: grid; place-items: center;
        }
        .stage video, .stage img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .stage .hint { color: #8b949e; font-size: 0.82rem; padding: 1rem; text-align: center; }
        .cam-btns { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .file-fallback { font-size: 0.85rem; color: var(--ink-2); }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <div class="mark">IC</div>
        <div><b>IMPRINT CUSTOMS</b><small>APPLY FOR WORK</small></div>
    </div>

    @if ($errors->any())
        <div class="err">
            @foreach ($errors->all() as $e) {{ $e }}<br> @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('hr.apply.submit') }}" enctype="multipart/form-data">
        @csrf

        <div class="card">
            <h1>Apply for work</h1>
            <p class="sub">Fill this in and we will call you. Nothing here is shown to anyone outside the shop.</p>

            <div class="row">
                <div>
                    <label for="first_name">First name</label>
                    <input type="text" id="first_name" name="first_name" value="{{ old('first_name') }}" maxlength="100" required>
                </div>
                <div>
                    <label for="last_name">Last name</label>
                    <input type="text" id="last_name" name="last_name" value="{{ old('last_name') }}" maxlength="100" required>
                </div>
            </div>

            <div class="row">
                <div>
                    <label for="contact_number">Contact number</label>
                    <input type="tel" id="contact_number" name="contact_number" value="{{ old('contact_number') }}" maxlength="40" required>
                </div>
                <div>
                    <label for="email">Email <span class="opt">(optional)</span></label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" maxlength="180">
                </div>
            </div>

            <div class="row">
                <div>
                    <label for="birthdate">Birthday <span class="opt">(optional)</span></label>
                    <input type="date" id="birthdate" name="birthdate" value="{{ old('birthdate') }}">
                </div>
                <div>
                    <label for="position">Position you want <span class="opt">(optional)</span></label>
                    <select id="position" name="position">
                        <option value="">— any —</option>
                        @foreach ($positions as $group => $items)
                            <optgroup label="{{ $group }}">
                                @foreach ($items as $value => $label)
                                    <option value="{{ $label }}" @selected(old('position') === $label)>{{ $label }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
            </div>

            <div style="margin-bottom: 0.9rem;">
                <label for="address">Address <span class="opt">(optional)</span></label>
                <input type="text" id="address" name="address" value="{{ old('address') }}" maxlength="255">
            </div>

            <div>
                <label for="about">Anything about you <span class="opt">(optional)</span></label>
                <textarea id="about" name="about" maxlength="2000" placeholder="Where you have worked, what you can do">{{ old('about') }}</textarea>
            </div>
        </div>

        <div class="card">
            <h1>Your photo</h1>
            <p class="sub">So whoever interviews you knows who walked in. Take one now, or send a picture from your phone.</p>

            <div class="shot">
                <div class="stage" id="stage">
                    <video id="video" playsinline muted hidden></video>
                    <img id="preview" alt="Your photo" hidden>
                    <div class="hint" id="hint">The camera is off. Press “Open camera”.</div>
                </div>

                <div class="cam-btns">
                    <button type="button" class="btn btn-ghost" id="open">Open camera</button>
                    <button type="button" class="btn" id="snap" hidden>Take photo</button>
                    <button type="button" class="btn btn-ghost" id="again" hidden>Take it again</button>
                </div>

                {{-- The camera needs a secure page. Over plain http on the shop
                     network it will not be offered at all, and a phone can
                     refuse it anyway — so the file box is always here. --}}
                <div class="file-fallback">
                    <label for="photo">Or choose a picture</label>
                    <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp">
                </div>

                <input type="hidden" name="photo_data" id="photo_data">
            </div>
        </div>

        <button class="btn" type="submit" style="width: 100%; padding: 0.85rem;">Send application</button>

        {{-- The other door. Staff reach this page by mistake too. --}}
        <p style="margin: 1rem 0 0; text-align: center; font-size: .8rem; color: var(--ink-3);">
            Already work here?
            <a href="{{ route('login') }}">Sign in instead</a>
        </p>
    </form>
</div>

<script>
(function () {
    var video = document.getElementById('video');
    var preview = document.getElementById('preview');
    var hint = document.getElementById('hint');
    var openBtn = document.getElementById('open');
    var snapBtn = document.getElementById('snap');
    var againBtn = document.getElementById('again');
    var field = document.getElementById('photo_data');
    var fileBox = document.getElementById('photo');
    var stream = null;

    function stop() {
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    }

    function show(el) {
        [video, preview, hint].forEach(function (n) { n.hidden = n !== el; });
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        openBtn.hidden = true;
        hint.textContent = 'This browser will not give the page a camera. Choose a picture below instead.';
        return;
    }

    openBtn.addEventListener('click', function () {
        /* The front camera, portrait-ish — it is a photo of a face. */
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 720 } }, audio: false })
            .then(function (s) {
                stream = s;
                video.srcObject = s;
                video.play();
                show(video);
                openBtn.hidden = true;
                snapBtn.hidden = false;
                againBtn.hidden = true;
            })
            .catch(function () {
                /* Refused, or no camera. Not an error worth shouting about —
                   the file box below does the same job. */
                hint.textContent = 'The camera was not allowed. Choose a picture below instead.';
                show(hint);
                openBtn.hidden = false;
            });
    });

    snapBtn.addEventListener('click', function () {
        var canvas = document.createElement('canvas');
        canvas.width = video.videoWidth || 480;
        canvas.height = video.videoHeight || 640;
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

        var data = canvas.toDataURL('image/jpeg', 0.85);
        field.value = data;
        preview.src = data;

        show(preview);
        stop();
        snapBtn.hidden = true;
        againBtn.hidden = false;
        /* A picture from the camera wins, so the file box is cleared rather
           than leaving two photos on the form and no way to say which. */
        fileBox.value = '';
    });

    againBtn.addEventListener('click', function () {
        field.value = '';
        preview.removeAttribute('src');
        againBtn.hidden = true;
        show(hint);
        hint.textContent = 'The camera is off. Press “Open camera”.';
        openBtn.hidden = false;
    });

    /* Choosing a file is the other way round: it clears the camera shot. */
    fileBox.addEventListener('change', function () {
        if (fileBox.files.length) {
            field.value = '';
            preview.removeAttribute('src');
            stop();
            show(hint);
            hint.textContent = 'Using the picture you chose.';
            snapBtn.hidden = true;
            againBtn.hidden = true;
            openBtn.hidden = false;
        }
    });

    window.addEventListener('pagehide', stop);
})();
</script>
</body>
</html>
