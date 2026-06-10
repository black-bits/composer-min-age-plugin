# Composer Minimum Age Plugin

A Composer plugin that blocks dependency versions based on minimum-age, approximating the supply-chain guardrails that npm and pnpm already ship (and that Composer is expected to add natively in 2.10).

Available policies:

- **Require a minimum release age** — refuse versions that are too new. A package that was published minutes ago can't be pulled in.
- **Block explicit versions** — refuse specific versions or ranges outright, regardless of age.
- **Exempt specific packages** — skip both checks for packages you trust. Every version is allowed regardless of age or block list.

### How it works

Disallowed versions are handled in two layers:

1. **During a solve** (`composer update` / `require`) the disallowed versions are filtered out of the solver pool. If a version is "too new", Composer simply chooses an older/allowed version instead — graceful, no error.
2. **Before anything is written** (every `install` and `update`) the policy is enforced against *everything that would end up installed* — the operations **plus** the already-installed packages the run leaves untouched. If a disallowed version would remain, the run is **aborted**. This is what catches a blocked version pinned in a committed `composer.lock`, or one installed before the policy existed.

## Installation

The plugin is distributed from its Git repository (no Packagist). The recommended approach is to **clone it to a location you control** — you can read and audit exactly what runs inside your Composer process, and update it on your own schedule with `git pull`.

```sh
# Clone once to a path (any location works)
git clone git@github.com:black-bits/composer-min-age-plugin.git \
  ~/.composer-plugins/composer-min-age-plugin
```

The plugin can be installed in two ways:

| Mode | Protects | Use when |
| --- | --- | --- |
| **Global** (recommended) | every Composer run on the machine | you want the policy everywhere, including the *first* run in a new project |
| **Per project** | one project, only after it's installed there | you want the policy committed alongside a specific project |

**Why global is recommended:** global plugins load *before* project-local ones, so a global install enforces the policy on every project — even the very first `composer install`/`update` in a project that doesn't list the plugin. A project-only install can only act once it has already been installed and allowed in that project, so it can't protect its own first resolve.

**Global (every project on the machine):**

```sh
composer global config repositories.composer-min-age-plugin \
  '{"type":"path","url":"~/.composer-plugins/composer-min-age-plugin","options":{"symlink":true}}'
composer global config allow-plugins.black-bits/composer-min-age-plugin true
composer global require black-bits/composer-min-age-plugin:@dev
```

**Per project:**

```sh
composer config repositories.composer-min-age-plugin \
  '{"type":"path","url":"~/.composer-plugins/composer-min-age-plugin","options":{"symlink":true}}'
composer config allow-plugins.black-bits/composer-min-age-plugin true
composer require black-bits/composer-min-age-plugin:@dev
```

Composer plugins must be explicitly allowed (the `allow-plugins` line) or they won't run — in non-interactive mode Composer fails otherwise. The symlinked path means a later `git pull` in the clone takes effect immediately.

> **Alternative — pin to the github repository instead of a clone.** Point a `vcs` repository at the repo and require a tagged version; Composer manages the checkout for you (you trade direct source control for automatic version pinning):
>
> ```sh
> composer config repositories.composer-min-age-plugin \
>   '{"type":"vcs","url":"git@github.com:black-bits/composer-min-age-plugin.git"}'
> composer config allow-plugins.black-bits/composer-min-age-plugin true
> composer require black-bits/composer-min-age-plugin:^0.2
> ```

## Configuration

**Out of the box the plugin does nothing.** It ships with no policy: `minimum-age` is `0` (age check off), and there are no blocked or exempt packages. You opt in by setting the keys below. 

The entire policy lives in the `extra.composer-min-age` block and is managed with the standard `composer config` command — there is no custom command, no env vars, and no bypass switch.

| Key | Default | Meaning |
| --- | --- | --- |
| `minimum-age` | `0` (off) | Minimum release age, `<n>h` or `<n>d`. `0` disables the age check. |
| `blocked-versions` | `{}` | Map of package name → one or more version constraints to refuse. |
| `exempt-packages` | `[]` | Package **names** skipped by the policy entirely (age *and* block checks). Whole-package, not per-version. The plugin always exempts itself. |
| `endpoint` | none | URL of a first-seen ledger (`/api/ledger/check`). When set, the age check uses the ledger's trusted first-seen timestamps instead of the spoofable release date. |

Add `global` (`composer global config …`) to any command below to set the policy machine-wide instead of per project.

### Minimum release age

Express the age as whole hours (`<n>h`) or days (`<n>d`):

```sh
composer config extra.composer-min-age.minimum-age 1d   # recommended — see below
composer config extra.composer-min-age.minimum-age 1h   # 1 hour

composer config extra.composer-min-age.minimum-age 3h   # 3 hours
composer config extra.composer-min-age.minimum-age 3d   # 3 days

composer config extra.composer-min-age.minimum-age 0    # disabled (default)
```

**Recommended: `24h` (`1d`).** A one-day floor is long enough that most malicious releases are detected before you'd install them, while barely slowing down normal updates. You can raise it (e.g. `3d`/`7d`) for stricter environments.

IMPORTANT: Without a ledger `endpoint` (below), the age check relies on the release date Composer gets from the package repository — which an attacker can backdate — and versions with an unknown release date are allowed. Configure the ledger to gate on a trusted first-seen timestamp instead.

### Blocked versions

