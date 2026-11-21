# Spilno

WordPress plugin for pages shared between two sites: a registry of page
templates (schema, field types, limits, defaults), page values with revisions
and an optimistic lock in its own tables, and an adapter contract (filters)
through which each site plugs the pages into its own records and admin.

Status: stage 2 — the template «about» (schema, markup, styles). No synchronisation yet.

- Requires WordPress 6.4+, PHP 8.1+, MySQL / MariaDB with InnoDB.
- Shipped to sites as a copy of a tag `spilno-X.Y.Z`; the same tag on both sites.
- Data (tables `{prefix}spilno_*`, option `spilno_db_version`) is kept on uninstall.
- WP-CLI: `wp spilno schema-hash`, `wp spilno doctor`, `wp spilno adopt`.
- Coding standard: `composer install`, then `vendor/bin/phpcs`.
- Styles: `npm ci --offline` (or `npm ci`), then `npm run build` → `assets/spilno.css` and
  `assets/spilno.tokens.txt` (both committed).
- Development only (never copied to the sites): `tests/`, `tools/`, `package*.json`,
  `composer.*`, `phpcs.xml`, `docs/`.

Rules for working on this repository: [AGENTS.md](AGENTS.md). Plan and owner
decisions: [docs/plan.md](docs/plan.md).
