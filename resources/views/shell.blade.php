<!DOCTYPE html>
<html lang="en" data-theme="auto">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Backfills')</title>

    <link rel="icon"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🗄️</text></svg>">

    <style>
        :root {
            --brand: #ff2d20;
            --brand-2: #ff6a3d;
            --brand-strong: #e11900;
            --brand-soft: rgba(255, 45, 32, .09);
            --brand-ring: rgba(255, 45, 32, .18);

            --bg: #f6f6f7;
            --bg-accent: radial-gradient(1200px 400px at 50% -120px, rgba(255, 45, 32, .06), transparent 70%);
            --surface: #fff;
            --surface-2: #fafafb;
            --border: #e8e9ed;
            --border-strong: #d6d8de;
            --text: #14161a;
            --text-2: #3f444d;
            --muted: #71767f;
            --track: #eef0f3;
            --tip-bg: #1c1f26;
            --tip-fg: #f4f5f7;

            --ok: #0e7c42;
            --ok-soft: rgba(14, 124, 66, .09);
            --warn: #9a5b00;
            --warn-soft: rgba(154, 91, 0, .10);
            --bad: #c9271b;
            --bad-soft: rgba(201, 39, 27, .09);

            --r-lg: 16px;
            --r: 12px;
            --r-sm: 9px;
            --r-xs: 7px;

            --sh-1: 0 1px 2px rgba(18, 20, 24, .05);
            --sh-2: 0 1px 3px rgba(18, 20, 24, .06), 0 6px 16px -6px rgba(18, 20, 24, .10);
        }

        :root[data-theme="dark"] {
            --brand: #ff2d20;
            --brand-2: #ff6a3d;
            --brand-strong: #ff5647;
            --brand-soft: rgba(255, 45, 32, .16);
            --brand-ring: rgba(255, 45, 32, .30);

            --bg: #0a0c0f;
            --bg-accent: radial-gradient(1200px 400px at 50% -120px, rgba(255, 45, 32, .13), transparent 70%);
            --surface: #131720;
            --surface-2: #171c26;
            --border: #232936;
            --border-strong: #303848;
            --text: #eceef2;
            --text-2: #b9c0cc;
            --muted: #8b93a2;
            --track: #1f2532;
            --tip-bg: #2b3342;
            --tip-fg: #f4f5f7;

            --ok: #4ade80;
            --ok-soft: rgba(74, 222, 128, .12);
            --warn: #fbbf24;
            --warn-soft: rgba(251, 191, 36, .12);
            --bad: #ff7062;
            --bad-soft: rgba(255, 112, 98, .12);

            --sh-1: 0 1px 2px rgba(0, 0, 0, .5);
            --sh-2: 0 1px 3px rgba(0, 0, 0, .5), 0 8px 22px -8px rgba(0, 0, 0, .6);
        }

        @media (prefers-color-scheme: dark) {
            :root[data-theme="auto"] {
                --brand: #ff2d20;
                --brand-2: #ff6a3d;
                --brand-strong: #ff5647;
                --brand-soft: rgba(255, 45, 32, .16);
                --brand-ring: rgba(255, 45, 32, .30);
                --bg: #0a0c0f;
                --bg-accent: radial-gradient(1200px 400px at 50% -120px, rgba(255, 45, 32, .13), transparent 70%);
                --surface: #131720;
                --surface-2: #171c26;
                --border: #232936;
                --border-strong: #303848;
                --text: #eceef2;
                --text-2: #b9c0cc;
                --muted: #8b93a2;
                --track: #1f2532;
                --tip-bg: #2b3342;
                --tip-fg: #f4f5f7;
                --ok: #4ade80;
                --ok-soft: rgba(74, 222, 128, .12);
                --warn: #fbbf24;
                --warn-soft: rgba(251, 191, 36, .12);
                --bad: #ff7062;
                --bad-soft: rgba(255, 112, 98, .12);
                --sh-1: 0 1px 2px rgba(0, 0, 0, .5);
                --sh-2: 0 1px 3px rgba(0, 0, 0, .5), 0 8px 22px -8px rgba(0, 0, 0, .6);
            }
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg-accent), var(--bg);
            background-repeat: no-repeat;
            color: var(--text);
            font: 14px/1.55 ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            min-height: 100vh;
        }

        /* Figures line up column-wise as they tick over instead of jittering. */
        .num,
        td,
        .value,
        .kpi-value {
            font-variant-numeric: tabular-nums;
        }

        .ico {
            flex: none;
        }

        /* ---------- masthead ---------- */

        .masthead {
            position: sticky;
            top: 0;
            z-index: 30;
            border-bottom: 1px solid var(--border);
            background: color-mix(in srgb, var(--surface) 82%, transparent);
            backdrop-filter: saturate(180%) blur(14px);
            -webkit-backdrop-filter: saturate(180%) blur(14px);
        }

        .masthead-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            align-items: center;
            gap: 24px;
            height: 62px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 15px;
            letter-spacing: -.015em;
            color: var(--text);
            text-decoration: none;
        }

        .brand .mark {
            width: 30px;
            height: 30px;
            border-radius: var(--r-xs);
            background: linear-gradient(145deg, var(--brand-2), var(--brand));
            color: #fff;
            display: grid;
            place-items: center;
            box-shadow: 0 2px 8px -2px var(--brand-ring);
        }

        .tabs {
            display: flex;
            gap: 2px;
        }

        .tab {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 13px;
            border-radius: var(--r-sm);
            color: var(--muted);
            text-decoration: none;
            font-weight: 550;
            transition: color .15s, background .15s;
        }

        .tab:hover {
            color: var(--text);
            background: var(--surface-2);
        }

        .tab[aria-current="page"] {
            color: var(--brand);
            background: var(--brand-soft);
        }

        .masthead .spacer {
            flex: 1;
        }

        .theme-toggle {
            width: 34px;
            height: 34px;
            padding: 0;
            display: grid;
            place-items: center;
            border-radius: var(--r-sm);
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--muted);
            cursor: pointer;
            transition: color .15s, border-color .15s;
        }

        .theme-toggle:hover {
            color: var(--brand);
            border-color: var(--brand);
        }

        /* Exactly one glyph visible in every state. */
        .theme-toggle .i-sun {
            display: none;
        }

        .theme-toggle .i-moon {
            display: block;
        }

        :root[data-theme="dark"] .theme-toggle .i-sun {
            display: block;
        }

        :root[data-theme="dark"] .theme-toggle .i-moon {
            display: none;
        }

        @media (prefers-color-scheme: dark) {
            :root[data-theme="auto"] .theme-toggle .i-sun {
                display: block;
            }

            :root[data-theme="auto"] .theme-toggle .i-moon {
                display: none;
            }
        }

        /* ---------- layout ---------- */

        .wrap {
            max-width: 1200px;
            margin: 0 auto;
            padding: 32px 24px 80px;
        }

        .page-head {
            margin-bottom: 24px;
        }

        .page-head h1 {
            font-size: 24px;
            margin: 0 0 4px;
            letter-spacing: -.03em;
            font-weight: 700;
        }

        .page-head p {
            color: var(--muted);
            margin: 0;
            font-size: 14.5px;
        }

        /* ---------- KPI strip ---------- */

        .kpis {
            display: grid;
            gap: 12px;
            margin-bottom: 20px;
            grid-template-columns: repeat(auto-fit, minmax(175px, 1fr));
        }

        .kpi {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--r);
            padding: 15px 17px;
            box-shadow: var(--sh-1);
            position: relative;
            overflow: hidden;
        }

        .kpi::after {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 2px;
            background: linear-gradient(90deg, var(--brand), var(--brand-2));
            opacity: 0;
            transition: opacity .2s;
        }

        .kpi.is-live::after {
            opacity: 1;
        }

        .kpi-label {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--muted);
            font-size: 11.5px;
            font-weight: 620;
            text-transform: uppercase;
            letter-spacing: .055em;
        }

        .kpi-value {
            font-size: 26px;
            font-weight: 700;
            letter-spacing: -.03em;
            margin-top: 7px;
            line-height: 1.1;
        }

        .kpi-value small {
            font-size: 13px;
            font-weight: 500;
            color: var(--muted);
            letter-spacing: 0;
        }

        .kpi.bad .kpi-value {
            color: var(--bad);
        }

        .kpi.ok .kpi-value {
            color: var(--ok);
        }

        /* ---------- panel ---------- */

        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--r-lg);
            box-shadow: var(--sh-2);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .panel-head {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px 20px;
            border-bottom: 1px solid var(--border);
        }

        .panel-head h2 {
            font-size: 13px;
            margin: 0;
            font-weight: 650;
            text-transform: uppercase;
            letter-spacing: .055em;
            color: var(--muted);
        }

        .panel-head .spacer {
            flex: 1;
        }

        .panel-body {
            padding: 20px;
        }

        .step {
            width: 22px;
            height: 22px;
            flex: none;
            border-radius: 50%;
            display: grid;
            place-items: center;
            font-size: 11.5px;
            font-weight: 700;
            background: linear-gradient(145deg, var(--brand-2), var(--brand));
            color: #fff;
        }

        /* ---------- table ---------- */

        .scroll {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            text-align: left;
            padding: 14px 20px;
            vertical-align: middle;
        }

        th {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--muted);
            font-weight: 650;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        tbody tr {
            border-bottom: 1px solid var(--border);
            transition: background .12s;
        }

        tbody tr:last-child {
            border-bottom: 0;
        }

        tbody tr:hover {
            background: var(--surface-2);
        }

        .right {
            text-align: right;
        }

        .row-name {
            font-weight: 620;
            letter-spacing: -.01em;
            font-size: 14.5px;
        }

        /* ---------- bits ---------- */

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 620;
            white-space: nowrap;
        }

        .badge-running {
            color: var(--brand);
            background: var(--brand-soft);
        }

        .badge-completed {
            color: var(--ok);
            background: var(--ok-soft);
        }

        .badge-paused,
        .badge-interrupted,
        .badge-pending {
            color: var(--warn);
            background: var(--warn-soft);
        }

        .badge-failed,
        .badge-cancelled {
            color: var(--bad);
            background: var(--bad-soft);
        }

        .badge-none {
            color: var(--muted);
            background: var(--track);
        }

        .pulse {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: currentColor;
            animation: pulse 1.8s infinite;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 var(--brand-ring);
            }

            70% {
                box-shadow: 0 0 0 6px transparent;
            }

            100% {
                box-shadow: 0 0 0 0 transparent;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .pulse {
                animation: none;
            }
        }

        .bar {
            background: var(--track);
            border-radius: 999px;
            height: 6px;
            width: 160px;
            overflow: hidden;
        }

        .bar span {
            display: block;
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, var(--brand), var(--brand-2));
            transition: width .5s cubic-bezier(.4, 0, .2, 1);
        }

        .bar.is-done span {
            background: var(--ok);
        }

        button {
            font: inherit;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 11px;
            font-size: 13.5px;
            line-height: 1.45;
            border-radius: var(--r-sm);
            border: 1px solid var(--border-strong);
            background: var(--surface);
            color: var(--text-2);
            font-weight: 560;
            transition: border-color .15s, color .15s, background .15s, box-shadow .15s, transform .06s;
        }

        button:hover {
            border-color: var(--brand);
            color: var(--brand);
        }

        button:active {
            transform: translateY(.5px);
        }

        button:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px var(--brand-ring);
        }

        button.primary {
            background: linear-gradient(145deg, var(--brand-2), var(--brand));
            border-color: transparent;
            color: #fff;
            font-weight: 650;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .1), 0 4px 12px -4px var(--brand-ring);
        }

        button.primary:hover {
            filter: brightness(1.06);
            color: #fff;
            border-color: transparent;
        }

        button.danger {
            color: var(--bad);
            border-color: color-mix(in srgb, var(--bad) 40%, transparent);
        }

        button.danger:hover {
            background: var(--bad);
            color: #fff;
            border-color: var(--bad);
        }

        button.link {
            border: 0;
            background: none;
            padding: 0;
            color: var(--brand);
            font-weight: 620;
        }

        button.link:hover {
            text-decoration: underline;
        }

        button.link.subtle {
            color: var(--text);
        }

        button.link.subtle:hover {
            color: var(--brand);
        }

        button.icon-only {
            padding: 7px;
        }

        button:disabled {
            opacity: .55;
            cursor: not-allowed;
        }

        .actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .note {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 14px 16px;
            border-radius: var(--r);
            margin-bottom: 18px;
            border: 1px solid transparent;
        }

        .note .ico {
            margin-top: 1px;
        }

        .note-ok {
            color: var(--ok);
            background: var(--ok-soft);
            border-color: color-mix(in srgb, var(--ok) 22%, transparent);
        }

        .note-bad {
            color: var(--bad);
            background: var(--bad-soft);
            border-color: color-mix(in srgb, var(--bad) 22%, transparent);
        }

        .note-warn {
            color: var(--warn);
            background: var(--warn-soft);
            border-color: color-mix(in srgb, var(--warn) 22%, transparent);
        }

        .note strong {
            display: block;
            margin-bottom: 3px;
        }

        /* Dividers come from each cell's own borders rather than a 1px grid gap
           over a coloured background — with an odd number of cells that gap
           left a grey void where the last row did not fill. */
        .stats {
            display: grid;
            background: var(--surface);
            grid-template-columns: repeat(auto-fit, minmax(146px, 1fr));
        }

        .stat {
            padding: 16px 20px;
            border-right: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .stat .label {
            color: var(--muted);
            font-size: 11px;
            font-weight: 650;
            text-transform: uppercase;
            letter-spacing: .055em;
        }

        .stat .value {
            font-size: 19px;
            font-weight: 680;
            margin-top: 6px;
            letter-spacing: -.025em;
            word-break: break-all;
        }

        .stat .value small {
            font-size: 12px;
            color: var(--muted);
            font-weight: 500;
        }

        .spark {
            display: flex;
            align-items: flex-end;
            gap: 3px;
            /* Height covers the bars plus headroom for a tooltip above the
               tallest one — border-box would otherwise take the padding out
               of the bars themselves and squash them. */
            height: 94px;
            padding-top: 36px;
        }

        .spark i {
            position: relative;
            flex: 1;
            min-height: 3px;
            border-radius: 3px 3px 0 0;
            background: linear-gradient(180deg, var(--brand-2), var(--brand));
            opacity: .78;
            transition: opacity .12s, filter .12s;
        }

        .spark i:hover {
            opacity: 1;
            filter: brightness(1.12);
        }

        /* A bar that failed rows or needed retrying is worth spotting without
           hovering every one of them. */
        .spark i.has-failed {
            background: linear-gradient(180deg, var(--bad), var(--bad));
        }

        .spark i.was-retried {
            background: linear-gradient(180deg, var(--warn), var(--warn));
        }

        /* CSS-only tooltip: native title= waits about a second and cannot be
           styled, which is no use for scrubbing along forty bars. */
        .spark i::after {
            content: attr(data-tip);
            position: absolute;
            bottom: calc(100% + 9px);
            left: 50%;
            transform: translateX(-50%) translateY(3px);
            z-index: 5;
            padding: 6px 10px;
            border-radius: var(--r-xs);
            background: var(--tip-bg);
            color: var(--tip-fg);
            font-size: 12px;
            font-weight: 550;
            line-height: 1.4;
            white-space: nowrap;
            box-shadow: 0 4px 14px -4px rgba(0, 0, 0, .35);
            opacity: 0;
            pointer-events: none;
            transition: opacity .12s, transform .12s;
        }

        .spark i::before {
            content: '';
            position: absolute;
            bottom: calc(100% + 4px);
            left: 50%;
            z-index: 5;
            transform: translateX(-50%) translateY(3px);
            border: 5px solid transparent;
            border-top-color: var(--tip-bg);
            opacity: 0;
            pointer-events: none;
            transition: opacity .12s, transform .12s;
        }

        .spark i:hover::after,
        .spark i:hover::before {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        /* Keep the first and last tooltips inside the panel. */
        .spark i:first-child::after { left: 0; transform: translateX(0) translateY(3px); }
        .spark i:first-child:hover::after { transform: translateX(0) translateY(0); }
        .spark i:last-child::after { left: auto; right: 0; transform: translateX(0) translateY(3px); }
        .spark i:last-child:hover::after { transform: translateX(0) translateY(0); }

        .spark-legend {
            display: flex;
            gap: 14px;
            margin-top: 10px;
            font-size: 12px;
            color: var(--muted);
        }

        .spark-legend b {
            color: var(--text-2);
            font-weight: 620;
        }

        .spark i:hover {
            opacity: 1;
        }

        code,
        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12.5px;
        }

        code {
            color: var(--muted);
        }

        .muted {
            color: var(--muted);
        }

        .empty {
            text-align: center;
            padding: 52px 20px;
        }

        .empty .ring {
            width: 56px;
            height: 56px;
            margin: 0 auto 14px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: var(--brand-soft);
            color: var(--brand);
        }

        .empty p {
            margin: 0 0 5px;
            color: var(--text-2);
        }

        .empty p.muted {
            color: var(--muted);
            font-size: 13.5px;
        }

        label.field {
            display: block;
            margin-bottom: 20px;
        }

        label.field>.lab {
            display: block;
            font-weight: 620;
            margin-bottom: 5px;
        }

        label.field .hint {
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 8px;
        }

        input[type=text],
        input[type=number],
        textarea,
        select {
            width: 100%;
            max-width: 480px;
            font: inherit;
            padding: 10px 12px;
            border-radius: var(--r-sm);
            border: 1px solid var(--border-strong);
            background: var(--surface);
            color: var(--text);
            transition: border-color .15s, box-shadow .15s;
        }

        textarea {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 13px;
            line-height: 1.6;
        }

        input:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-ring);
        }

        .req {
            color: var(--brand);
        }

        @media (max-width: 700px) {
            .masthead-inner {
                gap: 12px;
                padding: 0 14px;
            }

            .brand span.txt {
                display: none;
            }

            .wrap {
                padding: 22px 14px 60px;
            }

            th,
            td {
                padding: 12px 14px;
            }

            .page-head h1 {
                font-size: 21px;
            }
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

            <button type="button" class="theme-toggle" onclick="toggleTheme()" title="Toggle theme"
                aria-label="Toggle theme">
                <x-backfill::icon name="sun" :size="16" class="ico i-sun" />
                <x-backfill::icon name="moon" :size="16" class="ico i-moon" />
            </button>
        </div>
    </header>

    <div class="wrap">
        @yield('body')
    </div>

    <script>
        function applyTheme(t) { document.documentElement.setAttribute('data-theme', t); }

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