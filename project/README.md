# Parkaro - Parking Reservation System

A PHP / MySQL web application for booking and managing parking in three towns
(Kolhapur, Ajara and Gadhinglaj). Visitors book a parking spot for a number of hours
and download a PDF ticket; zone administrators see and manage the bookings of their
zone.

> Student project, security-reviewed and hardened for portfolio use. See
> [docs/AUDIT_REPORT.md](docs/AUDIT_REPORT.md) for what was found, what was fixed and
> what is still open.

> **New:** parking places, time-window reservations, verified payments, user/admin dashboards and earnings reports. Setup, migration and the XAMPP checklist: [docs/PARKKARO_UPGRADE.md](docs/PARKKARO_UPGRADE.md).

## Features

- Guest booking per town (`kolhapur_booking.php`, `ajara_booking.php`, `gadhinjlaj_booking.php`) and a combined form (`booking.html`)
- Capacity check per location (10 cars at a time); a booking uses a place only until its hours run out
- Verified confirmation page and PDF ticket (dompdf) built from the saved booking
- User registration / login and a support-ticket page (`index.php`)
- Admin login, zone dashboard (bookings today, this month's revenue), view / add / delete bookings
- Slot management per town with status history, bulk status change, search and paging
- Map pages (Leaflet + OpenStreetMap)

## Technology

PHP 8.2 (no framework), MySQL / MariaDB via `mysqli`, dompdf 3.1 (bundled in `dompdf/`),
Leaflet for maps, plain HTML/CSS/JavaScript.

## Prerequisites

- XAMPP (or any Apache + PHP 8.1+ + MySQL/MariaDB) with the `mysqli` and `mbstring` PHP extensions (both on by default in XAMPP)
- A browser

## Installation (XAMPP on Windows)

1. Copy this folder to `C:\xampp\htdocs\project`.
2. Start **Apache** and **MySQL** in the XAMPP control panel.
3. Database:
   - **Existing database (`parkaro`)**: keep using it. Compare its tables with
     [database/schema.sql](database/schema.sql) (reconstructed from the code, see the note at its top).
   - **Fresh start**: in phpMyAdmin create a database named `parkaro`, then import `database/schema.sql`.
4. Copy `.env.example` to `.env` and edit it if your MySQL user / password / database name differ from the XAMPP defaults.
5. Create your first admin: open `register.php?type=admin` and register with a username and password (or, from the command line: `php tools/create_admin.php --username=your_name --zone="Zone A" --yes`). Log in at `login1.php`. See `.env.example` for `ADMIN_SIGNUP_CODE` / `ADMIN_REGISTRATION=off`.
6. Open <http://localhost/project/home.html>.

### Admin passwords

Older rows stored plaintext passwords. The login still accepts those so that you are not
locked out, but you should convert them once:

1. Back up the database (phpMyAdmin > Export).
2. Make sure `admin_users.password` is `VARCHAR(255)`. The script tells you the exact `ALTER` statement if it is not.
3. From the project folder: `php tools\migrate_admin_passwords.php --yes`

If your old passwords were ever shared or committed anywhere (including `admindetails.txt`), **change them**.

## Usage

| Who | Where |
|---|---|
| Visitor | `home.html` -> pick a town -> book -> download ticket |
| User | `index.php` (register / log in / support tickets) |
| Admin | `login1.php` -> `dashboard1.php`, `view_bookings.php`, `manage_bookings.php`, `manage_slots.php`, `manage_gad.php`, `manage_ajara.php` |

## Configuration

All settings come from `.env` (or real environment variables): `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `APP_KEY`.
No credentials live in the source code. Rates and limits are constants at the top of the files:
`PARKING_CAPACITY` and `MAX_BOOKING_HOURS` in `src/booking.php`; the price per hour is set in each booking page
(Rs 20 on the town pages, Rs 50 in `book.php`; see "Known limitations").

## Testing

```text
php tests/unit/run.php              # validation, CSRF, ticket signing, passwords, escaping (no database)
php tests/unit/ticket_test.php      # renders a real PDF ticket with dompdf (no database)
php tests/http/run.php              # starts PHP's built-in server; login guards, CSRF, forged tickets (no database)
```

Database tests need a **throw-away** database whose name ends in `_test` (the script refuses anything else):

```text
$env:TEST_DB_NAME="parkaro_test"; php tests/integration/db_test.php
```

It covers capacity, expiry, 8 simultaneous bookings for 3 places (needs the `pcntl` extension: Linux/macOS/WSL),
slot history and users.

## Project layout

```
src/bootstrap.php   config, DB connection, sessions, CSRF, login guards, signed ticket links
src/booking.php     locations, validation, capacity check + insert, ticket HTML
src/users.php       registration / login
src/admin.php       admin booking actions     src/slots.php   slot management
db.php              one-line include that gives pages $conn
tools/              one-time admin password migration
tests/              unit, HTTP and database tests
legacy/             unreferenced old files, blocked from the web (see legacy/README.md)
dompdf/             bundled PDF library (LGPL-2.1; php-font-lib LGPL-2.1+, php-svg-lib LGPL-3.0+,
                    php-css-parser MIT, html5-php MIT)
```

## Security notes

Prepared statements everywhere; passwords hashed (`password_hash`); session id regenerated at login and
destroyed at logout; CSRF token on every state-changing form; all output escaped; tickets addressed by
booking id + HMAC token; `.htaccess` blocks `src/`, `storage/`, `legacy/` and note/secret file types.
Details and test evidence: [docs/AUDIT_REPORT.md](docs/AUDIT_REPORT.md).

## Known limitations

- Bookings have no start date/time and no user id. A booking starts when it is made and lasts the chosen hours; guests are identified only by their signed ticket link.
- Two booking rates (Rs 20 and Rs 50) and two location lists exist; they were kept as found.
- Several towns share zone names ("Zone A" exists in each town), so a zone admin sees every booking with that zone name.
- The slot-based pages (`manage_*`, `dashboards/*`, `revenue.php`) expect an older schema (`bookings.location`, `slot_number`, ...) that may not match your live database.
- No payments, no email, no rate limiting or CAPTCHA on the guest booking form.
- The booking-concurrency and database tests were written but not run in the sandbox used for the audit (no MySQL was available).

## License

Your own code: add a `LICENSE` file of your choice (MIT is common for portfolios). Bundled libraries keep their own licenses (listed above).
