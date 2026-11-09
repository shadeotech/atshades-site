# At Shades backend (Hostinger)

Small PHP endpoints that the page calls. `support.js` and `image-slot.js` are untouched.

| Endpoint | Used by |
|---|---|
| `POST api/lead.php` | Consult form, franchise enquiry, shade finder result (`type`: `consult` / `franchise` / `quiz`) |
| `GET api/slots.php` | Franchise meeting picker: open times |
| `POST api/book.php` | Franchise meeting booking, which emails a calendar invite (.ics) |

## Deploy

1. Upload the whole folder (index.html, support.js, image-slot.js, images, `api/`, `data/`) to `public_html`.
2. In hPanel → Emails, create a mailbox to send from (e.g. `leads@atshades.com`).
3. On the server, copy `api/config.sample.php` to `api/config.php` and fill in:
   - `allowed_origins`: your domain(s)
   - `smtp.password`: the mailbox password
   - `main_inbox`, `franchise_team_inbox` (both atshadesus@gmail.com for now)
   - `franchise_inboxes`: one email per location (blank means main inbox only)
   - `booking.timezone`, `booking.slots`, and meeting `links` if you have fixed ones
   - `era`: replace `CHANGE_ME_WEBSITE_INTAKE_SECRET` with the same value as `WEBSITE_INTAKE_SECRET` in ERA's Vercel environment (see ERA's docs/deploy.md, section 8)
4. Optional but recommended: set `data_dir` to a folder outside `public_html`.
5. Submit each form once on the live site and check the inboxes.

## Where things end up

- Every lead and booking: `data/leads.jsonl` (one JSON object per line)
- Bookings (used for availability): `data/bookings.json`. Delete an entry to free the slot.
- ERA pushes that failed: `data/era-failed.jsonl`, kept for replay
- Errors: Hostinger's PHP error log, prefixed `[atshades]`

## Routing

- Consult and quiz leads go to the franchise nearest the visitor's ZIP (worked out in the page by `nearest()`), plus `main_inbox`.
- Franchise enquiries and bookings go to `franchise_team_inbox` (enquiries also copy `main_inbox`).
- Quiz completions without an email are logged only, unless `email_anonymous_quiz` is on.
- Visitors get a confirmation email, and bookings include the calendar invite.

## Spam protection

Origin check, 12 submissions per IP per hour, and silent drop of submissions sent less than 2.5 seconds after page load.
