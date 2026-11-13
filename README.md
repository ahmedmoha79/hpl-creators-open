# HPL Creators: public review copy

A copy of the code of golddetectorinzambia.com (HPL Creators), made public on 2026-11-13 for an outside review.
Plain PHP 8 + MySQL, no framework. Start with **CLAUDE.md**: it explains every part of the app and every rule the owner decided.

Not in this copy, on purpose: passwords, API keys and the database (they live only on the server, in `config.php`),
the automatic deploy, and the rules of the hidden fake-views check (`integrity_score()` in `includes/integrity.php` is a stub here).

---

# HPL Creators (golddetectorinzambia.com)

The clipping platform for HPL Gold Detectors: creators sign up, pass the test, get approved,
join campaigns, post clips and withdraw to mobile money. PHP 8 + MySQL on Porkbun cPanel hosting.

## How changes go live
Every push to `main` runs **.github/workflows/deploy.yml**: it checks every PHP file for syntax
errors, then uploads only the changed files to the site by FTPS. If the check fails, nothing is
uploaded. To undo a change, revert the commit on GitHub; the revert deploys the same way.

## Not in this repository (they stay on the server only)
- `config.php` (database password and site secrets)
- `uploads/`, `storage/` (creator uploads, runtime data)
- tutorial and promo videos (`assets/media/**/*.mp4`)
- `database/` (old SQL dump)

## Secrets the deploy needs (GitHub → Settings → Secrets and variables → Actions)
`FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`. These belong to an FTP account whose home folder is
the site folder (`public_html/golddetectorinzambiacom`).
