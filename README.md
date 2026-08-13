# ai-dashboard

`ai-dashboard` is a mostly read-only web UI for the ai-toolset task manager. It
reads projects, tickets, phases, and tasks through `ai-lib`'s service layer
and renders them; a few narrow action routes shell out to `tm`'s CLI or a
configured editor command, but nothing in the dashboard writes to `ai-lib`
directly.

It is an optional, separate package from `tm` — install it only if you want
the web UI. `tm` itself works fully without it.

This file covers installing, updating, starting, and removing the
dashboard. For the HTTP route reference see `docs/api/http.md`. For how the
dashboard is built, see `AGENTS.md`.

## Install

Like `tm`, the dashboard installs through Composer from source, tracking
the `main` branch — there is no Packagist package and no version tags.

**1. Edit your global Composer config file.**

- Linux: `~/.config/composer/composer.json`
- macOS: `~/.composer/composer.json`

If you already installed `tm` (see `ai-tm`'s README), you already have this
file with two `repositories` entries and the two stability keys in it — add
the third entry to the existing array rather than replacing the file:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/mennozweistra/ai-dashboard"
        },
        {
            "type": "vcs",
            "url": "https://github.com/mennozweistra/ai-tm"
        },
        {
            "type": "vcs",
            "url": "https://github.com/mennozweistra/ai-lib"
        }
    ],
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

All three VCS entries are required — the dashboard depends on both
`ai-toolset/tm` and `ai-toolset/ai-lib`, and Composer only honours a
`repositories` block from the root package (your global `composer.json`
here), never from a dependency's own `composer.json`. Missing any one of
the three fails the same way missing `ai-lib` does for a `tm`-only install:
Composer reports the missing package as "could not be found in any
version". The `minimum-stability`/`prefer-stable` pair is required for the
same reason it is for `tm`: none of these three packages has a tagged
stable release yet. See `ai-tm`'s README for the exact failure messages
this fragment avoids.

**2. Install, naming the branch constraint explicitly:**

```
composer global require ai-toolset/ai-dashboard:dev-main
```

Use `:dev-main`, never the bare `composer global require
ai-toolset/ai-dashboard` — same reason as `tm`: a bare require prefers a
stable tag when one exists and pins a version range that a later `composer
global update` never escapes. Do not remove the constraint later.

**3. Put Composer's global bin directory on your `PATH`**, if it is not
already:

- Linux: `~/.config/composer/vendor/bin`
- macOS: `~/.composer/vendor/bin`

This is where the `ai-dashboard` executable lands.

## Update

```
composer global update ai-toolset/ai-dashboard
```

Tracks `main`, same as `tm`. Composer also refreshes the `ai-toolset/tm` and
`ai-toolset/ai-lib` dependencies to their current `main` unless something
else in your global `composer.json` pins them narrower.

## Starting it

```
ai-dashboard
```

By default this binds to `127.0.0.1:8766`. Override with flags:

```
ai-dashboard --host 0.0.0.0 --port 9000
```

Or with the two keys in `~/.ai-dashboard/config.toml` described below. A
flag, when given, always wins over the config file; the config file wins
over the built-in default.

## Uninstall

```
composer global remove ai-toolset/ai-dashboard
claude mcp remove tm
```

(Only run the `claude mcp remove` step if you are uninstalling `tm` too —
the dashboard itself registers no MCP server.)

Optionally, delete the dashboard's config file:

```
rm -rf ~/.ai-dashboard
```

The dashboard never writes to `~/.ai-dashboard/` itself (see below) — this
just removes the config file you created by hand, if you created one.
Nothing else was ever written anywhere.

## Settings model

The dashboard reads one config file, `~/.ai-dashboard/config.toml`. It is
machine-local, read-only from the dashboard's point of view — the
dashboard never creates it and never writes to it — and entirely optional;
every key defaults to "off" or a built-in value when the file or the key is
absent.

```toml
ide_command = "code"
address = "127.0.0.1"
port = 8766
```

- `ide_command` — a single command word (no arguments), launched with a
  ticket's workspace directory as its only argument when you click the
  ticket page's IDE button. Absent, blank, or malformed: the button is not
  rendered at all.
- `address` — default bind address for `ai-dashboard`, overridden by
  `--host`.
- `port` — default bind port for `ai-dashboard`, overridden by `--port`.

There is no `tm`-binary path key in this file. The dashboard locates `tm`'s
`bin/tm` automatically through its Composer dependency on `ai-toolset/tm`
— there is nothing to configure or get wrong there.

The dashboard also reads `TM_DB`, the same environment variable `tm`
itself reads (see `ai-tm`'s README), to select which SQLite database to
read from. It is unset by default, which reads `~/.ai-tm/store.db` — the
same database `tm` writes to. Set `TM_DB` to the same value for both `tm`
and the dashboard if you use a non-default database, so they read and
write the same file.

## Licence

MIT. See `LICENSE`.
