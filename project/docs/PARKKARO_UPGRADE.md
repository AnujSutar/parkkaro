# Parkkaro upgrade: parking places, reservations, payments, dashboards, earnings

Everything here is **additive**. Your old pages, tables and data keep working. New features switch on after you run one SQL migration.

## 0. Login, roles and dashboards (v2 fix)

**Root cause of "the separate logins/dashboards are missing":** `home.html` had a single "Login" link and it pointed to the ADMIN login (`login1.php`), so users had no way to find their login; the User/Admin chooser (`landingLogin.html`) was not linked from anywhere. After a user login, `index.php` sent the user back to the support-ticket page instead of `my_dashboard.php`. `admin_dashboard.php` could never open (it checked a session key no login sets, used a different database, and redirected to a non-existent page), and the old `dashboard.php` was orphaned. So two dashboards existed on disk but nothing led to them.

**Final URLs** (replace the base with your folder, e.g. `http://localhost/parkaro/`):

| What | URL |
|---|---|
| Public homepage | `home.html` (nav: User Login, Register, Admin Login) |
| User login / registration | `index.php?login=true` / `index.php` (also `register.php`) |
| Admin login | `login1.php` |
| User dashboard (after user login) | `my_dashboard.php` |
| Admin dashboard (after admin login) | `dashboard1.php` (the canonical one) |
| Admin tools | `admin_locations.php`, `admin_reservations.php`, `admin_earnings.php` |
| Logout (both roles) | `logout.php` -> `home.html` |

**Redirect flow:** user login -> `my_dashboard.php` (or the page that required login: only `find_parking`, `reserve`, `pay`, `my_dashboard` are accepted as `next`, so there is no open redirect); admin login -> `dashboard1.php`; registration -> link to the user login; logout -> `home.html` with the whole session destroyed.
**Role policy:** one role per browser session. Logging in as a user removes any admin session keys and vice versa; the session id is regenerated at every login. `require_user()` / `require_admin()` (in `src/bootstrap.php`) are the only guards; a logged-out visitor, or the wrong role, is sent to the matching login.
**Canonical admin dashboard:** `dashboard1.php`. `admin_dashboard.php` now just forwards to it (the old file is kept in `legacy/`); `dashboard.php` forwards to `my_dashboard.php`.
**Requires** PHP `pdo_mysql` (enabled by default in XAMPP): the login, registration and ticket code in `index.php`, `register.php` and `login1.php` now use PDO (`pdo()`), so the login journeys can be tested; table and password formats are unchanged (existing users and admins, including plaintext legacy admin rows, still log in).

## 0b. Admin accounts: the username mismatch (v3 fix)

**Root cause.** `register.php` / `index.php` registration only ever writes the `users` table (people log in with their e-mail; that table has no username). Admin Login (`login1.php`) reads `admin_users.username`. Nothing in the project created `admin_users` rows at all: the only way was a hand-typed SQL INSERT (old README step 5, with a plaintext password). So an "admin who registered" could never exist, and adding a Username box to the user form would not have created an administrator either.

