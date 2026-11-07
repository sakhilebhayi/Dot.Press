# R-40 skin: items for the owner

Written 2026-11-07 with the skin work (landing page, sign-in and account pages, error pages, legal text, icons).

## Legal text

This text should be reviewed by a lawyer before it is relied on: `resources/markdown/terms.md`.

This text should be reviewed by a lawyer before it is relied on: `resources/markdown/policy.md`.

This text should be reviewed by a lawyer before it is relied on: `resources/markdown/cookies.md`.

The three documents were rewritten from what the code does (data stored, InfoDot sign-in, link sharing, AI modes, exports, retention, cookies). Points for the reviewer to confirm against how the host is run:

- The responsible party is still named as BluePin Inc, as in the earlier text, with privacy@infodot.co.za as the contact.
- Retention figures come from the code: operations log 14 days, export files 24 hours, newest 40 automatic restore points per deck, daily database backups with the newest 14 kept.
- Account deletion is described from the database rules: projects a user created, and the decks inside them, are deleted with the account, including decks shared with a team.
- Whether model mode is on in production is a server setting. The policy says so without naming a provider.

## Production values

- `public/robots.txt` names `https://pres.infodot.co.za/sitemap.xml`. Change it if the production host differs. The sitemap, the canonical link and the share image use the host the app is configured with (`APP_URL`).
- `public/og.png` is made from the landing page with `node scripts/make-icons.mjs --og <url>`. Remake it when the landing headline changes.

## Not built

- No customer logos, quotes, statistics or awards appear on the landing page, because none exist.
