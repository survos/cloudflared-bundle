# survos/cloudflared-bundle

Expose a local Symfony dev app through a named [cloudflared](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/) tunnel without hand-maintaining its public hostname anywhere.

A dev app often needs a URL the outside world can reach: a webhook callback from a remote
service, a QR code a phone will open. Copying a tunnel hostname into `.env.local` works until
it quietly doesn't — the tunnel moves to another machine, two machines serve one tunnel and
Cloudflare load-balances between them, a port gets reassigned. This bundle derives the URL
from what is actually running, and can prove it answers from this machine.

## Where the facts come from

| Fact | Source |
|---|---|
| This app's port | the Symfony CLI proxy index, `http://127.0.0.1:7080/index.json`, by project directory (override: `port`) |
| What the tunnel serves | the **running** cloudflared's `/config` on its metrics port (127.0.0.1:20241-20245), never the config files — this machine may hold credentials for tunnels it must not run |
| The public hostname | the live ingress rule forwarding to this app's port, preferring `<machine>-<app>.<zone>` |
| Who else serves the tunnel | `cloudflared tunnel info` — every connector, on every machine |

## Install

```bash
composer require survos/cloudflared-bundle
export CLOUDFLARED_MACHINE=m4   # once, in your shell profile: the per-machine hostname prefix
```

Add `config/packages/survos_cloudflared.yaml` (its presence also opts the app into
`cloudflared:ingress`):

```yaml
survos_cloudflared:
    # port: 8021              # default: from the proxy index
    # machine: m4             # default: CLOUDFLARED_MACHINE
    # zone: survos.org        # the default; see Hostnames below
    # enabled: ~              # default: dev environment only
    # guard_debug_routes: true
```

## Use the URL

The `tunnel:` env var processor returns the tunnel's base URL when one serves this app, and the
variable's own value otherwise — so production (bundle disabled, no tunnel) is unchanged:

```php
#[Autowire('%env(tunnel:CALLBACK_BASE_URL)%')] private readonly string $callbackBaseUrl,
```

Or inject `Survos\CloudflaredBundle\Service\Tunnel` and call `url()` / `host()` (null when no
tunnel serves the app).

## Hostnames: `<machine>-<app>.survos.org`

Every dev app reachable through a tunnel is `<machine>-<app>.survos.org`: `m4-news`, `m4-ink`,
`m4-depot`, `praveen-ssai`. One zone, one pattern, on every machine.

- **survos.org is dev-only.** No production app ever lives on it. Dev tunnels publish dev-mode apps,
  and a shared parent domain with production lets cookies and credentials cross between them.
- **Hyphen, not dot.** `m4-depot.survos.org`, never `depot.m4.survos.org`: Cloudflare's free Universal
  SSL certificate covers one level (`*.survos.org`); a second level needs a paid Advanced Certificate.
- **No shared cookies between dev apps.** They all sit under one parent domain, so each app keeps
  its cookies host-only (never `domain: .survos.org`) with its own session name. Otherwise one app's
  session is sent to another; the symptom is an app that misbehaves in a normal window and works
  in an incognito one.
- **DNS lives in the survos.org zone.** `cloudflared tunnel route dns` creates records in the zone of
  the certificate from `cloudflared tunnel login`. With a certificate for another zone it appends
  that zone: `m4-news.survos.org` became `m4-news.survos.org.scanstationai.work`. Log in to
  survos.org, or create the proxied CNAME (`<tunnel-id>.cfargotunnel.com`) through the Cloudflare API.
- **Production traffic doesn't share a tunnel with dev apps.** Adding a dev app means restarting its
  tunnel; anything production depends on (the scanstation depot) goes on its own tunnel so that
  restart can't interrupt it.

`scanstationai.work` is the older zone; its hostnames stay as aliases until nothing uses them.

## Commands

- `cloudflared:status` — port, running connectors, live routes, the resolved public URL.
- `cloudflared:probe` — fetches `/_cloudflared/whoami` through the public hostname N times and
  compares the answer with this checkout's instance id; also lists every connector on the
  tunnel and flags any on another machine. Exit code 0 only when every request came back here.
- `cloudflared:ingress [apps...] [--all] [--machine=m4] [--write=file]` — generate the machine's
  ingress from the proxy index. Additive: every existing rule is kept; new
  `<machine>-<app>.<zone>` rules are added for opted-in apps, validated with
  `cloudflared tunnel ingress validate`, and the `cloudflared tunnel route dns` commands for the
  new hostnames are printed (not run). Warns when a rule forwards to a port the proxy index has
  given to a different app. Never overwrites the config the tunnel is running from.

## Safety

A tunnel publishes a **dev-mode** app to the internet, and in dev `/_profiler` exposes request
and server parameters, including env vars. For requests that arrive through Cloudflare
(`Cf-Ray` / `Cf-Connecting-Ip` present) the bundle returns 404 for `/_profiler` and `/_wdt` and
strips `X-Debug-Token`, which also stops the toolbar being injected. Local requests are
untouched. Dev error pages (stack traces) are not covered.
