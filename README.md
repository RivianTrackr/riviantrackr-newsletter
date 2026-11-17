# RivianTrackr Newsletter

RivianTrackr Weekly for riviantrackr.com. Every Thursday at 5 PM (site time) it drafts an issue from the week's news, articles and software updates and emails you a link. You add an intro, send yourself a test, and schedule it for Friday 9 AM. Brevo sends it. Nothing goes to readers without your click.

## Setup

1. In Brevo: create a list (Contacts → Lists) and add `jose@riviantrackr.com` as a sender (Senders, domains and IPs). The riviantrackr.com domain is already authenticated.
2. Activate the plugin. It uses the Brevo API key from WP Mail SMTP. To use a different key, define `RTNL_BREVO_API_KEY` in `wp-config.php`.
3. Create a page (for example /newsletter/) with the `[riviantrackr_newsletter]` shortcode. The page HTML is the same for every visitor, so Cloudflare can cache it.
4. Newsletter → Settings: list ID, from name and email, postal address, newsletter page.
5. Newsletter → Settings → Build a draft now, then Save and send test.
6. When you're ready, Newsletter → Settings → Send the invite to existing members.

## How it works

- **Drafts:** `rtnl_issue` posts. Stories are published `news` and `post` items since the last draft. Posts with `_rsu_is_update` (the software updates plugin) go in a Software updates section at the top. Summaries come from each post's excerpt.
- **Sending:** a Brevo email campaign per issue (`Brevo::campaign_payload()`), scheduled with `scheduledAt` or sent with `sendNow`. Tests are separate throwaway campaigns. Unsubscribe and "view in browser" use Brevo's `{{ unsubscribe }}` and `{{ mirror }}` tags.
- **Email design:** `Template` builds table-based HTML with inline light styles and a `prefers-color-scheme: dark` block (plus `[data-ogsc]` for Outlook.com). Gold is only a fill.
- **Signups:** Brevo's list is the source of truth. The signup form is double opt-in through a signed link (`Tokens`). Signed-in members subscribe in one click from the shortcode or the Newsletter tab on the account page (added through the passkeys plugin's `riviantrackr_passkeys_account_tabs` filter). Confirm and invite links land on the newsletter page, where a button does the opt-in, so email link scanners can't subscribe anyone. Each opt-in and opt-out is logged in `wp_riviantrackr_newsletter_consent` with a hashed IP.
- **Member invite:** one email per member, 25 a minute by cron, marked in user meta `riviantrackr_newsletter_invited` so nobody gets two.

## Development

```bash
composer install
vendor/bin/phpunit
phpcs --standard=.phpcs.xml
```
