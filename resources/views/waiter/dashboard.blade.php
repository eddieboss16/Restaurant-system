<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Waiter Dashboard — {{ auth()->user()->name }}</title>
    @vite(['resources/css/app.css'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-100 min-h-screen">
    <header class="bg-white border-b border-slate-200">
        <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
            <div>
                <h1 class="text-lg font-semibold text-slate-800">Waiter Dashboard</h1>
                <p class="text-xs text-slate-500">{{ auth()->user()->name }} · {{ ucfirst(auth()->user()->role) }}</p>
            </div>
            <div class="flex items-center gap-3">
                @if (auth()->user()->isAdmin())
                    <a href="/admin/dashboard" class="text-sm text-slate-600 hover:text-slate-900 underline">Admin</a>
                @endif
                @if (auth()->user()->isAdmin() || auth()->user()->isManager())
                    <a href="/kitchen/dashboard" class="text-sm text-slate-600 hover:text-slate-900 underline">Kitchen</a>
                @endif
                <form method="POST" action="/logout">
                    @csrf
                    <button class="text-sm text-slate-600 hover:text-slate-900 underline">Log out</button>
                </form>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-6"
          x-data="waiterDashboard()"
          x-init="init()">

        <!-- Today strip -->
        <div class="bg-white border border-slate-200 rounded-lg px-4 py-2 mb-4 flex items-center justify-between text-sm">
            <div class="text-slate-500">
                <span class="uppercase tracking-wide text-xs">Today</span>
            </div>
            <div class="flex gap-6 text-slate-700">
                <div>
                    <span class="font-semibold" x-text="myToday ? myToday.sessions_paid : '—'"></span>
                    <span class="text-xs text-slate-500 ml-1">sessions paid</span>
                </div>
                <div>
                    KES <span class="font-semibold" x-text="myToday ? formatMoney(myToday.revenue_collected) : '—'"></span>
                    <span class="text-xs text-slate-500 ml-1">collected</span>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between mb-4">
            <div class="text-sm text-slate-600">
                <span x-text="sessions.length"></span> active session(s)
                <span class="text-slate-400" x-show="loading">· refreshing…</span>
            </div>
            <button @click="showNewSession = true"
                class="rounded bg-slate-800 text-white text-sm px-4 py-2 hover:bg-slate-700">
                + New Session
            </button>
        </div>

        <!-- Sessions list -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <template x-for="session in sessions" :key="session.id">
                <div class="bg-white rounded-lg shadow-sm border border-slate-200 p-4"
                     :class="{ 'border-red-400 ring-2 ring-red-100': session.status === 'served' && !session.payment }">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="font-medium text-slate-800" x-text="session.customer_label || 'Unlabeled customer'"></div>
                            <div class="text-xs text-slate-500 mt-1">
                                Opened <span x-text="formatTime(session.opened_at)"></span>
                            </div>
                        </div>
                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded"
                              :class="statusClass(session.status)"
                              x-text="session.status"></span>
                    </div>

                    <!-- Items -->
                    <div class="mt-3 divide-y divide-slate-100 border-t border-slate-100">
                        <template x-for="order in (session.orders || [])" :key="order.id">
                            <div class="py-2 flex items-center justify-between text-sm">
                                <div>
                                    <div>
                                        <span x-text="order.quantity + '× ' + order.menu_item.name"></span>
                                        <span class="text-xs ml-1 px-1.5 py-0.5 rounded"
                                              :class="orderStatusClass(order.status)"
                                              x-text="order.status"></span>
                                    </div>
                                    <div class="text-xs text-slate-500" x-show="order.notes" x-text="order.notes"></div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-slate-700" x-text="'KES ' + formatMoney(order.quantity * order.unit_price)"></span>
                                    <button x-show="order.status === 'ready'"
                                            @click="markDelivered(order.id)"
                                            class="text-xs bg-green-600 text-white rounded px-2 py-1 hover:bg-green-700">
                                        Delivered
                                    </button>
                                    <button x-show="!['delivered','cancelled'].includes(order.status)"
                                            @click="cancelOrder(order.id)"
                                            class="text-xs text-red-600 hover:underline">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </template>
                        <template x-if="!session.orders || session.orders.length === 0">
                            <div class="py-2 text-xs text-slate-500 italic">No items yet.</div>
                        </template>
                    </div>

                    <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-3">
                        <div class="text-sm">
                            <span class="text-slate-500">Total:</span>
                            <span class="font-semibold text-slate-800" x-text="'KES ' + formatMoney(sessionTotal(session))"></span>
                        </div>
                        <div class="flex gap-2">
                            <button @click="openAddOrder(session)"
                                    class="text-xs bg-slate-700 text-white rounded px-3 py-1.5 hover:bg-slate-600">
                                + Add Items
                            </button>
                            <button x-show="canCollectPayment(session)"
                                    @click="openPayment(session)"
                                    class="text-xs bg-emerald-600 text-white rounded px-3 py-1.5 hover:bg-emerald-700">
                                Collect Payment
                            </button>
                        </div>
                    </div>
                </div>
            </template>
            <template x-if="sessions.length === 0 && !loading">
                <div class="col-span-full text-center text-slate-500 text-sm py-10">
                    No active sessions. Click <strong>+ New Session</strong> to start.
                </div>
            </template>
        </div>

        <!-- New session modal -->
        <div x-show="showNewSession" x-cloak
             class="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-sm p-5">
                <h2 class="text-lg font-semibold text-slate-800 mb-3">New Session</h2>
                <label class="block text-xs text-slate-600 mb-1">Customer label (optional)</label>
                <input x-model="newSessionLabel" type="text" placeholder="e.g. lady in red"
                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm mb-4">
                <div class="flex justify-end gap-2">
                    <button @click="showNewSession = false; newSessionLabel = ''"
                            class="text-sm text-slate-600 px-3 py-2">Cancel</button>
                    <button @click="createSession()"
                            class="text-sm bg-slate-800 text-white rounded px-4 py-2 hover:bg-slate-700">Open</button>
                </div>
            </div>
        </div>

        <!-- Add order modal -->
        <div x-show="addOrderSession" x-cloak
             class="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-md p-5">
                <h2 class="text-lg font-semibold text-slate-800 mb-1">Add items</h2>
                <p class="text-xs text-slate-500 mb-4" x-show="addOrderSession"
                   x-text="'For: ' + (addOrderSession?.customer_label || 'Unlabeled customer')"></p>

                <div class="mb-3">
                    <input type="text" x-model="menuSearch" placeholder="Search menu…"
                           class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div class="max-h-56 overflow-y-auto border border-slate-200 rounded mb-4">
                    <template x-for="item in filteredMenu()" :key="item.id">
                        <button @click="addDraftItem(item)"
                                class="w-full flex items-center justify-between text-sm px-3 py-2 hover:bg-slate-50 border-b border-slate-100 text-left">
                            <span>
                                <span x-text="item.name"></span>
                                <span class="text-xs text-slate-400 ml-1" x-text="item.category"></span>
                            </span>
                            <span class="text-slate-600" x-text="'KES ' + formatMoney(item.price)"></span>
                        </button>
                    </template>
                    <template x-if="filteredMenu().length === 0">
                        <div class="text-xs text-slate-500 px-3 py-2 italic">No matches.</div>
                    </template>
                </div>

                <div class="space-y-2 mb-4" x-show="draftItems.length > 0">
                    <template x-for="(draft, idx) in draftItems" :key="idx">
                        <div class="flex items-center gap-2 text-sm">
                            <span class="flex-1" x-text="draft.name"></span>
                            <input type="number" min="1" x-model.number="draft.quantity"
                                   class="w-16 rounded border border-slate-300 px-2 py-1 text-sm">
                            <input type="text" placeholder="notes" x-model="draft.notes"
                                   class="flex-1 rounded border border-slate-300 px-2 py-1 text-sm">
                            <button @click="draftItems.splice(idx, 1)" class="text-red-600 text-xs">×</button>
                        </div>
                    </template>
                </div>

                <div class="flex justify-end gap-2">
                    <button @click="cancelAddOrder()" class="text-sm text-slate-600 px-3 py-2">Cancel</button>
                    <button @click="submitOrder()"
                            :disabled="draftItems.length === 0"
                            class="text-sm bg-slate-800 text-white rounded px-4 py-2 hover:bg-slate-700 disabled:opacity-40">
                        Send to kitchen
                    </button>
                </div>
            </div>
        </div>

        <!-- Payment modal -->
        <div x-show="paymentSession" x-cloak
             class="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-sm p-5">
                <h2 class="text-lg font-semibold text-slate-800 mb-1">Collect payment</h2>
                <p class="text-xs text-slate-500 mb-4" x-show="paymentSession"
                   x-text="'Total due: KES ' + formatMoney(sessionTotal(paymentSession))"></p>

                <label class="block text-xs text-slate-600 mb-1">Method</label>
                <div class="flex gap-2 mb-3">
                    <button @click="paymentMethod = 'cash'"
                            :class="paymentMethod === 'cash' ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-700'"
                            class="flex-1 rounded text-sm py-2">Cash</button>
                    <button @click="paymentMethod = 'mpesa'"
                            :class="paymentMethod === 'mpesa' ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-700'"
                            class="flex-1 rounded text-sm py-2">Mpesa</button>
                </div>

                <label class="block text-xs text-slate-600 mb-1">Amount (KES)</label>
                <input type="number" step="0.01" x-model.number="paymentAmount"
                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm mb-3">

                <template x-if="paymentMethod === 'mpesa'">
                    <div>
                        <div class="flex gap-1 mb-3 text-xs">
                            <button @click="mpesaMode = 'stk'"
                                    :class="mpesaMode === 'stk' ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-600'"
                                    class="flex-1 rounded py-1.5">Send STK push</button>
                            <button @click="mpesaMode = 'code'"
                                    :class="mpesaMode === 'code' ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-600'"
                                    class="flex-1 rounded py-1.5">Type code</button>
                        </div>

                        <template x-if="mpesaMode === 'stk'">
                            <div>
                                <label class="block text-xs text-slate-600 mb-1">Customer phone</label>
                                <input type="tel" x-model="paymentPhone" placeholder="07XX XXX XXX"
                                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm mb-3">
                                <p class="text-xs text-slate-500 mb-3">Customer's phone will vibrate with a payment prompt.</p>
                            </div>
                        </template>

                        <template x-if="mpesaMode === 'code'">
                            <div>
                                <label class="block text-xs text-slate-600 mb-1">Mpesa transaction code</label>
                                <input type="text" x-model="paymentMpesaCode" placeholder="e.g. QJK12ABCD3"
                                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm mb-3">
                            </div>
                        </template>
                    </div>
                </template>

                <div x-show="stkWaiting" x-cloak class="bg-amber-50 border border-amber-200 text-amber-800 rounded text-sm px-3 py-2 mb-3">
                    Waiting for customer… (STK push sent, prompt expires in ~60s)
                </div>

                <div class="flex justify-end gap-2">
                    <button @click="cancelPayment()" :disabled="stkWaiting"
                            class="text-sm text-slate-600 px-3 py-2 disabled:opacity-40">Cancel</button>
                    <button @click="submitPayment()" :disabled="stkWaiting"
                            class="text-sm bg-emerald-600 text-white rounded px-4 py-2 hover:bg-emerald-700 disabled:opacity-40"
                            x-text="(paymentMethod === 'mpesa' && mpesaMode === 'stk') ? 'Send STK' : 'Confirm'"></button>
                </div>
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

        function waiterDashboard() {
            return {
                sessions: [],
                menu: [],
                loading: false,

                showNewSession: false,
                newSessionLabel: '',

                addOrderSession: null,
                menuSearch: '',
                draftItems: [],

                paymentSession: null,
                paymentMethod: 'cash',
                paymentAmount: 0,
                paymentMpesaCode: '',
                paymentPhone: '',
                mpesaMode: 'stk',
                stkWaiting: false,
                stkPollHandle: null,

                myToday: null,

                async init() {
                    await Promise.all([this.loadSessions(), this.loadMenu(), this.loadMyToday()]);
                    setInterval(() => this.loadSessions(), 15000);
                    setInterval(() => this.loadMyToday(), 60000);
                },

                async loadSessions() {
                    this.loading = true;
                    try {
                        this.sessions = await api('/sessions');
                    } catch (e) {
                        console.error(e);
                    } finally {
                        this.loading = false;
                    }
                },

                async loadMenu() {
                    try {
                        this.menu = await api('/menu-items');
                    } catch (e) {
                        console.error(e);
                    }
                },

                async loadMyToday() {
                    try {
                        this.myToday = await api('/me/today');
                    } catch (e) {
                        // not fatal -- the strip will just show dashes.
                    }
                },

                filteredMenu() {
                    const q = this.menuSearch.trim().toLowerCase();
                    if (!q) return this.menu;
                    return this.menu.filter(m =>
                        m.name.toLowerCase().includes(q) ||
                        (m.category || '').toLowerCase().includes(q)
                    );
                },

                sessionTotal(session) {
                    return (session.orders || [])
                        .filter(o => o.status !== 'cancelled')
                        .reduce((sum, o) => sum + Number(o.quantity) * Number(o.unit_price), 0);
                },

                canCollectPayment(session) {
                    if (session.payment) return false;
                    if (!session.orders || session.orders.length === 0) return false;
                    return session.orders.every(o => ['delivered', 'cancelled'].includes(o.status));
                },

                statusClass(status) {
                    return {
                        open: 'bg-slate-100 text-slate-600',
                        ordered: 'bg-blue-100 text-blue-700',
                        served: 'bg-amber-100 text-amber-700',
                        billed: 'bg-purple-100 text-purple-700',
                        paid: 'bg-emerald-100 text-emerald-700',
                    }[status] || 'bg-slate-100 text-slate-600';
                },

                orderStatusClass(status) {
                    return {
                        pending: 'bg-slate-200 text-slate-700',
                        preparing: 'bg-blue-200 text-blue-800',
                        ready: 'bg-amber-200 text-amber-800',
                        delivered: 'bg-emerald-200 text-emerald-800',
                        cancelled: 'bg-red-200 text-red-800',
                    }[status] || 'bg-slate-200 text-slate-700';
                },

                formatMoney(n) {
                    return Number(n).toFixed(2);
                },

                formatTime(ts) {
                    if (!ts) return '';
                    const d = new Date(ts);
                    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                },

                async createSession() {
                    try {
                        await api('/sessions', {
                            method: 'POST',
                            body: JSON.stringify({ customer_label: this.newSessionLabel || null }),
                        });
                        this.showNewSession = false;
                        this.newSessionLabel = '';
                        await this.loadSessions();
                    } catch (e) {
                        alert(e.message);
                    }
                },

                openAddOrder(session) {
                    this.addOrderSession = session;
                    this.draftItems = [];
                    this.menuSearch = '';
                },

                cancelAddOrder() {
                    this.addOrderSession = null;
                    this.draftItems = [];
                },

                addDraftItem(item) {
                    const existing = this.draftItems.find(d => d.menu_item_id === item.id);
                    if (existing) {
                        existing.quantity += 1;
                        return;
                    }
                    this.draftItems.push({
                        menu_item_id: item.id,
                        name: item.name,
                        quantity: 1,
                        notes: '',
                    });
                },

                async submitOrder() {
                    if (!this.addOrderSession || this.draftItems.length === 0) return;
                    try {
                        await api(`/sessions/${this.addOrderSession.id}/orders`, {
                            method: 'POST',
                            body: JSON.stringify({
                                items: this.draftItems.map(d => ({
                                    menu_item_id: d.menu_item_id,
                                    quantity: d.quantity,
                                    notes: d.notes || null,
                                })),
                            }),
                        });
                        this.cancelAddOrder();
                        await this.loadSessions();
                    } catch (e) {
                        alert(e.message);
                    }
                },

                async markDelivered(orderId) {
                    try {
                        await api(`/orders/${orderId}/status`, {
                            method: 'PATCH',
                            body: JSON.stringify({ status: 'delivered' }),
                        });
                        await this.loadSessions();
                    } catch (e) {
                        alert(e.message);
                    }
                },

                async cancelOrder(orderId) {
                    const reason = prompt('Reason for cancellation (min 5 chars):');
                    if (!reason || reason.length < 5) return;
                    try {
                        await api(`/orders/${orderId}`, {
                            method: 'DELETE',
                            body: JSON.stringify({ reason }),
                        });
                        await this.loadSessions();
                    } catch (e) {
                        alert(e.message);
                    }
                },

                openPayment(session) {
                    this.paymentSession = session;
                    this.paymentMethod = 'cash';
                    this.paymentAmount = this.sessionTotal(session);
                    this.paymentMpesaCode = '';
                    this.paymentPhone = '';
                    this.mpesaMode = 'stk';
                    this.stkWaiting = false;
                },

                cancelPayment() {
                    if (this.stkPollHandle) {
                        clearInterval(this.stkPollHandle);
                        this.stkPollHandle = null;
                    }
                    this.paymentSession = null;
                    this.paymentMpesaCode = '';
                    this.paymentPhone = '';
                    this.stkWaiting = false;
                },

                async submitPayment() {
                    if (!this.paymentSession) return;

                    if (this.paymentMethod === 'mpesa' && this.mpesaMode === 'stk') {
                        return this.sendStkPush();
                    }

                    if (this.paymentMethod === 'mpesa' && !this.paymentMpesaCode.trim()) {
                        alert('Mpesa code is required.');
                        return;
                    }

                    try {
                        await api(`/sessions/${this.paymentSession.id}/payment`, {
                            method: 'POST',
                            body: JSON.stringify({
                                method: this.paymentMethod,
                                amount: this.paymentAmount,
                                mpesa_code: this.paymentMethod === 'mpesa' ? this.paymentMpesaCode : null,
                            }),
                        });
                        this.cancelPayment();
                        await this.loadSessions();
                    } catch (e) {
                        alert(e.message);
                    }
                },

                async sendStkPush() {
                    if (!this.paymentPhone.trim()) {
                        alert('Customer phone is required.');
                        return;
                    }
                    const sessionId = this.paymentSession.id;
                    try {
                        await api(`/sessions/${sessionId}/payment/stk`, {
                            method: 'POST',
                            body: JSON.stringify({ phone: this.paymentPhone, amount: this.paymentAmount }),
                        });
                        this.stkWaiting = true;
                        this.startStkPolling(sessionId);
                    } catch (e) {
                        alert(e.message);
                    }
                },

                startStkPolling(sessionId) {
                    let elapsed = 0;
                    this.stkPollHandle = setInterval(async () => {
                        elapsed += 3;
                        try {
                            const fresh = await api('/sessions/' + sessionId);
                            if (fresh.status === 'paid') {
                                clearInterval(this.stkPollHandle);
                                this.stkPollHandle = null;
                                this.cancelPayment();
                                await this.loadSessions();
                            } else if (fresh.payment && fresh.payment.status === 'failed') {
                                clearInterval(this.stkPollHandle);
                                this.stkPollHandle = null;
                                this.stkWaiting = false;
                                alert('STK failed: ' + (fresh.payment.mpesa_result_desc || 'unknown error'));
                            }
                        } catch (e) {
                            // session may have disappeared from this waiter's list once paid
                        }
                        if (elapsed >= 90) {
                            clearInterval(this.stkPollHandle);
                            this.stkPollHandle = null;
                            this.stkWaiting = false;
                            alert('STK push timed out. Try again, or use the Type-code fallback.');
                        }
                    }, 3000);
                },
            };
        }
    </script>
    <style>[x-cloak] { display: none !important; }</style>
</body>
</html>
