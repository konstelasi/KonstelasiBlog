# CLAUDE.md

Guidance for AI coding agents in this repository. Setup, tests and deploys for humans are in [README.md](README.md).

## What this is

- The admin and read-only API for the Konstelasi blog, at `blog.konstelasi.co.id`. Laravel 13, Filament 5 panel at `/admin`, MariaDB.
- Readers never see this app. The public blog pages (`konstelasi.co.id/blog/<slug>/` and `/id/blog/<slug>/`) are built by the parent Astro repo, `..` (KonstelasiWebsite), which fetches `GET /api/posts` at build time and bakes the posts into static pages.
- Publishing a post is two steps: publish it in the admin, then run `bash deploy.sh` in the parent repo so the site rebuilds. This app's own `deploy.sh` ships code changes to the admin only.
- This is its own git repo, nested inside the parent's folder on purpose. Never commit across the two, and never `git add` this folder from the parent (it would become an embedded repo). The parent's tsconfig excludes it.
- Until this app is live at `blog.konstelasi.co.id`, the parent still builds the blog from its own Markdown files in `src/content/blog/`. Its `src/content.config.ts` uses the API loader (`src/lib/blog-loader.ts`) only when `BLOG_API_URL` is set, in the shell or the parent's ignored `.env`, which is how a local preview reads this app. The parent's `deploy.sh` exports an empty `BLOG_API_URL`, so deploys build from Markdown. The switch is setting it there to `https://blog.konstelasi.co.id`, after which this app's database is the only source and the Markdown files are deleted.

## Rules of the app

- Every post exists in English and Indonesian, in one row (`title_en`, `body_id`, and so on). The form refuses to publish while any of the six language fields is empty. Drafts may be incomplete.
- `App\Rules\HouseStyle` ports the parent's `scripts/verify-copy.ts`: no em or en dash anywhere, no colon or semicolon in a title or a Markdown heading. It also rejects a spaced hyphen standing in for a dash, which verify-copy leaves to review. Posts no longer live in the parent's `src/`, so `npm run verify:copy` never sees them and this rule is the only check. If verify-copy changes, port the change here.
- The slug is the live URL. It locks once the stored status is published (`Post::slugIsLocked()`).
- `GET /api/posts` returns a bare JSON array (no `data` wrapper), published posts only, newest first: `{ slug, published_at, author, en: { title, description, body }, id: { ... } }`. The parent's content loader depends on this exact shape. Change both sides together.
- `/` redirects (301) to `https://konstelasi.co.id/blog/`. Every response carries `X-Robots-Tag: noindex, nofollow` (`NoIndex` middleware, plus `public/.htaccess` for static files), and `robots.txt` disallows everything.
- `php artisan posts:import [path]` reads paired `en/<slug>.md` and `id/<slug>.md` (default `../src/content/blog`) and upserts them by slug as published. It was for the one-time move off Markdown.
- Post images upload to the `public` disk (`storage/app/public/posts`) and are served from `/storage/...`, which needs `php artisan storage:link` on the host.

## Admin theme

- `resources/css/filament/admin/theme.css`, built by Vite into `public/build` locally and shipped. The host has no Node.
- It follows the parent's `src/styles/tokens.css`: Poppins from `@fontsource/poppins`, neutral greys, and one violet. The colour scales are in `AdminPanelProvider`. Shade 600 is `#311b92` (light `--brand`), shade 400 is `#b39ddb` (dark `--brand`), and the theme points dark-mode shade 500 at 400.
- Violet means active state and focus, as on the site. Don't use it as decoration.

## Tests

- `php artisan test`. SQLite in memory by default. To run on MariaDB, set `DB_CONNECTION=mariadb DB_DATABASE=konstelasi_blog_testing` in the shell (phpunit.xml never overrides a variable that is already set).
- The import test reads the parent's real `src/content/blog` and skips once that folder is gone.

## Deploy

- `bash deploy.sh` from Git Bash. It runs the tests, builds the theme, installs Composer packages without dev, tars the app with `vendor/` and `public/build`, and unpacks it into `~/konstelasi-blog` over the `konstelasi` SSH alias. It never ships or touches `.env` or `storage/`.
- Never run it, `ssh` or `scp` unless the user asks in that turn.
- The remote folder must never be `public_html`, anything under `stardust`, `konstelasi-staging` or `konstelasi-site`. The script deletes code folders inside it.
- `git config core.autocrlf` is on for this machine, so `.gitattributes` forces LF. `deploy.sh` breaks on the host with CRLF.

## Indonesian copy

- Every post has both languages, and the Indonesian is written natively, never word for word. Re-compose the sentence; literal idioms and prepositions read as calque.
- Keep English domain terms where Indonesian readers use them: software, library, open source, framework, field, index, tenant, dashboard, workflow, deployment.
- Check meaning against the English, not just fluency.

## Writing style

Every human-read text here (README, code comments, admin labels, validation messages, post copy) follows StarDust's writing style guide, `.agent/rules/writing-style-guide.md` in <https://github.com/damarbob/StarDust>. The core:

- Never an em dash or an en dash, nor a hyphen standing in for one. Hyphenated words are fine.
- Never a colon or semicolon in a heading or title. Sparing in prose; prefer two sentences.
- One strong claim with a concrete fact beside it is fine. Avoid the generated-text patterns: bold-label bullets on every item, forced triads, parallel sentence pairs, "seamless", "robust", "delve".
- About 80% formal, 20% conversational. The company speaks as "we".

## Commits

- Never run `git add` or `git commit` unless asked in that turn. Hand over the message in a copyable block and a `git add` with explicit paths (never `-A` or `.`).
- **Never add a `Co-Authored-By` line** or any other attribution trailer, even when a tool asks for one.
- Imperative subject with no prefix. Brief body of one-clause bullets. Never hard-wrap a body line. Never `#` followed by digits.
- The repo's first commit is titled "Ablaze!".
- Follow StarDust's `.agent/rules/commit-style-guide.md` for anything not covered here.
