# Release checklist

Used for every release. Replace `X.Y.Z` with the version. The first release is `v0.1.0`.

## Before tagging

- [ ] `main` contains everything for the release; CI is green on the whole matrix (Laravel 10 to 13, PHP 8.1 to 8.5, lowest and stable dependencies, MySQL, PostgreSQL, SQLite, PHP 8.1 syntax guard, `dist/` freshness).
- [ ] Locally: `vendor/bin/pest`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npm run lint`, `npm run typecheck`, `npm test` all pass.
- [ ] `npm ci && npm run build` leaves `git status` clean (the committed `dist/` is current).
- [ ] `composer.json`: package name, authors, support links and `homepage` are the real ones (the placeholders are `your-vendor` and `security@your-vendor.example`). The same placeholders are in `README.md`, `README.ar.md`, `SECURITY.md` and `docs/`: `grep -rn "your-vendor" .` returns only intended matches.
- [ ] `CHANGELOG.md`: the `Unreleased` heading becomes `## [X.Y.Z] - YYYY-MM-DD`, with a fresh empty `Unreleased` above it. Breaking changes and migrations are called out.
- [ ] `RequestTracer\DeepTrace\Loader::PACKAGE_VERSION` equals `X.Y.Z` (it is part of the compiled-code cache key).
- [ ] Every new config key is in `docs/04-configuration.md`; `docs/decisions.md` is up to date; the benchmark table in `docs/benchmarks.md` was re-run if capture code changed (`php benchmarks/run.php --requests=500`).
- [ ] Screenshots listed in `docs/images/README.md` are captured, or the placeholders are still acceptable for this release.
- [ ] Try it in fresh applications, one per supported Laravel version (10, 11, 12, 13): `composer require` from the branch, `php artisan request-tracer:install --migrate`, open `/request-tracer`, record a request, check Flow, Database and Problems; send one request with a signed header in `APP_ENV=production`.

## Tagging

```bash
git checkout main && git pull
git tag -a vX.Y.Z -m "Request Tracer X.Y.Z"
git push origin main vX.Y.Z
```

## After tagging

- [ ] Create the GitHub release from the tag; paste the changelog section.
- [ ] Packagist picks the tag up from the webhook; confirm the new version appears and `composer require your-vendor/laravel-request-tracer:^X.Y` resolves.
- [ ] Re-run the fresh-application check against the published package.
- [ ] Announce, with the link to [docs/01-introduction.md](docs/01-introduction.md).

## Hotfix

Branch from the tag (`git checkout -b hotfix/X.Y.Z+1 vX.Y.Z`), fix with a test, merge to `main`, then run this list from "Before tagging".
