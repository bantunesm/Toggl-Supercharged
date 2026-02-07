const initCockpitProductivityPage = () => {
    const pageData = window.__COCKPIT_PRODUCTIVITY__;
    if (!pageData || typeof pageData !== 'object') {
        return;
    }

    const monthlyLabels = pageData.monthlyLabels ?? [];
    const monthlyHours = pageData.monthlyHours ?? [];
    const yearlyLabels = pageData.yearlyLabels ?? [];
    const yearlyHours = pageData.yearlyHours ?? [];
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

        activateTab(buttons[0].dataset.tabTarget);
    });

    if (typeof window.Chart !== 'function') {
        return;
    }

    const axisColor = '#334155';
    const gridColor = 'rgba(100, 116, 139, 0.16)';
    const monthlyChartCanvas = document.getElementById('monthlyChart');
    const yearlyChartCanvas = document.getElementById('yearlyChart');

    if (monthlyChartCanvas) {
        new window.Chart(monthlyChartCanvas, {
            type: 'bar',
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: 'Heures',
                    data: monthlyHours,
                    borderRadius: 8,
                    backgroundColor: 'rgba(15, 118, 110, 0.76)',
                    hoverBackgroundColor: 'rgba(13, 148, 136, 0.88)',
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
                        grid: { color: gridColor },
                        ticks: { color: axisColor },
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: axisColor },
                    },
                },
            },
        });
    }

    if (yearlyChartCanvas) {
        new window.Chart(yearlyChartCanvas, {
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
                    pointBackgroundColor: '#0e7490',
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
                        grid: { color: gridColor },
                        ticks: { color: axisColor },
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: axisColor },
                    },
                },
            },
        });
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCockpitProductivityPage);
} else {
    initCockpitProductivityPage();
}
