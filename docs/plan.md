# Plan

The source of truth is the plan in the bpr-okl repository:

- file `docs/design/shared-pages-plan.md`, branch `feature/shared-pages`,
  commit `36bd3b8` (read with
  `git -C ~/Documents/learn/bpr-okl/web show 36bd3b8:docs/design/shared-pages-plan.md`).

The plan is not copied here; changes to it are made in bpr-okl.

## Owner decisions on the plan's open questions (2026-11-21)

| № | Question | Decision |
|---|---|---|
| S1 | Where the link registry lives (§C7) | **wp-admin of the plugin** — «Tools → Shared pages», `manage_options` |
| S2 | Trash / delete of a linked page (§C6) | **Refused** while the page is in a link (`pre_trash_post`, `pre_delete_post`) |
| S3 | Changing «where we take it from» of a link (§C2) | **Handshake `link/source`, only in the synced state** (no copying) |
| S4 | Partner for synchronisation tests | **A separate stand `spilno-dev`** (and its partner stand, stage 4) |

## Deviations of the implementation from the plan

Recorded per stage, with the reason.

### Stage 1

- Value columns are `values_json` (pages, log), not `values`: `VALUES` is a
  reserved word in MySQL / MariaDB and `dbDelta()` does not quote column
  names.
- Templates are files `templates/{key}/schema.php` of the plugin. A test
  template directory is accepted only through the constant
  `SPILNO_DEV_TEMPLATES_DIR` and only on `local` / `development`; there is no
  public filter to add templates.
- `spilno_save( $record_id, $values, $expected_rev, $args )`: a fourth
  argument — `template` (a page without a row yet), `schema_hash` (the plan's
  «the form carries the schema hash», §A2; required), `author_label`, `event`.
  `$expected_rev` is the `rev_id` (UUID) the form was opened with, `''` for a
  page without values yet.
- `spilno_publishable( $record_id, $values = null, $template = null )`: the
  site can check the values before saving them (a published page must not
  lose its required fields).
- `required` is checked only by the publication gate, not by `spilno_parse()`
  (a draft may be incomplete — as for BPR records). An empty field means the
  default, so a required field with a default is always satisfied.
- A field absent from the input is an error (a POST cut by
  `max_input_vars` cannot blank the page); an absent toggle is «off».
- Adopt map: filter `spilno_adopt_map( null, $record_id, $template )` → dotted
  field path → raw value. Adopt requires `manage_options` (or WP-CLI).
- `spilno_can` without an adapter answers `false` (nobody edits); WP-CLI
  bypasses it.
- No schema migrations between template versions: a stored page is
  normalized against the current schema on read (missing → empty, unknown →
  dropped, over the limit → empty) and written in the new version by the next
  save.
- CLI lives in `includes/cli.php` (not `includes/sync/`): stage 1 has no sync.
- No `uninstall.php`: data stays on uninstall (plan §A1).

### Stage 2

Reference: the page «Про лікарню» of the first site as its stand renders it
(record 495, branch `feature/pages-p5` `6a1cb15`; its working tree had
uncommitted changes outside the about files). Not Figma: the design of that
page is already agreed with the owner.

Inherited from the reference as is (plan §A4, differences from Figma
`1828:83093 / 83183 / 83243`): Tablet — 3 cards instead of 2 (Д23); the
«Content» band on every width (Д22); news without photos (Д28); a static
backdrop (Д26); cards on the inverse band are `bg/paper` with a border;
the mosaic sized by its content; Mobile gap 32 instead of 20; mosaic photo
radius 16 / 16 / 8; tile 254 instead of 224; side gutters 24 / 16 instead of
20 / 12.

Deviations:

- `spilno_render( $record_id, $context )` echoes. `$context`: `lang`,
  `template` (a page without stored values yet renders the defaults of the
  template its record names) and **`before`** — the site's own escaped markup
  printed inside the page root above the page head. Reason: the reference
  draws its breadcrumbs on the page backdrop; with the crumbs outside the root
  the top of the page would differ from the reference.
- List groups take per-item `defaults` (the three cards, five links and six
  tiles of the design have different texts); the defaults are part of the
  schema hash.
- The style sheet is enqueued by `spilno_render()`; when the document head is
  already out it is printed right before the page root (no new public
  function for an early enqueue).
