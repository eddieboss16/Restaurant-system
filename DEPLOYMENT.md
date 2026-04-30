# Deployment Guide

Step-by-step path from a fresh server to a real restaurant taking real money. Written for a single-restaurant deployment in Kenya. Assumes Ubuntu 24.04 LTS on a small VPS (DigitalOcean / Hostinger / Linode — anything with 1GB RAM is enough).

> **Order matters.** The two slowest steps are external paperwork: M-Pesa production approval (5-10 business days) and WhatsApp template approval (24-72 hours). Start those *first*.

---

## 0. Pre-flight checklist

Before you provision anything, gather:

- [ ] **KRA PIN** for the business (needed for M-Pesa production)
- [ ] **Business registration certificate** (needed for M-Pesa production)
- [ ] **Safaricom paybill or till number** for the business (or apply via M-Pesa Business)
- [ ] **Owner's WhatsApp-capable phone number** in 254XXXXXXXXX format
- [ ] **A domain name** pointing at the server's public IP (M-Pesa callback needs HTTPS, which needs a real domain)
- [ ] **Thermal printer** purchased and tested locally (Goojprt PT-210, Xprinter XP-58, or similar ESC/POS-compatible)
- [ ] **A second machine on the same LAN as the printer** to run the bridge (often the same Windows PC the floor uses)

---

## 1. Start the slow paperwork

### 1a. M-Pesa Daraja production app (start day 1)

1. Sign in at <https://developer.safaricom.co.ke> with the same account that owns the paybill/till.
2. Create an app → enable **Lipa Na M-Pesa Online (STK Push)** product.
3. Submit for **production approval**: upload KRA PIN, business cert, app description ("restaurant POS taking customer payments at point-of-sale").
4. **Wait 5-10 business days.** While waiting, you can develop and test against sandbox using the same flow.

When approved you get:
- `MPESA_CONSUMER_KEY`
- `MPESA_CONSUMER_SECRET`
- `MPESA_SHORTCODE` (your real paybill/till)
- `MPESA_PASSKEY`

### 1b. WhatsApp Cloud API + template (start day 1)

1. At <https://developers.facebook.com>: create a Meta app → add WhatsApp product.
2. Verify the business at <https://business.facebook.com>.
3. Add the owner's phone number as the WhatsApp Business number (or use Meta's test number for the first week).
4. Generate a **System User permanent access token** (NOT the 24h temp token shown by default).
5. **Submit a daily-summary message template** for review:
   - Name: `restaurant_daily_summary`
   - Category: **Utility**
   - Language: `en`
   - Body (with 6 variables):
     ```
     *Daily Summary* {{1}}
     Revenue: {{2}}
     Expenses: {{3}}
     Net: {{4}}
     Sessions: {{5}}
     Top item: {{6}}
     ```
6. **Wait 24-72 hours** for Meta to approve.

Once approved you'll need:
- `WHATSAPP_PHONE_NUMBER_ID`
- `WHATSAPP_ACCESS_TOKEN`
- `WHATSAPP_OWNER_PHONE` (e.g. `254712345678`)
- `WHATSAPP_DAILY_SUMMARY_TEMPLATE=restaurant_daily_summary`

---

## 2. Provision the server

### 2a. Pick a host

Anything with 1GB RAM, 25GB SSD, public IPv4. Cheap working setups:
- DigitalOcean basic droplet (~$6/mo)
- Hostinger VPS-1 (~KES 600/mo)
- Linode Nanode (~$5/mo)
- A Mac mini at the restaurant + a static IP from the ISP (works but you own the uptime)

### 2b. Install the stack

SSH in as root, then:

```bash
# Base + PHP 8.2 + extensions Laravel needs
apt update && apt -y upgrade
apt -y install software-properties-common
add-apt-repository -y ppa:ondrej/php
apt update
apt -y install nginx mysql-server php8.2-fpm php8.2-cli \
    php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl \
    php8.2-zip php8.2-gd php8.2-bcmath php8.2-intl \
    php8.2-opcache unzip git certbot python3-certbot-nginx

# Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Node 22 LTS (for `npm run build`; the print bridge can run elsewhere)
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt -y install nodejs
```

### 2c. MySQL setup

```bash
mysql_secure_installation        # set a root password, drop test db
mysql -u root -p
```

```sql
CREATE DATABASE restaurant_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'restaurant'@'localhost' IDENTIFIED BY 'PICK_A_LONG_RANDOM_PASSWORD';
GRANT ALL PRIVILEGES ON restaurant_management.* TO 'restaurant'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 2d. Create a deploy user (don't run as root)

```bash
adduser deploy
usermod -aG www-data deploy
```

---

## 3. Deploy the app

```bash
su - deploy
cd ~
git clone <YOUR_REPO_URL> restaurant-system
cd restaurant-system
composer install --no-dev --optimize-autoloader
npm install
npm run build
cp .env.example .env       # then edit it -- see section 4
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force # creates the seed staff and menu -- DELETE OR EDIT FOR PROD
chmod -R 775 storage bootstrap/cache
chown -R deploy:www-data storage bootstrap/cache
```

> **Reseed warning:** `db:seed` creates demo staff (`admin@restaurant.co.ke` etc) with password `password`. **Change those passwords or delete those rows immediately.** Better: edit `database/seeders/DatabaseSeeder.php` to skip seed staff entirely in production and create the owner's account via tinker:
> ```bash
> php artisan tinker
> >>> User::create(['name' => 'Edwin', 'email' => 'real@email.com', 'password' => Hash::make('long-real-password'), 'role' => 'admin', 'is_active' => true])
> ```

---

## 4. Configure `.env` for production

Edit `~/restaurant-system/.env`:

```env
APP_NAME="Your Restaurant"
APP_ENV=production
APP_DEBUG=false
APP_KEY=                # already set by `key:generate`
APP_URL=https://yourdomain.example.com

