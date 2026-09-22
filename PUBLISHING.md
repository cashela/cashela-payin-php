# Publishing `cashela/payin` to Packagist

`composer.json` validated OK. Packagist is **not** a push target: you register the repo URL once and Packagist reads `composer.json` + git tags from it.

## Prerequisites (yours — I can't do these for you)
- A Packagist account (https://packagist.org), signed in (GitHub login works).
- **The repo must be PUBLIC.** Public Packagist cannot index a private repo. Either make
  `github.com/cashela/cashela-payin-php` public, or use Private Packagist (paid) instead.

## Steps
1. Tag a release (Packagist derives versions from git tags):
   ```bash
   git tag v0.1.0
   git push origin v0.1.0
   ```
2. Go to https://packagist.org, click **Submit**, and paste:
   `https://github.com/cashela/cashela-payin-php`
3. Enable the auto-update webhook when Packagist offers it (or add the GitHub Packagist webhook) so future pushes/tags update the package automatically.

Then anyone can: `composer require cashela/payin`.

## Future releases
Push a new tag (`v0.2.0`, …); the webhook updates Packagist. Without a tag, only `dev-master` is available.

> Publishing here makes the code PUBLIC (Packagist requires a public repo) and the package name `cashela/payin` becomes permanently claimed.
