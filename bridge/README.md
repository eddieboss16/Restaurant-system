# Print bridge

Node.js service that polls the Laravel app for queued print jobs and forwards them to a thermal ESC/POS printer.

## Why a bridge?

PHP can't talk to USB or Bluetooth printers directly on most Windows/macOS setups. The bridge runs on a machine with the printer attached (or on the LAN with a network printer) and uses [node-thermal-printer](https://www.npmjs.com/package/node-thermal-printer) to render and send ESC/POS bytes.

## Setup

```bash
cd bridge
npm install
```

Set environment variables (or copy from a `.env` file using `dotenv` or your shell):

| Var | Example | Notes |
|---|---|---|
| `API_BASE` | `http://192.168.1.10:8000` | Laravel server reachable on the LAN |
| `BRIDGE_TOKEN` | long random string | **must match** Laravel's `PRINT_BRIDGE_TOKEN` |
| `PRINTER_TYPE` | `epson` (default) or `star` | Most generic Chinese printers are EPSON-compatible |
| `PRINTER_INTERFACE` | `//?vid=04b8&pid=0202` (USB) or `tcp://192.168.1.20:9100` (network) | See node-thermal-printer docs for OS-specific syntax |
| `PRINTER_WIDTH` | `32` for 58mm, `48` for 80mm | Default 32 |
| `POLL_MS` | `2000` | Poll interval |

Run:

```bash
npm start
```

## Hardware tested

- Goojprt PT-210 (USB, 58mm) — `PRINTER_INTERFACE=//?vid=0483&pid=5743`
- Xprinter XP-58 (network) — `PRINTER_INTERFACE=tcp://<printer-ip>:9100`

## Bluetooth

Pair the printer at the OS level first, then point `PRINTER_INTERFACE` at the OS-assigned serial port:
- Windows: `COM3` (check Device Manager after pairing)
- Linux: `/dev/rfcomm0` (see `rfcomm bind` man page)

## Troubleshooting

- **"printer not reachable"** — the printer is off, the USB cable is loose, or the network address is wrong.
- **HTTP 401 from the API** — `BRIDGE_TOKEN` doesn't match Laravel's `PRINT_BRIDGE_TOKEN`.
- **Garbled output** — wrong `PRINTER_TYPE`. Try `star` if `epson` produces nothing.
- **Stuck "printing" jobs in the DB** — bridge crashed mid-job; the follow-up sweep that resets these isn't built yet, you can manually reset with `UPDATE print_jobs SET status='pending' WHERE status='printing' AND updated_at < NOW() - INTERVAL 2 MINUTE`.