LOG_CHANNEL=daily       # so logs rotate

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=restaurant_management
DB_USERNAME=restaurant
DB_PASSWORD=THE_PASSWORD_FROM_SECTION_2c

SESSION_DRIVER=database
SESSION_LIFETIME=480
SESSION_SECURE_COOKIE=true     # set after HTTPS is up

# --- M-Pesa (production) ---
MPESA_ENV=production
MPESA_CONSUMER_KEY=...         # from Daraja
MPESA_CONSUMER_SECRET=...
MPESA_SHORTCODE=247247         # YOUR real paybill/till
MPESA_PASSKEY=...
MPESA_CALLBACK_URL=https://yourdomain.example.com/api/mpesa/callback
MPESA_TRANSACTION_TYPE=CustomerPayBillOnline    # or CustomerBuyGoodsOnline for till

# --- WhatsApp ---
WHATSAPP_ENABLED=true
WHATSAPP_GRAPH_VERSION=v20.0
WHATSAPP_PHONE_NUMBER_ID=...
WHATSAPP_ACCESS_TOKEN=...
WHATSAPP_OWNER_PHONE=254712345678
WHATSAPP_DAILY_SUMMARY_TEMPLATE=restaurant_daily_summary
WHATSAPP_TEMPLATE_LANGUAGE=en

# --- Print bridge ---
PRINT_BRIDGE_TOKEN=GENERATE_A_LONG_RANDOM_STRING_HERE_MIN_32_CHARS
```

Then cache config + routes for production speed:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> **Cache caveat:** after this, `.env` and route changes are ignored until you re-run `php artisan optimize:clear`. Make this the *last* step before going live.

---

## 5. nginx + HTTPS

Create `/etc/nginx/sites-available/restaurant-system`:

```nginx
server {
    listen 80;
    server_name yourdomain.example.com;
    root /home/deploy/restaurant-system/public;

    add_header X-Frame-Options SAMEORIGIN;
    add_header X-Content-Type-Options nosniff;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
    client_max_body_size 10M;
}
```

```bash
ln -s /etc/nginx/sites-available/restaurant-system /etc/nginx/sites-enabled/
rm /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

Get HTTPS via Let's Encrypt (M-Pesa requires it):

```bash
certbot --nginx -d yourdomain.example.com
```

certbot auto-edits the nginx config to listen on 443 and redirect 80 → 443. It also installs a renewal timer. Verify:

```bash
systemctl list-timers | grep certbot
```

---

## 6. Cron (schedule:run)

Without this, the daily WhatsApp summary never fires and stuck STK pushes never expire.

```bash
crontab -e -u deploy
```

Add:

```
* * * * * cd /home/deploy/restaurant-system && php artisan schedule:run >> /dev/null 2>&1
```

Verify:

```bash
sudo -u deploy php /home/deploy/restaurant-system/artisan schedule:list
```

You should see `mpesa:expire-stuck-stk` (every minute) and `summary:send-daily` (23:59 daily).

---

## 7. Print bridge

