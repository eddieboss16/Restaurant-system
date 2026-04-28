<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Kitchen — {{ auth()->user()->name }}</title>
    @vite(['resources/css/app.css'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen">
    <header class="bg-slate-800 border-b border-slate-700">
        <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
            <div>
                <h1 class="text-lg font-semibold">Kitchen</h1>
                <p class="text-xs text-slate-400">{{ auth()->user()->name }} · {{ ucfirst(auth()->user()->role) }}</p>
            </div>
            <div class="flex items-center gap-3">
                @if (auth()->user()->isAdmin())
                    <a href="/admin/dashboard" class="text-xs text-slate-300 hover:text-white underline">Admin</a>
                @endif
                @if (auth()->user()->isAdmin() || auth()->user()->isManager())
                    <a href="/waiter/dashboard" class="text-xs text-slate-300 hover:text-white underline">Floor</a>
                @endif
                <form method="POST" action="/logout">
                    @csrf
                    <button class="text-xs text-slate-300 hover:text-white underline">Log out</button>
                </form>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-6"
          x-data="kitchenQueue()"
          x-init="init()">

        <div class="flex items-center justify-between mb-4">
            <div class="flex gap-1 text-xs">
                <button @click="setView('queue')"
                        :class="view === 'queue' ? 'bg-slate-700 text-white' : 'bg-slate-800 text-slate-400'"
                        class="rounded px-3 py-1.5">
                    Queue
                    <span x-show="view === 'queue'" class="ml-1 opacity-70" x-text="'(' + orders.length + ')'"></span>
                </button>
                <button @click="setView('history')"
                        :class="view === 'history' ? 'bg-slate-700 text-white' : 'bg-slate-800 text-slate-400'"
                        class="rounded px-3 py-1.5">History (last 50)</button>
            </div>
            <div class="flex items-center gap-2">
                <span x-show="loading" class="text-xs text-slate-500">refreshing…</span>
                <div x-show="error" x-cloak class="text-xs bg-red-900/40 text-red-300 rounded px-2 py-1" x-text="error"></div>
            </div>
        </div>

        <!-- Queue view -->
        <div x-show="view === 'queue'" x-cloak>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                <template x-for="o in orders" :key="o.id">
                    <div class="rounded-lg p-3 border"
                         :class="cardClass(o.status)">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-xs uppercase tracking-wide" x-text="o.status"></div>
                                <div class="text-base font-semibold mt-0.5">
                                    <span x-text="o.quantity + '×'"></span>
                                    <span x-text="o.menu_item.name"></span>
                                </div>
                                <div class="text-xs text-slate-300 mt-0.5"
                                     x-text="(o.session.customer_label || 'unlabeled') + ' · ' + (o.session.waiter?.name || '?')"></div>
                                <div class="text-xs text-amber-300 italic mt-1" x-show="o.notes" x-text="'Note: ' + o.notes"></div>
                            </div>
                            <div class="text-xs text-slate-400" x-text="formatAge(o.created_at)"></div>
                        </div>

                        <div class="flex gap-2 mt-3">
                            <template x-if="o.status === 'pending'">
                                <button @click="setStatus(o, 'preparing')"
                                        class="flex-1 text-xs bg-blue-600 hover:bg-blue-500 rounded py-1.5">Start cooking</button>
                            </template>
                            <template x-if="o.status === 'preparing'">
                                <button @click="setStatus(o, 'ready')"
                                        class="flex-1 text-xs bg-amber-600 hover:bg-amber-500 rounded py-1.5">Mark ready</button>
                            </template>
                            <template x-if="o.status === 'ready'">
                                <span class="flex-1 text-center text-xs text-emerald-300 py-1.5">Waiting for waiter</span>
                            </template>
                        </div>
                    </div>
                </template>
                <template x-if="orders.length === 0 && !loading">
                    <div class="col-span-full text-center text-slate-500 py-10 text-sm">
                        Nothing in the queue. 🎉
                    </div>
                </template>
            </div>
        </div>

        <!-- History view -->
        <div x-show="view === 'history'" x-cloak>
            <div class="bg-slate-800 rounded-lg border border-slate-700 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-900 text-xs text-slate-400 uppercase">
                        <tr>
                            <th class="px-3 py-2 text-left">Item</th>
                            <th class="px-3 py-2 text-left">Customer</th>
                            <th class="px-3 py-2 text-left">Waiter</th>
                            <th class="px-3 py-2 text-left">Notes</th>
                            <th class="px-3 py-2 text-right">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="o in history" :key="o.id">
                            <tr class="border-t border-slate-700">
                                <td class="px-3 py-2">
                                    <span class="font-medium" x-text="o.quantity + '× ' + o.menu_item.name"></span>
                                </td>
                                <td class="px-3 py-2 text-slate-300" x-text="o.session.customer_label || 'unlabeled'"></td>
                                <td class="px-3 py-2 text-slate-300" x-text="o.session.waiter?.name || '?'"></td>
                                <td class="px-3 py-2 text-slate-400 italic" x-text="o.notes || ''"></td>
                                <td class="px-3 py-2 text-slate-400 text-right text-xs" x-text="formatWhen(o.updated_at)"></td>
                            </tr>
                        </template>
                        <template x-if="history.length === 0 && !loading">
                            <tr><td colspan="5" class="px-3 py-6 text-center text-slate-500 italic text-xs">No delivered orders yet.</td></tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
        const API_TOKEN = @json(session('api_token'));

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

        function kitchenQueue() {
            return {
                orders: [],
                history: [],
                view: 'queue',
                loading: false,
                error: '',
                pollHandle: null,

                async init() {
                    await this.load();
                    this.pollHandle = setInterval(() => this.load(), 8000);
                },

                async setView(view) {
                    if (this.view === view) return;
                    this.view = view;
                    if (view === 'history') {
                        await this.loadHistory();
                    } else {
                        await this.load();
                    }
                },

                async load() {
                    if (this.view !== 'queue') return; // skip auto-poll if user is viewing history
                    this.loading = true;
                    try {
                        this.orders = await api('/kitchen/queue');
                    } catch (e) {
                        this.error = e.message;
                    } finally {
                        this.loading = false;
                    }
                },

                async loadHistory() {
                    this.loading = true;
                    try {
                        this.history = await api('/kitchen/history');
                    } catch (e) {
                        this.error = e.message;
                    } finally {
                        this.loading = false;
                    }
                },

                formatWhen(ts) {
                    if (!ts) return '';
                    const d = new Date(ts);
                    return d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                },

                async setStatus(order, status) {
                    try {
                        await api('/orders/' + order.id + '/status', {
                            method: 'PATCH',
                            body: JSON.stringify({ status }),
                        });
                        await this.load();
                    } catch (e) {
                        this.error = e.message;
                    }
                },

                cardClass(status) {
                    return {
                        pending:   'bg-slate-800 border-slate-700',
                        preparing: 'bg-blue-950/60 border-blue-700',
                        ready:     'bg-amber-950/60 border-amber-700',
                    }[status] || 'bg-slate-800 border-slate-700';
                },

                formatAge(ts) {
                    if (!ts) return '';
                    const mins = Math.floor((Date.now() - new Date(ts).getTime()) / 60000);
                    if (mins < 1) return 'just now';
                    if (mins < 60) return mins + 'm';
                    return Math.floor(mins / 60) + 'h ' + (mins % 60) + 'm';
                },
            };
        }
    </script>
    <style>[x-cloak] { display: none !important; }</style>
</body>
</html>
