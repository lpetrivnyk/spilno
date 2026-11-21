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
