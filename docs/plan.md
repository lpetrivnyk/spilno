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
