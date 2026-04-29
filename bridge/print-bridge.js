/**
 * Print bridge: polls the Laravel app for pending print jobs and
 * forwards them to a thermal ESC/POS printer.
 *
 * Configure with environment variables:
 *   API_BASE        e.g. http://192.168.1.10:8000  (the Laravel server on the LAN)
 *   BRIDGE_TOKEN    must match Laravel's PRINT_BRIDGE_TOKEN
 *   PRINTER_TYPE    "epson" | "star"  (default: epson; epson covers most generic ESC/POS)
 *   PRINTER_INTERFACE   USB id like "//?vid=04b8&pid=0202"  OR  network "tcp://192.168.1.20:9100"
 *   PRINTER_WIDTH   columns at standard font (default: 32 for 58mm, 48 for 80mm)
 *   POLL_MS         poll interval in milliseconds (default: 2000)
 *
 * Run:
 *   cd bridge && npm install && npm start
 *
 * Hardware tested with Goojprt PT-210 (USB) and Xprinter XP-58 (TCP).
 * For Bluetooth: pair the printer first, then point PRINTER_INTERFACE
 * at the OS-assigned serial port (Windows: "COM3", Linux: "/dev/rfcomm0").
 */

const { ThermalPrinter, PrinterTypes } = require('node-thermal-printer');

const API_BASE = process.env.API_BASE || 'http://localhost:8000';
const BRIDGE_TOKEN = process.env.BRIDGE_TOKEN;
const PRINTER_TYPE = (process.env.PRINTER_TYPE || 'epson').toLowerCase() === 'star' ? PrinterTypes.STAR : PrinterTypes.EPSON;
const PRINTER_INTERFACE = process.env.PRINTER_INTERFACE;
const PRINTER_WIDTH = parseInt(process.env.PRINTER_WIDTH || '32', 10);
const POLL_MS = parseInt(process.env.POLL_MS || '2000', 10);

if (!BRIDGE_TOKEN) {
  console.error('BRIDGE_TOKEN env var is required.');
  process.exit(1);
}
if (!PRINTER_INTERFACE) {
  console.error('PRINTER_INTERFACE env var is required.');
  process.exit(1);
}

function makePrinter() {
  return new ThermalPrinter({
    type: PRINTER_TYPE,
    interface: PRINTER_INTERFACE,
    width: PRINTER_WIDTH,
    characterSet: 'PC437_USA',
    removeSpecialCharacters: false,
  });
}

async function api(path, options = {}) {
  const res = await fetch(API_BASE + path, {
    ...options,
    headers: {
      'X-Bridge-Token': BRIDGE_TOKEN,
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      ...(options.headers || {}),
    },
  });
  if (!res.ok && res.status !== 204) {
    const body = await res.text().catch(() => '');
    throw new Error(`HTTP ${res.status}: ${body}`);
  }
  if (res.status === 204) return null;
  const text = await res.text();
  return text ? JSON.parse(text) : null;
}

async function renderReceipt(printer, payload) {
  printer.alignCenter();
  printer.bold(true);
  printer.println(payload.header.name);
  printer.bold(false);
  if (payload.header.subtitle) printer.println(payload.header.subtitle);
  printer.drawLine();
  printer.alignLeft();

  if (payload.meta?.customer_label) printer.println(`Customer: ${payload.meta.customer_label}`);
  if (payload.meta?.waiter)         printer.println(`Waiter:   ${payload.meta.waiter}`);
  if (payload.meta?.served_at)      printer.println(`Served:   ${new Date(payload.meta.served_at).toLocaleString()}`);
  printer.println(`Session:  #${payload.meta.session_id}`);
  printer.drawLine();

  for (const item of payload.items) {
    printer.tableCustom([
      { text: `${item.quantity}x ${item.name}`, align: 'LEFT', width: 0.6 },
      { text: item.line_total.toFixed(2), align: 'RIGHT', width: 0.4 },
    ]);
  }
  printer.drawLine();

  printer.alignRight();
  printer.bold(true);
  printer.println(`TOTAL: KES ${payload.totals.total.toFixed(2)}`);
  printer.bold(false);
  printer.alignLeft();

  if (payload.payment) {
    printer.println(`Paid via ${payload.payment.method.toUpperCase()}`);
    if (payload.payment.mpesa_code) printer.println(`Code: ${payload.payment.mpesa_code}`);
  }

  printer.drawLine();
  printer.alignCenter();
  if (payload.footer?.thank_you) printer.println(payload.footer.thank_you);
  printer.cut();
}

async function tick() {
  let job;
  try {
    job = await api('/api/print-jobs/pending');
  } catch (e) {
    console.error('[poll] error:', e.message);
    return;
  }

  if (!job) return;

  console.log(`[job ${job.id}] picked up`);
  const printer = makePrinter();
  try {
    const isConnected = await printer.isPrinterConnected();
    if (!isConnected) throw new Error('printer not reachable');

    await renderReceipt(printer, job.payload);
    await printer.execute();

    await api(`/api/print-jobs/${job.id}/ack`, { method: 'POST' });
    console.log(`[job ${job.id}] printed`);
  } catch (e) {
    console.error(`[job ${job.id}] failed:`, e.message);
    try {
      await api(`/api/print-jobs/${job.id}/fail`, {
        method: 'POST',
        body: JSON.stringify({ error: e.message.slice(0, 500) }),
      });
    } catch (ackErr) {
      console.error(`[job ${job.id}] could not report failure:`, ackErr.message);
    }
  }
}

console.log(`Print bridge polling ${API_BASE} every ${POLL_MS}ms ->  ${PRINTER_INTERFACE}`);
setInterval(tick, POLL_MS);
tick();
