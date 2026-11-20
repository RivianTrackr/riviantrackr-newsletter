# Changelog

## 0.4.0

Newsletter dashboard rework.

- Next issue card: send date, whether the draft is waiting, scheduled or still to be built, and how many stories are in so far.
- Top stats: subscribers with today's change, joined and left in the last 7 days, and last issue opens and clicks.
- Opens leave out Apple Mail preloads (Brevo `trackableViews`), which count every Apple Mail reader as an open. The raw number shows next to it.
- Signups by day chart for the last 30 days.
- Last issue card with delivered, opened, clicked, unsubscribed, bounced or spam.
- Most clicked links show the story title.
- Where readers came from, since launch: newsletter page, sidebar, signup box elsewhere, member invite, account page, and how many confirmation links are waiting. Form placement and confirmation requests are logged from this version on. Unconfirmed requests are deleted after 30 days.
- Admin screens move from the blue admin palette to paper and ink: gold fills, brass links, warm lines, sentence case labels.

## 0.3.4

The live subscriber count ("Join 376 readers.") also shows in the signup form's fine print on the newsletter page and anywhere else the signup box is used.

## 0.3.3

Sidebar card shows the subscriber count ("Join 376 readers.") once the list has 50 or more. It's live: the card asks a public `GET /count` endpoint on every page load (Brevo list size, cached a minute on the server, `no-store` for browsers and Cloudflare), and the cache clears whenever someone subscribes or unsubscribes on the site.

## 0.3.2

Social icons in the email footer (YouTube, X, Instagram, Threads, Bluesky, Facebook, LinkedIn), in the site footer's order. Gray PNGs that read in light and dark. Filter `riviantrackr_newsletter_socials` to change them.

## 0.3.1

Fixed admin styles: a cut-off rule in admin.css stopped the dashboard table and the WordPress dashboard widget from being styled.

Brevo no longer adds its default "If you are not able to see this mail, click here" header or default footer around the email.

Dashboard: new signups and unsubscribes over 30 days, average opens and clicks over the last 4 issues, invites accepted, bounces per issue, the most clicked links in the last issue, and where signups came from.

## 0.3.0

New email layout: logo in the masthead, intro signed by Jose, a Top story block with the photo and a read link, software updates in a raised callout, and the rest as compact rows with thumbnails. One-off emails (confirm, invite, draft ready) carry the logo too.

## 0.2.4

A summary whose first sentence runs past the limit keeps that whole sentence (up to 1.6 times the limit) instead of being cut mid-sentence.

## 0.2.3

- Claude picks the lead story and it moves to the top with the big image, so subject, intro and image agree.
- The email header shows the send date, not the day the draft was built.
- Story summaries end on a full sentence when they can.

## 0.2.2

Claude writes in Jose's voice: new default guidelines (full specific opener, medium sentences, specifics, a quick take), each story's opening paragraphs as context, and the openings of the three latest articles as voice samples.

## 0.2.1

Deleting a draft frees its week, so "Build a draft now" works again right away. The draft window now follows the newest existing issue instead of a stored option.

Claude's default guidelines: lead with the biggest news, not the newest story, and keep preview text to 80 characters or fewer.

## 0.2.0

- Newsletter → Dashboard: subscribers, net signups, opens and clicks per issue from Brevo. A small widget on the WordPress dashboard.
- Claude writes the subject line, preview text and intro, automatically on the Thursday draft and on demand with "Write with Claude". Guidelines are editable and it learns from your last three sent intros.
- Settings and dashboard screens use the shared RivianTrackr admin design.
- The Thursday email button now reads "Review and approve".

## 0.1.4

Sidebar card: subscribed members see "You’re subscribed" with a link to manage it on their account page, in place of no card.

## 0.1.3

`[riviantrackr_newsletter layout="sidebar"]`: a compact card that matches the sidebar guide cards. Hidden for members who already subscribe (status cached 12 hours).

## 0.1.2

Privacy line under the signup button, a "Read the latest issue" link on the newsletter page once an issue has gone out, and web versions of sent issues at /newsletter/issue/<date>/ (noindex).

## 0.1.1

`[riviantrackr_newsletter layout="page"]`: a centered card for the newsletter page that matches the sign-in and register pages.

## 0.1.0

First version: weekly draft, issue editor with preview, test, schedule and send through Brevo, light and dark email template, double opt-in signup form, account page Newsletter tab, one-time member invite, consent log.
