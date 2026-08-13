<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Backfills')</title>
    <style>
        :root {
            --bg: #f6f7f9;
            --panel: #ffffff;
            --border: #e3e6ea;
            --text: #1b1f24;
            --muted: #6b7480;
            --accent: #2f6feb;
            --ok: #1a7f37;
            --warn: #9a6700;
            --bad: #cf222e;
            --track: #eceff2;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0d1117;
                --panel: #161b22;
                --border: #2a313a;
                --text: #e6edf3;
                --muted: #9198a1;
                --accent: #4c8dff;
                --ok: #3fb950;
                --warn: #d29922;
                --bad: #f85149;
                --track: #21262d;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 14px/1.5 ui-sans-serif, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        .wrap { max-width: 1100px; margin: 0 auto; padding: 32px 20px 64px; }

        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 0 0 12px; }
        .sub { color: var(--muted); margin: 0 0 24px; }

        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 18px;
        }

        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); font-weight: 600; }
        tr:last-child td { border-bottom: 0; }
        .scroll { overflow-x: auto; }

        .badge {
            display: inline-block; padding: 2px 8px; border-radius: 999px;
            font-size: 12px; font-weight: 600; border: 1px solid transparent;
        }
        .badge-running { color: var(--accent); border-color: var(--accent); }
        .badge-completed { color: var(--ok); border-color: var(--ok); }
        .badge-paused, .badge-interrupted, .badge-pending { color: var(--warn); border-color: var(--warn); }
        .badge-failed, .badge-cancelled { color: var(--bad); border-color: var(--bad); }
        .badge-none { color: var(--muted); border-color: var(--border); }

        .bar { background: var(--track); border-radius: 999px; height: 6px; width: 140px; overflow: hidden; }
        .bar span { display: block; height: 100%; background: var(--accent); }

        button {
            font: inherit; cursor: pointer; padding: 5px 11px; border-radius: 6px;
            border: 1px solid var(--border); background: var(--panel); color: var(--text);
        }
        button:hover { border-color: var(--accent); color: var(--accent); }
        button.link { border: 0; background: none; padding: 0; color: var(--accent); text-decoration: underline; }
        .actions { display: flex; gap: 6px; flex-wrap: wrap; }

        .note { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; border: 1px solid; }
        .note-ok { color: var(--ok); border-color: var(--ok); }
        .note-bad { color: var(--bad); border-color: var(--bad); }

        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; }
        .stat .label { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .stat .value { font-size: 18px; font-weight: 600; margin-top: 2px; }

        .spark { display: flex; align-items: flex-end; gap: 2px; height: 44px; margin-top: 6px; }
        .spark i { flex: 1; background: var(--accent); opacity: .75; border-radius: 1px 1px 0 0; min-height: 1px; }

        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; color: var(--muted); }
        .muted { color: var(--muted); }
        .right { text-align: right; }
    </style>
    @livewireStyles
</head>
<body>
    <div class="wrap">
        @yield('body')
    </div>
    @livewireScripts
</body>
</html>
