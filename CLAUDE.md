# laravel-nightwatch-cap

A public Composer package: a monthly cap on the events a Laravel application sends to Laravel Nightwatch. The README is the contract. Every behavior it states has a test.

## Public repository

Everything here is readable outside the company. Never name a customer, an internal service codename, an internal host or a private repository, in code, tests, docs, commits or PR text. Use these placeholders:

| Use | Placeholder |
| --- | --- |
| Applications | `acme-app` |
| Cache stores | `default`, `counter` |
| Tenant or customer | `Acme`, `Acme Books` |

## Rules the code depends on

- The counter key is `nightwatch-events:Y-m` and its decay is 31 days. Applications that adopt the package keep counting from the value already in their store, so neither changes without a migration plan.
- The listener returns `null` or `false`, never `true`. Nightwatch dispatches through `Event::until()`, and any other non-null value stops the listeners after it.
- A throwing listener makes Nightwatch drop the batch and report nothing. A cache call that throws is caught and logged, and the batch goes through uncounted.
- Configuration lives under the application's `nightwatch.*` keys. The package ships no config file.

## Pointers

- Releases are CHANGELOG-driven: a new top `## vX.Y.Z` block on `main` cuts the tag and the GitHub release, and Packagist picks up the tag.
- Each behavior change is a minor release while the package is at 0.x, so `^0.1` in an application never picks one up on its own.