Runs on the machine attached to the printer (often a Windows PC at the restaurant; doesn't have to be the server).

On that machine:

```bash
git clone <YOUR_REPO_URL>
cd restaurant-system/bridge
npm install
```

Set environment variables. On Windows PowerShell:

```powershell
$env:API_BASE = "https://yourdomain.example.com"
$env:BRIDGE_TOKEN = "SAME_VALUE_AS_PRINT_BRIDGE_TOKEN_IN_LARAVEL_ENV"
$env:PRINTER_TYPE = "epson"
$env:PRINTER_INTERFACE = "//?vid=04b8&pid=0202"   # check your printer's USB id
$env:PRINTER_WIDTH = "32"
npm start
```

Make it run at boot:
- **Windows**: install `pm2` + `pm2-windows-service`, or use Task Scheduler with "At log on" trigger
- **Linux**: systemd unit; example at `bridge/README.md`
- **macOS**: `launchd` plist

See `bridge/README.md` for the per-OS notes and Bluetooth pairing instructions.

---

## 8. Backups

**Don't skip this.** One disk failure without backups = every payment, expense, and session record gone.

### 8a. Daily mysqldump

Create `/home/deploy/backup-db.sh`:

```bash
#!/usr/bin/env bash
set -e
TS=$(date +%Y%m%d-%H%M%S)
OUT=/home/deploy/backups/restaurant_$TS.sql.gz
mkdir -p /home/deploy/backups
mysqldump --single-transaction -u restaurant -p"$DB_PASSWORD" restaurant_management | gzip > "$OUT"
# Keep 30 days locally
find /home/deploy/backups -name "restaurant_*.sql.gz" -mtime +30 -delete
```

```bash
chmod +x /home/deploy/backup-db.sh
crontab -e -u deploy
```

Add a daily run + read the password from somewhere safe:

```
30 2 * * * DB_PASSWORD='THE_PASSWORD' /home/deploy/backup-db.sh >> /home/deploy/backups/backup.log 2>&1
```

### 8b. Off-machine copy (highly recommended)

Local backups don't survive a server fire. Mirror to:
- Backblaze B2 (`b2 sync /home/deploy/backups b2://your-bucket/`) — cheapest cloud
- AWS S3 (`aws s3 sync ...`) — most reliable
- Google Drive via `rclone`

Even copying the dump to a free Google Drive nightly is better than nothing.

---

## 9. Log rotation

`LOG_CHANNEL=daily` (set in section 4) keeps one file per day. Add cleanup so logs don't fill the disk:

```bash
crontab -e -u deploy
```

```
0 3 * * * find /home/deploy/restaurant-system/storage/logs -name "laravel-*.log" -mtime +14 -delete
```

---

## 10. Smoke test before going live

In this exact order:

1. **Visit `https://yourdomain.example.com`** — login page renders, no certificate warnings.
2. **Log in as admin**, check the Reports tab loads (will be empty/zero — that's fine).
3. **Log in as the seeded waiter, open a session, add an item** — confirm inventory deducted.
4. **Log in as kitchen, advance the order to `ready`** — confirm flip.
5. **Back as waiter, mark delivered** — confirm session moves to `served`.
6. **Take a cash payment** — confirm session moves to `paid`, appears in Paid Sessions tab.
7. **Click "Print receipt"** — confirm bridge picks it up and the printer fires.
8. **Test M-Pesa STK on a personal phone with KES 1**:
   - Open a new session, deliver, hit Send STK with your number, enter PIN.
   - Confirm session closes within ~30 seconds.
   - Check `payments` table — `status='completed'`, `mpesa_code` populated.
9. **Manually trigger the daily summary** to test WhatsApp:
   ```bash
   php artisan summary:send-daily
   ```
   You should receive the message within seconds.
10. **Run the test suite once on the server** to catch environment quirks:
    ```bash
    php artisan test
    ```

---

## 11. Day-of launch

The morning of launch:

1. Run `composer install --no-dev --optimize-autoloader && npm run build` one last time.
2. Re-cache: `php artisan optimize:clear && php artisan optimize`.
3. Verify: `systemctl status nginx php8.2-fpm mysql`.
4. Tail the log: `tail -f storage/logs/laravel-$(date +%Y-%m-%d).log` — watch for errors as the floor opens.
5. Have the bridge running before service starts.
6. **Have a fallback**: a printed paper menu and a calculator. Power outages happen.

---

## 12. Rollback plan

If something breaks badly during the first day:

```bash
cd /home/deploy/restaurant-system
git log --oneline -10                    # find the last known-good commit
git checkout <previous-commit-hash>
composer install --no-dev --optimize-autoloader
php artisan migrate:rollback             # only if a bad migration is the cause
php artisan optimize:clear && php artisan optimize
sudo systemctl reload php8.2-fpm
```

If the database was corrupted by a bad migration:
```bash
mysql -u restaurant -p restaurant_management < /home/deploy/backups/restaurant_<TIMESTAMP>.sql.gz
```
(After `gunzip` first if it ends in `.gz`.)

---

## Updating the app after launch

```bash
cd /home/deploy/restaurant-system
git pull
composer install --no-dev --optimize-autoloader
npm install && npm run build
php artisan migrate --force
php artisan optimize:clear && php artisan optimize
sudo systemctl reload php8.2-fpm
```

If you change `routes/console.php` (scheduled tasks), the cron picks up the change on the next tick — no restart needed.

---

## Quick reference: the env vars Laravel needs

| Var | Where it comes from |
|---|---|
| `APP_KEY` | `php artisan key:generate` |
| `DB_PASSWORD` | section 2c |
| `MPESA_CONSUMER_KEY` / `_SECRET` | Daraja portal after production approval |
| `MPESA_SHORTCODE` | your business paybill/till |
| `MPESA_PASSKEY` | Daraja portal |
| `MPESA_CALLBACK_URL` | `https://YOUR_DOMAIN/api/mpesa/callback` |
| `WHATSAPP_PHONE_NUMBER_ID` | Meta Business → WhatsApp → Phone numbers |
| `WHATSAPP_ACCESS_TOKEN` | Meta Business → System Users → Generate token |
| `WHATSAPP_OWNER_PHONE` | the recipient, format `254XXXXXXXXX` |
| `WHATSAPP_DAILY_SUMMARY_TEMPLATE` | the template name you registered with Meta |
| `PRINT_BRIDGE_TOKEN` | a long random string, copied identically into the bridge env |
