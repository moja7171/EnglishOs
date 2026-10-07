# رله‌ی AI روی VPS — آنچه ساخته شده و چطور در آینده منتقلش کنیم

آخرین به‌روزرسانی: ۲۰۲۶-۱۰-۰۷

این سند می‌گوید روی VPS دقیقاً چه چیزی نصب و تنظیم شده، سایت چطور به آن وصل است، و اگر روزی لازم شد VPS را عوض کنید چه مراحلی را باید تکرار کنید. **هیچ رمز یا کلیدی در این سند نیست**؛ جای هر کدام مشخص شده است.

## ۱. چرا اصلاً VPS داریم

سایت English OS (و `skillos`) روی یک هاست اشتراکی cPanel در ایران است. از آن هاست نمی‌شد به API های Gemini، Groq و Pexels وصل شد. رله یک «پل» کوچک روی یک سرور خارج از ایران است: سایت درخواستش را به رله می‌فرستد، رله همان درخواست را عیناً به مقصد اصلی می‌رساند و جواب را برمی‌گرداند.

پروتکل رله (توضیح کامل در docstring فایل `scripts/ai-relay.py`):

- هر درخواست باید هدر `X-Relay-Auth` (رمز رله) و `X-Relay-Url` (آدرس واقعی مقصد، فقط https) داشته باشد.
- فقط این مقصدها مجازند: `generativelanguage.googleapis.com`، `api.groq.com`، `api.pexels.com`، `images.pexels.com`، `videos.pexels.com`. بقیه 403 می‌گیرند. (`ALLOWED_HOSTS` در `ai-relay.py`.)
- رمز غلط یا نبودن آن: 401 با بدنه‌ی `bad auth`.
- رله به مقصد حداکثر ۳۰ ثانیه فرصت می‌دهد (`timeout=30` در `ai-relay.py`)؛ جواب طولانی‌تر 502 می‌شود.
- کلیدهای API خود اپ داخل همان هدرهای ارسالی می‌روند و رله ذخیره‌شان نمی‌کند.
- **فشرده‌سازی شفاف است:** هدر `Accept-Encoding` کلاینت عیناً به مقصد می‌رسد (اگر نبود، `identity`) و پاسخ **خام و فشرده** با همان `Content-Encoding` برمی‌گردد تا خود کلاینت بازش کند. (نسخه‌ی قدیمی فقط gzip را باز می‌کرد و هدر را حذف می‌کرد؛ برای کلاینتی که `br` اعلام می‌کند — مثل cURL در PHP هاست — پاسخ Groq خراب می‌شد و رونویسی صوتی بی‌صدا «بدون متن» برمی‌گشت، چون curl دستی هرگز `br` نمی‌فرستد و تست‌های دستی سالم به نظر می‌رسیدند.)

## ۲. معماری فعلی

```
                      ┌───────────── مسیر اصلی ("vps") ─────────────┐
                      │                                              │
سایت (cPanel، ایران) ─┤  https://relay.growwise.ir  (Cloudflare)  ───┼─► Caddy :443 ─► ai-relay.py :8899 ─► Gemini/Groq/Pexels
 130.185.77.84        │                                              │      (VPS آلمان، 82.115.21.133)
                      └───────────── مسیر پشتیبان ("local") ─────────┘
                         https://<id>.lhr.life  (تونل localhost.run)  ──► ssh -R روی همان VPS ─► ai-relay.py :8899
```

- **مسیر اصلی (`vps`)**: دامنه‌ی `relay.growwise.ir`، رکورد A در Cloudflare با حالت **Proxied** به IP سرور. Caddy گواهی Let's Encrypt دارد.
- **مسیر پشتیبان (`local`)**: یک تونل رایگان localhost.run که روی همان VPS اجرا می‌شود. آدرسش (`https://<id>.lhr.life`) **خودبه‌خود عوض می‌شود** و اپ آن را از VPS می‌پرسد (بخش ۷).
- اپ بین این دو مسیر **خودکار** سوییچ می‌کند (بخش ۷).

### چرا این معماری (تاریخچه‌ی مشکل)

