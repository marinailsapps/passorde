# Extra Caddy sites (server only)

`docker/caddy/Caddyfile` ends with `import /etc/caddy/sites/*.caddy`, and
`compose.prod.yml` mounts this directory read-only into the `caddy` container.
In the repository it holds only this README, so the glob matches nothing and
production serves `APP_DOMAIN` alone.

On the server, `<install directory>/docker/caddy/sites/` may hold `*.caddy`
files written by hand, for example a redirect from another name or a second
stack. Copying the deploy files never touches this directory.

After adding, changing or removing a file there:

```sh
cd /srv/passorde
docker compose exec caddy caddy validate --config /etc/caddy/Caddyfile
docker compose exec caddy caddy reload --config /etc/caddy/Caddyfile
```

Each site should `import /etc/caddy/security.caddy` inside its block, as the
production site does: it sets the security headers and the CSP
(`style-src 'self'`, `script-src 'self'` + Cloudflare Turnstile) that
CLAUDE.md requires of every page.

A reload is graceful: production keeps answering. A site's upstream must be
reachable from Caddy, i.e. on the production `edge` network
(`<COMPOSE_PROJECT_NAME>_edge`), under a name other than `app` (Caddy's
production upstream).
