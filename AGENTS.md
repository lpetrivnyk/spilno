# AGENTS.md

Rules for working on the Spilno plugin repository.

## Hard rules

- **No site specifics.** The plugin is shared by two sites and knows neither.
  It must never contain code, data, or names of any particular project
  (`okl`, `bpr`, hospital, their post types, options or functions). Template
  defaults (design texts) are data of a template, not site code.
- **Adapters live in the sites.** The plugin talks to a site exclusively
  through its documented filters and functions (`spilno_*`, see
  `docs/plan.md` → plan §A1). Each site implements them in its own adapter
  (its core plugin). Do not invent new public filters or functions beyond the
  plan; if one is missing, stop and ask.
- **Templates are registered only by the plugin** (`templates/{key}/`). Sites
  cannot add or change templates. Test templates live only in `tests/dev-mu/`
  and load only on `local` / `development`.
- **Field rule: if cleaning would change a value, it is a field error**, never
  a silent change. A malformed request (array instead of a string, unknown
  field) is a field error too, never a PHP error or a 500.
- **English source strings.** All translatable strings are English in code
  and go through `__()` / `esc_html__()` etc. with the `spilno` text domain.
  Ukrainian lives only in `languages/` (`spilno-uk.po` / `.mo`).
- **Prefixes.** Global functions `spilno_*` (public) or `_spilno_*`
  (internal); constants `SPILNO_*`; options, tables and hooks `spilno_*`.
- **Database.** Own tables `{prefix}spilno_*`, schema version
  `SPILNO_DB_VERSION` + option `spilno_db_version`; a schema change bumps the
  constant in the same commit. InnoDB is a deploy condition. Tables are not
  dropped on uninstall.
- **Shipped as a copy of a tag.** Sites get the plugin as a copy of the tag
  `spilno-X.Y.Z` (the same tag on both sites); copies are never edited in the
  sites — change it here, tag, copy again.
- **phpcs must be clean.** `vendor/bin/phpcs` reports 0 errors and 0 warnings
  before any commit; `php -l` on every changed file.
- **No network**, except the development stands (`spilno-dev` and the site
  stands named in the plan).

## Git

- Every item of a task is its own commit. Commit messages: `type(scope): …`
  in Ukrainian, ending with the co-author line when written by an agent.
- Commits go to `main` locally. **Tags only on the owner's explicit command**
  in that message.
- **`git push` — never without an explicit command** in that specific message.
- No `rebase`, `reset --hard`, `push --force` or anything else that rewrites
  existing history without a direct request for that exact operation.

## Process

- `docs/process/limits.md` (a copy from bpr-okl) applies to every task:
  no subagents except the pre-merge review round, narrow reads, one stand
  script per run, report format.
- The plan lives in bpr-okl (`docs/design/shared-pages-plan.md`); this
  repository keeps only a pointer and the owner's decisions on its open
  questions (`docs/plan.md`). bpr-okl, okl and vydno-seo are read-only from
  here.
- Stand: `~/Documents/learn/spilno-dev` (ddev, outside this repository); the
  repository is bind-mounted as `wp-content/plugins/spilno`, harness files
  from `tests/dev-mu/` are symlinked into `wp-content/mu-plugins/`.
