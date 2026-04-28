<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Manager — {{ auth()->user()->name }}</title>
    @vite(['resources/css/app.css'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-100 min-h-screen">
    <header class="bg-slate-800 text-white">
        <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
            <div>
                <h1 class="text-lg font-semibold">Manager Dashboard</h1>
                <p class="text-xs text-slate-300">{{ auth()->user()->name }} · {{ ucfirst(auth()->user()->role) }}</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="/waiter/dashboard" class="text-xs underline text-slate-200">Floor</a>
                <a href="/kitchen/dashboard" class="text-xs underline text-slate-200">Kitchen</a>
                <form method="POST" action="/logout">
                    @csrf
                    <button class="text-xs underline text-slate-200">Log out</button>
                </form>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-6"
          x-data="managerDashboard()"
          x-init="init()">

        <div class="flex gap-1 mb-4 border-b border-slate-200">
            <template x-for="t in tabs" :key="t.key">
                <button @click="setTab(t.key)"
                        :class="tab === t.key ? 'border-slate-800 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700'"
                        class="px-4 py-2 text-sm border-b-2 -mb-px"
                        x-text="t.label"></button>
            </template>
        </div>

        <div x-show="error" x-cloak class="mb-3 bg-red-100 text-red-700 text-sm rounded px-3 py-2" x-text="error"></div>

        <!-- Reports -->
        <section x-show="tab === 'reports'" x-cloak>
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-semibold text-slate-800">
                    <span x-text="reportPeriod === 'day' ? 'Today\'s P&L' : 'This month\'s P&L'"></span>
                </h2>
                <div class="flex gap-1 text-xs">
                    <button @click="setReportPeriod('day')"
                            :class="reportPeriod === 'day' ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-600'"
                            class="rounded px-3 py-1">Today</button>
                    <button @click="setReportPeriod('month')"
                            :class="reportPeriod === 'month' ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-600'"
                            class="rounded px-3 py-1">This month</button>
                </div>
            </div>

            <template x-if="report">
                <div class="space-y-3">
                    <div class="text-xs text-slate-500 mb-1" x-text="report.label"></div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div class="bg-white rounded-lg border border-slate-200 p-4">
                            <div class="text-xs uppercase tracking-wide text-emerald-700">Revenue (in)</div>
                            <div class="text-2xl font-semibold text-slate-800 mt-1">
                                KES <span x-text="formatKes(report.revenue.current)"></span>
                            </div>
                            <div class="text-xs text-slate-500 mt-1"
                                 x-text="previousLabel(report.period) + ': KES ' + formatKes(report.revenue.previous)"></div>
                        </div>
                        <div class="bg-white rounded-lg border border-slate-200 p-4">
                            <div class="text-xs uppercase tracking-wide text-red-700">Expenses (out)</div>
                            <div class="text-2xl font-semibold text-slate-800 mt-1">
                                KES <span x-text="formatKes(report.expenses.current)"></span>
                            </div>
                            <div class="text-xs text-slate-500 mt-1"
                                 x-text="previousLabel(report.period) + ': KES ' + formatKes(report.expenses.previous)"></div>
                        </div>
                        <div class="bg-white rounded-lg border border-slate-200 p-4"
                             :class="report.net.current >= 0 ? '' : 'border-red-300 ring-1 ring-red-100'">
                            <div class="text-xs uppercase tracking-wide text-slate-500">Net</div>
                            <div class="text-2xl font-semibold mt-1"
                                 :class="report.net.current >= 0 ? 'text-emerald-700' : 'text-red-700'">
                                KES <span x-text="formatKes(report.net.current)"></span>
                            </div>
                            <div class="text-xs text-slate-500 mt-1"
                                 x-text="previousLabel(report.period) + ': KES ' + formatKes(report.net.previous)"></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="bg-white rounded-lg border border-slate-200 p-4">
                            <div class="text-xs uppercase tracking-wide text-slate-500 mb-3">Revenue by method</div>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between"><span class="text-slate-600">Cash</span><span class="font-medium" x-text="'KES ' + formatKes(report.by_method.cash)"></span></div>
                                <div class="flex justify-between"><span class="text-slate-600">M-Pesa</span><span class="font-medium" x-text="'KES ' + formatKes(report.by_method.mpesa)"></span></div>
                            </div>
                        </div>
                        <div class="bg-white rounded-lg border border-slate-200 p-4">
                            <div class="text-xs uppercase tracking-wide text-slate-500 mb-3">Expenses by category</div>
                            <div class="space-y-1.5 text-sm">
                                <template x-for="(amount, cat) in report.expenses_by_category" :key="cat">
                                    <div class="flex justify-between" x-show="amount > 0">
                                        <span class="text-slate-600 capitalize" x-text="cat"></span>
                                        <span class="font-medium" x-text="'KES ' + formatKes(amount)"></span>
                                    </div>
                                </template>
                                <template x-if="!hasAnyExpenses(report.expenses_by_category)">
                                    <div class="text-xs text-slate-400 italic">No expenses recorded yet.</div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border border-slate-200 p-4">
                        <div class="text-xs uppercase tracking-wide text-slate-500 mb-3">Top items</div>
                        <template x-if="report.top_items.length === 0">
                            <div class="text-xs text-slate-400 italic">No items sold yet.</div>
                        </template>
                        <div class="space-y-1.5 text-sm">
                            <template x-for="item in report.top_items" :key="item.name">
                                <div class="flex justify-between">
                                    <span class="text-slate-700">
                                        <span x-text="item.quantity"></span>×
                                        <span x-text="item.name"></span>
                                    </span>
                                    <span class="text-slate-500" x-text="'KES ' + formatKes(item.revenue)"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </template>
            <template x-if="!report">
                <div class="text-sm text-slate-500 py-10 text-center">Loading…</div>
            </template>
        </section>

        <!-- Expenses -->
        <section x-show="tab === 'expenses'" x-cloak>
            <div class="flex justify-between items-center mb-3">
                <h2 class="font-semibold text-slate-800">Expenses</h2>
                <button @click="newExpenseOpen = true" class="text-sm bg-slate-800 text-white rounded px-3 py-1.5">+ Record expense</button>
            </div>
            <div class="bg-white rounded shadow-sm border border-slate-200 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                        <tr>
                            <th class="px-3 py-2 text-left">Date</th>
                            <th class="px-3 py-2 text-left">Category</th>
                            <th class="px-3 py-2 text-left">Description</th>
                            <th class="px-3 py-2 text-right">Amount</th>
                            <th class="px-3 py-2 text-left">By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="e in expenses" :key="e.id">
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-2 text-slate-600" x-text="e.incurred_on"></td>
                                <td class="px-3 py-2"><span class="text-xs uppercase bg-slate-100 text-slate-700 rounded px-1.5 py-0.5" x-text="e.category"></span></td>
                                <td class="px-3 py-2" x-text="e.description"></td>
                                <td class="px-3 py-2 text-right font-medium" x-text="'KES ' + formatKes(e.amount)"></td>
                                <td class="px-3 py-2 text-slate-500" x-text="e.recorded_by?.name || ''"></td>
                            </tr>
                        </template>
                        <template x-if="expenses.length === 0">
                            <tr><td colspan="5" class="px-3 py-4 text-center text-xs text-slate-400 italic">No expenses recorded.</td></tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- New expense modal -->
        <div x-show="newExpenseOpen" x-cloak class="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-sm p-5">
                <h3 class="font-semibold text-slate-800 mb-3">Record expense</h3>
                <div class="space-y-2">
                    <input type="number" step="0.01" x-model.number="newExpense.amount" placeholder="Amount (KES)" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <select x-model="newExpense.category" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <template x-for="c in categories" :key="c">
                            <option :value="c" x-text="c"></option>
                        </template>
                    </select>
                    <input type="text" x-model="newExpense.description" placeholder="Description" maxlength="255" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <input type="date" x-model="newExpense.incurred_on" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="newExpenseOpen = false" class="text-sm text-slate-600 px-3 py-2">Cancel</button>
                    <button @click="createExpense()" class="text-sm bg-slate-800 text-white rounded px-4 py-2">Save</button>
                </div>
            </div>
        </div>
    </main>

    <script>
        const API_TOKEN = @json(session('api_token'));
        const TODAY = @json(\Carbon\Carbon::today()->toDateString());

        function api(path, options = {}) {
            return fetch('/api' + path, {
                ...options,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + API_TOKEN,
                    ...(options.headers || {}),
                },
            }).then(async (r) => {
                if (r.status === 204) return null;
                const body = await r.json().catch(() => ({}));
                if (!r.ok) throw new Error(body.message || ('HTTP ' + r.status));
                return body;
            });
        }

        function managerDashboard() {
            return {
                tabs: [
                    { key: 'reports',  label: 'Reports' },
                    { key: 'expenses', label: 'Expenses' },
                ],
                tab: 'reports',
                error: '',

                report: null,
                reportPeriod: 'day',

                expenses: [],
                categories: ['supplies', 'salaries', 'utilities', 'rent', 'transport', 'other'],

                newExpenseOpen: false,
                newExpense: { amount: 0, category: 'supplies', description: '', incurred_on: TODAY },

                async init() {
                    await this.loadReport();
                    await this.loadExpenses();
                },

                async setTab(key) {
                    this.tab = key;
                    if (key === 'expenses') await this.loadExpenses();
                    if (key === 'reports' && !this.report) await this.loadReport();
                },

                async loadReport() {
                    try {
                        this.report = await api('/reports/' + (this.reportPeriod === 'day' ? 'today' : 'month'));
                    } catch (e) { this.error = e.message; }
                },

                async setReportPeriod(period) {
                    if (this.reportPeriod === period) return;
                    this.reportPeriod = period;
                    this.report = null;
                    await this.loadReport();
                },

                async loadExpenses() {
                    try {
                        this.expenses = await api('/expenses');
                    } catch (e) { this.error = e.message; }
                },

                async createExpense() {
                    if (!this.newExpense.amount || this.newExpense.amount <= 0) {
                        this.error = 'Amount must be greater than 0.';
                        return;
                    }
                    if (!this.newExpense.description.trim()) {
                        this.error = 'Description is required.';
                        return;
                    }
                    try {
                        await api('/expenses', { method: 'POST', body: JSON.stringify(this.newExpense) });
                        this.newExpenseOpen = false;
                        this.newExpense = { amount: 0, category: 'supplies', description: '', incurred_on: TODAY };
                        await Promise.all([this.loadExpenses(), this.loadReport()]);
                    } catch (e) { this.error = e.message; }
                },

                hasAnyExpenses(byCat) {
                    return Object.values(byCat || {}).some(v => v > 0);
                },

                formatKes(n) {
                    return Number(n || 0).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                },

                previousLabel(period) {
                    return period === 'day' ? 'yesterday' : 'last month';
                },
            };
        }
    </script>
    <style>[x-cloak] { display: none !important; }</style>
</body>
</html>
