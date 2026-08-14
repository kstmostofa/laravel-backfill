<!DOCTYPE html>
<html lang="en" data-theme="auto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Backfills')</title>

    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🗄️</text></svg>">

    {{--
        Everything is inline on purpose. This has to render in an app with no
        build step, no Tailwind and no outbound network — the same reason the
        icons are inline SVG rather than a font.
    --}}
    <style>
        :root {
            --brand: #ff2d20;
            --brand-strong: #e11900;
            --brand-soft: rgba(255, 45, 32, .10);

            --bg: #f7f7f8;
            --surface: #ffffff;
            --surface-2: #fbfbfc;
            --border: #e6e7eb;
            --border-strong: #d3d5db;
            --text: #16181d;
            --muted: #6b7280;
            --track: #eceef1;

            --ok: #0f7b3f;
            --ok-soft: rgba(15, 123, 63, .10);
            --warn: #a35b00;
            --warn-soft: rgba(163, 91, 0, .10);
            --bad: #c8271b;
            --bad-soft: rgba(200, 39, 27, .10);

            --radius: 12px;
            --radius-sm: 8px;
            --shadow: 0 1px 2px rgba(16, 18, 22, .05), 0 1px 3px rgba(16, 18, 22, .04);
            --shadow-lg: 0 4px 16px rgba(16, 18, 22, .08);
        }

        :root[data-theme="dark"],
        :root[data-theme="auto"] {
            color-scheme: light;
        }

        @media (prefers-color-scheme: dark) {
            :root[data-theme="auto"] { color-scheme: dark; }
        }

        :root[data-theme="dark"],
        :root[data-theme="auto"] { }

        /* Dark palette, applied either by explicit choice or by system default. */
        :root[data-theme="dark"] {
            --brand: #ff5647;
            --brand-strong: #ff2d20;
            --brand-soft: rgba(255, 86, 71, .14);

            --bg: #0c0e12;
            --surface: #14171d;
            --surface-2: #191d24;
            --border: #262b34;
            --border-strong: #333944;
            --text: #e8eaed;
            --muted: #98a0ad;
            --track: #232830;

            --ok: #4ade80;
            --ok-soft: rgba(74, 222, 128, .12);
            --warn: #fbbf24;
            --warn-soft: rgba(251, 191, 36, .12);
            --bad: #ff6b5e;
            --bad-soft: rgba(255, 107, 94, .12);

            --shadow: 0 1px 2px rgba(0, 0, 0, .4);
            --shadow-lg: 0 4px 20px rgba(0, 0, 0, .5);
        }

        @media (prefers-color-scheme: dark) {
            :root[data-theme="auto"] {
                --brand: #ff5647;
                --brand-strong: #ff2d20;
                --brand-soft: rgba(255, 86, 71, .14);

                --bg: #0c0e12;
                --surface: #14171d;
                --surface-2: #191d24;
                --border: #262b34;
                --border-strong: #333944;
                --text: #e8eaed;
                --muted: #98a0ad;
                --track: #232830;

                --ok: #4ade80;
                --ok-soft: rgba(74, 222, 128, .12);
                --warn: #fbbf24;
                --warn-soft: rgba(251, 191, 36, .12);
                --bad: #ff6b5e;
                --bad-soft: rgba(255, 107, 94, .12);

                --shadow: 0 1px 2px rgba(0, 0, 0, .4);
                --shadow-lg: 0 4px 20px rgba(0, 0, 0, .5);
            }
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 14px/1.55 ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .ico { flex: none; vertical-align: -.18em; }

        /* ---------- masthead ---------- */

        .masthead {
            border-bottom: 1px solid var(--border);
            background: var(--surface);
            position: sticky; top: 0; z-index: 20;
        }
        .masthead-inner {
            max-width: 1180px; margin: 0 auto; padding: 0 22px;
            display: flex; align-items: center; gap: 26px; height: 60px;
        }
        .brand {
            display: flex; align-items: center; gap: 9px;
            font-weight: 700; font-size: 15px; letter-spacing: -.01em;
            color: var(--text); text-decoration: none;
        }
        .brand .mark {
            width: 28px; height: 28px; border-radius: 7px;
            background: var(--brand); color: #fff;
            display: grid; place-items: center;
        }
        .tabs { display: flex; gap: 2px; margin-left: 4px; }
        .tab {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 7px 12px; border-radius: var(--radius-sm);
            color: var(--muted); text-decoration: none; font-weight: 550;
            border: 1px solid transparent;
        }
        .tab:hover { color: var(--text); background: var(--surface-2); }
        .tab[aria-current="page"] {
            color: var(--brand); background: var(--brand-soft);
        }
        .masthead .spacer { flex: 1; }

        .theme-toggle {
            width: 34px; height: 34px; padding: 0;
            display: grid; place-items: center;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border); background: var(--surface);
            color: var(--muted); cursor: pointer;
        }
        .theme-toggle:hover { color: var(--brand); border-color: var(--brand); }

        /* Show the moon while light (click for dark), the sun while dark.
           Exactly one is visible in every state — an earlier version hid both
           in "auto" and left an empty box in the corner. */
        .theme-toggle .i-sun { display: none; }
        .theme-toggle .i-moon { display: block; }

        :root[data-theme="dark"] .theme-toggle .i-sun { display: block; }
        :root[data-theme="dark"] .theme-toggle .i-moon { display: none; }

        @media (prefers-color-scheme: dark) {
            :root[data-theme="auto"] .theme-toggle .i-sun { display: block; }
            :root[data-theme="auto"] .theme-toggle .i-moon { display: none; }
        }

        /* ---------- layout ---------- */

        .wrap { max-width: 1180px; margin: 0 auto; padding: 28px 22px 72px; }

        .page-head { margin-bottom: 22px; }
        .page-head h1 {
            font-size: 21px; margin: 0 0 3px; letter-spacing: -.02em; font-weight: 700;
        }
        .page-head p { color: var(--muted); margin: 0; }

        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 18px;
            overflow: hidden;
        }
        .panel-head {
            display: flex; align-items: center; gap: 10px;
            padding: 14px 18px; border-bottom: 1px solid var(--border);
            background: var(--surface-2);
        }
        .panel-head h2 {
            font-size: 13px; margin: 0; font-weight: 650;
            text-transform: uppercase; letter-spacing: .05em; color: var(--muted);
        }
        .panel-head .spacer { flex: 1; }
        .panel-body { padding: 18px; }

        /* ---------- table ---------- */

        .scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 13px 18px; vertical-align: middle; }
        th {
            font-size: 11px; text-transform: uppercase; letter-spacing: .05em;
            color: var(--muted); font-weight: 650;
            border-bottom: 1px solid var(--border); background: var(--surface-2);
            white-space: nowrap;
        }
        tbody tr { border-bottom: 1px solid var(--border); }
        tbody tr:last-child { border-bottom: 0; }
        tbody tr:hover { background: var(--surface-2); }
        .right { text-align: right; }

        .name-cell { display: flex; align-items: center; gap: 11px; }
        .name-cell .dot {
            width: 32px; height: 32px; border-radius: 9px; flex: none;
            display: grid; place-items: center;
            background: var(--brand-soft); color: var(--brand);
        }
        .name-cell strong { display: block; font-weight: 620; letter-spacing: -.01em; }

        /* ---------- bits ---------- */

        .badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 9px 3px 7px; border-radius: 999px;
            font-size: 12px; font-weight: 620; white-space: nowrap;
            border: 1px solid transparent;
        }
        .badge-running    { color: var(--brand); background: var(--brand-soft); border-color: var(--brand-soft); }
        .badge-completed  { color: var(--ok);    background: var(--ok-soft);    border-color: var(--ok-soft); }
        .badge-paused,
        .badge-interrupted,
        .badge-pending    { color: var(--warn);  background: var(--warn-soft);  border-color: var(--warn-soft); }
        .badge-failed,
        .badge-cancelled  { color: var(--bad);   background: var(--bad-soft);   border-color: var(--bad-soft); }
        .badge-none       { color: var(--muted); background: var(--track);      border-color: transparent; }

        .bar {
            background: var(--track); border-radius: 999px;
            height: 7px; width: 150px; overflow: hidden;
        }
        .bar span {
            display: block; height: 100%; border-radius: 999px;
            background: var(--brand); transition: width .4s ease;
        }
        .bar.is-done span { background: var(--ok); }

        button {
            font: inherit; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 12px; border-radius: var(--radius-sm);
            border: 1px solid var(--border-strong); background: var(--surface);
            color: var(--text); font-weight: 550;
            transition: border-color .12s, color .12s, background .12s;
        }
        button:hover { border-color: var(--brand); color: var(--brand); }
        button:active { transform: translateY(.5px); }
        button:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }

        button.primary {
            background: var(--brand); border-color: var(--brand);
            color: #fff; font-weight: 650;
        }
        button.primary:hover { background: var(--brand-strong); border-color: var(--brand-strong); color: #fff; }

        button.danger { color: var(--bad); border-color: var(--bad); }
        button.danger:hover { background: var(--bad); color: #fff; }

        button.link {
            border: 0; background: none; padding: 0;
            color: var(--brand); font-weight: 620;
        }
        button.link:hover { text-decoration: underline; }

        /* Row names are ordinary text until hovered. Painting every name in
           brand red made a table of healthy backfills look like a list of
           problems, and left nothing for the red to actually mean. */
        button.link.subtle { color: var(--text); }
        button.link.subtle:hover { color: var(--brand); }

        button.icon-only { padding: 7px; }

        .actions { display: flex; gap: 6px; flex-wrap: wrap; }

        .note {
            display: flex; gap: 11px; align-items: flex-start;
            padding: 13px 16px; border-radius: var(--radius-sm);
            margin-bottom: 16px; border: 1px solid;
        }
        .note .ico { margin-top: 1px; }
        .note-ok  { color: var(--ok);   background: var(--ok-soft);   border-color: var(--ok-soft); }
        .note-bad { color: var(--bad);  background: var(--bad-soft);  border-color: var(--bad-soft); }
        .note-warn{ color: var(--warn); background: var(--warn-soft); border-color: var(--warn-soft); }
        .note strong { display: block; margin-bottom: 3px; }

        .stats {
            display: grid; gap: 1px; background: var(--border);
            grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
            border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);
        }
        .stat { background: var(--surface); padding: 15px 18px; }
        .stat .label {
            color: var(--muted); font-size: 11px; font-weight: 620;
            text-transform: uppercase; letter-spacing: .05em;
            display: flex; align-items: center; gap: 6px;
        }
        .stat .value {
            font-size: 20px; font-weight: 680; margin-top: 5px;
            letter-spacing: -.02em; word-break: break-all;
        }
        .stat .value small { font-size: 12px; color: var(--muted); font-weight: 500; }

        .spark { display: flex; align-items: flex-end; gap: 2px; height: 52px; }
        .spark i {
            flex: 1; min-height: 2px; border-radius: 2px 2px 0 0;
            background: var(--brand); opacity: .8;
        }
        .spark i:hover { opacity: 1; }

        code, .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12.5px;
        }
        code { color: var(--muted); }
        .muted { color: var(--muted); }

        .empty { text-align: center; padding: 44px 20px; color: var(--muted); }
        .empty .ico { color: var(--border-strong); margin-bottom: 10px; }
        .empty p { margin: 0 0 4px; }

        label.field { display: block; margin-bottom: 18px; }
        label.field > .lab {
            display: block; font-weight: 620; margin-bottom: 5px;
        }
        label.field .hint { color: var(--muted); font-size: 13px; margin-bottom: 7px; }
        input[type=text], input[type=number], textarea, select {
            width: 100%; max-width: 460px; font: inherit;
            padding: 9px 11px; border-radius: var(--radius-sm);
            border: 1px solid var(--border-strong);
            background: var(--surface); color: var(--text);
        }
        textarea { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; }
        input:focus, textarea:focus, select:focus {
            outline: none; border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-soft);
        }
        .req { color: var(--brand); }

        @media (max-width: 640px) {
            .masthead-inner { gap: 12px; padding: 0 14px; }
            .brand span.txt { display: none; }
            .wrap { padding: 20px 14px 56px; }
            th, td { padding: 11px 12px; }
        }
    </style>

    @livewireStyles
