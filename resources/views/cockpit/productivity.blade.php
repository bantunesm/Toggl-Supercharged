<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cockpit | Productivité</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <style>
        :root {
            --bg-1: #f6f8fb;
            --bg-2: #eaf1f1;
            --ink: #101828;
        }

        body {
            font-family: 'Space Grotesk', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1200px 600px at 90% -10%, #c7f0e2 0%, transparent 55%),
                radial-gradient(1000px 500px at -10% 110%, #dbeafe 0%, transparent 52%),
                linear-gradient(180deg, var(--bg-1), var(--bg-2));
        }

        .mono {
            font-family: 'JetBrains Mono', monospace;
        }

        .welcome-modal {
            opacity: 1;
            transition: opacity 1650ms cubic-bezier(0.16, 1, 0.3, 1);
        }

        .welcome-backdrop {
            background:
                radial-gradient(1200px 650px at 80% 8%, rgba(45, 212, 191, 0.34), transparent 58%),
                radial-gradient(900px 560px at 12% 88%, rgba(56, 189, 248, 0.24), transparent 56%),
                linear-gradient(160deg, rgba(2, 6, 23, 0.94), rgba(15, 23, 42, 0.9));
            transition: transform 1500ms cubic-bezier(0.16, 1, 0.3, 1), filter 1500ms ease, opacity 1500ms ease;
        }

        .welcome-shell {
            transition: transform 1350ms cubic-bezier(0.16, 1, 0.3, 1), opacity 1350ms ease, filter 1350ms ease;
            transform-origin: center center;
        }

        .welcome-modal.modal-exit {
            opacity: 0;
        }

        .welcome-modal.modal-exit .welcome-backdrop {
            opacity: 0;
            filter: blur(3px) saturate(1.28);
            transform: scale(1.05);
        }

        .welcome-modal.modal-exit .welcome-shell {
            opacity: 0;
            filter: blur(8px) saturate(1.24);
            transform: perspective(1200px) translateY(-28px) scale(1.03) rotateX(8deg);
        }

        .welcome-ripple-layer {
            position: absolute;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
            mix-blend-mode: screen;
        }

        .welcome-ripple {
            position: absolute;
            border-radius: 9999px;
            opacity: 0;
            border: 2px solid rgba(103, 232, 249, 0.84);
            box-shadow:
                0 0 26px rgba(45, 212, 191, 0.36),
                inset 0 0 12px rgba(186, 230, 253, 0.2);
            transform: translate(-50%, -50%) scale(0.2);
            animation: cockpit-ripple 1650ms cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes cockpit-ripple {
            0% {
                opacity: 0.94;
                transform: translate(-50%, -50%) scale(0.2);
                filter: blur(0);
            }

            55% {
                opacity: 0.72;
            }

            100% {
                opacity: 0;
                transform: translate(-50%, -50%) scale(var(--rscale));
                filter: blur(1.8px);
            }
        }

        .dashboard-spotlight {
            background:
                radial-gradient(900px 420px at 12% 8%, rgba(45, 212, 191, 0.17), transparent 60%),
                radial-gradient(700px 360px at 92% 92%, rgba(14, 165, 233, 0.16), transparent 62%),
                linear-gradient(130deg, rgba(255, 255, 255, 0.95), rgba(241, 245, 249, 0.92));
        }

        .spotlight-avatar {
            position: relative;
        }

        .spotlight-avatar::before {
            content: '';
            position: absolute;
            inset: -8px;
            border-radius: 9999px;
            background: conic-gradient(from 0deg, rgba(13, 148, 136, 0.72), rgba(14, 165, 233, 0.18), rgba(13, 148, 136, 0.72));
            animation: cockpit-orbit 3.8s linear infinite;
            z-index: 0;
        }

        @keyframes cockpit-orbit {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        .highlight-card {
            position: relative;
            overflow: hidden;
            border-color: rgba(148, 163, 184, 0.34);
            background: linear-gradient(160deg, rgba(255, 255, 255, 0.96), rgba(248, 250, 252, 0.92));
        }

        .highlight-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 2.25rem;
            width: 2.25rem;
            border-radius: 0.75rem;
            border: 1px solid rgba(148, 163, 184, 0.28);
            background: rgba(255, 255, 255, 0.86);
            color: #0f766e;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.08);
        }

        .highlight-watermark {
            position: absolute;
            right: 0.9rem;
            top: 50%;
            height: 4.4rem;
            width: 4.4rem;
            color: #0f766e;
            opacity: 0.17;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .highlight-watermark svg {
            height: 100%;
            width: 100%;
        }

        @media (max-width: 640px) {
            .highlight-watermark {
                height: 3.8rem;
                width: 3.8rem;
                right: 0.7rem;
            }
        }
    </style>
</head>
<body class="min-h-screen">
    @php
        $yesterdayDeltaClass = $yesterdayDeltaDirection === 'hausse'
            ? 'text-emerald-700'
            : ($yesterdayDeltaDirection === 'baisse' ? 'text-rose-700' : 'text-slate-700');
        $weeklyDeltaClass = $weeklyDeltaDirection === 'hausse'
            ? 'text-emerald-700'
            : ($weeklyDeltaDirection === 'baisse' ? 'text-rose-700' : 'text-slate-700');
    @endphp
    <div id="welcomeModal" class="welcome-modal fixed inset-0 z-[100]">
        <div class="welcome-backdrop absolute inset-0"></div>
        <div id="welcomeModalContent" class="welcome-shell relative h-full w-full overflow-hidden p-4 sm:p-8 lg:p-10">
            <div class="mx-auto flex h-full w-full max-w-7xl flex-col rounded-3xl border border-white/15 bg-slate-900/32 p-5 shadow-2xl backdrop-blur-xl sm:p-8">
                <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_220px] lg:items-center">
                    <header class="max-w-3xl">
                        <p class="text-xs uppercase tracking-[0.2em] text-cyan-200/90">Cockpit</p>
                        <h2 class="mt-2 text-3xl font-bold text-white sm:text-5xl">{{ $welcomeTitle }}</h2>
                        <p class="mt-4 text-base leading-relaxed text-slate-100 sm:text-lg">{{ $motivationMessage }}</p>
                        <p class="mono mt-4 text-xs text-cyan-100/80">Stats de la veille · {{ $yesterdayLabel }}</p>
                    </header>

                    <figure class="mx-auto w-40 sm:w-48 lg:mx-0 lg:justify-self-end">
                        <img
                            src="{{ asset('images/1758276756115.jpeg') }}"
                            alt="Photo de monsieur Antunes"
                            class="h-auto w-full rounded-3xl border border-cyan-100/40 object-cover shadow-xl shadow-cyan-900/40"
                        >
                    </figure>
                </div>

                <div class="mt-6 grid flex-1 gap-4 lg:grid-cols-3">
                    <article class="rounded-2xl border border-white/15 bg-white/10 p-5">
                        <p class="text-xs uppercase tracking-wide text-cyan-100/90">Veille</p>
                        <p class="mono mt-2 text-4xl font-semibold text-white">{{ $yesterdayHours }} h</p>
                        <p class="mt-2 text-sm text-slate-200">{{ $yesterdayGoalState }} · objectif {{ $yesterdayGoalHours }} h</p>
                        <div class="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-cyan-900/40">
                            <div class="h-full rounded-full bg-cyan-300 transition-all duration-700" style="width: {{ $yesterdayProgressBarPercent }}%;"></div>
                        </div>
                        <p class="mono mt-2 text-xs text-cyan-100/80">{{ $yesterdayProgressPercent }}% de l'objectif</p>
                    </article>

                    <article class="rounded-2xl border border-white/15 bg-white/10 p-5">
                        <p class="text-xs uppercase tracking-wide text-cyan-100/90">Progression quotidienne</p>
                        <div class="mt-3 rounded-xl border border-white/10 bg-slate-900/35 p-3">
                            <p class="text-xs text-slate-300">Veille vs avant-hier</p>
                            <p class="mono mt-1 text-2xl font-semibold {{ $yesterdayDeltaClass === 'text-emerald-700' ? 'text-emerald-300' : ($yesterdayDeltaClass === 'text-rose-700' ? 'text-rose-300' : 'text-slate-100') }}">{{ $yesterdayDeltaHours }} h</p>
                            <p class="mono mt-1 text-xs text-slate-300">
                                @if ($yesterdayDeltaPercent !== null)
                                    {{ $yesterdayDeltaPercent }}%
                                @else
                                    n/a
                                @endif
                            </p>
                        </div>
                    </article>

                    <article class="rounded-2xl border border-white/15 bg-white/10 p-5">
                        <p class="text-xs uppercase tracking-wide text-cyan-100/90">Progression 7 jours</p>
                        <p class="mono mt-2 text-2xl font-semibold text-white">{{ $weeklyAverageHours }} h/j</p>
                        <p class="mono mt-1 text-xs {{ $weeklyDeltaClass === 'text-emerald-700' ? 'text-emerald-300' : ($weeklyDeltaClass === 'text-rose-700' ? 'text-rose-300' : 'text-slate-100') }}">
                            {{ $weeklyDeltaAverageHours }} h/j
                            @if ($weeklyDeltaPercent !== null)
                                · {{ $weeklyDeltaPercent }}%
                            @endif
                        </p>
                        <div class="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-cyan-900/40">
                            <div class="h-full rounded-full bg-teal-300 transition-all duration-700" style="width: {{ $weeklyProgressBarPercent }}%;"></div>
                        </div>
                        <p class="mono mt-2 text-xs text-cyan-100/80">Atteinte objectif: {{ $weeklyProgressPercent }}%</p>
                    </article>
                </div>

                <div class="mt-6 flex flex-col items-center justify-between gap-3 border-t border-white/15 pt-5 sm:flex-row">
                    <p class="text-xs text-slate-200/90">Prêt à lancer la journée ?</p>
                    <button
                        id="enterCockpitButton"
                        type="button"
                        class="rounded-xl border border-cyan-200/50 bg-cyan-300/95 px-6 py-3 text-sm font-semibold text-slate-900 shadow-lg shadow-cyan-500/30 transition hover:scale-[1.01] hover:bg-cyan-200"
                    >
                        Prendre les commandes
                    </button>
                </div>
            </div>

            <div id="welcomeRippleLayer" class="welcome-ripple-layer"></div>
        </div>
    </div>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <section class="rounded-2xl border border-slate-200/80 bg-white/85 p-6 shadow-sm backdrop-blur">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <header>
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Cockpit</p>
                    <h1 class="mt-1 text-3xl font-bold text-slate-900">Productivité Toggl</h1>
                    <p class="mt-2 text-sm text-slate-600">
                        Analyse par période fixe (mois/année): <span class="font-semibold text-slate-800">{{ $periodLabel }}</span>
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <a
                            href="{{ $switchToCurrentMonthUrl }}"
                            class="rounded-lg border px-3 py-1.5 text-xs font-medium transition {{ $isCurrentMonthSelected ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-teal-600 hover:text-teal-700' }}"
                        >
                            Mois en cours
                        </a>
                        <a
                            href="{{ $switchToYearlyUrl }}"
                            class="rounded-lg border px-3 py-1.5 text-xs font-medium transition {{ $isYearlyView ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-teal-600 hover:text-teal-700' }}"
                        >
                            Vue annuelle
                        </a>

                        @if ($previousPeriodUrl !== null)
                            <a href="{{ $previousPeriodUrl }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-slate-400">
                                ← {{ $previousPeriodLabel }}
                            </a>
                        @endif

                        @if ($nextPeriodUrl !== null)
                            <a href="{{ $nextPeriodUrl }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-slate-400">
                                {{ $nextPeriodLabel }} →
                            </a>
                        @endif
                    </div>
                </header>

                <form method="GET" action="{{ route('cockpit.productivity') }}" class="grid w-full gap-3 sm:grid-cols-12 sm:items-end lg:w-auto">
                    <label class="flex flex-col gap-1 text-sm text-slate-600 sm:col-span-4">
                        <span>Année</span>
                        <select name="year" class="h-10 rounded-lg border-slate-300 bg-white text-slate-900">
                            @foreach ($yearsForSelect as $year)
                                <option value="{{ $year }}" @selected($selectedYear === $year)>{{ $year }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="flex flex-col gap-1 text-sm text-slate-600 sm:col-span-5">
                        <span>Mois (optionnel)</span>
                        <select name="month" class="h-10 rounded-lg border-slate-300 bg-white text-slate-900">
                            <option value="">Toute l'année</option>
                            @foreach ($monthsForSelect as $monthValue => $monthLabel)
                                <option
                                    value="{{ $monthValue }}"
                                    @selected($selectedMonth === $monthValue)
                                    @disabled($selectedYear === $currentYear && $monthValue > $currentMonthNumber)
                                >
                                    {{ $monthLabel }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <button type="submit" class="h-10 min-w-[110px] justify-self-start rounded-lg bg-teal-700 px-4 text-sm font-medium text-white shadow-sm transition hover:bg-teal-800 sm:col-span-3">
                        Filtrer
                    </button>
                </form>
            </div>

            <div class="dashboard-spotlight mt-5 rounded-2xl border border-teal-200/60 p-4 shadow-inner shadow-teal-50">
                <div class="grid gap-4 md:grid-cols-[auto_minmax(0,1fr)_auto] md:items-center">
                    <div class="spotlight-avatar mx-auto md:mx-0">
                        <img
                            src="{{ asset('images/1758276756115.jpeg') }}"
                            alt="Avatar de monsieur Antunes"
                            class="relative z-[1] h-20 w-20 rounded-full border-4 border-white object-cover shadow-lg shadow-teal-900/20"
                        >
                    </div>

                    <div class="text-center md:text-left">
                        <p class="text-xs uppercase tracking-[0.2em] text-teal-700">Focus du jour</p>
                        <h2 class="mt-1 text-2xl font-bold text-slate-900">Monsieur Antunes, vous êtes aux commandes.</h2>
                        <p class="mt-2 text-sm text-slate-700">{{ $motivationMessage }}</p>
                    </div>

                    <div class="rounded-xl border border-teal-200 bg-white/80 p-3 md:min-w-[220px]">
                        <p class="text-xs text-slate-500">Veille</p>
                        <p class="mono mt-1 text-2xl font-semibold text-slate-900">{{ $yesterdayHours }} h</p>
                        <p class="mono mt-1 text-xs text-slate-600">{{ $yesterdayProgressPercent }}% objectif atteint</p>
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-teal-100">
                            <div class="h-full rounded-full bg-teal-600" style="width: {{ $yesterdayProgressBarPercent }}%;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        @if ($showDataWarning)
            <section class="mt-4 rounded-2xl border border-amber-300 bg-amber-50/90 p-4 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="mt-0.5 h-2.5 w-2.5 rounded-full bg-amber-500"></div>
                    <div>
                        <h2 class="text-sm font-semibold text-amber-900">{{ $warningTitle }}</h2>
                        <p class="mt-1 text-sm text-amber-800">{{ $warningBody }}</p>
                    </div>
                </div>
            </section>
        @endif

        <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm">
                <p class="text-sm text-slate-500">Temps total</p>
                <p class="mt-2 text-3xl font-bold text-slate-900">{{ $totalHours }} h</p>
                <p class="mono mt-2 text-xs text-slate-400">{{ $startDate }} → {{ $endDate }}</p>
            </article>

            <article class="rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm">
                <p class="text-sm text-slate-500">Moyenne journalière réelle</p>
                <p class="mt-2 text-3xl font-bold text-slate-900">{{ $dailyAverageHours }} h/j</p>
                <p class="mono mt-2 text-xs text-slate-400">Total / {{ $daysInPeriod }} jours</p>
            </article>

            <article class="rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm">
                <p class="text-sm text-slate-500">Objectif atteint</p>
                <p class="mt-2 text-3xl font-bold text-slate-900">{{ $progressPercent }}%</p>
                <p class="mono mt-2 text-xs text-slate-400">Objectif: {{ $dailyGoalHours }} h/j</p>
            </article>

            <article class="rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm">
                <p class="text-sm text-slate-500">Mois le plus fort</p>
                <p class="mt-2 text-3xl font-bold text-slate-900">{{ $bestMonthHours }} h</p>
                <p class="mono mt-2 text-xs text-slate-400">{{ strtoupper($bestMonthLabel) }} · {{ $activeMonths }} mois actifs</p>
            </article>
        </section>

        <section class="mt-6 rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Highlights</h2>
                    <p class="mt-1 text-sm text-slate-500">Records all time calculés sur les snapshots synchronisés.</p>
                </div>
                <p class="mono text-xs text-slate-500">
                    @if ($trackingSinceLabel !== null)
                        Historique depuis {{ $trackingSinceLabel }}
                    @else
                        Historique indisponible
                    @endif
                </p>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article class="highlight-card rounded-xl border p-4 pr-24">
                    <div class="pointer-events-none absolute -right-10 -top-10 h-24 w-24 rounded-full bg-cyan-100/75 blur-2xl"></div>
                    <div class="highlight-watermark">
                        <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                            <path d="M8 3v3M16 3v3M4 9h16M6 5h12a2 2 0 0 1 2 2v11a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3V7a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="m12 12.5.75 1.55 1.7.26-1.22 1.25.3 1.78L12 16.5l-1.53.84.3-1.78-1.22-1.25 1.7-.26L12 12.5Z" fill="currentColor"/>
                        </svg>
                    </div>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Record jour</p>
                    <p class="mono mt-2 text-2xl font-semibold text-slate-900">
                        @if ($allTimeDayRecordHours !== null)
                            {{ $allTimeDayRecordHours }} h
                        @else
                            n/a
                        @endif
                    </p>
                    <p class="mt-1 text-xs text-slate-500">{{ $allTimeDayRecordDate ?? 'Aucune donnée' }}</p>
                </article>

                <article class="highlight-card rounded-xl border p-4 pr-24">
                    <div class="pointer-events-none absolute -right-10 -top-10 h-24 w-24 rounded-full bg-teal-100/70 blur-2xl"></div>
                    <div class="highlight-watermark">
                        <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                            <path d="M4 20V9M10 20V5M16 20v-8M22 20H2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="m4 9 5-3 4 2 5-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Record mois</p>
                    <p class="mono mt-2 text-2xl font-semibold text-slate-900">
                        @if ($allTimeMonthRecordHours !== null)
                            {{ $allTimeMonthRecordHours }} h
                        @else
                            n/a
                        @endif
                    </p>
                    <p class="mt-1 text-xs text-slate-500">{{ $allTimeMonthRecordDate ?? 'Aucune donnée' }}</p>
                </article>

                <article class="highlight-card rounded-xl border p-4 pr-24">
                    <div class="pointer-events-none absolute -right-10 -top-10 h-24 w-24 rounded-full bg-emerald-100/75 blur-2xl"></div>
                    <div class="highlight-watermark">
                        <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                            <path d="M7 20h10M9 20v-3h6v3M8 4h8l-1 5a3 3 0 0 1-3 2h0a3 3 0 0 1-3-2L8 4ZM6 6H4a2 2 0 0 0 2 2M18 6h2a2 2 0 0 1-2 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Record année</p>
                    <p class="mono mt-2 text-2xl font-semibold text-slate-900">
                        @if ($allTimeYearRecordHours !== null)
                            {{ $allTimeYearRecordHours }} h
                        @else
                            n/a
                        @endif
                    </p>
                    <p class="mt-1 text-xs text-slate-500">{{ $allTimeYearRecordDate ?? 'Aucune donnée' }}</p>
                </article>

                <article class="highlight-card rounded-xl border p-4 pr-24">
                    <div class="pointer-events-none absolute -right-10 -top-10 h-24 w-24 rounded-full bg-sky-100/75 blur-2xl"></div>
                    <div class="highlight-watermark">
                        <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                            <path d="M12 21a9 9 0 1 0-9-9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                            <path d="m12 12 5.6-5.6M14.2 6.4h3.4v3.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="12" cy="12" r="1.9" fill="currentColor"/>
                        </svg>
                    </div>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Période actuelle vs record {{ $recordScopeLabel }}</p>
                    <p class="mono mt-2 text-2xl font-semibold text-slate-900">
                        @if ($recordProgressPercent !== null)
                            {{ $recordProgressPercent }}%
                        @else
                            n/a
                        @endif
                    </p>
                    @if ($recordProgressPercent !== null && $recordGapHours !== null)
                        <p class="mt-1 text-xs {{ $recordGapDirection === 'record' ? 'text-emerald-700' : 'text-slate-500' }}">
                            @if ($recordGapDirection === 'record')
                                Record battu de {{ $recordGapHours }} h
                            @elseif ($recordGapDirection === 'egalite')
                                Record égalé
                            @else
                                Encore {{ $recordGapHours }} h pour le record
                            @endif
                        </p>
                    @else
                        <p class="mt-1 text-xs text-slate-500">Base de comparaison indisponible</p>
                    @endif
                </article>
            </div>
        </section>

        <section class="mt-4 rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm">
            <div class="mb-2 flex items-center justify-between text-sm">
                <span class="text-slate-600">Progression vers l'objectif de période</span>
                <span class="font-semibold text-slate-900">{{ $progressPercent }}%</span>
            </div>
            <div class="h-3 w-full overflow-hidden rounded-full bg-teal-100">
                <div class="h-full rounded-full bg-teal-600 transition-all duration-700" style="width: {{ $progressBarPercent }}%;"></div>
            </div>
        </section>

        <section class="mt-6 grid gap-4 xl:grid-cols-2">
            <article class="rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-slate-900">Comparatif vs période précédente</h2>
                <p class="mt-1 text-sm text-slate-500">{{ $comparisonLabel }}</p>

                @php
                    $comparisonColor = $comparisonDirection === 'hausse' ? 'text-emerald-700' : 'text-rose-700';
                @endphp

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-slate-200 p-4">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Delta heures totales</p>
                        <p class="mono mt-2 text-2xl font-semibold {{ $comparisonColor }}">{{ $comparisonTotalDeltaHours }} h</p>
                        <p class="mono mt-1 text-xs text-slate-500">
                            @if ($comparisonTotalDeltaPercent !== null)
                                {{ $comparisonTotalDeltaPercent }}%
                            @else
                                n/a (base précédente à 0)
                            @endif
                        </p>
                    </div>

                    <div class="rounded-xl border border-slate-200 p-4">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Delta moyenne/jour</p>
                        <p class="mono mt-2 text-2xl font-semibold {{ $comparisonColor }}">{{ $comparisonAverageDeltaHours }} h/j</p>
                        <p class="mt-1 text-xs text-slate-500">{{ ucfirst($comparisonDirection) }} sur la période</p>
                    </div>
                </div>
            </article>

            <article class="rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-slate-900">Heatmap journalière</h2>
                <p class="mt-1 text-sm text-slate-500">
                    {{ $heatmapTrackedDays }} jours tracés · max journalier {{ $heatmapMaxHours }} h
                </p>

                <div class="mt-4 overflow-x-auto pb-2">
                    <div class="inline-flex min-w-max items-start gap-2">
                        <div class="mt-5 grid grid-rows-7 gap-1 pr-1 text-[10px] text-slate-500">
                            <div class="h-3 leading-3">Lun</div>
                            <div class="h-3 leading-3"></div>
                            <div class="h-3 leading-3">Mer</div>
                            <div class="h-3 leading-3"></div>
                            <div class="h-3 leading-3">Ven</div>
                            <div class="h-3 leading-3"></div>
                            <div class="h-3 leading-3"></div>
                        </div>

                        <div>
                            <div class="mb-1 flex gap-1">
                                @foreach ($heatmapWeekLabels as $monthLabel)
                                    <div class="relative h-3 w-[11px]">
                                        @if ($monthLabel !== '')
                                            <span class="absolute left-0 top-0 whitespace-nowrap text-[10px] leading-3 text-slate-500">
                                                {{ strtoupper($monthLabel) }}
                                            </span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            <div class="flex gap-1">
                                @foreach ($heatmapWeeks as $week)
                                    <div class="flex w-[11px] flex-col gap-1">
                                        @foreach ($week as $cell)
                                            @if (($cell['type'] ?? '') === 'day')
                                                @php
                                                    $isMissingCell = (bool) ($cell['is_missing'] ?? false);
                                                    $cellTitle = $isMissingCell
                                                        ? ($cell['label'].' · a completer')
                                                        : ($cell['label'].' · '.$cell['hours'].' h');
                                                @endphp
                                                <div
                                                    title="{{ $cellTitle }}"
                                                    class="h-[11px] w-[11px] rounded-[3px] {{ $cell['color_class'] }} border {{ $isMissingCell ? 'border-slate-300' : 'border-slate-200/70' }}"
                                                ></div>
                                            @else
                                                <div class="h-[11px] w-[11px] rounded-[3px] bg-transparent"></div>
                                            @endif
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
                <div class="mt-2 text-xs text-slate-500">
                    Vue horizontale type GitHub: semaines en colonnes, jours en lignes.
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                    <span>A completer</span>
                    <span class="h-3 w-3 rounded bg-white border border-slate-300"></span>
                    <span>Faible</span>
                    <span class="h-3 w-3 rounded bg-slate-100 border border-slate-200/70"></span>
                    <span class="h-3 w-3 rounded bg-emerald-100 border border-slate-200/70"></span>
                    <span class="h-3 w-3 rounded bg-emerald-300 border border-slate-200/70"></span>
                    <span class="h-3 w-3 rounded bg-emerald-500 border border-slate-200/70"></span>
                    <span class="h-3 w-3 rounded bg-emerald-700 border border-slate-200/70"></span>
                    <span>Fort</span>
                </div>
            </article>
        </section>

        <section class="mt-6 grid gap-4 xl:grid-cols-2">
            <article class="rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm" data-tabs-widget>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Répartition du temps</h2>
                        <p class="mt-1 text-sm text-slate-500">Distribution sur la période sélectionnée.</p>
                    </div>
                    <p class="mono text-xs text-slate-500">Total: {{ $periodBreakdownTotalHours }} h</p>
                </div>

                <div class="mt-4 inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1">
                    <button
                        type="button"
                        data-tab-button
                        data-tab-target="clients"
                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-teal-600 hover:text-teal-700"
                    >
                        Par client
                    </button>
                    <button
                        type="button"
                        data-tab-button
                        data-tab-target="projects"
                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-teal-600 hover:text-teal-700"
                    >
                        Par projet
                    </button>
                </div>

                @if ($periodBreakdownHasData)
                    <div class="mt-4 overflow-x-auto" data-tab-panel="clients">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold text-slate-700">Client</th>
                                    <th class="px-4 py-3 text-right font-semibold text-slate-700">Heures</th>
                                    <th class="px-4 py-3 text-right font-semibold text-slate-700">Part</th>
                                    <th class="px-4 py-3 text-right font-semibold text-slate-700">Projets</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach ($periodBreakdownClients as $row)
                                    <tr class="hover:bg-slate-50/80">
                                        <td class="px-4 py-3 font-medium text-slate-700">{{ $row['name'] }}</td>
                                        <td class="mono px-4 py-3 text-right text-slate-900">{{ $row['hours'] }} h</td>
                                        <td class="mono px-4 py-3 text-right text-slate-600">{{ $row['share_percent'] }}%</td>
                                        <td class="mono px-4 py-3 text-right text-slate-600">{{ $row['project_count'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 hidden overflow-x-auto" data-tab-panel="projects">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold text-slate-700">Projet</th>
                                    <th class="px-4 py-3 text-left font-semibold text-slate-700">Client</th>
                                    <th class="px-4 py-3 text-right font-semibold text-slate-700">Heures</th>
                                    <th class="px-4 py-3 text-right font-semibold text-slate-700">Part</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach ($periodBreakdownProjects as $row)
                                    <tr class="hover:bg-slate-50/80">
                                        <td class="px-4 py-3 font-medium text-slate-700">{{ $row['name'] }}</td>
                                        <td class="px-4 py-3 text-slate-600">{{ $row['client'] }}</td>
                                        <td class="mono px-4 py-3 text-right text-slate-900">{{ $row['hours'] }} h</td>
                                        <td class="mono px-4 py-3 text-right text-slate-600">{{ $row['share_percent'] }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                        Répartition indisponible pour cette période (sync en attente ou données API incomplètes).
                    </div>
                @endif
            </article>

            <article class="rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm" data-tabs-widget>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Comparaison externe</h2>
                        <p class="mt-1 text-sm text-slate-500">Ta moyenne journalière vs repères connus.</p>
                    </div>
                    <p class="mono text-xs text-slate-500">Ta moyenne: {{ $currentDailyAverageHoursDisplay }} h/j</p>
                </div>

                <div class="mt-4 inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1">
                    <button
                        type="button"
                        data-tab-button
                        data-tab-target="entrepreneurs"
                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-teal-600 hover:text-teal-700"
                    >
                        Entrepreneurs
                    </button>
                    <button
                        type="button"
                        data-tab-button
                        data-tab-target="countries"
                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-teal-600 hover:text-teal-700"
                    >
                        Pays
                    </button>
                </div>

                <div class="mt-4 overflow-x-auto" data-tab-panel="entrepreneurs">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Repère</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">h/j</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">Toi / repère</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">Écart</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @foreach ($entrepreneurBenchmarks as $row)
                                @php
                                    $deltaClass = $row['delta_direction'] === 'au-dessus'
                                        ? 'text-emerald-700'
                                        : ($row['delta_direction'] === 'en-dessous' ? 'text-amber-700' : 'text-slate-600');
                                @endphp
                                <tr class="hover:bg-slate-50/80">
                                    <td class="px-4 py-3 font-medium text-slate-700">{{ $row['name'] }}<p class="mt-1 text-xs text-slate-500">{{ $row['note'] }}</p></td>
                                    <td class="mono px-4 py-3 text-right text-slate-900">{{ $row['daily_hours'] }}</td>
                                    <td class="mono px-4 py-3 text-right text-slate-600">{{ $row['relative_percent'] }}%</td>
                                    <td class="mono px-4 py-3 text-right {{ $deltaClass }}">{{ $row['delta_hours'] }} h ({{ $row['delta_direction'] }})</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 hidden overflow-x-auto" data-tab-panel="countries">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Pays</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">h/j</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">Toi / moyenne</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">Écart</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @foreach ($countryBenchmarks as $row)
                                @php
                                    $deltaClass = $row['delta_direction'] === 'au-dessus'
                                        ? 'text-emerald-700'
                                        : ($row['delta_direction'] === 'en-dessous' ? 'text-amber-700' : 'text-slate-600');
                                @endphp
                                <tr class="hover:bg-slate-50/80">
                                    <td class="px-4 py-3 font-medium text-slate-700">{{ $row['name'] }}<p class="mt-1 text-xs text-slate-500">{{ $row['note'] }}</p></td>
                                    <td class="mono px-4 py-3 text-right text-slate-900">{{ $row['daily_hours'] }}</td>
                                    <td class="mono px-4 py-3 text-right text-slate-600">{{ $row['relative_percent'] }}%</td>
                                    <td class="mono px-4 py-3 text-right {{ $deltaClass }}">{{ $row['delta_hours'] }} h ({{ $row['delta_direction'] }})</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <section class="mt-6 grid gap-4 xl:grid-cols-2">
            <article class="rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-slate-900">Evolution mensuelle ({{ $selectedYear }})</h2>
                <p class="mt-1 text-sm text-slate-500">Répartition des heures par mois.</p>
                <div class="mt-4 h-72">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </article>

            <article class="rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-slate-900">Evolution annuelle</h2>
                <p class="mt-1 text-sm text-slate-500">Comparatif multi-années (heures totales).</p>
                <div class="mt-4 h-72">
                    <canvas id="yearlyChart"></canvas>
                </div>
            </article>
        </section>

        <section class="mt-6 rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">Répartition par mois ({{ $selectedYear }})</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">Mois</th>
                            <th class="px-4 py-3 text-right font-semibold text-slate-700">Heures</th>
                            <th class="px-4 py-3 text-right font-semibold text-slate-700">Part de l'année</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach ($monthRows as $row)
                            <tr class="hover:bg-slate-50/80">
                                <td class="px-4 py-3 font-medium text-slate-700">{{ strtoupper($row['label']) }}</td>
                                <td class="mono px-4 py-3 text-right text-slate-900">{{ $row['hours'] }} h</td>
                                <td class="mono px-4 py-3 text-right text-slate-600">{{ $row['share_percent'] }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <footer class="mt-5 px-1 text-xs text-slate-500">
            Dernière synchronisation: <span class="mono">{{ $syncedAt }}</span>
            · Sync API à la demande (TTL {{ $syncTtlMinutes }} min)
            · Heatmap: {{ $heatmapSyncedDays }} jours synchronisés à l'ouverture
            @if ($heatmapSyncBudget === 0)
                · sync à la demande désactivée
            @endif
            @if ($heatmapMissingDays > 0)
                · {{ $heatmapMissingDays }} jours resteront en attente du warmup nocturne
            @endif
        </footer>
    </main>

    <script>
        const monthlyLabels = @json($monthlyChartLabels);
        const monthlyHours = @json($monthlyChartHours);
        const yearlyLabels = @json($yearlyChartLabels);
        const yearlyHours = @json($yearlyChartHours);
        const welcomeModal = document.getElementById('welcomeModal');
        const welcomeRippleLayer = document.getElementById('welcomeRippleLayer');
        const enterCockpitButton = document.getElementById('enterCockpitButton');
        const welcomeModalDayKey = @json(\Carbon\CarbonImmutable::today(config('app.timezone'))->toDateString());

        const tabActiveClasses = ['border-teal-700', 'bg-teal-700', 'text-white'];
        const tabInactiveClasses = ['border-slate-300', 'bg-white', 'text-slate-700', 'hover:border-teal-600', 'hover:text-teal-700'];

        if (welcomeModal && welcomeRippleLayer && enterCockpitButton) {
            const welcomeModalStorageKey = 'cockpit.welcome.modal.last-seen-day.v1';

            const closeWelcomeModal = () => {
                document.body.classList.remove('overflow-hidden');
                welcomeModal.remove();
            };

            let lastSeenDay = null;
            try {
                lastSeenDay = window.localStorage.getItem(welcomeModalStorageKey);
            } catch (error) {
                lastSeenDay = null;
            }

            if (lastSeenDay === welcomeModalDayKey) {
                closeWelcomeModal();
            } else {
                document.body.classList.add('overflow-hidden');

                const spawnPortalRipples = (originX, originY) => {
                    const ringScales = [6, 12, 19, 29, 41, 56, 74];
                    const ringSizes = [20, 26, 34, 40, 46, 52, 58];

                    for (let index = 0; index < ringScales.length; index += 1) {
                        const ripple = document.createElement('span');
                        ripple.className = 'welcome-ripple';
                        ripple.style.left = `${originX}px`;
                        ripple.style.top = `${originY}px`;
                        ripple.style.width = `${ringSizes[index]}px`;
                        ripple.style.height = `${ringSizes[index]}px`;
                        ripple.style.setProperty('--rscale', ringScales[index].toString());
                        ripple.style.animationDelay = `${index * 85}ms`;
                        welcomeRippleLayer.appendChild(ripple);
                    }
                };

                const markWelcomeModalSeen = () => {
                    try {
                        window.localStorage.setItem(welcomeModalStorageKey, welcomeModalDayKey);
                    } catch (error) {
                        // Ignore storage errors and keep the default behavior.
                    }
                };

                const triggerModalTransition = (originX, originY) => {
                    if (welcomeModal.dataset.state === 'closing') {
                        return;
                    }

                    welcomeModal.dataset.state = 'closing';
                    markWelcomeModalSeen();
                    spawnPortalRipples(originX, originY);
                    requestAnimationFrame(() => {
                        welcomeModal.classList.add('modal-exit');
                    });

                    window.setTimeout(() => {
                        welcomeRippleLayer.innerHTML = '';
                        closeWelcomeModal();
                    }, 1900);
                };

                enterCockpitButton.addEventListener('click', (event) => {
                    triggerModalTransition(event.clientX, event.clientY);
                });

                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && welcomeModal.dataset.state !== 'closing') {
                        triggerModalTransition(window.innerWidth * 0.5, window.innerHeight * 0.78);
                    }
                });
            }
        }

        document.querySelectorAll('[data-tabs-widget]').forEach((widget) => {
            const buttons = Array.from(widget.querySelectorAll('[data-tab-button]'));
            const panels = Array.from(widget.querySelectorAll('[data-tab-panel]'));
            if (buttons.length === 0 || panels.length === 0) {
                return;
            }

            const activateTab = (target) => {
                buttons.forEach((button) => {
                    const isActive = button.dataset.tabTarget === target;
                    button.classList.toggle('pointer-events-none', isActive);
                    tabActiveClasses.forEach((className) => button.classList.toggle(className, isActive));
                    tabInactiveClasses.forEach((className) => button.classList.toggle(className, !isActive));
                });

                panels.forEach((panel) => {
                    const isActive = panel.dataset.tabPanel === target;
                    panel.classList.toggle('hidden', !isActive);
                });
            };

            buttons.forEach((button) => {
                button.addEventListener('click', () => activateTab(button.dataset.tabTarget));
            });

            activateTab(buttons[0].dataset.tabTarget);
        });

        const axisColor = '#334155';
        const gridColor = 'rgba(100, 116, 139, 0.16)';

        new Chart(document.getElementById('monthlyChart'), {
            type: 'bar',
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: 'Heures',
                    data: monthlyHours,
                    borderRadius: 8,
                    backgroundColor: 'rgba(15, 118, 110, 0.76)',
                    hoverBackgroundColor: 'rgba(13, 148, 136, 0.88)'
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor },
                        ticks: { color: axisColor }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: axisColor }
                    }
                }
            }
        });

        new Chart(document.getElementById('yearlyChart'), {
            type: 'line',
            data: {
                labels: yearlyLabels,
                datasets: [{
                    label: 'Heures',
                    data: yearlyHours,
                    borderColor: '#0e7490',
                    backgroundColor: 'rgba(14, 116, 144, 0.15)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 4,
                    pointBackgroundColor: '#0e7490'
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor },
                        ticks: { color: axisColor }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: axisColor }
                    }
                }
            }
        });
    </script>
</body>
</html>
