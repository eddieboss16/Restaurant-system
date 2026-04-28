<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Admin — {{ auth()->user()->name }}</title>
    @vite(['resources/css/app.css'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-100 min-h-screen">
    <header class="bg-slate-900 text-white">
        <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
            <div>
                <h1 class="text-lg font-semibold">Admin Dashboard</h1>
                <p class="text-xs text-slate-300">{{ auth()->user()->name }} · Owner</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="/waiter/dashboard" class="text-xs underline text-slate-200">Waiter view</a>
                <a href="/kitchen/dashboard" class="text-xs underline text-slate-200">Kitchen view</a>
                <form method="POST" action="/logout">
                    @csrf
                    <button class="text-xs underline text-slate-200">Log out</button>
                </form>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-6"
          x-data="adminDashboard()"
          x-init="init()">

        <!-- Low-stock warning -->
        <div x-show="lowStock.length > 0" x-cloak
             class="mb-4 bg-amber-50 border border-amber-300 rounded-lg p-3 flex items-start justify-between text-sm">
            <div class="flex-1">
                <div class="font-medium text-amber-900">Low stock</div>
                <div class="text-xs text-amber-800 mt-0.5">
                    <template x-for="(r, idx) in lowStock" :key="r.id">
                        <span>
                            <span x-text="r.name + ' (' + Number(r.current_stock).toFixed(0) + r.unit + ' left, threshold ' + Number(r.low_stock_threshold).toFixed(0) + r.unit + ')'"></span><span x-show="idx < lowStock.length - 1">, </span>
                        </span>
                    </template>
                </div>
            </div>
            <button @click="tab = 'resources'" class="text-xs text-amber-900 underline ml-3 shrink-0">Restock</button>
        </div>

        <div class="flex gap-1 mb-4 border-b border-slate-200">
            <template x-for="t in tabs" :key="t.key">
                <button @click="tab = t.key"
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
                    <span x-text="reportPeriod === 'day' ? 'Today\'s snapshot' : 'This month\'s snapshot'"></span>
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
                        <div class="bg-white rounded-lg border border-slate-200 p-4 md:col-span-1">
                            <div class="text-xs uppercase tracking-wide text-slate-500" x-text="'Revenue ' + currentLabel(report.period)"></div>
                            <div class="text-2xl font-semibold text-slate-800 mt-1">
                                KES <span x-text="formatKes(report.revenue.current)"></span>
                            </div>
                            <div class="text-xs mt-1"
                                 :class="deltaClass(report.revenue.current, report.revenue.previous)"
                                 x-text="deltaLabel(report.revenue.current, report.revenue.previous) + ' vs ' + previousLabel(report.period) + ' (KES ' + formatKes(report.revenue.previous) + ')'"></div>
                        </div>

                        <div class="bg-white rounded-lg border border-slate-200 p-4">
                            <div class="text-xs uppercase tracking-wide text-slate-500">Sessions paid</div>
                            <div class="text-2xl font-semibold text-slate-800 mt-1" x-text="report.sessions_paid.current"></div>
                            <div class="text-xs text-slate-500 mt-1"
                                 x-text="previousLabel(report.period) + ': ' + report.sessions_paid.previous"></div>
                        </div>

                        <div class="bg-white rounded-lg border border-slate-200 p-4">
                            <div class="text-xs uppercase tracking-wide text-slate-500">Cancellations</div>
                            <div class="text-2xl font-semibold text-slate-800 mt-1" x-text="report.cancellations"></div>
                            <div class="text-xs text-slate-500 mt-1" x-text="currentLabel(report.period)"></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="bg-white rounded-lg border border-slate-200 p-4">
                            <div class="text-xs uppercase tracking-wide text-slate-500 mb-3">By payment method</div>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-slate-600">Cash</span>
                                    <span class="font-medium" x-text="'KES ' + formatKes(report.by_method.cash)"></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-600">M-Pesa</span>
                                    <span class="font-medium" x-text="'KES ' + formatKes(report.by_method.mpesa)"></span>
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
                </div>
            </template>
            <template x-if="!report">
                <div class="text-sm text-slate-500 py-10 text-center">Loading…</div>
            </template>
        </section>

        <!-- Staff -->
        <section x-show="tab === 'staff'" x-cloak>
            <div class="flex justify-between items-center mb-3">
                <h2 class="font-semibold text-slate-800">Staff</h2>
                <button @click="newStaffOpen = true" class="text-sm bg-slate-800 text-white rounded px-3 py-1.5">+ Add staff</button>
            </div>
            <div class="bg-white rounded shadow-sm border border-slate-200 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                        <tr>
                            <th class="px-3 py-2 text-left">Name</th>
                            <th class="px-3 py-2 text-left">Email</th>
                            <th class="px-3 py-2 text-left">Role</th>
                            <th class="px-3 py-2 text-left">PIN</th>
                            <th class="px-3 py-2 text-left">Active</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="u in staff" :key="u.id">
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-2">
                                    <span x-text="u.name"></span>
                                    <span x-show="u.is_primary_admin"
                                          class="ml-2 text-[10px] uppercase tracking-wide bg-amber-100 text-amber-800 rounded px-1.5 py-0.5">Owner</span>
                                </td>
                                <td class="px-3 py-2 text-slate-600" x-text="u.email"></td>
                                <td class="px-3 py-2">
                                    <select :value="u.role" @change="updateStaff(u, { role: $event.target.value })"
                                            :disabled="lockedFor(u)"
                                            :title="lockedFor(u) ? 'The owner cannot be demoted by another admin.' : ''"
                                            class="text-xs rounded border border-slate-300 px-1 py-0.5 disabled:opacity-40 disabled:cursor-not-allowed">
                                        <option value="waiter">waiter</option>
                                        <option value="kitchen">kitchen</option>
                                        <option value="manager">manager</option>
                                        <option value="admin">admin</option>
                                    </select>
                                </td>
                                <td class="px-3 py-2 text-slate-500" x-text="u.pin || '—'"></td>
                                <td class="px-3 py-2">
                                    <button @click="updateStaff(u, { is_active: !u.is_active })"
                                            :disabled="lockedFor(u)"
                                            :title="lockedFor(u) ? 'The owner cannot be deactivated by another admin.' : ''"
                                            :class="u.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'"
                                            class="text-xs rounded px-2 py-0.5 disabled:opacity-40 disabled:cursor-not-allowed"
                                            x-text="u.is_active ? 'active' : 'disabled'"></button>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button @click="resetPasswordFor = u; newPassword = ''"
                                            class="text-xs text-slate-500 hover:text-slate-800 underline">Reset password</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Menu items -->
        <section x-show="tab === 'menu'" x-cloak>
            <div class="flex justify-between items-center mb-3">
                <h2 class="font-semibold text-slate-800">Menu items</h2>
                <button @click="newItemOpen = true" class="text-sm bg-slate-800 text-white rounded px-3 py-1.5">+ Add item</button>
            </div>
            <div class="bg-white rounded shadow-sm border border-slate-200 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                        <tr>
                            <th class="px-3 py-2 text-left">Name</th>
                            <th class="px-3 py-2 text-left">Category</th>
                            <th class="px-3 py-2 text-left">Price (KES)</th>
                            <th class="px-3 py-2 text-left">Available</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="m in menuItems" :key="m.id">
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-2" x-text="m.name"></td>
                                <td class="px-3 py-2 text-slate-500" x-text="m.category || '—'"></td>
                                <td class="px-3 py-2">
                                    <input type="number" step="0.01" :value="m.price"
                                           @change="updateMenuItem(m, { price: parseFloat($event.target.value) })"
                                           class="w-24 text-sm rounded border border-slate-300 px-2 py-0.5">
                                </td>
                                <td class="px-3 py-2">
                                    <button @click="updateMenuItem(m, { is_available: !m.is_available })"
                                            :class="m.is_available ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'"
                                            class="text-xs rounded px-2 py-0.5"
                                            x-text="m.is_available ? 'on menu' : 'hidden'"></button>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button @click="deleteMenuItem(m)" class="text-xs text-red-600 hover:underline">Delete</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Resources -->
        <section x-show="tab === 'resources'" x-cloak>
            <h2 class="font-semibold text-slate-800 mb-3">Inventory</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <template x-for="r in resources" :key="r.id">
                    <div class="bg-white rounded shadow-sm border border-slate-200 p-3"
                         :class="r.low_stock ? 'border-red-300 ring-1 ring-red-100' : ''">
                        <div class="flex justify-between items-start">
                            <div>
                                <div class="font-medium text-slate-800" x-text="r.name"></div>
                                <div class="text-xs text-slate-500" x-text="'Last restocked: ' + (r.last_restocked_at ? new Date(r.last_restocked_at).toLocaleString() : 'never')"></div>
                            </div>
                            <div class="text-right">
                                <div class="text-lg font-semibold" x-text="Number(r.current_stock).toFixed(0) + ' ' + r.unit"></div>
                                <div class="text-xs" :class="r.low_stock ? 'text-red-600' : 'text-slate-500'"
                                     x-text="'threshold: ' + Number(r.low_stock_threshold).toFixed(0)"></div>
                            </div>
                        </div>
                        <div class="flex gap-2 items-center mt-3">
                            <input type="number" step="any" min="0.001" placeholder="amount" x-model.number="restockAmounts[r.id]"
                                   class="w-24 text-sm rounded border border-slate-300 px-2 py-1">
                            <input type="text" placeholder="reason (optional)" x-model="restockReasons[r.id]"
                                   class="flex-1 text-sm rounded border border-slate-300 px-2 py-1">
                            <button @click="restock(r)" class="text-xs bg-emerald-600 text-white rounded px-3 py-1.5">Restock</button>
                        </div>
                        <div class="flex gap-2 items-center mt-2">
                            <label class="text-xs text-slate-500">Threshold:</label>
                            <input type="number" step="any" min="0" :value="r.low_stock_threshold"
                                   @change="updateResource(r, { low_stock_threshold: parseFloat($event.target.value) })"
                                   class="w-24 text-sm rounded border border-slate-300 px-2 py-1">
                        </div>
                    </div>
                </template>
            </div>
        </section>

        <!-- Cancellations -->
        <section x-show="tab === 'cancellations'" x-cloak>
            <h2 class="font-semibold text-slate-800 mb-3">Recent cancellations</h2>
            <div class="bg-white rounded shadow-sm border border-slate-200 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                        <tr>
                            <th class="px-3 py-2 text-left">When</th>
                            <th class="px-3 py-2 text-left">Item</th>
                            <th class="px-3 py-2 text-left">By</th>
                            <th class="px-3 py-2 text-left">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="c in cancellations" :key="c.id">
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-2 text-slate-600" x-text="new Date(c.cancelled_at).toLocaleString()"></td>
                                <td class="px-3 py-2" x-text="c.order?.menu_item?.name || ('order #' + c.order_id)"></td>
                                <td class="px-3 py-2 text-slate-600" x-text="c.cancelled_by?.name || '—'"></td>
                                <td class="px-3 py-2" x-text="c.reason"></td>
                            </tr>
                        </template>
                        <template x-if="cancellations.length === 0">
                            <tr><td colspan="4" class="px-3 py-3 text-center text-slate-500 italic text-xs">No cancellations recorded.</td></tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- New staff modal -->
        <div x-show="newStaffOpen" x-cloak class="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-sm p-5">
                <h3 class="font-semibold text-slate-800 mb-3">Add staff</h3>
                <div class="space-y-2">
                    <input x-model="newStaff.name" placeholder="Name" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <input x-model="newStaff.email" type="email" placeholder="Email" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <input x-model="newStaff.password" type="password" placeholder="Password (min 8)" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <select x-model="newStaff.role" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <option value="waiter">waiter</option>
                        <option value="kitchen">kitchen</option>
                        <option value="manager">manager</option>
                        <option value="admin">admin</option>
                    </select>
                    <input x-model="newStaff.pin" placeholder="PIN (4 digits, optional)" maxlength="4" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="newStaffOpen = false" class="text-sm text-slate-600 px-3 py-2">Cancel</button>
                    <button @click="createStaff()" class="text-sm bg-slate-800 text-white rounded px-4 py-2">Create</button>
                </div>
            </div>
        </div>

        <!-- New menu item modal -->
        <div x-show="newItemOpen" x-cloak class="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-sm p-5">
                <h3 class="font-semibold text-slate-800 mb-3">Add menu item</h3>
                <div class="space-y-2">
                    <input x-model="newItem.name" placeholder="Name" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <input x-model.number="newItem.price" type="number" step="0.01" placeholder="Price (KES)" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <input x-model="newItem.category" placeholder="Category (food, drinks, …)" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="newItemOpen = false" class="text-sm text-slate-600 px-3 py-2">Cancel</button>
                    <button @click="createMenuItem()" class="text-sm bg-slate-800 text-white rounded px-4 py-2">Create</button>
                </div>
            </div>
        </div>

        <!-- Reset password modal -->
        <div x-show="resetPasswordFor" x-cloak class="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-sm p-5">
                <h3 class="font-semibold text-slate-800 mb-1">Reset password</h3>
                <p class="text-xs text-slate-500 mb-3" x-show="resetPasswordFor" x-text="'For: ' + resetPasswordFor?.name"></p>
                <input x-model="newPassword" type="password" placeholder="New password (min 8)" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="resetPasswordFor = null" class="text-sm text-slate-600 px-3 py-2">Cancel</button>
                    <button @click="submitPasswordReset()" class="text-sm bg-slate-800 text-white rounded px-4 py-2">Save</button>
                </div>
            </div>
        </div>
    </main>

    <script>
        const API_TOKEN = @json(session('api_token'));
        const CURRENT_USER_ID = @json(auth()->user()->id);

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

        function adminDashboard() {
            return {
                tabs: [
                    { key: 'reports',       label: 'Reports' },
                    { key: 'staff',         label: 'Staff' },
                    { key: 'menu',          label: 'Menu' },
                    { key: 'resources',     label: 'Inventory' },
                    { key: 'cancellations', label: 'Cancellations' },
                ],
                tab: 'reports',
                error: '',

                staff: [],
                menuItems: [],
                resources: [],
                cancellations: [],
                report: null,
                reportPeriod: 'day',
                lowStock: [],

                restockAmounts: {},
                restockReasons: {},

                newStaffOpen: false,
                newStaff: { name: '', email: '', password: '', role: 'waiter', pin: '' },

                newItemOpen: false,
                newItem: { name: '', price: 0, category: '' },

                resetPasswordFor: null,
                newPassword: '',

                async init() {
                    await this.loadAll();
                },

                lockedFor(u) {
                    return u.is_primary_admin && u.id !== CURRENT_USER_ID;
                },

                async loadAll() {
                    try {
                        const [staff, menu, res, canc, report, lowStock] = await Promise.all([
                            api('/admin/staff'),
                            api('/admin/menu-items'),
                            api('/admin/resources'),
                            api('/admin/cancellations'),
                            api('/reports/' + (this.reportPeriod === 'day' ? 'today' : 'month')),
                            api('/inventory/low-stock'),
                        ]);
                        this.staff = staff;
                        this.menuItems = menu;
                        this.resources = res;
                        this.cancellations = canc;
                        this.report = report;
                        this.lowStock = lowStock;
                    } catch (e) {
                        this.error = e.message;
                    }
                },

                async setReportPeriod(period) {
                    if (this.reportPeriod === period) return;
                    this.reportPeriod = period;
                    this.report = null;
                    try {
                        this.report = await api('/reports/' + (period === 'day' ? 'today' : 'month'));
                    } catch (e) {
                        this.error = e.message;
                    }
                },

                currentLabel(period) {
                    return period === 'day' ? 'today' : 'this month';
                },

                previousLabel(period) {
                    return period === 'day' ? 'yesterday' : 'last month';
                },

                formatKes(n) {
                    return Number(n || 0).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                },

                deltaLabel(current, previous) {
                    if (!previous || previous === 0) {
                        return current > 0 ? '+new' : 'no activity';
                    }
                    const pct = ((current - previous) / previous) * 100;
                    const sign = pct >= 0 ? '+' : '';
                    return sign + pct.toFixed(1) + '%';
                },

                deltaClass(current, previous) {
                    if (current === previous) return 'text-slate-500';
                    return current > previous ? 'text-emerald-600' : 'text-red-600';
                },

                async createStaff() {
                    try {
                        await api('/admin/staff', { method: 'POST', body: JSON.stringify(this.newStaff) });
                        this.newStaffOpen = false;
                        this.newStaff = { name: '', email: '', password: '', role: 'waiter', pin: '' };
                        await this.loadAll();
                    } catch (e) { this.error = e.message; }
                },

                async updateStaff(user, patch) {
                    try {
                        await api('/admin/staff/' + user.id, { method: 'PATCH', body: JSON.stringify(patch) });
                        await this.loadAll();
                    } catch (e) { this.error = e.message; }
                },

                async submitPasswordReset() {
                    if (!this.resetPasswordFor || this.newPassword.length < 8) {
                        this.error = 'Password must be at least 8 characters.';
                        return;
                    }
                    try {
                        await api('/admin/staff/' + this.resetPasswordFor.id, {
                            method: 'PATCH',
                            body: JSON.stringify({ password: this.newPassword }),
                        });
                        this.resetPasswordFor = null;
                        this.newPassword = '';
                    } catch (e) { this.error = e.message; }
                },

                async createMenuItem() {
                    try {
                        await api('/admin/menu-items', { method: 'POST', body: JSON.stringify(this.newItem) });
                        this.newItemOpen = false;
                        this.newItem = { name: '', price: 0, category: '' };
                        await this.loadAll();
                    } catch (e) { this.error = e.message; }
                },

                async updateMenuItem(item, patch) {
                    try {
                        await api('/admin/menu-items/' + item.id, { method: 'PATCH', body: JSON.stringify(patch) });
                        await this.loadAll();
                    } catch (e) { this.error = e.message; }
                },

                async deleteMenuItem(item) {
                    if (!confirm('Delete ' + item.name + '?')) return;
                    try {
                        await api('/admin/menu-items/' + item.id, { method: 'DELETE' });
                        await this.loadAll();
                    } catch (e) { this.error = e.message; }
                },

                async updateResource(resource, patch) {
                    try {
                        await api('/admin/resources/' + resource.id, { method: 'PATCH', body: JSON.stringify(patch) });
                        await this.loadAll();
                    } catch (e) { this.error = e.message; }
                },

                async restock(resource) {
                    const amount = this.restockAmounts[resource.id];
                    if (!amount || amount <= 0) {
                        this.error = 'Enter a restock amount.';
                        return;
                    }
                    try {
                        await api('/admin/resources/' + resource.id + '/restock', {
                            method: 'POST',
                            body: JSON.stringify({ amount, reason: this.restockReasons[resource.id] || null }),
                        });
                        this.restockAmounts[resource.id] = null;
                        this.restockReasons[resource.id] = '';
                        await this.loadAll();
                    } catch (e) { this.error = e.message; }
                },
            };
        }
    </script>
    <style>[x-cloak] { display: none !important; }</style>
</body>
</html>