- IP قدیمی هاست (`45.159.149.29`) از طرف زیرساخت **فیلتر** شده بود (پشتیبانی هاست تأیید کرد). روی آن node: اتصال به IP این VPS اصلاً برقرار نمی‌شد، و درخواست‌های POST بزرگ‌تر از حدود ۱ کیلوبایت به Cloudflare گم می‌شدند (کوچک‌ها رد می‌شدند). مسیر `lhr.life` با بدنه‌ی بزرگ کار می‌کرد، برای همین تونل ساخته شد.
- هاست به IP جدید **`130.185.77.84`** منتقل شد و روی آن هر دو مسیر با بدنه‌ی تا ۸ کیلوبایت کار کردند. حتی اتصال مستقیم به Google هم جواب داد. رله هم‌چنان نگه داشته شده چون از ریسک فیلتر یا بلاک دوباره‌ی IP جلوگیری می‌کند.
- VPS قدیمی `64.226.95.102` (relay2) دیگر استفاده نمی‌شود و نباید جایی به آن اشاره شود.

## ۳. مشخصات و دسترسی VPS

| مورد | مقدار |
|---|---|
| ارائه‌دهنده | خریداری‌شده از ParsVDS (لوکیشن فرانکفورت، آلمان؛ در whois: BitCommand LLC) |
| IP | `82.115.21.133` |
| سیستم‌عامل | Ubuntu 24.04 LTS |
| منابع | ۱ vCPU، حدود ۱ گیگ رم، ۲۴ گیگ دیسک (مصرف رله ناچیز است) |
| ورود | فقط **SSH با کلید** (`PasswordAuthentication no`)؛ کاربر `root` |
| کلید | روی لپ‌تاپ توسعه: `~/.ssh/englishos_vps` (خصوصی، **داخل ریپو نیست**؛ پشتیبان بگیرید) |
| فایل بستن رمز | `/etc/ssh/sshd_config.d/00-keys-only.conf` |
| فایروال (ufw) | فقط OpenSSH، 80، 443 |

اتصال:

```bash
ssh -i ~/.ssh/englishos_vps root@82.115.21.133
```

## ۴. آنچه روی VPS نصب شده

| چیز | محل / نام | توضیح |
|---|---|---|
| رله | `/opt/englishos-relay/ai-relay.py` | نسخه‌ی `scripts/ai-relay.py` ریپو. پورت `127.0.0.1:8899` (فقط محلی) |
| سرویس رله | `englishos-relay.service` | کاربر بدون دسترسی `englishos-relay`، `Restart=always` |
| env رله | `/etc/englishos-relay.env` | شامل `RELAY_SECRET` و `TUNNEL_LOG` (فقط root و گروه رله می‌خوانند) |
| HTTPS | `caddy` + `/etc/caddy/Caddyfile` | reverse proxy به رله، گواهی خودکار |
| تونل | `englishos-tunnel.service` | `ssh -R` به localhost.run، کاربر `englishos-tunnel` |
| لاگ تونل | `/var/lib/englishos-tunnel/tunnel.log` | آدرس فعلی تونل از همین‌جا خوانده می‌شود |
| فایروال | `ufw` | بخش ۳ |
| Node.js 18 | بسته‌ی سیستم | **دیگر لازم نیست** (بخش ۹) |

### محتوای Caddyfile

```
relay.growwise.ir {
    reverse_proxy 127.0.0.1:8899
    request_body {
        max_size 20MB
    }
}
```

### سرویس رله (`/etc/systemd/system/englishos-relay.service`)

```ini
[Unit]
Description=English OS AI relay (Gemini/Groq forwarding)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=englishos-relay
Group=englishos-relay
EnvironmentFile=/etc/englishos-relay.env
ExecStart=/usr/bin/python3 /opt/englishos-relay/ai-relay.py 8899
Restart=always
RestartSec=3
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
```

(این فایل را `scripts/vps-relay-setup.sh` می‌سازد؛ نیازی به ساختن دستی نیست.)

### سرویس تونل (`/etc/systemd/system/englishos-tunnel.service`) — این را اسکریپت نصب نمی‌سازد، دستی ساخته شده

```ini
[Unit]
Description=English OS relay tunnel (localhost.run)
After=network-online.target englishos-relay.service
Wants=network-online.target

[Service]
Type=simple
User=englishos-tunnel
Group=englishos-tunnel
ExecStart=/usr/bin/ssh -T -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/var/lib/englishos-tunnel/known_hosts -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o ExitOnForwardFailure=yes -R 80:127.0.0.1:8899 nokey@localhost.run
StandardOutput=append:/var/lib/englishos-tunnel/tunnel.log
StandardError=append:/var/lib/englishos-tunnel/tunnel.log
Restart=always
RestartSec=5
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=/var/lib/englishos-tunnel
PrivateTmp=true

[Install]
WantedBy=multi-user.target
```