**Fix (v5: users AND admins can register on `register.php`).**
* `register.php` = user registration (unchanged). `register.php?type=admin` = admin registration (Username, Password, Repeat password; plus a sign-up code if you set one). Switch between them with the two links at the top of the form. After registering, admins log in at `login1.php` with that username, and land on `dashboard1.php`.
* **Safety of public admin sign-up:** a self-registered admin is placed in a brand-new private zone (`Own-xxxxxx`); they can never choose an existing zone, so they cannot read or delete anyone else's bookings or customers (the old pages are zone-based; the new parking-place, reservation and earnings pages are username-scoped). Forged `admin_zone`/`role`/`is_admin` form fields are ignored, and the user form can never create an admin. `ADMIN_REGISTRATION=off` in `.env` switches public admin sign-up off; `ADMIN_SIGNUP_CODE=...` requires a code (recommended once the site is online, otherwise anyone can create admin accounts and list fake parking places).
* `src/admin_accounts.php`: one validator + creator. Username required, 3-50 chars, letters/digits, single spaces between words (e.g. "anuj sutar") and `. _ @ + -`; unique ignoring upper/lower case (DB UNIQUE key as backstop); password 8-72 chars with a letter and a digit, stored with `password_hash()`. A taken username gives "This username is already taken."
* `admin_create.php` ("Add Admin Account"): a logged-in admin adds a colleague, always into the creator's OWN zone (the zone cannot be edited). `tools/create_admin.php`: server-owner command line, any zone: `php tools/create_admin.php --username=NAME --zone="Zone A" --yes`.
* Admin Login uses the same `username` column. No database migration is needed; `admin_users` is unchanged and existing admins/users/passwords/bookings are untouched (legacy plaintext admin rows still log in; hash them with `tools/migrate_admin_passwords.php`).
* Users and admins stay separate: user credentials do not work at Admin Login and admin credentials do not work at User Login.

**XAMPP steps:** open `register.php?type=admin`, register, then log in at `login1.php`. (Optional: set `ADMIN_SIGNUP_CODE` in `.env`; or create an admin on the command line with `C:\\xampp\\php\\php.exe tools\\create_admin.php --username=... --zone="..." --yes`.) Checklist: create an admin with a taken username (error), with a weak password (error), log in with the new account (lands on `dashboard1.php`), try the same credentials at User Login (rejected), open `admin_create.php` while logged out and as a normal user (sent to a login).

## 1. What was added

| Area | Who | Page |
|---|---|---|
| Search public/private parking by name, city, type and time window | anyone | `find_parking.php` |
| Reserve a time window at a place | logged-in user | `reserve.php` |
| Pay online (Razorpay) or have the admin record cash | user / admin | `pay.php`, `payment_callback.php`, `payment_webhook.php`, `admin_reservations.php` |
| User dashboard (stats, awaiting payment, upcoming, past, tickets) | user | `my_dashboard.php` |
| Register / edit / switch off my parking places | admin | `admin_locations.php` |
| Reservations at my places, record cash | admin | `admin_reservations.php` |
| Daily / monthly / per-place earnings from verified payments | admin | `admin_earnings.php` |
| Existing admin dashboard shows verified earnings and status | admin | `dashboard1.php` (modified) |

## 2. How the rules work (assumptions, viva-ready)

* **Capacity over time.** A place has `capacity` spaces. A reservation `[start, end)` is accepted only if, at every instant inside it, fewer than `capacity` other active reservations overlap. Active = `confirmed`, or `pending_payment` whose 20-minute hold has not expired. The check runs inside a transaction holding a row lock on the place (`SELECT ... FOR UPDATE`), so two people cannot take the last space together.
* **Same car, same time** cannot hold two overlapping reservations (this is the "overlap" refusal you may see).
* **Price** is computed on the server: started hours x the place's hourly rate. The browser never sends a price. Changing the rate later does not change existing reservations.
* **Payment is never assumed.** Online success is recorded only when the Razorpay API reports `captured` for the stored order with the matching amount and currency (after the checkout signature `HMAC_SHA256(order_id|payment_id)` is verified), or from a webhook whose `X-Razorpay-Signature` (HMAC of the raw body) is valid. Cash is recorded only by the owning admin. Events are de-duplicated.
* **Late payment.** If money arrives after the hold lapsed: it confirms the reservation if the space is still free; otherwise the payment is kept, the reservation stays unconfirmed, it is NOT counted as earnings and it is listed on the earnings page for refund. **Refunds are not automated.**
* **Earnings** = payments with `status='succeeded'` whose reservation is `confirmed`, at places owned by that admin. Online and cash are shown separately; unpaid reservations are shown separately as "awaiting payment, not earned". Old `bookings.cost` values (never verified) are not counted as earnings.
* Money is whole rupees; times are stored in `APP_TIMEZONE` (default Asia/Kolkata).
* Paid reservations cannot be cancelled or deleted from the UI (no refund flow). The old "delete booking / clear all" buttons skip bookings that have payments.

