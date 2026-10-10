# CLAUDE.md

Guidance for AI coding agents in this repository. Setup, tests and deploys for humans are in [README.md](README.md).

## What this is

- The admin and read-only API for the Konstelasi blog, at `blog.konstelasi.co.id`. Laravel 13, Filament 5 panel at `/admin`, MariaDB.
- Readers never see this app. The public blog pages (`konstelasi.co.id/blog/<slug>/` and `/id/blog/<slug>/`) are built by the parent Astro repo, `..` (KonstelasiWebsite), which fetches `GET /api/posts` at build time and bakes the posts into static pages.
- Publishing a post is one step. `PostObserver` starts the `RebuildSite` job after any save or delete that a visitor could see (a published post, or one that was published until that save), and the job asks GitHub to run the parent's Deploy workflow, which rebuilds the public pages. It runs after the response is sent, never fails a save, and does nothing without `GITHUB_DISPATCH_TOKEN`. Changes that skip Eloquent events, such as a bulk query update, start no rebuild. This app's own `deploy.sh` ships code changes to the admin only.
- This is its own git repo, nested inside the parent's folder on purpose. Never commit across the two, and never `git add` this folder from the parent (it would become an embedded repo). The parent's tsconfig excludes it.
- This app is live at `blog.konstelasi.co.id` and is the only source of the blog. The parent's `src/content.config.ts` always uses the API loader (`src/lib/blog-loader.ts`). It asks `https://blog.konstelasi.co.id` unless `BLOG_API_URL` says otherwise, in the shell or the parent's ignored `.env`, which is how a local preview reads this app. The parent's `deploy.sh` always exports the live URL. The old Markdown posts are gone from the parent and survive only in its git history.

## Rules of the app

- Every post exists in English and Indonesian, in one row (`title_en`, `body_id`, and so on). The form refuses to publish while any of the six language fields is empty. Drafts may be incomplete.
- `App\Rules\HouseStyle` ports the parent's `scripts/verify-copy.ts`: no em or en dash anywhere, no colon or semicolon in a title or a Markdown heading. It also rejects a spaced hyphen standing in for a dash, which verify-copy leaves to review, and a Markdown image with empty alt text (`![](...)`), which verify-copy doesn't check at all. Each message quotes the words around the problem. Posts no longer live in the parent's `src/`, so `npm run verify:copy` never sees them and this rule is the only check. If verify-copy changes, port the change here.
- The author is never typed. `CreatePost` stores the signed-in account as `posts.user_id` and copies its name into `posts.author`. The form shows the field read-only. The API's `author` is `Post::byline()`, the writer's current account name, falling back to the stored `author` text for imported posts and for a deleted account (`user_id` is set null on delete). So `users.name` is public, and the API shape does not change. Editing a post never changes its writer.
- Roles and permissions use `spatie/laravel-permission`. Each account has one role, Admin, Editor or Writer, and a role is a bundle of permissions. `App\Support\Rbac::table()` is the one definition of which role holds which permission, and `Rbac::sync()` makes the database match it. It is idempotent (it creates what is missing, resets each role to the table, deletes permissions the table dropped and clears the permission cache), and the tests, the migration and `rbac:assign` all call it. After changing the table, run `php artisan rbac:sync`; the deploy does not.
- The table: every role has `post.view`, `post.create`, `post.update.own` and `post.delete.own`. Editor adds `post.update.any`, `post.publish` and `post.delete.any`. Admin adds `user.manage`. Admin lists every permission explicitly, with no bypass rule (no `Gate::before`), so the table is the truth. To add a permission, add a constant, put it in `table()`, and use it in a policy.
- `php artisan rbac:assign <email> <role>` gives an account exactly one role. It is the step after `make:filament-user` on a fresh install, and the recovery path over SSH if every Admin is locked out. The migration `create_roles_and_make_users_admins` made every account that existed when roles arrived an Admin. In tests, `User::factory()->admin()`, `->editor()` and `->writer()` create an account with the role.
- The slug is the live URL. It locks once the stored status is published (`Post::slugIsLocked()`).
- `GET /api/posts` returns a bare JSON array (no `data` wrapper), published posts only, newest first: `{ slug, published_at, author, en: { title, description, body }, id: { ... } }`. The parent's content loader depends on this exact shape. Change both sides together.
- `/` redirects (301) to `https://konstelasi.co.id/blog/`. Every response carries `X-Robots-Tag: noindex, nofollow` (`NoIndex` middleware, plus `public/.htaccess` for static files), and `robots.txt` disallows everything.
- The writer hears about rebuilds in two places. After a save that `Post::affectsPublicSite()` says could change the site, and only when `GITHUB_DISPATCH_TOKEN` is set, the "Saved" toast says the site is rebuilding and links to the workflow on GitHub (`ReportsRebuild` on the create and edit pages). When the request to GitHub fails, `RebuildSite` stores the time under the cache key `site-rebuild-failed`, and a render hook in `AdminPanelProvider` shows a warning banner on every admin page until a later request succeeds. The banner says the rebuild did not start, because it can't know whether the workflow itself later failed.
- The Posts list has a "View on site" action for published posts. It opens `Post::publicUrl()`, the English address built from `SITE_URL` (`services.site.url`, default `https://konstelasi.co.id`).
- `php artisan posts:import [path]` reads paired `en/<slug>.md` and `id/<slug>.md` (default `../src/content/blog`, a folder that no longer exists) and upserts them by slug as published. It was for the one-time move off Markdown.
- Post images upload to the `public` disk (`storage/app/public/posts`) and are served from `/storage/...`, which needs `php artisan storage:link` on the host.

