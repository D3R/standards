D3R Coding Standards
=========

## Mago static analysis (IRM-1004 pilot)

This branch adds an optional Mago analyser for D3R projects. It discovers model/XML metadata automatically and uses the same commands locally and in GitHub Actions. It does not run the formatter or linter, bootstrap the application, connect to a database, or install PHPStan.

**This is an advisory pilot.** Discovery is incomplete for dynamic provider/model patterns. Reports show those gaps explicitly; exit 2 means tooling failure or incomplete discovery, not a clean analysis. See [coverage and limitations](docs/static-analysis-pilot.md).

### Add it to a project

Until this branch is released, install the pilot explicitly:

```sh
composer require --dev "d3r/standards:dev-IRM-1004-mago-analysis as 1.4.3"
```

After a release, new projects can use `composer require --dev d3r/standards`; existing projects update to the release containing `bin/d3r-analysis`. Commit the project's Composer manifest and lockfile through its normal review process. Do not install dev dependencies in production just to run analysis.

From the project root:

```sh
vendor/bin/d3r-analysis setup
vendor/bin/d3r-analysis doctor
vendor/bin/d3r-analysis discover
vendor/bin/d3r-analysis check
```

`setup` downloads the pinned native Mago binary, verifies its checked-in SHA-256 digest and installs the locked SDK/parser in an isolated cache with Composer plugins/scripts disabled. `discover` and `check` also ensure setup automatically. PHP 8.2+, Composer 2, `tar`, HTTPS access, and macOS or glibc Linux on arm64/x86_64 are required. The consumer does not need to install an SDK or parser itself.

`doctor` checks tool presence; it does not certify discovery completeness. `discover` writes a provenance/coverage report. `check` regenerates metadata and runs Mago over the root Composer source paths. No per-field/model manifest or hand-written Mago configuration is required. Run a focused check with `--path=src/Products`; repeat `--path` for more roots.

Generated files default to `.cache/d3r-analysis/`; add that directory to the project's `.gitignore`. Use `--cache-dir=/path/to/cache` and `--report-dir=/path/to/reports` to put all output elsewhere. All options use `--name=value`. `--project=/path/to/project` allows running outside its root.

Exit codes:

| Exit | Meaning |
| --- | --- |
| 0 | No diagnostics and no recorded discovery gaps |
| 1 | Diagnostics, including warnings |
| 2 | Incomplete discovery or a tooling failure; inspect the reports |

Full diagnostics are in `mago.json`, coverage/provenance in `discovery.json`, status in `result.json`, and process errors in `mago.stderr`. Mago's native exit is recorded separately: warnings can produce native exit 0, while this wrapper deliberately returns 1 for any diagnostic. Exit 0 is limited to the documented static contracts, not proof of full framework coverage.

### GitHub Actions

Keep the normal project dependency-install step, including its approved private registry credentials. Then invoke the package-owned composite action:

```yaml
jobs:
  mago:
    runs-on: ubuntu-latest
    continue-on-error: true # Advisory while discovery and diagnostic quality are evaluated
    permissions:
      contents: read
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer:v2.2.28
      # Configure the project's existing private Composer access here.
      - run: composer install --no-interaction --no-progress --prefer-dist
      - uses: ./vendor/d3r/standards/.github/actions/mago
```

The action restores only the isolated tool cache, performs fresh discovery, runs the same `check` command, emits up to 50 warning annotations, appends a job summary, and uploads complete reports for 14 days. Reports are retained even when analysis fails. It preserves the failing outcome; the caller's job-level `continue-on-error` makes the pilot advisory. Remove that setting only after discovery gaps and findings have been reviewed. No PR comments, write token, baseline generation, code scanning subscription or `security-events: write` permission is required.

For a custom workflow, the underlying commands are:

```sh
vendor/bin/d3r-analysis setup
vendor/bin/d3r-analysis check --github --report-dir=.github/outputs/mago
```

Use the package action to retain failure reports automatically, or add `actions/upload-artifact@v4` under `if: always()` yourself. Do not mask the exit with `|| true`. See [GitHub's workflow command documentation](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-commands) for annotations and summaries.

Soho is the first consumer pilot. `D3R/github-workflows` has not been changed globally; IRM-1095 can reuse this package action after the consumer job is proven. Changing the standards branch alone does not add a job to existing projects.

## Installation
```composer require --dev d3r/standards```

## PHPCS
```
"phpcs_additional_args": {
	"--standard": "vendor/d3r/standards/D3R-PHP.xml"
}
```

or use the stricter standard
```"phpcs_additional_args": {
	"--standard": "vendor/d3r/standards/D3R-DEV-PHP.xml"
}
```

## Dev environment
For your editor I suggest checking this package out globally:

`composer global require d3r/standards`

Then point your editor's phpcs and phpcbf to `/Users/<username>/.composer/vendor/d3r/standards/D3R-PHP.xml`.

## Licence Checker
You will automatically get licence checking in CI if you are using the standard D3R GitHub Workflow.

If you need to add specific packages, vendors or licences to your local project, you can create a `.allowed-licenses.php` config file using the [base config](./.allowed-licenses.php) as a starting point.

As an example, to include an extra vendor and a specific package on top of the base setup, you would add the following to `.allowed-licenses.php`:

```php
<?php

declare(strict_types=1);

use D3R\Standards\ComposerLicenseChecker;

return ComposerLicenseChecker::defaultBuilder()
    ->addAllowedVendor('foo') // Allow all foo packages
    ->addAllowedPackage('printnode/printnode-php') // Specifies MIT in GitHub but not in composer.json
    ->build();
```
