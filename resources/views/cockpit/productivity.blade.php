<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cockpit | Productivité</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
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
    @include('cockpit.partials.welcome-modal')

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

            <div class="dashboard-spotlight mt-5 rounded-2xl border border-teal-200/60 p-4 pl-8 shadow-inner shadow-teal-50">
                <div class="grid gap-8 md:grid-cols-[auto_minmax(0,1fr)_auto] md:items-center">
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
            @php
                $totalVariationClass = str_starts_with($totalVariationHours, '+')
                    ? 'text-emerald-700'
                    : (str_starts_with($totalVariationHours, '-') ? 'text-rose-700' : 'text-slate-500');
                $avgVariationClass = str_starts_with($dailyAverageVariationHours, '+')
                    ? 'text-emerald-700'
                    : (str_starts_with($dailyAverageVariationHours, '-') ? 'text-rose-700' : 'text-slate-500');
                $progressVariationClass = str_starts_with($progressVariationPoints, '+')
                    ? 'text-emerald-700'
                    : (str_starts_with($progressVariationPoints, '-') ? 'text-rose-700' : 'text-slate-500');
                $bestMonthVariationClass = str_starts_with($bestMonthVariationHours, '+')
                    ? 'text-emerald-700'
                    : (str_starts_with($bestMonthVariationHours, '-') ? 'text-rose-700' : 'text-slate-500');
                $previousScoreLabel = $isYearlyView ? 'N-1' : 'M-1';
            @endphp
            <article class="rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm">
                <p class="text-sm text-slate-500">Temps total</p>
                <p class="mt-2 text-3xl font-bold text-slate-900">{{ $totalHours }} h</p>
                <p class="mono mt-2 text-xs text-slate-400">{{ $startDate }} → {{ $endDate }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span class="mono inline-flex items-center rounded-full border px-2 py-0.5 text-xs {{ $totalVariationClass }} {{ str_starts_with($totalVariationHours, '+') ? 'border-emerald-200 bg-emerald-50' : (str_starts_with($totalVariationHours, '-') ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-slate-50') }}">
                        {{ $totalVariationPercentLabel }}
                    </span>
                    <span class="mono text-xs text-slate-400">{{ $previousScoreLabel }}: {{ $previousTotalHours }} h</span>
                </div>
            </article>

            <article class="rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm">
                <p class="text-sm text-slate-500">Moyenne journalière réelle</p>
                <p class="mt-2 text-3xl font-bold text-slate-900">{{ $dailyAverageHours }} h/j</p>
                <p class="mono mt-2 text-xs text-slate-400">Total / {{ $daysInPeriod }} jours</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span class="mono inline-flex items-center rounded-full border px-2 py-0.5 text-xs {{ $avgVariationClass }} {{ str_starts_with($dailyAverageVariationHours, '+') ? 'border-emerald-200 bg-emerald-50' : (str_starts_with($dailyAverageVariationHours, '-') ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-slate-50') }}">
                        {{ $dailyAverageVariationPercentLabel }}
                    </span>
                    <span class="mono text-xs text-slate-400">{{ $previousScoreLabel }}: {{ $previousDailyAverageHours }} h/j</span>
                </div>
            </article>

            <article class="rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm">
                <p class="text-sm text-slate-500">Objectif atteint</p>
                <p class="mt-2 text-3xl font-bold text-slate-900">{{ $progressPercent }}%</p>
                <p class="mono mt-2 text-xs text-slate-400">Objectif: {{ $dailyGoalHours }} h/j</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span class="mono inline-flex items-center rounded-full border px-2 py-0.5 text-xs {{ $progressVariationClass }} {{ str_starts_with($progressVariationPoints, '+') ? 'border-emerald-200 bg-emerald-50' : (str_starts_with($progressVariationPoints, '-') ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-slate-50') }}">
                        {{ $progressVariationPercentLabel }}
                    </span>
                    <span class="mono text-xs text-slate-400">{{ $previousScoreLabel }}: {{ $previousProgressPercent }}%</span>
                </div>
            </article>

            <article class="rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm">
                <p class="text-sm text-slate-500">Mois le plus fort</p>
                <p class="mt-2 text-3xl font-bold text-slate-900">{{ $bestMonthHours }} h</p>
                <p class="mono mt-2 text-xs text-slate-400">{{ strtoupper($bestMonthLabel) }} · {{ $activeMonths }} mois actifs</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span class="mono inline-flex items-center rounded-full border px-2 py-0.5 text-xs {{ $bestMonthVariationClass }} {{ str_starts_with($bestMonthVariationHours, '+') ? 'border-emerald-200 bg-emerald-50' : (str_starts_with($bestMonthVariationHours, '-') ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-slate-50') }}">
                        {{ $bestMonthVariationPercentLabel }}
                    </span>
                    <span class="mono text-xs text-slate-400">{{ $previousScoreLabel }}: {{ $previousBestMonthHours }} h</span>
                </div>
            </article>
        </section>

        @include('cockpit.partials.highlights-cards')

        <section class="mt-4 rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm" data-tabs-widget data-initial-tab="forecast">
            @php
                $driftToneClass = $driftLevel === 'critical'
                    ? 'text-rose-700'
                    : ($driftLevel === 'warning' ? 'text-amber-700' : 'text-emerald-700');
                $catchupToneClass = $catchupState === 'ahead'
                    ? 'text-emerald-700'
                    : (($catchupState === 'pending' || $catchupState === 'today') ? 'text-amber-700' : 'text-slate-700');
            @endphp
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Pilotage intelligent</h2>
                    <p class="mt-1 text-sm text-slate-500">Prévision, dérive, focus, constance et rattrapage.</p>
                </div>
                <p class="mono text-xs text-slate-500">Période: {{ $periodLabel }}</p>
            </div>

            <div class="mt-4 inline-flex flex-wrap rounded-lg border border-slate-200 bg-slate-50 p-1">
                <button type="button" data-tab-button data-tab-target="forecast" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-teal-600 hover:text-teal-700">Prévision</button>
                <button type="button" data-tab-button data-tab-target="drift" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-teal-600 hover:text-teal-700">Dérive</button>
                <button type="button" data-tab-button data-tab-target="focus" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-teal-600 hover:text-teal-700">Focus</button>
                <button type="button" data-tab-button data-tab-target="streak" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-teal-600 hover:text-teal-700">Streak</button>
                <button type="button" data-tab-button data-tab-target="catchup" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-teal-600 hover:text-teal-700">Rattrapage</button>
            </div>

            <div class="mt-4" data-tab-panel="forecast">
                <div class="grid gap-3 md:grid-cols-2 md:items-stretch">
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-xs uppercase tracking-wide text-slate-500">{{ $forecastScopeLabel }}</p>
                        <p class="mono mt-2 text-2xl font-semibold text-slate-900">{{ $forecastProjectedHours }} h</p>
                        <p class="mt-1 text-sm text-slate-600">Objectif cible: {{ $forecastTargetHours }} h · {{ $forecastProgressPercent }}%</p>
                        <div class="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-teal-100">
                            <div class="h-full rounded-full bg-teal-600 transition-all duration-700" style="width: {{ $forecastProgressBarPercent }}%;"></div>
                        </div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        @if ((float) $forecastSurplusHours > 0)
                            <p class="text-xs text-slate-500">Avance estimée</p>
                            <p class="mono mt-2 text-2xl font-semibold text-emerald-700">+{{ $forecastSurplusHours }} h</p>
                            <p class="mt-1 text-xs text-slate-500">Objectif déjà dépassé à projection constante.</p>
                        @else
                            <p class="text-xs text-slate-500">Reste à couvrir</p>
                            <p class="mono mt-2 text-2xl font-semibold text-slate-900">{{ $forecastRemainingHours }} h</p>
                            <p class="mt-1 text-xs text-slate-500">Écart restant vers l’objectif cible.</p>
                        @endif
                        <div class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                            {{ $forecastIsProjectionOpen ? 'Projection dynamique (période ouverte).' : 'Période clôturée (valeur finale).' }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 hidden" data-tab-panel="drift">
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-500">Alerte de dérive</p>
                            <p class="mt-2 text-lg font-semibold {{ $driftToneClass }}">{{ $driftTitle }}</p>
                            <p class="mt-1 text-sm text-slate-600">{{ $driftMessage }}</p>
                        </div>
                        <div class="text-right">
                            <p class="mono text-sm text-slate-500">Veille</p>
                            <p class="mono text-lg font-semibold {{ $yesterdayDeltaClass }}">{{ $yesterdayDeltaHours }} h</p>
                            <p class="mono mt-1 text-sm text-slate-500">7j</p>
                            <p class="mono text-lg font-semibold {{ $weeklyDeltaClass }}">{{ $weeklyDeltaAverageHours }} h/j</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 hidden" data-tab-panel="focus">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-xs text-slate-500">Score focus</p>
                        <p class="mono mt-2 text-3xl font-semibold text-slate-900">{{ $focusScore }}/100</p>
                        <p class="mt-1 text-xs text-slate-600">{{ $focusLabel }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-xs text-slate-500">Top 1 projet</p>
                        <p class="mono mt-2 text-2xl font-semibold text-slate-900">{{ $focusTop1Percent }}%</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-xs text-slate-500">Top 3 projets</p>
                        <p class="mono mt-2 text-2xl font-semibold text-slate-900">{{ $focusTop3Percent }}%</p>
                        <p class="mt-1 text-xs text-slate-600">{{ $focusActiveProjects }} projets actifs</p>
                    </div>
                </div>
            </div>

            <div class="mt-4 hidden" data-tab-panel="streak">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-xs text-slate-500">Série actuelle</p>
                        <p class="mono mt-2 text-3xl font-semibold text-slate-900">{{ $streakCurrentDays }} jours</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-xs text-slate-500">Meilleure série</p>
                        <p class="mono mt-2 text-3xl font-semibold text-slate-900">{{ $streakBestDays }} jours</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-xs text-slate-500">Constance</p>
                        <p class="mono mt-2 text-2xl font-semibold text-slate-900">{{ $streakConsistencyPercent }}%</p>
                        <p class="mt-1 text-xs text-slate-600">{{ $streakGoalHitDays }} jours objectif atteint / {{ $streakSyncedDayCount }} jours synchronisés</p>
                    </div>
                </div>
            </div>

            <div class="mt-4 hidden" data-tab-panel="catchup">
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Plan de rattrapage</p>
                    <p class="mt-2 text-lg font-semibold {{ $catchupToneClass }}">{{ $catchupMessage }}</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div>
                            <p class="text-xs text-slate-500">Heures restantes</p>
                            <p class="mono mt-1 text-xl font-semibold text-slate-900">{{ $catchupRemainingHours }} h</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Jours restants</p>
                            <p class="mono mt-1 text-xl font-semibold text-slate-900">{{ $catchupRemainingDays }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Rythme requis</p>
                            <p class="mono mt-1 text-xl font-semibold text-slate-900">{{ $catchupHoursPerDay ?? 'n/a' }} h/j</p>
                        </div>
                    </div>
                </div>
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
                                                {{ $monthLabel }}
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

        <section id="time-breakdown" class="mt-6 grid gap-4 xl:grid-cols-2">
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
                        data-tab-target="projects"
                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-teal-600 hover:text-teal-700"
                    >
                        Par projet
                    </button>
                    <button
                        type="button"
                        data-tab-button
                        data-tab-target="clients"
                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-teal-600 hover:text-teal-700"
                    >
                        Par client
                    </button>
                </div>

                @if ($periodBreakdownHasData)
                    <div class="mt-4 overflow-x-auto" data-tab-panel="projects" data-pagination-panel data-page-size="6">
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
                        <div class="mt-3 flex flex-col gap-2 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between" data-pagination-controls>
                            <span class="mono" data-pagination-range></span>
                            <div class="inline-flex items-center gap-2">
                                <button
                                    type="button"
                                    data-page-action="prev"
                                    class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 transition hover:border-slate-400 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                                >
                                    ← Précédent
                                </button>
                                <span class="mono" data-pagination-label></span>
                                <button
                                    type="button"
                                    data-page-action="next"
                                    class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 transition hover:border-slate-400 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                                >
                                    Suivant →
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 hidden overflow-x-auto" data-tab-panel="clients" data-pagination-panel data-page-size="6">
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
                        <div class="mt-3 flex flex-col gap-2 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between" data-pagination-controls>
                            <span class="mono" data-pagination-range></span>
                            <div class="inline-flex items-center gap-2">
                                <button
                                    type="button"
                                    data-page-action="prev"
                                    class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 transition hover:border-slate-400 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                                >
                                    ← Précédent
                                </button>
                                <span class="mono" data-pagination-label></span>
                                <button
                                    type="button"
                                    data-page-action="next"
                                    class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 transition hover:border-slate-400 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                                >
                                    Suivant →
                                </button>
                            </div>
                        </div>
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
                                    <td class="px-4 py-3 font-medium text-slate-700">
                                        {{ $row['name'] }}
                                        <p class="mt-1 text-xs text-slate-500">{{ $row['note'] }}</p>
                                    </td>
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
                                    <td class="px-4 py-3 font-medium text-slate-700">
                                        {{ $row['name'] }}
                                        <p class="mt-1 text-xs text-slate-500">{{ $row['note'] }}</p>
                                    </td>
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
                                <td class="px-4 py-3 font-medium text-slate-700">{{ ucfirst($row['label']) }}</td>
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

    @php
        $cockpitPayload = [
            'monthlyLabels' => $monthlyChartLabels,
            'monthlyHours' => $monthlyChartHours,
            'yearlyLabels' => $yearlyChartLabels,
            'yearlyHours' => $yearlyChartHours,
            'welcomeModalDayKey' => \Carbon\CarbonImmutable::today(config('app.timezone'))->toDateString(),
        ];
    @endphp
    <script>
        window.__COCKPIT_PRODUCTIVITY__ = {!! json_encode($cockpitPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};
    </script>
</body>
</html>