## 3. Setup (XAMPP, Windows)

1. Back up: phpMyAdmin > select your database > Export > Quick > Go.
2. Copy the project over your `htdocs\parkaro` folder (keep your own `.env`).
3. Make sure `extension=pdo_mysql` is enabled in `php.ini` (it is by default in XAMPP) and restart Apache.
4. Add to `.env` (see `.env.example`): `APP_TIMEZONE=Asia/Kolkata`, and for online payments `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET` (use TEST keys first). Leave them empty to run cash-only.
5. Run the migration (section 4).
6. Razorpay (optional): Dashboard > Settings > Payment capture: automatic. Webhooks: URL `https://YOUR-PUBLIC-DOMAIN/payment_webhook.php`, event `payment.captured`, secret = `RAZORPAY_WEBHOOK_SECRET`. Webhooks cannot reach `localhost`; use a tunnel (e.g. ngrok) to test them. Without webhooks the browser callback still confirms payments. If PHP on Windows cannot verify HTTPS, set `curl.cainfo` in `php.ini` to a CA bundle file.

## 4. Migration

File: `database/migrations/001_parkkaro_core.mysql.sql`

* Creates 4 new tables (`IF NOT EXISTS`): `schema_migrations`, `parking_locations`, `payments`, `payment_events`.
* Adds 7 columns and 2 indexes to `bookings`, each only if missing. Existing rows are untouched; their new `status` is `confirmed` by column default.
* No DROP / TRUNCATE / RENAME / UPDATE / DELETE. Safe to run repeatedly. No foreign keys (old tables may be MyISAM). Uses only MySQL 5.7+/MariaDB 10.x features; index sizes fit the old 767-byte InnoDB limit.

Run it with ONE of:
* phpMyAdmin: your database > SQL tab > paste the file > Go.
* `php tools/check_migration.php` (read-only preview), then `php tools/apply_migration.php --yes`.
* `mysql -u root parkaro < database/migrations/001_parkkaro_core.mysql.sql`

Verify: `php tools/check_migration.php` should say "migration fully applied", and legacy bookings by status all `confirmed`.
Rollback: restore your backup (manual DROP statements are in comments at the end of the SQL file and delete the new data).

## 5. Changed files vs the original ZIP

