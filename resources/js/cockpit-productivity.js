const initCockpitProductivityPage = () => {
    const pageData = window.__COCKPIT_PRODUCTIVITY__;
    if (!pageData || typeof pageData !== 'object') {
        return;
    }

    const monthlyLabels = pageData.monthlyLabels ?? [];
    const monthlyHours = pageData.monthlyHours ?? [];
    let yearlyLabels = pageData.yearlyLabels ?? [];
    let yearlyHours = pageData.yearlyHours ?? [];
    let yearlyScope = pageData.yearlyScope ?? 'recent';
    const welcomeModalDayKey = pageData.welcomeModalDayKey ?? null;

    const welcomeModal = document.getElementById('welcomeModal');
    const welcomeRippleLayer = document.getElementById('welcomeRippleLayer');
    const enterCockpitButton = document.getElementById('enterCockpitButton');
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

        if (welcomeModalDayKey !== null && lastSeenDay === welcomeModalDayKey) {
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
                if (welcomeModalDayKey === null) {
                    return;
                }

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

        const preferredTab = widget.dataset.initialTab ?? '';
        const hasPreferredTab = buttons.some((button) => button.dataset.tabTarget === preferredTab);
        const initialTabTarget = hasPreferredTab ? preferredTab : buttons[0].dataset.tabTarget;
        activateTab(initialTabTarget);
    });

    document.querySelectorAll('[data-pagination-panel]').forEach((panel) => {
        const rows = Array.from(panel.querySelectorAll('tbody tr'));
        const controls = panel.querySelector('[data-pagination-controls]');
        const rangeElement = controls?.querySelector('[data-pagination-range]');
        const labelElement = controls?.querySelector('[data-pagination-label]');
        const prevButton = controls?.querySelector('[data-page-action="prev"]');
        const nextButton = controls?.querySelector('[data-page-action="next"]');
        const pageSize = Math.max(1, Number.parseInt(panel.dataset.pageSize ?? '6', 10) || 6);

        if (rows.length === 0 || !controls || !prevButton || !nextButton) {
            return;
        }

        const totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
        let currentPage = 1;

        const renderPage = () => {
            const startIndex = (currentPage - 1) * pageSize;
            const endIndex = startIndex + pageSize;

            rows.forEach((row, index) => {
                const isVisible = index >= startIndex && index < endIndex;
                row.classList.toggle('hidden', !isVisible);
            });

            const from = startIndex + 1;
            const to = Math.min(rows.length, endIndex);

            if (rangeElement) {
                rangeElement.textContent = `Lignes ${from}-${to} sur ${rows.length}`;
            }

            if (labelElement) {
                labelElement.textContent = `Page ${currentPage}/${totalPages}`;
            }

            prevButton.disabled = currentPage <= 1;
            nextButton.disabled = currentPage >= totalPages;
            controls.classList.toggle('hidden', rows.length <= pageSize);
        };

        prevButton.addEventListener('click', () => {
            if (currentPage <= 1) {
                return;
            }

            currentPage -= 1;
            renderPage();
        });

        nextButton.addEventListener('click', () => {
            if (currentPage >= totalPages) {
                return;
            }

            currentPage += 1;
            renderPage();
        });

        renderPage();
    });

    const chartPalette = {
        axisColor: '#334155',
        gridColor: 'rgba(100, 116, 139, 0.16)',
        monthlyBar: 'rgba(15, 118, 110, 0.76)',
        monthlyHover: 'rgba(13, 148, 136, 0.88)',
        yearlyLine: '#0e7490',
        yearlyFill: 'rgba(14, 116, 144, 0.15)',
        yearlyPoint: '#0e7490',
    };

    const monthlyChartCanvas = document.getElementById('monthlyChart');
    const yearlyChartCanvas = document.getElementById('yearlyChart');
    const yearlyScopeLabel = document.querySelector('[data-yearly-scope-label]');
    const yearlyScopeInput = document.querySelector('[data-yearly-scope-input]');
    const yearlyScopeLinks = Array.from(document.querySelectorAll('[data-yearly-scope-link]'));
    let monthlyChart = null;
    let yearlyChart = null;
    let yearlyScopeRequestPending = false;

    const setYearlyScopeButtonsState = (activeScope) => {
        yearlyScopeLinks.forEach((link) => {
            const isActive = link.dataset.yearlyScope === activeScope;
            link.classList.toggle('pointer-events-none', isActive);
            tabActiveClasses.forEach((className) => link.classList.toggle(className, isActive));
            tabInactiveClasses.forEach((className) => link.classList.toggle(className, !isActive));
        });
        if (yearlyScopeInput) {
            yearlyScopeInput.value = activeScope;
        }
    };

    const setYearlyScopeLoading = (isLoading) => {
        yearlyScopeLinks.forEach((link) => {
            link.classList.toggle('opacity-60', isLoading);
            link.classList.toggle('cursor-progress', isLoading);
        });
    };

    const renderCharts = () => {
        if (typeof window.Chart !== 'function') {
            return false;
        }

        const palette = chartPalette;

        if (monthlyChartCanvas && monthlyChart === null) {
            monthlyChart = new window.Chart(monthlyChartCanvas, {
                type: 'bar',
                data: {
                    labels: monthlyLabels,
                    datasets: [{
                        label: 'Heures',
                        data: monthlyHours,
                        borderRadius: 8,
                        backgroundColor: palette.monthlyBar,
                        hoverBackgroundColor: palette.monthlyHover,
                    }],
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: palette.gridColor },
                            ticks: { color: palette.axisColor },
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: palette.axisColor },
                        },
                    },
                },
            });
        }

        if (yearlyChartCanvas && yearlyChart === null) {
            yearlyChart = new window.Chart(yearlyChartCanvas, {
                type: 'line',
                data: {
                    labels: yearlyLabels,
                    datasets: [{
                        label: 'Heures',
                        data: yearlyHours,
                        borderColor: palette.yearlyLine,
                        backgroundColor: palette.yearlyFill,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 4,
                        pointBackgroundColor: palette.yearlyPoint,
                    }],
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: palette.gridColor },
                            ticks: { color: palette.axisColor },
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: palette.axisColor },
                        },
                    },
                },
            });
        }

        return monthlyChart !== null || yearlyChart !== null;
    };

    if (!renderCharts()) {
        let attempts = 0;
        const maxAttempts = 40;
        const intervalId = window.setInterval(() => {
            attempts += 1;
            if (renderCharts() || attempts >= maxAttempts) {
                window.clearInterval(intervalId);
            }
        }, 125);

        window.addEventListener('load', () => {
            renderCharts();
            window.clearInterval(intervalId);
        }, { once: true });
    }

    if (yearlyScopeLinks.length > 0) {
        setYearlyScopeButtonsState(yearlyScope);
        yearlyScopeLinks.forEach((link) => {
            link.addEventListener('click', async (event) => {
                event.preventDefault();
                if (yearlyScopeRequestPending) {
                    return;
                }

                const requestedScope = link.dataset.yearlyScope ?? '';
                if (requestedScope === '' || requestedScope === yearlyScope) {
                    return;
                }

                yearlyScopeRequestPending = true;
                setYearlyScopeLoading(true);

                try {
                    const requestUrl = new URL(link.href, window.location.origin);
                    requestUrl.searchParams.set('ajax', 'yearly_evolution');
                    const response = await window.fetch(requestUrl.toString(), {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const payload = await response.json();
                    if (!Array.isArray(payload.labels) || !Array.isArray(payload.hours)) {
                        throw new Error('Invalid yearly payload');
                    }

                    yearlyLabels = payload.labels;
                    yearlyHours = payload.hours;
                    yearlyScope = typeof payload.scope === 'string' ? payload.scope : requestedScope;

                    if (yearlyChart !== null) {
                        yearlyChart.data.labels = yearlyLabels;
                        yearlyChart.data.datasets[0].data = yearlyHours;
                        yearlyChart.update();
                    } else {
                        renderCharts();
                    }

                    if (yearlyScopeLabel && typeof payload.scopeLabel === 'string') {
                        yearlyScopeLabel.textContent = `Comparatif multi-années (heures totales) · ${payload.scopeLabel}.`;
                    }

                    yearlyScopeLinks.forEach((scopeLink) => {
                        if (scopeLink.dataset.yearlyScope === 'recent' && typeof payload.scopeRecentUrl === 'string') {
                            scopeLink.href = payload.scopeRecentUrl;
                        }
                        if (scopeLink.dataset.yearlyScope === 'all' && typeof payload.scopeAllUrl === 'string') {
                            scopeLink.href = payload.scopeAllUrl;
                        }
                    });

                    setYearlyScopeButtonsState(yearlyScope);
                    const browserUrl = new URL(window.location.href);
                    browserUrl.searchParams.set('yearly_scope', yearlyScope);
                    browserUrl.searchParams.delete('ajax');
                    window.history.replaceState({}, '', browserUrl.toString());
                } catch (error) {
                    window.location.assign(link.href);
                } finally {
                    yearlyScopeRequestPending = false;
                    setYearlyScopeLoading(false);
                }
            });
        });
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCockpitProductivityPage);
} else {
    initCockpitProductivityPage();
}
