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