New: `find_parking.php reserve.php pay.php payment_callback.php payment_webhook.php my_dashboard.php admin_locations.php admin_reservations.php admin_earnings.php`, `src/{dbx,reservations,locations,payments,earnings,ui}.php`, `database/migrations/001_parkkaro_core.mysql.sql`, `tools/{check_migration,apply_migration}.php`, `tests/sqlite/*`, `tests/integration/mysql_migration_test.php`, `.htaccess` in tests/tools/database, this document.
New in v5 (public admin registration): `register.php`, `login1.php`, `index.php` (links), `src/admin_accounts.php` (`register_admin_public`, private zones), `admin_create.php` (zone fixed to creator's), `.env.example`.
New in v3 (admin accounts): `src/admin_accounts.php`, `admin_create.php`, `tools/create_admin.php`; modified `src/ui.php`, `dashboard1.php` (menu link), `README.md` (step 5), `tests/sqlite/auth.php`.
Modified in v2 (login/roles): `src/bootstrap.php` (role guards, `login_user_session`, `login_admin_session`, `safe_next`, `find_admin`, `USER_HOME`, `ADMIN_HOME`), `src/users.php` (register/authenticate also accept PDO), `index.php` (login -> my_dashboard, PDO, next, role links), `login1.php` (PDO, one-role session, links), `register.php` (PDO, links), `home.html` (User Login / Register / Admin Login), `landingLogin.html` (User link fixed), `dashboard.php` + `admin_dashboard.php` (forward to the canonical pages), `src/ui.php` and `dashboard1.php` (consistent nav labels), `tests/sqlite/auth.php` (+ `tickets` table in `tests/sqlite/schema.sql`).
Modified (small, backward compatible): `src/bootstrap.php` (adds `pdo()`), `src/booking.php` (window columns optional, `booking_is_confirmed`), `src/admin.php` (protect paid bookings), `dashboard1.php` (verified earnings, status), `booking_confirmed.php`, `generate_ticket.php` (only confirmed tickets), `index.php`, `home.html` (links), `.env.example`.
Unchanged: all other legacy pages, design files, `dompdf/`.

## 6. Test results (run in the development sandbox, PHP 8.3, SQLite, no MySQL)

| Suite | Result |
|---|---|
| `php -l` on all PHP files (excluding dompdf) | all clean |
| `php tests/sqlite/run.php` (logic: capacity, windows, payments, webhook, earnings, 8-process concurrency) | 221 passed, 0 failed |
| `php tests/sqlite/pages.php` (real pages on PHP's web server: CSRF, ownership, XSS, pay > callback > confirmed, cash, earnings) | 115 passed, 0 failed |
| `php tests/sqlite/auth.php` (real logins, cookies, sessions, redirects, role isolation, logout, links, admin account creation incl. the CLI; served from the subfolder `/parkkaro_upgraded/project/`) | 197 passed, 0 failed |
| `php tests/unit/run.php` | 69 passed |
| `php tests/unit/ticket_test.php` | 5 passed |
| `php tests/http/run.php` | 58 passed |

Bugs found and fixed by these tests: session started after page output in `ui_page_start` (fixed); several test-script mistakes.

### NOT tested here (needs your XAMPP / real services)
* Executing the migration on real MySQL/MariaDB: `php tests/integration/mysql_migration_test.php` on a throwaway DB named `*test*`.
* `SELECT ... FOR UPDATE` locking under real concurrent MySQL connections (SQLite was used for the concurrency test).
* After logging in as admin, the real `dashboard1.php` page (it needs MySQL; only its login guard and redirect were tested). The old mysqli pages (`dashboard1.php`, `view_bookings.php`, `manage_bookings.php`, `src/admin.php`, `find_booking()` fallback) and `tests/integration/db_test.php`.
* Live Razorpay: real order creation, payment fetch, webhook delivery.
* Apache `.htaccess` behaviour, real browsers, the Razorpay checkout popup, PDF ticket on the new flow.

## 7. Manual XAMPP checklist

1. [ ] Back up the DB. `php tools/check_migration.php` shows "not applied yet" and your old row counts.
2. [ ] Apply the migration. Re-run the check: "fully applied". Old bookings unchanged, status `confirmed`. Run it again: no error.
3. [ ] Old site: register/login a user, make a booking through the old city pages, open its ticket. Admin login > `dashboard1.php` works, old bookings still listed.
4. [ ] Admin > My Parking Places: add a private place (capacity 1) and a public one (capacity 3). Edit price. Switch one off: it disappears from search.
5. [ ] Another admin account sees none of the first admin's places, reservations or earnings.
6. [ ] User > Find parking: filter by city/type/time; closed-hours place is flagged.
7. [ ] Reserve capacity-1 place 10:00-12:00 as user A (pay.php appears). As user B try 11:00-13:00: refused. Try 12:00-13:00: accepted.
8. [ ] Do not pay for 20+ minutes: the hold lapses and the slot becomes free again.
9. [ ] Pay with a Razorpay TEST card: redirected to My dashboard, reservation Confirmed, ticket opens. Admin earnings show the amount; "Online" column.
10. [ ] Cancel an unpaid reservation (works); a paid one cannot be cancelled.
11. [ ] Admin > Reservations > "Record cash received" on an unpaid reservation: confirmed, earnings "Cash" increases; second click is refused.
12. [ ] Earnings page: today / this month / last month / custom range; an invalid range shows an error; unpaid shows only under "awaiting payment".
13. [ ] Open `http://localhost/parkaro/tests/` and `/src/` and `/.env`: all forbidden.
14. [ ] (Optional) Run `tests/integration/mysql_migration_test.php` on a throwaway DB.

### Manual login/role checks (XAMPP)
15. [ ] `home.html`: click User Login (shows the user login), Register, Admin Login. Log in as a user: you land on `my_dashboard.php`. Log out: back on the homepage; the Back button does not show the dashboard data.
16. [ ] Log in as admin: you land on `dashboard1.php` with the sidebar (Admin Dashboard, Manage Parking Locations, Reservations, Earnings). While logged in as admin open `my_dashboard.php`: you are sent to the user login. Log in as a user in the same browser: admin pages are no longer open.
17. [ ] Logged out, open `reserve.php?location=1`: you are sent to the user login and returned to the reservation page after logging in.
18. [ ] Existing user and admin accounts (including old plaintext admin rows) still log in. Generate a ticket for an old booking.
19. [ ] Click every link in the user and admin navigation, including `view_bookings.php` and `manage_bookings.php` (these old mysqli pages could not be run in the sandbox).

## 8. Known limitations
* No refunds or payouts; late payments that cannot be confirmed are only flagged for manual refund.
* Old pages (`view_bookings.php`, `manage_bookings.php`) count every booking row of a zone, including new-flow unpaid ones, in their slot totals.
* Zone names repeat across towns in the old design; new places are identified by owner + name, not zone.
* The old city-based booking flow is unchanged and its prices are not "verified" income.

## 9. Find parking: nearby map, free parking, distance and directions

`find_parking.php` is now the single place for discovery. The old `nearby.html` redirects to it (original kept in `legacy/nearby.html`); the home page "Nearby" box points to it. No second booking system was added: **Book Parking** links to the existing `reserve.php?location=ID` -> `pay.php` flow, so login, time window, capacity and payment verification are unchanged.

* **Two kinds of card, never mixed up.** *Bookable* = active rows of `parking_locations` (admin-registered): hourly price, free spaces / capacity from the existing capacity logic, "Full" / "Closed" status with no Book button. *Free Parking* = informational spots from `src/free_parking_data.php`: no price, no Book button, "availability is unknown".
* **City selector** (Kolhapur, Ajara, Gadhinglaj) filters the list and the map on the server (`?city=`); unknown values are ignored. Type filter has "Free parking only"; private/public show bookable places only.
* **Map**: Leaflet 1.9.4 + OpenStreetMap tiles (no API key, no paid service). Green = bookable, blue = free, red = you. Clicking a card or a marker selects both.
* **Your location**: asked once when the page opens and again when you press *Use my location*; read with `getCurrentPosition` (never `watchPosition`, nothing is tracked in the background). The red marker appears only after the browser returns a real position. Denied / unavailable / timeout / unsupported / insecure-page cases each show a message and the city selector still works. If you picked a city yourself, the automatic fix does not move the map away from it; the button does.
* **Distance**: straight-line (great-circle) estimate in m/km, nearest first; it is not road distance or travel time.
* **Directions**: `https://www.google.com/maps/dir/?api=1&origin=…&destination=…&travelmode=driving` opens in a new tab. `origin` is added only from the real browser position; without permission only `destination` is sent.
* **Geolocation needs HTTPS or `http://localhost`.** `http://localhost/parkkaro_upgraded/project/` works; opening the site through a LAN IP (`http://192.168.x.x/...`) will be refused by the browser, and the page then says so.
* **Migration: none required.** No table or column changed. Free spots are a PHP data file, not database rows, so nothing can be dropped or duplicated.
* **Free-spot data** was merged from the old pages by `tools/build_free_parking_data.php` (138 unique spots shown, 68 flagged). Entries with conflicting or implausible coordinates were NOT shown or guessed: they are listed in `docs/PARKING_DATA_FLAGS.md` for you to verify. None of the coordinates were checked against a survey.

### Manual checklist (localhost)
20. [ ] `http://localhost/parkkaro_upgraded/project/find_parking.php` loads, map tiles appear, no red error in the browser console.
21. [ ] Allow location: a red dot and "About N m away" appear on cards, nearest first. Press *Use my location* again: it re-centres.
22. [ ] Block location (site settings): the message appears and nothing breaks; choose a city and browse.
23. [ ] Choose Kolhapur, then Ajara, then Gadhinglaj: map re-centres, list changes, admin places of that city appear with a Book Parking button.
24. [ ] A free card has the blue "Free Parking" badge, no price and no Book button; *Get Directions* opens Google Maps in a new tab with your position as start (if allowed) and the spot as destination.
25. [ ] Admin: add a place with a latitude/longitude; it shows in Find parking. Switch it off: it disappears. Fill it for a time window: it shows "Full" with no Book button.
26. [ ] Spot-check a few free parking pins on the map against reality; report wrong ones (see `docs/PARKING_DATA_FLAGS.md`).

## 10. New home page and Live map page

* `home.html` was redesigned (shared styles in `site.css`; the old page is kept as `legacy/home_old.html`). The "Booking" button is now **Live map**; "Nearby" and the city menu now lead to Find parking. The hero has a search box that opens Find parking with the chosen city.
* `booking.html` keeps its URL and page but the **old booking form is removed** (name/e-mail/hours form and the fixed Rs 50 price; original kept as `legacy/booking_old.html`). It is now the **Live map**: the map, a city selector, *Use my location*, the 8 nearest places with straight-line distance and Google Maps directions. Reservable places link to `reserve.php` (the one booking flow); free places have no Book button.
* `parking_points.php` is a small public JSON feed (free spots + active admin places with coordinates: name, city, coordinates, price; no personal data) used by the Live map.
* `book.php` (the old form's receiving script) was left in place and is no longer linked from anywhere. It can be removed or turned into a redirect to Find parking if you want it gone; I did not change it.
* The pages load two Google fonts; if offline they fall back to system fonts.
* Tests: `node tests/browser/pages.mjs` (Chromium; Leaflet stubbed as in section 9).

## 11. Fix: places showing under the wrong city
Find parking now decides a place's city from its **map position** first (inside the Kolhapur / Ajara / Gadhinglaj area), then the city text (the first-named city wins, so "Ajara, Kolhapur" is Ajara), then the address. The admin form's city is a list of the three cities, no longer suggests Kolhapur coordinates as an example, and refuses a city that disagrees with the coordinates ("The latitude/longitude is in Kolhapur, but the city says Ajara"). Existing rows are fixed automatically when displayed; no migration. A place with no coordinates is matched by its city text only and does not appear on the maps.

## 12. Sign-in and registration pages match the home page
`register.php` (user and admin), `index.php` (user login / registration / support tickets) and `login1.php` (admin login) now use the same header, fonts, colours and footer as `home.html` through `src/auth_ui.php` and `site.css`. Login, registration, CSRF and role logic are unchanged; only the presentation changed. The admin login page no longer shows the unrelated New Delhi map. Old versions are in `legacy/*_old.php`. Browser test: `node tests/browser/pages.mjs`.

## 13. Payment methods per place and ticket confirmations
See `docs/NOTIFICATIONS_SETUP.md` (setup, credentials, checklist). Summary:
* **Migration 002 is required** for this feature (`php tools/apply_migration.php 002 --yes`). It is additive and idempotent; without it the app behaves exactly as before.
* Owners choose per place: online (Razorpay) and/or at the place (cash recorded by the owner). Enforced on the server in `pay.php`, `start_online_payment()` and `record_cash_payment()`.
* After a **verified** payment (callback, webhook or owner-recorded cash) the customer gets a ticket by e-mail (SMTP) and, if they gave a number and ticked it, WhatsApp / SMS (Twilio). Once per booking and channel; failures are logged in `notifications` and retried safely.
* Code: `src/notify.php`; hooks in `src/reservations.php` (`confirm_booking_in_tx`) and `src/payments.php`.
* Tests: `tests/sqlite/pages.php` (owner permissions, forced online payment at a cash-only place, no message for unpaid/failed/forged, once-only on repeated callbacks and webhooks, provider outage and retry, skipped channels, cash), `tests/unit/notify_test.php` (phone numbers, Twilio request format, SMTP conversation with a local fake server, header-injection, escaping), `tests/integration/mysql_migration_test.php` (migration 002 on a real MySQL; **not run in the sandbox**).

## 14. Owner confirms Cash / UPI received (fix for "ticket not sent after cash")
**What was wrong:** the owner's screen only had a bare "Record cash received" button: no choice between cash and UPI paid to the owner, no review of the reservation, no "money received" confirmation, and the ticket messages depended on migration 002 and on `.env` e-mail/SMS settings. When those were missing, the message was silently recorded as "skipped" and the owner saw nothing, so it looked as if no ticket was sent. The ticket itself (dashboard link) was always created on confirmation.

**What it does now** (`admin_reservations.php`, `src/payments.php::record_cash_payment`, `src/notify.php`):
* Reservations > **Confirm Cash/UPI Payment** opens a review screen (number, customer, place, vehicle, time, amount). The owner picks **Cash** or **UPI received by owner** and must tick that the money was really received. Amount always comes from the stored reservation; the amount on the form is only a guard and a mismatch records nothing.
* Only the owner of that place can do it (other owners see "not found"); CSRF protected; refused if already paid, cancelled, or the hold has ended (the screen explains: do not take the money, or refund it and ask for a new reservation). One database transaction; messages are sent after commit, never inside it.
* Payment rows: `provider 'cash'` / `verification admin_cash`, or `provider 'upi_owner'` / `admin_upi`, with the owner's username and time. These are NOT labelled as verified online payments; both count in earnings (shown as "Cash / UPI received by you"), once per payment.
* The admin list shows the payment state (Awaiting payment / Cash paid at the place / UPI received by owner / Verified online payment / Expired) and, per channel, **Sent** (accepted by the provider; not proof of delivery), **Failed** (with reason), **Not sent** (not configured / no number) or "not requested". **Retry messages** re-sends only failed / not-sent channels (never the ones already sent) and only for the owner's own confirmed, paid reservations. A banner tells the owner when migration 002 or `.env` settings are missing.
* Customer dashboard shows the payment method and ticket links; the ticket page shows reservation number, address and payment method (needs migration 001/002 data). The pay page shows pending status, amount, hold expiry, pay-the-owner instructions, and a dashboard link, with no self-confirm button.
* No new migration: this uses the existing `payments` and `notifications` tables (`payments.provider` is VARCHAR(20); `upi_owner` fits).

**Tests added** (all in `tests/sqlite/pages.php` and `run.php`): valid cash, valid UPI, unauthorised owner, duplicate confirmation, expired hold, wrong amount, missing tick / method, ticket visibility and wrong-token ticket access, nothing sent before payment, three channels sent once, duplicate click sends nothing, provider failure keeps the booking, retry (only missing channels), unconfigured channel and retry, retry for unpaid refused.

**Manual XAMPP check:** reserve as a user (with a phone, tick WhatsApp/SMS) > pay page shows "Pay the owner in person" > as owner open Reservations > Confirm Cash/UPI Payment > pick UPI > tick received > confirm > see "Sent"/"Failed" per channel > as the user open My dashboard > ticket link > if a channel failed, fix `.env` and press Retry messages. Real SMTP / WhatsApp / SMS delivery and a real MySQL run are not covered by the automated tests.