نکته‌ها: لاگ تونل باید برای کاربر رله خواندنی باشد (`chmod 0755 /var/lib/englishos-tunnel` و `chmod 0644 …/tunnel.log`) چون رله آدرس را از آن می‌خواند. آدرس تونل رایگان **حتی بدون قطع ssh هم عوض می‌شود** (در ۲۰ دقیقه دیده شد).

### endpoint ی که به رله اضافه شده: `GET /_tunnel-url`

اگر متغیر `TUNNEL_LOG` در `/etc/englishos-relay.env` تنظیم باشد، یک `GET /_tunnel-url` **با هدر `X-Relay-Auth` درست** آخرین آدرس `https://<id>.lhr.life` را از لاگ تونل برمی‌گرداند (404 اگر نبود). بدون رمز 401 است. اپ همین را هر دقیقه می‌پرسد.

## ۵. DNS و Cloudflare

| رکورد | مقدار | وضعیت |
|---|---|---|
| `relay.growwise.ir` A | IP سرور VPS | **Proxied** (ابر نارنجی) |

- در Cloudflare بخش SSL/TLS باید روی **Full** یا **Full (strict)** باشد (نه Flexible)، چون Caddy روی VPS گواهی معتبر دارد.
- صدور اولیه‌ی گواهی با HTTP-01 از پشت Cloudflare هم کار کرد (لازم نشد ابر را موقتاً خاکستری کنید)؛ اگر در VPS جدید صدور گیر کرد، رکورد را موقتاً DNS only کنید.
- سایت‌های خود هاست روی IP `130.185.77.84` و **DNS only** هستند. این‌ها به VPS ربطی ندارند.
- `relay2.growwise.ir` رکورد قدیمی است و استفاده نمی‌شود.

## ۶. تنظیمات پروداکشن (`.env` سایت)

```
AI_PROXY_TARGET=vps                       # vps = مسیر Cloudflare، local = تونل (اپ خودش عوض می‌کند)
AI_PROXY_URL_VPS=https://relay.growwise.ir
AI_PROXY_SECRET_VPS=<RELAY_SECRET>        # همان مقدار /etc/englishos-relay.env روی VPS
AI_PROXY_URL_LOCAL=https://<id>.lhr.life  # خودکار به‌روز می‌شود، دستی ننویسید
AI_PROXY_SECRET_LOCAL=<RELAY_SECRET>      # همان رمز بالا؛ هر دو مسیر به یک رله می‌رسند
DEPLOY_TOKEN=<token>                      # برای مسیرهای /_diag/*
```

- هر دو اسلات به **یک** رله‌ی روی یک VPS می‌رسند، پس رمزشان یکی است.
- روی هاست، پس از تغییر `.env` اگر config cache شده باشد باید `config:clear` اجرا شود (`/_diag/ai-relay-use` این کار را می‌کند).
- رمز را جایی کامیت نکنید. (رمز قدیمی‌ای که در `scripts/vps-relay-README.fa.md` هست دیگر استفاده نمی‌شود و بهتر است پاک شود.)

## ۷. کدهای مرتبط در پروژه

| بخش | فایل |
|---|---|
| کد رله | `scripts/ai-relay.py` |
| اسکریپت نصب رله | `scripts/vps-relay-setup.sh` |
| سمت Laravel (پروکسی) | `app/Services/Concerns/UsesOutboundProxy.php`، `config/services.php` (بلوک `ai_proxy`) |
| سوییچ دستی | `php artisan ai:relay-use vps|local` یا `/_diag/ai-relay-use?token=…&target=…` یا تب «AI Relay» در پروفایل ادمین |
| به‌روزرسانی خودکار آدرس تونل | `app/Console/Commands/AiRelaySyncLocalUrl.php` (`ai:relay-sync-local-url`، هر دقیقه) |
| سوییچ خودکار مسیر | `app/Console/Commands/AiRelayFailover.php` (`ai:relay-failover`، هر دقیقه) |
| زمان‌بندی | `routes/console.php` (داخل همان پروسه اجرا می‌شود چون هاست `proc_open` را بسته) |

