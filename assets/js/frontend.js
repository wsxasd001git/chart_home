(function () {
    'use strict';

    let allData = [];
    let chart = null;

    const COLORS = {
        strategy: '#4CAF50',
        strategyBg: 'rgba(76, 175, 80, 0.1)',
        mcftr: '#9C27B0',
        mcftrBg: 'rgba(156, 39, 176, 0.1)',
        annotation: 'rgba(100, 100, 100, 0.6)',
    };

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        fetchData().then(function (data) {
            if (!data || data.length === 0) return;
            allData = data;
            populateSelects();
            buildChart(allData);
            bindEvents();
        });
    }

    function fetchData() {
        return fetch(tscConfig.ajaxUrl + '?action=tsc_get_data')
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.success) return resp.data;
                return [];
            })
            .catch(function () { return []; });
    }

    function populateSelects() {
        var fromSel = document.getElementById('tsc-date-from');
        var toSel = document.getElementById('tsc-date-to');
        if (!fromSel || !toSel) return;

        fromSel.innerHTML = '';
        toSel.innerHTML = '';

        allData.forEach(function (d, i) {
            var opt1 = document.createElement('option');
            opt1.value = i;
            opt1.textContent = d.label;
            fromSel.appendChild(opt1);

            var opt2 = document.createElement('option');
            opt2.value = i;
            opt2.textContent = d.label;
            toSel.appendChild(opt2);
        });

        fromSel.selectedIndex = 0;
        toSel.selectedIndex = allData.length - 1;
    }

    function bindEvents() {
        var btn = document.getElementById('tsc-calculate');
        if (btn) {
            btn.addEventListener('click', calculate);
        }

        var amountInput = document.getElementById('tsc-amount');
        if (amountInput) {
            amountInput.addEventListener('input', function () {
                var raw = this.value.replace(/\D/g, '');
                if (raw) {
                    this.value = Number(raw).toLocaleString('ru-RU');
                }
            });
        }
    }

    function getAmountValue() {
        var input = document.getElementById('tsc-amount');
        if (!input) return 500000;
        var raw = input.value.replace(/[^\d]/g, '');
        return parseInt(raw, 10) || 0;
    }

    function calculate() {
        var fromIdx = parseInt(document.getElementById('tsc-date-from').value, 10);
        var toIdx = parseInt(document.getElementById('tsc-date-to').value, 10);

        if (fromIdx >= toIdx) {
            alert('Дата подключения должна быть раньше даты отключения');
            return;
        }

        var amount = getAmountValue();
        var sliced = allData.slice(fromIdx, toIdx + 1);

        // Calculate strategy return between dates
        var startVal = allData[fromIdx].sc_top10;
        var endVal = allData[toIdx].sc_top10;
        var returnPct = ((endVal - startVal) / startVal) * 100;
        var resultMoney = amount * (returnPct / 100);

        // Show results
        var resultsEl = document.getElementById('tsc-results');
        resultsEl.style.display = 'flex';

        var moneyEl = document.getElementById('tsc-result-money');
        var pctEl = document.getElementById('tsc-result-pct');

        var sign = resultMoney >= 0 ? '+' : '';
        moneyEl.textContent = sign + formatMoney(resultMoney) + ' \u20BD';
        moneyEl.className = 'tsc-result-value ' + (resultMoney >= 0 ? 'tsc-positive' : 'tsc-negative');

        pctEl.textContent = sign + returnPct.toFixed(2) + '%';
        pctEl.className = 'tsc-result-pct ' + (returnPct >= 0 ? 'tsc-positive' : 'tsc-negative');

        // Rebuild chart for selected period with annotations
        buildChart(sliced, fromIdx, toIdx);
    }

    function buildChart(data, fromIdx, toIdx) {
        var ctx = document.getElementById('tsc-chart');
        if (!ctx) return;

        if (chart) {
            chart.destroy();
        }

        var labels = data.map(function (d) { return d.label; });

        // Normalize to percentage change from start
        var startMcftr = data[0].mcftr;
        var startSc = data[0].sc_top10;

        var mcftrNorm = data.map(function (d) {
            return ((d.mcftr - startMcftr) / startMcftr) * 100;
        });
        var scNorm = data.map(function (d) {
            return ((d.sc_top10 - startSc) / startSc) * 100;
        });

        var annotations = {};

        if (fromIdx !== undefined && toIdx !== undefined) {
            annotations.lineStart = {
                type: 'line',
                xMin: 0,
                xMax: 0,
                borderColor: COLORS.annotation,
                borderWidth: 1,
                borderDash: [4, 4],
                label: {
                    display: true,
                    content: data[0].label,
                    position: 'start',
                    backgroundColor: 'rgba(0,0,0,0.6)',
                    color: '#fff',
                    font: { size: 11 },
                },
            };
            annotations.lineEnd = {
                type: 'line',
                xMin: data.length - 1,
                xMax: data.length - 1,
                borderColor: COLORS.annotation,
                borderWidth: 1,
                borderDash: [4, 4],
                label: {
                    display: true,
                    content: data[data.length - 1].label,
                    position: 'start',
                    backgroundColor: 'rgba(0,0,0,0.6)',
                    color: '#fff',
                    font: { size: 11 },
                },
            };
        }

        chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'SC TOP 10',
                        data: scNorm,
                        borderColor: COLORS.strategy,
                        backgroundColor: COLORS.strategyBg,
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        tension: 0.3,
                        fill: false,
                    },
                    {
                        label: 'MCFTR',
                        data: mcftrNorm,
                        borderColor: COLORS.mcftr,
                        backgroundColor: COLORS.mcftrBg,
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        tension: 0.3,
                        fill: false,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 20,
                            font: { size: 13 },
                        },
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0,0,0,0.8)',
                        titleFont: { size: 13 },
                        bodyFont: { size: 12 },
                        padding: 12,
                        callbacks: {
                            title: function (items) {
                                return items[0].label;
                            },
                            label: function (context) {
                                var idx = context.dataIndex;
                                var d = data[idx];
                                if (context.dataset.label === 'SC TOP 10') {
                                    return 'SC TOP 10: ' + context.parsed.y.toFixed(2) + '% (' + formatMoney(d.sc_top10) + ' \u20BD)';
                                } else {
                                    return 'MCFTR: ' + context.parsed.y.toFixed(2) + '% (' + formatMoney(d.mcftr) + ')';
                                }
                            },
                        },
                    },
                    annotation: {
                        annotations: annotations,
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            maxRotation: 45,
                            autoSkip: true,
                            maxTicksLimit: 12,
                            font: { size: 11 },
                        },
                    },
                    y: {
                        grid: { color: 'rgba(0,0,0,0.06)' },
                        ticks: {
                            callback: function (value) {
                                return value.toFixed(0) + '%';
                            },
                            font: { size: 11 },
                        },
                    },
                },
            },
        });
    }

    function formatMoney(value) {
        var num = Math.round(value);
        return num.toLocaleString('ru-RU');
    }
})();
