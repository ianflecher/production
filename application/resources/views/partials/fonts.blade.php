{{-- The app's typefaces, loaded in one place.

     Inter carries all UI text; Space Grotesk the headings. The system stack
     behind each (see --font-body / --font-head) keeps a page readable when the
     font CDN is unreachable.

     The pages that stand outside the app shell — the login screen, the public
     client brief, the file-path helper — include this too, so a screen a client
     sees is set in the same type as the one the office sees.

     The variables themselves live in css/app.css. The two pages that don't load
     that stylesheet repeat them in their own :root, and say so. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
