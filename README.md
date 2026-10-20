# perfect-beauty.uk

Website for Perfect Beauty, Banbury.

- `site/` is the website. Everything in it is copied to the `/perfect-beauty` folder on IONOS.
- `deploy/deploy.php` runs on IONOS as a cron job every few minutes and pulls new commits from `main`.
- The token lives only on the server in `deploy/config.php`, never in this repo.
- Files starting with `.ht` (the preview password lock) are never overwritten or deleted by deploys.