**سوییچ خودکار:** هر دقیقه به هر مسیر یک POST ۳ کیلوبایتی بدون رمز می‌فرستد؛ جواب 401 `bad auth` یعنی سالم. اگر مسیر `vps` دو دقیقه پشت‌سرهم خراب بود و تونل سالم بود، `target` را روی `local` می‌گذارد؛ اگر `vps` سه دقیقه پشت‌سرهم سالم بود، برمی‌گرداند. فقط اتصال و اندازه‌ی بدنه را می‌سنجد، نه خطاهای خود Google. هر سوییچ در لاگ ثبت می‌شود.

**چرا آدرس تونل را اپ می‌کشد و VPS نمی‌فرستد:** از VPS به هاست (روی IP قدیمی) داده نمی‌رسید، ولی هاست می‌توانست درخواست کوچک به `relay.growwise.ir` بزند. این معماری «کشیدن» همان را پوشش می‌دهد.

## ۸. اگر روزی خواستید VPS را عوض کنید

هدف: بدون دست زدن به `.env` پروداکشن، همه‌چیز را روی سرور جدید بسازید و فقط یک رکورد DNS را عوض کنید. **همان `RELAY_SECRET` را دوباره استفاده کنید.**

۱. **خرید:** VPS با لوکیشن **خارج از ایران** و در کشوری که Gemini در آن پشتیبانی می‌شود (آلمان، هلند، فنلاند، آمریکا؛ نه ایران/روسیه/چین). Ubuntu 22.04 یا 24.04، حداقل ۱ vCPU و ۵۱۲MB رم. ماهانه بگیرید تا اگر IP فیلتر بود بتوانید عوضش کنید. IP باید IPv4 اختصاصی باشد و پورت‌های 80 و 443 باز.

۲. **کلید SSH:** روی لپ‌تاپ (اگر کلید ندارید) `ssh-keygen -t ed25519 -f ~/.ssh/englishos_vps -N ""` و بعد `ssh-copy-id -i ~/.ssh/englishos_vps.pub root@<IP-جدید>`.

۳. **قبل از هر کاری** از دسترسی به Google/Groq مطمئن شوید:
   ```bash
   ssh -i ~/.ssh/englishos_vps root@<IP-جدید> \
     'curl -s -o /dev/null -w "google: %{http_code}\n" https://generativelanguage.googleapis.com; curl -s -o /dev/null -w "groq: %{http_code}\n" https://api.groq.com'
   ```
   باید کد HTTP (مثل 404/200)، نه `000` بیاید.

۴. **نصب رله** (رمز فعلی را از `/etc/englishos-relay.env` سرور قدیمی بردارید یا از `.env` پروداکشن):
   ```bash
   scp scripts/ai-relay.py scripts/vps-relay-setup.sh root@<IP-جدید>:/root/
   ssh -i ~/.ssh/englishos_vps root@<IP-جدید> \
     "DOMAIN=relay.growwise.ir RELAY_SECRET='<همان رمز>' RELAY_SOURCE=/root/ai-relay.py bash /root/vps-relay-setup.sh"
   ```
   اسکریپت بسته‌ها، سرویس رله، Caddy با HTTPS و ufw را می‌سازد. **قبل از اجرا، رکورد DNS را** (قدم ۶) باید برای دامنه روی IP جدید اشاره داده باشید وگرنه گواهی صادر نمی‌شود. اگر نشد، رکورد را DNS only کنید.

۵. **تونل:** سرویس `englishos-tunnel` (بخش ۴) را بسازید و فعال کنید:
   ```bash
   useradd --system --create-home --home-dir /var/lib/englishos-tunnel --shell /usr/sbin/nologin englishos-tunnel
   chmod 0755 /var/lib/englishos-tunnel
   # فایل unit را بخش ۴ در /etc/systemd/system/englishos-tunnel.service بگذارید
   systemctl daemon-reload && systemctl enable --now englishos-tunnel
   chmod 0644 /var/lib/englishos-tunnel/tunnel.log
   echo 'TUNNEL_LOG=/var/lib/englishos-tunnel/tunnel.log' >> /etc/englishos-relay.env
   systemctl restart englishos-relay
   ```

۶. **DNS:** رکورد `relay.growwise.ir` در Cloudflare را به IP جدید ببرید (Proxied). SSL/TLS روی Full یا Full (strict).

