# Payment methods and ticket confirmations: setup guide

**Status of live delivery: NOT tested against real providers.** The code was tested with fake transports, a local fake
SMTP server and a faked Twilio HTTP layer. Nothing has been sent through a real mail server, real Twilio, WhatsApp or a
real Razorpay account. Follow the checklist at the end to verify it yourself.

## 1. Apply migration 002 (once)
Back up the database (phpMyAdmin > Export), then in the project folder:

    php tools/check_migration.php          (bottom of the output shows "Migration 002")
    php tools/apply_migration.php 002 --yes
    php tools/check_migration.php          (should say "Migration 002: fully applied.")

It is additive and safe to repeat: it adds `parking_locations.accepts_online / accepts_cash` (default 1 = both, exactly
today's behaviour), `bookings.contact_phone / notify_via`, and the `notifications` table; nothing is dropped, changed or
deleted. It needs migration 001 first. Until it is applied everything keeps working as before: both payment methods are
offered everywhere, and no ticket messages are sent.

## 2. Payment methods per place
Admin > Manage Parking Locations > add/edit a place > **How can customers pay?**
* **Online** (Razorpay: UPI, cards, net banking, wallets, whatever is enabled on your Razorpay account) is confirmed only
  after the server verifies the payment (signed callback + gateway lookup, or signed webhook).
* **At the place** (cash, or UPI paid straight to the owner): the owner presses *Record cash received* in Reservations.
At least one must be ticked. Users see only the enabled methods on Find parking, Reserve and Pay pages. The server also
refuses an online payment for a cash-only place and refuses *Record cash* for an online-only place. Only the owner of a
place can change its settings.

## 3. Credentials (put them in `.env`, never in code)
Copy `.env.example` to `.env` and fill only what you want. Missing values simply switch that channel off: the booking and
payment are never affected, the message is recorded as `skipped` in the `notifications` table.

| Channel | Settings | What you need |
|---|---|---|
| Public URL | `APP_BASE_URL` | The address people reach the site on, e.g. `https://parking.example.com` (used for the ticket link). |
| E-mail | `MAIL_HOST`, `MAIL_PORT`, `MAIL_SECURE` (`tls`, `ssl` or `none`), `MAIL_USER`, `MAIL_PASS`, `MAIL_FROM`, `MAIL_FROM_NAME` | Any SMTP account. Gmail: `smtp.gmail.com`, port 587, `tls`, an **App password** (2-step verification must be on); sender = that Gmail address. For real use, a domain mailbox (SPF/DKIM set up) avoids spam folders. |
| SMS | `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_SMS_FROM` | A Twilio account and an SMS-capable sender (number or Messaging Service `MG...`). **India:** SMS to Indian numbers needs DLT registration (sender ID + approved template) and a trial account can only message verified numbers. Check Twilio's current India rules, or use an Indian provider and ask me to add a transport. |
| WhatsApp | `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_WHATSAPP_FROM`, optionally `TWILIO_WHATSAPP_CONTENT_SID` | For testing: the Twilio WhatsApp **Sandbox** (each tester first sends the join code to the sandbox number). For real customers: an approved WhatsApp Business sender, and an **approved message template** (set its Content SID). WhatsApp only allows free text inside a 24-hour customer chat; anything else must use a template. |

Suggested WhatsApp template body (variables `{{1}}`..`{{8}}` are filled in this order: name, place, from, until, car, amount, reservation no, ticket link):

    Hi {{1}}, your parking at {{2}} is confirmed. From {{3}} until {{4}}, car {{5}}, amount {{6}}. Reservation no {{7}}. Ticket: {{8}}

## 4. How sending works (so you can trust it)
* Sent **only** for a reservation that is `confirmed` **and** has a verified payment row (online verified by the gateway, or cash recorded by the owner). Unpaid, failed, authorized-only, cancelled or expired reservations never produce a message.
* Triggered after the database transaction commits, from every confirmation path: verified checkout callback, signed webhook, owner-recorded cash.
* **Once per booking and channel**: the `notifications` table has a unique key (booking, channel) and the row is claimed before sending, so repeated callbacks, duplicate webhooks, double clicks or two servers at once cannot send twice.
* A provider failure is recorded (`failed`, attempts, short error) and never changes the booking or payment. A repeated callback/webhook (the gateway retries automatically) retries **only the failed channel**, up to 3 attempts.
* `skipped` (not configured / no number) is final for that booking: switching a channel on later does not message old bookings.
* Message content: place, address, from/until, car number, amount, payment status ("Paid online" / "Paid in cash at the place"), reservation number and the signed ticket link. No payment ids, gateway order ids, keys or secrets. Note the ticket link is a signed link that opens the ticket, so treat the message as private.
* E-mail goes to the customer's account e-mail. WhatsApp/SMS go to the mobile number the customer typed and ticked on the Reserve page (stored in international form, e.g. `+919876543210`). The number is not verified by an OTP; it is only used to send that customer's ticket.

## 4b. Owner screen
Owner confirmation of cash / UPI paid to the owner, per-channel status (Sent = accepted by the provider only), and the **Retry messages** button are described in section 14 of `docs/PARKKARO_UPGRADE.md`.

## 5. Manual test checklist (needs your credentials; XAMPP)
1. [ ] `php tools/apply_migration.php 002 --yes`, then `php tools/check_migration.php` shows 002 fully applied.
2. [ ] Admin: edit a place, untick *At the place*, save; reopen: still saved. Another admin cannot change it.
3. [ ] User: Find parking shows "Pay: online ..." for it; Reserve page says the same; Pay page has no cash box. Tick only *At the place* on another place: no online button.
4. [ ] Fill `.env` for **e-mail only**; reserve with a test card/UPI (Razorpay TEST keys), pay: the ticket e-mail arrives once. Press browser Back/refresh on the result page: no second e-mail.
5. [ ] Add Twilio sandbox settings; reserve with your number and WhatsApp/SMS ticked; pay: WhatsApp (after joining the sandbox) and SMS arrive.
6. [ ] Wrong `MAIL_PASS` on purpose: the payment still confirms; `SELECT * FROM notifications` shows `failed` with the reason; fix `.env`, resend the Razorpay webhook (Dashboard > Webhooks > the event > Resend) and the failed channel is delivered, others not repeated.
7. [ ] Cash: reserve at a cash-enabled place, owner presses *Record cash received*: e-mail "Paid in cash at the place".
8. [ ] Cancel an unpaid reservation: nothing is sent.
9. [ ] Razorpay Dashboard: webhook URL `https://YOUR-DOMAIN/payment_webhook.php`, event `payment.captured`, same secret as `RAZORPAY_WEBHOOK_SECRET` (it cannot reach `localhost`; use the browser callback on localhost, or a tunnel such as ngrok).