## Admin theme

- `resources/css/filament/admin/theme.css`, built by Vite into `public/build` locally and shipped. The host has no Node.
- It follows the parent's `src/styles/tokens.css`: Poppins from `@fontsource/poppins`, neutral greys, and one violet. The colour scales are in `AdminPanelProvider`. Shade 600 is `#311b92` (light `--brand`), shade 400 is `#b39ddb` (dark `--brand`), and the theme points dark-mode shade 500 at 400.
- Violet means active state and focus, as on the site. Don't use it as decoration.
- Shape follows the site too: flat. Cards, the table and the login card are told apart by a 1px border (a 0-blur `box-shadow`), not Filament's shadow and ring. Corners are 10px (`--radius`) and 6px (`--radius-sm`), headings are weight 600 with -0.02em tracking. Only things that float (dropdowns, modals, search results) keep a shadow. The tokens at the top of that block (`--surface`, `--field` and so on) are copies of the site's, with dark mode keyed on Filament's `html.dark`.
- Those rules are unlayered on purpose. Filament's own CSS sits in `@layer components`, so any unlayered rule beats it. A field's resting ring is replaced only under `:not(:focus-within)`, so Filament's brand focus ring still shows. Check the built CSS after adding a Filament class to the list.
- The logo is `resources/views/filament/brand-logo.blade.php`, the site's `konstelasi-logo.svg` inlined with the panel name beside it, so it follows the text colour in both themes. It is a copy, so redo it if the site's logo changes.

## Tests

- `php artisan test`. SQLite in memory by default. To run on MariaDB, set `DB_CONNECTION=mariadb DB_DATABASE=konstelasi_blog_testing` in the shell (phpunit.xml never overrides a variable that is already set).
- The import test reads the parent's real `src/content/blog` and skips once that folder is gone.

## Deploy

- `bash deploy.sh` from Git Bash. It runs the tests, builds the theme, installs Composer packages without dev, tars the app with `vendor/` and `public/build`, and unpacks it into `~/apps/blog` over the `konstelasi` SSH alias (or the one in `DEPLOY_HOST`). It never ships or touches `.env` or `storage/`.
- Never run it, `ssh` or `scp` unless the user asks in that turn.
- A push to `main` that touches more than Markdown also deploys, through `.github/workflows/deploy.yml` on GitHub's runner, with a key kept in repository secrets. The repository is public, so never print a secret or the host details in a workflow step. The deploy runs migrations on the host.
- The remote folder must never be `public_html`, anything under `stardust`, or `konstelasi-staging`. The script deletes code folders inside it.
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
