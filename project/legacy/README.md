# Legacy files

These files were moved here during the audit because nothing in the project links
to them (checked with a search for every file name and form action), and most of
them cannot work with the current database or sessions. Nothing was deleted.

They still contain the ORIGINAL code, including SQL injection and missing login
checks, so `.htaccess` blocks web access. Do not move them back without fixing them.

| File | Why it was set aside |
|---|---|
| `success.php` | Reads a session key nothing sets; links to a missing `bookings.php` |
| `booking_page.php`, `book_slot.php` | Older slot-booking prototypes (`slots` / `parking_slots` tables); not linked anywhere; no CSRF; race condition on booking |
| `availability.php` | Uses a different database name (`parking`) and a made-up capacity of 5; not linked |
| `admin_dashboard.php` | Checks session keys that are never set; uses the `parking` database and columns (`amount`, `ticket_id`) that no other page uses |
| `confirm.php` | Old confirmation page that trusted URL parameters; replaced by `booking_confirmed.php` |
| `dashboard.php` | Empty page behind a session key nothing sets |
| `dashboards_ajara1_dashboard.php` | Two-line copy of `dashboard1.php` |
| `dashboards_ajara_booking.php` | Old copy of `ajara_booking.php` (different spelling and coordinates) |
| `pr1.py`, `a`, `row dashboard` | Scratch notes / a Python snippet with a syntax error / an old copy of a dashboard |
