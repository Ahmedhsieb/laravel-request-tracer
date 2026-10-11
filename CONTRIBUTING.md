# Contributing

Thank you for helping. The short version lives in [docs/13-troubleshooting-faq-upgrade.md](docs/13-troubleshooting-faq-upgrade.md#contributing): how to run the tests, the style and static analysis, the dashboard build and the rules for `dist/`.

Before you open a pull request:

1. Open an issue first for anything bigger than a fix, so we agree on the direction.
2. Add a test with every behaviour change (`tests/Unit` for isolated logic, `tests/Order` for anything that boots the example "Order flow" application).
3. Run `vendor/bin/pest`, `vendor/bin/pint`, `vendor/bin/phpstan analyse`, `npm run lint && npm run typecheck && npm test`.
4. If you touched `resources/js`, run `npm run build` and commit `dist/` (CI fails when it is stale).
5. Update `CHANGELOG.md` (under `Unreleased`) and the docs. Every config key belongs in `docs/04-configuration.md`.
6. Keep `src/` to PHP 8.1 syntax.

The hard rules the package is built on (an idle tracer costs one check, it never breaks a request, writes happen in `terminate()`, secrets are masked before storage, long-running workers must not leak state) are listed in [CLAUDE.md](CLAUDE.md); a change that breaks one of them will not be merged.

Report security problems privately, as described in [SECURITY.md](SECURITY.md).