۷. **تست از لپ‌تاپ:**
   ```bash
   curl -s -w " [%{http_code}]\n" https://relay.growwise.ir/                              # باید: bad auth [401]
   curl -s -w " [%{http_code}]\n" -H "X-Relay-Auth: <رمز>" https://relay.growwise.ir/_tunnel-url   # باید آدرس lhr.life [200]
   ```
   حتماً **از خود هاست** هم تست کنید، نه فقط لپ‌تاپ (قبلاً فقط از هاست مشکل داشتیم): `/_diag/ai?token=<DEPLOY_TOKEN>` را باز کنید و بخش‌های ۱، ۲ (`pong`) و ۲c (اندازه‌های بدنه) را ببینید.

۸. **VPS قدیمی:** تا اطمینان کامل سرویسش را نگه دارید، بعد تونل و رله را `systemctl disable --now` کنید و سرور را حذف کنید.

اگر فقط **IP یا دامنه‌ی رله** عوض می‌شود: در `.env` پروداکشن `AI_PROXY_URL_VPS` را عوض کنید و config cache را پاک کنید.

## ۹. عیب‌یابی

| نشانه | علت محتمل | بررسی |
|---|---|---|
| «Couldn't reach Sage» | رله یا مسیر در دسترس نیست | `/_diag/ai?token=…` — بخش ۱ باید `401 bad auth`، بخش ۲ باید `pong` باشد |
| بخش ۱ خطای اتصال فوری (۰ میلی‌ثانیه) | IP هاست یا IP رله فیلتر شده | با پشتیبانی هاست صحبت کنید؛ مسیر دیگر را (`ai:relay-use local`) امتحان کنید |
| فقط بدنه‌های بزرگ timeout می‌شوند | همان مشکل قدیمی مسیر Cloudflare روی یک node خاص | بخش ۲c گزارش `/_diag/ai`؛ تونل را فعال کنید |
| بعد از قطع تونل Sage خراب شد | آدرس تونل عوض شد و هنوز دنبال نشده | `ai:relay-sync-local-url` را اجرا کنید؛ ببینید cron هاست هر دقیقه می‌خورد (`/_diag/health`) |
| پاسخ Groq/Gemini به اپ می‌رسد ولی JSON خراب یا متن خالی است | رله پاسخ فشرده را خراب کرده (نسخه‌ی قدیمی) | روی VPS: `curl -H 'Accept-Encoding: br' …` با `-D -` بگیرید؛ باید `Content-Encoding: br` برگردد و بایت اول `{` نباشد |
| رله جواب می‌دهد ولی 403 | مقصد در `ALLOWED_HOSTS` نیست | `ai-relay.py` را روی VPS ببینید و سرویس را ری‌استارت کنید |
| 502 `relay fetch failed` | مقصد از VPS در دسترس نیست یا بیشتر از ۳۰ ثانیه طول کشید | `journalctl -u englishos-relay -f` |
| لاگ‌ها | `journalctl -u englishos-relay`، `journalctl -u englishos-tunnel`، `journalctl -u caddy` | |

وضعیت سرویس‌ها: `systemctl status englishos-relay englishos-tunnel caddy`.

## ۱۰. نکات امنیتی و موارد باز

- **حریم خصوصی:** هر دو مسیر ترافیک را از یک واسطه‌ی شخص ثالث رد می‌کنند (Cloudflare، و در مسیر پشتیبان خود localhost.run). این‌ها TLS را خودشان تمام می‌کنند و می‌توانند **کلیدهای API و متن گفتگوها** را ببینند. کلیدهای Gemini/Groq را در پنل‌هایشان محدود (سقف سهمیه، در صورت امکان محدودیت) کنید.
- رله فقط به مقصدهای فهرست‌شده می‌رسد و بدون رمز 401 می‌دهد؛ ولی رمز یکی است و برای هر دو سایت (`englishos` و `skillos`) مشترک است. اگر لو رفت، همه جا عوض شود.
- `/opt/englishos-tunnel/` روی VPS حاوی بقایای یک سرویس انتشار (`relay-publish-url.cjs`, `config.json`) است که **غیرفعال و بی‌استفاده** است. `config.json` آن شامل یک token و رمز رله است؛ بهتر است پاک شود (`systemctl disable englishos-tunnel-publisher` و `rm /etc/systemd/system/englishos-tunnel-publisher.service /opt/englishos-tunnel/config.json`). بعدش Node.js را هم می‌شود حذف کرد.
- مسیرهای `/_diag/*` با `DEPLOY_TOKEN` (در query string) محافظت می‌شوند؛ token را در جای عمومی نفرستید.
- **تونل رایگان** بی‌ضمانت است (ممکن است محدود یا قطع شود). مسیر اصلی `vps` است؛ تونل فقط پشتیبان است.