Refuse specific versions or ranges by package, regardless of age. Unlike the age rule (which clears itself as a version gets older), a block stays until you remove it. Set it with `--json` (it's a map):

```sh
# Block a single known-bad release
composer config extra.composer-min-age.blocked-versions --json \
  '{"illuminate/support":["=12.61.0"]}'

# Multiple constraints / packages
composer config extra.composer-min-age.blocked-versions --json \
  '{"vendor/pkg":["=1.2.3",">=2.0 <2.1"]}'

# Remove all blocks
composer config --unset extra.composer-min-age.blocked-versions
```

### Exempt packages

Skip the policy for trusted packages by name. Exemption is whole-package — every version of a listed package is allowed, even if it would otherwise be too new or on the block list:

```sh
# Add an exemption
composer config extra.composer-min-age.exempt-packages --json '["vendor/trusted"]'

# Remove all exemptions
composer config --unset extra.composer-min-age.exempt-packages
```

### Trusted first-seen ledger

Git commit/tag dates — and therefore the release date the age check falls back to — are attacker-controllable. The companion ledger app watches Packagist and records, on its own clock, when each `(package, version, commit-SHA)` first became visible. Point the plugin at it and the age check gates on that trusted first-seen timestamp for the exact SHA being installed:

```sh
composer config extra.composer-min-age.endpoint https://your-ledger.example/api/ledger/check
```

The endpoint's bearer token is **not** part of the policy config — it lives in Composer's standard `auth.json` (per host), so it never ends up in a committed `composer.json`:

```sh
composer config [--global] bearer.your-ledger.example YOUR_TOKEN
```

Composer attaches it automatically when the plugin calls the endpoint.

**When first-seen data is missing** (a version the ledger hasn't crawled yet, or the lookup fails), the plugin lists the affected versions, falls back to their self-reported release date for the age check, and asks whether to continue. Answering no aborts the run; non-interactive runs (CI) take the default and continue with the fallback. This fail-open posture is deliberate while the project is a proof of concept.

### Combining global and project config

Global and project config merge, with **global as a floor**: block lists combine, the stricter `minimum-age` wins, and exemptions from both apply. A project can *tighten* the global policy but cannot drop a global block or lower the global minimum age.

### Turning the policy off

```sh
# Remove the policy, keep the plugin
composer config --unset extra.composer-min-age

# Stop the plugin from running at all
composer config allow-plugins.black-bits/composer-min-age-plugin false
```

## Uninstall

```sh
# Uninstall
composer remove black-bits/composer-min-age-plugin

# Global uninstall
composer global remove black-bits/composer-min-age-plugin

# Optional: drop the repo entry
composer config --unset repositories.composer-min-age-plugin
# (global install: composer global config --unset repositories.composer-min-age-plugin)
```

## Testing the plugin

Use Composer's own `--dry-run` to preview a run without changing anything; the plugin reports what it acted on.

## Usage example

**Graceful filter** — a blocked/too-new version exists, but the solver can route around it, so it picks an allowed one (no error):

```text
$ composer update illuminate/support --dry-run

[composer-min-age] Prevented disallowed versions during resolution:
  - illuminate/support v12.61.0 [candidate version]: is on the block list

Lock file operations: 0 installs, 1 update, 0 removals
  - Downgrading illuminate/support (v12.61.0 => v12.60.2)
```

**Hard abort** — a disallowed version would remain installed (here it's pinned in the committed lock), so the run stops:

```text
$ composer install --dry-run

[composer-min-age] Dependency policy violations:
  - illuminate/support v12.61.0 [installed package]: is on the block list

In Plugin.php line 188:

  [composer-min-age] Blocked one or more disallowed package versions (see above). Update minimum-age or blocked-versions to allow them.
```

## Resolving a block

The plugin checks **every version that would be installed, not just the ones your command changes** — including packages you didn't touch. So a single disallowed version that's *already* installed will abort **every** later `install`/`update`.

A common case: a dependency was installed at a version that is now younger than your `minimum-age`. Any subsequent run (even adding an unrelated package) aborts on that dependency, because the run would leave the too-new version in place.

To get unstuck, do one of:

- **Move it to an allowed version** — `composer update vendor/package` re-resolves and filters the disallowed version out, or require a specific allowed version (`composer require vendor/pkg:1.2.0`).
- **Remove it** — `composer remove vendor/package` if the dependency isn't needed.
- **Allow it via policy** — drop its entry from `blocked-versions`, add it to `exempt-packages`, or lower/disable `minimum-age`.
- **Wait** (age rule only) — once the version is older than the threshold it's allowed automatically, no config change needed.

## Questions & support

This plugin is built and maintained by **[Black Bits](https://www.blackbits.io)** — a Laravel & AWS partner for SaaS platforms and agencies that need expert-level support. 

We embed with SaaS, EdTech, platform companies, and agencies delivering complex client projects: building features, managing production systems, and scaling architecture as the platform grows. 

Day to day, that means Laravel application development, AWS infrastructure management, CI/CD pipelines, payment integrations, API design, and AI-powered product features. 

We work across the full stack, from database optimization to deployment automation. Platforms we've worked on serve millions of users across EdTech, entertainment, marketplaces, and enterprise SaaS — several of those partnerships running for 5–10+ years.

Building or scaling a Laravel platform and want senior hands on it? **[Let's talk about your project](https://www.blackbits.io/#contact)**
