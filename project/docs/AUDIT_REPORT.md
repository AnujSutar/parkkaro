# Parkaro - Security and Correctness Audit Report

Scope: the PHP project supplied as `project 3.zip` (about 35 PHP files, bundled dompdf 3.1.0).
Method: code reading and tracing of every active page, `php -l`, and new automated tests.
**Not done:** nothing was run against MySQL/MariaDB (none was available in the audit sandbox); no real
data, admin file contents or credentials were opened or copied into this report; nothing was committed or published.

## 1. Architecture

No framework. Each `.php` file in the project root is a page. `bookings` is the core table.

- **Public booking:** `home.html` -> `kolhapur.php` / `ajara.php` / `gad.php` -> `*_booking.php` -> `booking_confirmed.php` -> `generate_ticket.php` (PDF). A second form, `booking.html` -> `book.php`, feeds the same confirmation page.
- **Users:** `index.php` (register, login, support tickets), `register.php`.
- **Admins:** `login1.php` -> `dashboard1.php`, `view_bookings.php`, `manage_bookings.php` (all filtered by the admin's `admin_zone`), plus slot pages `manage_slots.php`, `manage_gad.php`, `manage_ajara.php`, `revenue.php` and `dashboards/*`.
- **Duplicates found:** three city booking pages that differ by about 16 lines; two admin login pages; `view_bookings.php` / `manage_bookings.php` with identical logic; ten files with their own copy of the DB credentials.

## 2. Baseline (before any change)

- `php -l` on all PHP files: **no syntax errors** (matches the earlier report).
- No tests, no README, no `.gitignore`, no schema file, no Git history existed.
- Database tests: **not possible** in the sandbox (no MySQL, no network to install it).

## 3. Confirmed findings and fixes

Severity is my judgement from reading the code. "Fixed" means the code was changed and, where stated, covered by a test that ran.

| # | Severity | Finding | Fix | Evidence |
|---|---|---|---|---|
| 1 | Critical | SQL injection in admin login (`login1.php`, `adminlogin.php`), user login/register (`index.php`, `register.php`), dashboards, `view/manage_bookings` (zone, filter, delete), slot history, `revenue.php`, `gad/ajara/kolhapur` dashboards | Prepared statements everywhere (`db_select`, `db_scalar`, explicit `prepare`) | HTTP test: injection string in login is stopped at the CSRF gate; unit tests reject SQL-like input; **not run against a database** |
| 2 | Critical | Admin passwords compared as plaintext inside SQL | `password_verify` for hashed rows, constant-time compare for old plaintext rows (so nobody is locked out), `tools/migrate_admin_passwords.php` to convert | unit tests (hash, plaintext, empty, wrong) |
| 3 | Critical | No effective admin check on `manage_slots/gad/ajara`, `dashboards/*`; `revenue.php`, `admin_dashboard.php` checked session keys that nothing sets | `require_admin()` at the top of every admin page | HTTP tests: 9 pages x GET and 3 x POST redirect to `login1.php` with no page content |
| 4 | High | `login.php` had a form but no code; `logout.php` was empty (session never ended) | `login.php` redirects to the working user login; real `logout.php` empties and destroys the session and cookie | HTTP tests |
| 5 | High | Ticket and confirmation pages built from URL parameters: anyone could forge a ticket, inject HTML/XSS into the page and the PDF | Pages load the saved booking by id and need an HMAC token; all output escaped; dompdf remote fetching off, chroot set | HTTP tests (old URLs -> 404), PDF render test with a hostile name |
| 6 | High | `book.php` trusted the price sent by the browser and saved nothing | Server validates, prices on the server (Rs 50/h as the form shows), saves the booking | unit + HTTP tests |
| 7 | High | No CSRF protection; delete / clear-all ran from GET links | CSRF token on every state-changing form; delete is now POST | HTTP tests (403 without token) |
| 8 | High | Session fixation: id never regenerated at login; cookies without HttpOnly/SameSite | `session_regenerate_id` at both logins; HttpOnly, SameSite=Lax, strict mode | code review only (not asserted by a test) |
| 9 | High | Reflected/stored XSS in many `echo $row[...]` / `echo $_GET` outputs | `e()` (htmlspecialchars) on all output of request/DB data in active pages | HTTP test (HTML not echoed back); template review |
| 10 | High | Capacity = 10 - *all bookings ever*: a place became full forever after 10 lifetime bookings | Active bookings only (`booking_time + hours > now`) | integration test written, **not run** |
| 11 | High | Check-then-insert race: simultaneous requests could overbook | Per-location MySQL named lock around count + insert in a transaction | integration test (8 parallel requests) written, **not run** |
| 12 | Medium | Slot history logged after the update, so old = new status | History row first, in one transaction; status whitelist; updates limited to the page's town | integration test written, **not run** |
| 13 | Medium | `manage_slots.php` / `manage_gad.php` bound `LIMIT`/`OFFSET` as strings (likely a SQL error on load) | Bound as integers | code review; not run |
| 14 | Medium | Revenue used `MONTH()` without `YEAR()` | Month **and** year | not run against data |
| 15 | Medium | Raw DB error text shown to visitors (`die("Connection failed: " . ...)`), credentials hardcoded in about 10 files, two DB names (`parkaro`, `parking`) | One `db()` from `.env`; errors logged, generic message shown | code review |
| 16 | Medium | `admin_password_matches('', '')` would have been true (found by my own unit test) | Empty values never match | unit test |
| 17 | Low | Copy-paste navigation errors (links to missing `live-map.php`, `logout.php` in a subfolder, wrong dashboard links) | Links corrected | review |
| 18 | Info | `admindetails.txt` looks like a credentials list (9 lines, 3 fields each; contents not opened for this report) | `.gitignore` + `.htaccess` block it | **You must keep it out of GitHub and change those passwords** |

## 4. Files changed

New: `src/bootstrap.php`, `src/booking.php`, `src/users.php`, `src/admin.php`, `src/slots.php`, `tools/migrate_admin_passwords.php`, `database/schema.sql`, `tests/*`, `README.md`, `.gitignore`, `.env.example`, `.htaccess` (root, `src/`, `storage/`, `legacy/`), `docs/AUDIT_REPORT.md`.

Rewritten or edited: `db.php`, `config.php`, `login.php`, `login1.php`, `adminlogin.php`, `logout.php`, `register.php`, `index.php`, `book.php`, `booking_confirmed.php`, `generate_ticket.php`, `kolhapur_booking.php`, `ajara_booking.php`, `gadhinjlaj_booking.php`, `dashboard1.php`, `view_bookings.php`, `manage_bookings.php`, `manage_slots.php`, `manage_gad.php`, `manage_ajara.php`, `revenue.php`, `dashboards/kolhapur1_dashboard.php`, `dashboards/gadhi1_dashboard.php`, `live_map.php`, `gad.php`.

Moved (not deleted) to `legacy/` after confirming nothing references them: `success.php`, `booking_page.php`, `book_slot.php`, `availability.php`, `admin_dashboard.php`, `confirm.php`, `dashboard.php`, `dashboards/ajara1_dashboard.php`, `dashboards/ajara_booking.php`, `pr1.py`, `a`, `row dashboard`. Reason for each: `legacy/README.md`. The original zip still has every original file.

## 5. Tests (exact commands, results from the audit sandbox, PHP 8.3.6)

| Command | Result |
|---|---|
| `php -l` on every PHP file | no syntax errors |
| `php tests/unit/run.php` | **69 passed, 0 failed** |
| `php tests/unit/ticket_test.php` | **5 passed, 0 failed** (real PDF rendered) |
| `php tests/http/run.php` | **58 passed, 0 failed** |
| `php tests/integration/db_test.php` | **NOT RUN** - needs MySQL and a `*_test` database (exits with "SKIPPED") |

Failures that occurred while developing: 3 first-run failures in the unit tests (one real bug, #16; two wrong expectations in the tests), fixed and re-run. The earlier session's `php -l` result was the only previous test.

What the passing tests do **not** prove: any page behaviour after a successful admin login, any SQL actually executing, concurrency, or Apache `.htaccess` behaviour (PHP's built-in server ignores it). Treat those as unverified until you run the integration test and click through the app.

## 6. Database changes

**None applied.** `database/schema.sql` is reconstructed from the code for documentation and for the test database; compare it with your real database before use.

## 7. Needs your decision (not changed without approval)

1. **Admin password migration** - run `tools/migrate_admin_passwords.php --yes` after a backup (changes data; may need `ALTER TABLE admin_users MODIFY password VARCHAR(255)`).
2. **Real reservations.** Bookings have no start date/time, status, or user id, so "overlapping reservation", "invalid booking date", "status transitions" and "users see only their own bookings" cannot be implemented properly. Current model: a booking starts when made and lasts N hours. A proper model needs a schema migration (`start_time`, `end_time`, `status`, `user_id`, optionally a `slot_id`). Tell me if you want that, with a backup first.
3. **Business rules I kept as found:** Rs 20/h on city pages vs Rs 50/h in `book.php`; capacity 10 per location; maximum 24 h per booking (my assumption); two different location lists.
4. **Zone names repeat across towns** ("Zone A" in Ajara and Kolhapur), so a zone admin sees both towns' bookings. Needs a rule (e.g. admin per town).
5. **Slot pages' schema.** `manage_*`, `dashboards/*`, `revenue.php` use `bookings.location`, `user_email`, `slot_number`, `status`, which the current booking pages never write. I only made them safe; whether they work depends on your real tables.
6. **Moving files to `legacy/`** (reversible).

## 8. Remaining issues and limitations

- Guest booking has no CSRF token (the page is static HTML) and no rate limit or CAPTCHA: someone can fill a location with fake bookings.
- No login throttling or lockout on the admin login.
- `is_admin` for support tickets on `index.php` is never set anywhere; that admin panel is unreachable.
- Phone number on `booking.html` is not stored (no column).
- Password rule kept at the original minimum (6 characters, one capital, one symbol); consider 10+.
- `index.php` opens the database on every request.
- `booking.html`, `home.html` etc. load Leaflet from unpkg without integrity hashes.
- No HTTPS enforcement; set up TLS before any real deployment.

## 9. Run it locally

See `README.md` (installation, `.env`, tests).

## 10. GitHub-readiness checklist

- [x] No credentials in the code; `.env` ignored; `.env.example` provided
- [x] `.gitignore` excludes `.env`, keys, dumps, logs, `admindetails.txt`, generated PDFs
- [x] README with setup, usage, tests, limitations
- [x] Dependencies and licenses documented (dompdf LGPL, etc.)
- [x] Tests included, with honest status
- [ ] **Delete or keep out `admindetails.txt` and change those passwords** (you)
- [ ] Run `tests/integration/db_test.php` on a `_test` database and click through the app (you)
- [ ] Decide on items in section 7
- [ ] Add a `LICENSE` file; add screenshots to the README
- [ ] `git init`, review `git status`, commit - not done by me; nothing was pushed

## 11. Suggested future improvements

Proper reservation model (start/end time, status, user id), one parameterised booking page instead of three,
per-town admins, login throttling, email confirmations, a migration tool (Phinx), PHPUnit + CI, a front controller and templates.