</head>
<body>
    <header class="masthead">
        <div class="masthead-inner">
            <a class="brand" href="{{ url(config('backfill.dashboard.path', 'backfills')) }}">
                <span class="mark"><x-backfill::icon name="database" :size="16" /></span>
                <span class="txt">Backfills</span>
            </a>

            <nav class="tabs">
                @php($base = trim(config('backfill.dashboard.path', 'backfills'), '/'))
                @php($ops = trim(config('backfill.dashboard.operator_path', 'backfills/tasks'), '/'))
                @php($here = trim(request()->path(), '/'))

                <a class="tab" href="{{ url($base) }}" @if($here === $base) aria-current="page" @endif>
                    <x-backfill::icon name="list" :size="15" /> Dashboard
                </a>
                <a class="tab" href="{{ url($ops) }}" @if($here === $ops) aria-current="page" @endif>
                    <x-backfill::icon name="bolt" :size="15" /> Tasks
                </a>
            </nav>

            <span class="spacer"></span>

            <button type="button" class="theme-toggle" onclick="toggleTheme()" title="Toggle theme" aria-label="Toggle theme">
                <x-backfill::icon name="sun" :size="16" class="ico i-sun" />
                <x-backfill::icon name="moon" :size="16" class="ico i-moon" />
            </button>
        </div>
    </header>

    <div class="wrap">
        @yield('body')
    </div>

    <script>
        // Applied before paint via the inline call below, so there is no flash
        // of the wrong theme on load.
        function applyTheme(t) {
            document.documentElement.setAttribute('data-theme', t);
        }

        function toggleTheme() {
            const current = localStorage.getItem('backfill-theme') || 'auto';
            const next = current === 'dark' ? 'light' : 'dark';

            localStorage.setItem('backfill-theme', next);
            applyTheme(next);
        }

        applyTheme(localStorage.getItem('backfill-theme') || 'auto');
    </script>

    @livewireScripts
</body>
</html>
