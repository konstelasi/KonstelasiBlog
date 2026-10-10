# Konstelasi blog admin

The place where we write the Konstelasi blog. It runs at `blog.konstelasi.co.id` and has two parts, an admin at `/admin` built with [Filament](https://filamentphp.com) and a read-only API at `/api/posts`. It's a Laravel 13 app on MariaDB.

Readers never come here. The posts appear on [konstelasi.co.id/blog/](https://konstelasi.co.id/blog/), which is the static Astro site in the parent folder. That site asks this app for its posts when it builds, so a post reaches readers only after the site has been rebuilt. Anyone who opens `blog.konstelasi.co.id` itself is sent on to the public blog, and the whole host asks search engines to stay away.

Every post has an English and an Indonesian version, and the admin won't publish one until both are complete. The Indonesian is written for Indonesian readers, not translated word for word.

## Running it locally

You need PHP 8.4.1 or later with the `intl` extension, Composer, Node 22 and a MariaDB server.

```bash
composer install
npm install
npm run build                 # the admin theme, into public/build
cp .env.example .env
php artisan key:generate
```

Create a database called `konstelasi_blog` and put its user and password in `.env`. Then create the tables and start the server.

```bash
php artisan migrate
php artisan serve             # http://localhost:8000/admin
```

## Running the tests

```bash
php artisan test
```

The tests use an SQLite database in memory unless you say otherwise, so they run without MariaDB. To run them on MariaDB, create a `konstelasi_blog_testing` database and pass it in from the shell.

```bash
DB_CONNECTION=mariadb DB_DATABASE=konstelasi_blog_testing php artisan test
```

## Creating the admin account

Nobody can sign up through the admin. The account is made from the command line, locally or on the host.

```bash
php artisan make:filament-user
```

## Importing the old posts

The two posts from the old WordPress site were kept as Markdown in the parent repo, one file per language, and have been imported once into the live database. The Markdown files were then removed from the parent, so they exist only in its git history. This command reads a folder of that shape into the database as published posts.

```bash
php artisan posts:import some/path  # any folder with en/ and id/ inside
```

It matches posts by slug, so running it twice updates the same posts instead of adding copies.

## Writing a post

Sign in at `/admin` and open Posts. A post has a Publishing section (status, date, slug and the writer, which is filled in and can't be edited) and one tab per language with a title, a short description and a Markdown body. Images dropped into a body are stored on the server and linked from the Markdown. In the list, the EN and ID columns show a tick when that language has a title, a description and a body, so a draft that isn't ready to publish is easy to spot.

The writer is the account that created the post, and readers see that account's name as the author, so each writer's account needs the name they want printed. Renaming an account renames it on their posts at the next rebuild. Posts that came in through the import keep the author text they were imported with, and so does a post whose writer's account is deleted.

The slug comes from the English title and becomes the post's address, `konstelasi.co.id/blog/<slug>/`. Once a post is published the slug can't be changed, because that would break a live link. A published post has a "View on site" action in the list, which opens that address in a new tab (`SITE_URL` in `.env.example` says which site).

The admin checks the house writing style as you save. It rejects an em dash or an en dash in any field, a hyphen with a space on each side standing in for one, and a colon or semicolon in a title or a Markdown heading. It also rejects an image with no alt text, so write a short description between the square brackets of `![](...)`. Each message gives the line and quotes the words around the problem, so it can be found without counting lines.

To put a post on the site, set its status to Published and save. The public pages are static files, so the site has to be rebuilt, and saving does that: this app asks GitHub to run the website's Deploy workflow, whose build fetches `/api/posts` and turns each post into a page in both languages. The change is live about two or three minutes later. Editing a draft starts nothing. Setting a live post back to Draft, or deleting it, asks first and names the two addresses that will stop working.

That needs `GITHUB_DISPATCH_TOKEN` in the host's `.env`, a fine-grained GitHub token with only the Actions read and write permission on the website repository (`GITHUB_DISPATCH_REPO` in `.env.example`). Without it saving still works, and the site only changes when someone runs `bash deploy.sh` in the parent repo or runs the workflow by hand in the Actions tab. If the request fails, the failure is written to the log and the save is not affected. The "Saved" message says when a rebuild has been asked for and links to the workflow on GitHub. After a failed request every admin page shows a warning that the public blog may be out of date, until the next request goes through.

## The API

`GET /api/posts` returns every published post, newest first, as a JSON array.

```json
[
  {
    "slug": "stars-align-starcore-reaches-stability-with-v0-2-0",
    "published_at": "2025-12-24T00:00:00+00:00",
    "author": "Damar Maulana",
    "en": { "title": "...", "description": "...", "body": "..." },
    "id": { "title": "...", "description": "...", "body": "..." }
  }
]
```

It needs no key, since everything in it is public on the site anyway. Drafts never appear in it. Like any Laravel API it allows 60 requests a minute from one address.

## Deploying

A push to `main` deploys. The `Deploy` workflow in `.github/workflows/` runs the tests on GitHub, builds the theme and ships the app with `deploy.sh`, with no SSH passphrase involved. It needs five repository secrets (`DEPLOY_SSH_HOST`, `DEPLOY_SSH_PORT`, `DEPLOY_SSH_USER`, `DEPLOY_SSH_KEY` and `DEPLOY_KNOWN_HOSTS`) that belong only to this repository. A push that changes only Markdown files deploys nothing, and neither does a change to the workflow alone. The deploy runs the database migrations on the host, so keep unfinished work on a branch until it is merged. The rest of this section is the manual route, which still works.

`deploy.sh` ships a new version of this app. It runs the tests, builds the admin theme, installs the Composer packages without the development ones, and uploads the result over SSH. The host needs PHP but neither Composer nor Node.

```bash
bash deploy.sh
```

Run it from Git Bash on Windows. It reads the host, port, user and key from a `konstelasi` alias in your own `~/.ssh/config`, so no credential lives in this repository. Set `DEPLOY_HOST` to use another alias. On the host it unpacks into `~/apps/blog`, runs the migrations and rebuilds Laravel's caches. It never overwrites the host's `.env` or `storage/` folder, which hold the settings and the uploaded images.

If the host's default `php` is older than the version the subdomain runs, point the script at the right one, for example `REMOTE_PHP=/opt/cpanel/ea-php84/root/usr/bin/php bash deploy.sh`.

Deploying this app doesn't change the public blog. Only the parent repo's `deploy.sh` does that.

## Before the first deploy

These steps happen once, in cPanel and on your own machine.

1. Add the SSH alias to `~/.ssh/config`. The site's deploy uses the same one.

   ```
   Host konstelasi
       HostName <the host>
       Port <the SSH port>
       User <the cPanel user>
       IdentityFile ~/.ssh/<your key>
   ```

2. In cPanel, create the subdomain `blog.konstelasi.co.id` with its document root at `apps/blog/public`. The folder must not be `public_html`, anything under `stardust`, or another site's folder.
3. Give the blog PHP 8.4.1 or later, with the `intl` extension on. The locked packages need 8.4.1, even though `composer.json` still says 8.3. Where cPanel has MultiPHP Manager, set it for `blog.konstelasi.co.id` alone. On a CloudLinux host that only has Select PHP Version, the setting covers the whole account, and a folder's own `.htaccess` handler line is ignored.
4. In MySQL Databases, create a database and a user, and give the user all privileges on that database.
5. Write `~/apps/blog/.env` on the host by hand, starting from `.env.example`. Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://blog.konstelasi.co.id`, the database details from step 4, and an `APP_KEY` made with `php artisan key:generate --show`. The deploy stops if this file is missing.
6. Run `bash deploy.sh`.
7. Over SSH, in `~/apps/blog`, create the admin account with `php artisan make:filament-user`. To bring the old posts over, copy the old Markdown posts up (the parent no longer has them, so check them out of its git history) and run `php artisan posts:import <that folder>`, or import locally and move the rows across.
8. Check that `https://blog.konstelasi.co.id/api/posts` answers, that `/admin` signs you in, and that an image uploaded into a post opens from its `/storage/...` address.
