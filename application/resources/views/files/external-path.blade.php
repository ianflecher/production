<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $file->label ?? 'File' }} — on the shared drive</title>
    <style>
        body { font-family: 'Inter', 'Segoe UI', system-ui, sans-serif; background: #F4F6F9; color: #17202E; margin: 0; display: grid; place-items: center; min-height: 100vh; padding: 1.5rem; }
        .card { background: #fff; border: 1px solid #dce3ea; border-radius: 12px; padding: 2rem; max-width: 620px; width: 100%; box-shadow: 0 2px 12px rgba(19,30,51,.06); text-align: center; }
        .icon { font-size: 3rem; line-height: 1; }
        h1 { font-size: 1.25rem; margin: 0.6rem 0 0.2rem; }
        p { color: #58656F; margin: 0 0 1.2rem; }
        .path { font-family: ui-monospace, Consolas, monospace; font-size: 0.9rem; background: #F4F6F9; border: 1px solid #dce3ea; border-radius: 8px; padding: 0.8rem 1rem; word-break: break-all; text-align: left; }
        .actions { margin-top: 1.1rem; display: flex; gap: 0.6rem; justify-content: center; flex-wrap: wrap; }
        button, a.btn { font: inherit; font-weight: 600; padding: 0.6rem 1.1rem; border-radius: 8px; cursor: pointer; border: 1px solid transparent; text-decoration: none; }
        .primary { background: #E31B23; color: #fff; border: none; }
        .ghost { background: #fff; border: 1px solid #C7D0D6; color: #17202E; }
        .hint { font-size: 0.8rem; color: #89949C; margin-top: 1rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">📁</div>
        <h1>{{ $file->label ?? 'File' }} is on the shared drive</h1>
        <div class="path" id="p">{{ $file->external_path }}</div>
        <div class="actions">
            <button type="button" class="primary" onclick="navigator.clipboard.writeText(document.getElementById('p').textContent.trim()).then(()=>{this.textContent='✓ Copied';})">📋 Copy path</button>
            <a class="btn ghost" href="#" onclick="window.close(); return false;">Close</a>
        </div>
        <div class="hint">Paste it into File Explorer's address bar (Ctrl+L) to open the file.</div>
    </div>
</body>
</html>