- Texts that are not fields (the lede sentences resting on figures, «since
  {year}», the staff lines with plurals, «Read») are code strings in English
  with `uk.po`; the field defaults are Ukrainian data of the schema.
- Data slots: `facts` — `{key: {value, label?}}` of the figures the site may
  show now (its own confirmation rule); `news` — a list of `{title, url,
  meta}`, at most 3 shown. Link slots must be absolute http(s) URLs; ports and
  local hosts are allowed (stands), unlike `wp_http_validate_url()`.
- The plugin's own reduced-motion rule is scoped to `.spilno` (system setting
  and `data-motion="reduce"`), so the page does not depend on the theme's.
- Tokens: `assets/spilno.tokens.txt` (81 roles) is generated from the built
  CSS by `tools/tokens.mjs`; all are present in the generated token files of
  both themes, which are byte-identical; `--ds-font-base` comes from the
  theme as the contract says.
- Test theme shell: `tests/dev-theme/` (in the repository, so the stand is
  reproducible; never loaded by the plugin). Token files are copies of the
  reference theme's generated files (identical in the second theme), fonts
  Gilroy + Onest.

### Stage 4 — split into parts (owner, 2026-11-21)

Stage 4 of the plan is done in parts, each with a stop and a report:

| Part | Scope |
|---|---|
| 4a | Partner stand `spilno-dev-b`, endpoint `/wp-spilno/v1/*` and its protection (§B), signed client, `ping` and «Check the link» in CLI (§E3) |
| 4b | Link registry (`spilno_links`) and handshake, wp-admin screen (with the «Check the link» button) |
| 4c | Page synchronisation, queue, cron, «read only» on the non-source |
| 4d | Media (§B1, §E4) |
| 4e | Cloned-database detector, trash refusal for linked pages, the rest of the CLI, tag `0.2.0` |

### Stage 4a

Stands: `spilno-dev` (site `a`) and `spilno-dev-b` (site `b`), both
`https://…ddev.site:33001`, prepared by `tests/stands.sh`; the pair's secrets
live only in the stands' wp-config.php. ddev must not regenerate those files
(the `#ddev-generated` marker is removed).

Deviations:

- **Rate-limit and block key** = address + claimed site, as the owner asked;
  only the expected partner identifier gets its own key, any other claimed
  value shares one key per address (changing the header does not escape the
  limit; claiming the partner from another address does not block it).
- Rate limit and block answer **403**, not 429 (the refusal codes are 401 /
  403 / 404 / 413 / 400). A wrong method, an unknown path under `/wp-spilno/`
  and any query string answer 404; missing or malformed signature headers
  401; a full table of nonces 503.
- **Nonces** are kept by their request time: deleted once older than 300 s +
  60 s margin (plan: 15 min — longer than any accepted request time). The
  cleanup runs on every accepted request, at most 20 rows, index
  `req_time`; no cron. The nonce of an authenticated request is stored
  before the body check, so a body refusal (400) keeps it (a replay stays
  impossible).
- **Journal of refusals**: `spilno_log`, `record_id` 0, `event` `refused`,
  `site`, `note` = status, reason, operation, address; the newest 1000 rows
  are kept. IP refusals are journaled at most once per 10 s (anybody can
  send them); rate and block — once per window and key.
- Operation **`ping/back`** (GET, signed): the receiver pings the sender back
  and reports the result — the plan's «other direction» (§E3.6), not in the
  §B list.
- Operation **`dev/echo`** (POST, JSON `{text}`) exists only on `local` /
  `development` with `SPILNO_DEV_ENDPOINT` (the harness), to test the body
  checks before the real POST operations exist.
- Body checks in 4a: a JSON object, allowed keys of the operation, string
  values with a length limit; `spilno_parse()` of page values comes with 4c.
- The endpoint reads `spilno_enabled` on `plugins_loaded:1`: a site switches
  it off from a plugin or a must-use plugin, not from its theme. Its answers
  are English when the site's translations are not loadable that early.
- «Check the link», TLS step: trusts the WordPress bundle and the system
  store, as WordPress requests over curl do (the stand's mkcert CA is in the
  system store).
- `SPILNO_DB_VERSION` 1.1.0: table `spilno_nonces (site, nonce, req_time)`.
