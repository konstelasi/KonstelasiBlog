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

Nobody can sign up through the admin. The account is made from the command line, locally or on the host, and then given a role.

```bash
php artisan make:filament-user
php artisan rbac:assign you@example.com admin
```

## Roles

Every account has one role. Admin, Editor and Writer are defined in `app/Support/Rbac.php`, and `php artisan rbac:sync` makes the database match that file after it changes.

| | Writer | Editor | Admin |
|---|---|---|---|
| Read every post | yes | yes | yes |
| Create a post | yes | yes | yes |
| Edit or delete their own post, while it is a draft | yes | yes | yes |
| Edit or delete anyone's post, live ones too | no | yes | yes |
| Publish, unpublish and set the date | no | yes | yes |
| Manage accounts and roles | no | no | yes |

Everyone can read every post. A Writer opens their own drafts to edit and everyone else's posts, and their own once they are live, in a read-only view, because changing a live post changes the public site. A post that was imported has no writer, so it is edited by Editors and Admins. An account with no role cannot sign in to the admin, and gets a 403 page if it tries.

Admins also get a Users item in the menu. It lists the accounts, creates one with a name, an email address, a password and a role, and lets an Admin change a role, reset a password or delete an account. The name is printed to readers as the author of that person's posts, so type it the way it should appear. The admin will not let you delete your own account, or delete or demote the last Admin. When an account is deleted its posts stay, with the name they had.

The accounts that existed when roles were added became Admins, so nobody was locked out. If every Admin is ever locked out, `php artisan rbac:assign <email> admin` over SSH brings one back.

## Your profile

Everyone has a profile page, from their name in the top corner. The page has a list of three sections on the left, Overview, Account and Security, and shows one at a time. The address remembers which, so a reload stays where you were.

Overview is your work at a glance. It counts your drafts, your live posts and the words you have written. It lists the drafts of yours that still need a language finished, each with the next step, such as Finish Indonesian, and a table of your latest posts. Editors and Admins also see other people's drafts that have both languages and are ready to publish. At the bottom it says what your role lets you do, and also shows what it does not, so a Writer can see what an Editor adds.

Account shows your role (an Admin changes it) and lets you change your name, email address and password. Changing the email address or the password asks for your current password. A new name is printed on all your posts, so as you type it the page says how many posts that touches and how many of them are live, and saving starts a site rebuild when any is live.

Security turns on two-factor sign-in, which is optional. With it on, signing in takes your password and then a six-digit code from an authenticator app such as Google Authenticator or Authy. Choose Set up, enter your current password, scan the QR code with the app, type the first code to confirm, and save the recovery codes that appear. They are shown once, each works one time in place of a code, and they are how you get in if you lose your phone.

If you lose both the phone and the recovery codes, an Admin opens your account on the Users screen and chooses Turn off two-factor. If the only Admin is locked out, run `php artisan mfa:reset <email>` over SSH on the host. Either way you sign in with your password again and can set it up anew.

Security also shows where you are signed in, with each browser, its address and when it was last used, and marks the one you are on. Sign out other browsers, after your password, ends every other sign-in of your account, which is the thing to do if you forgot to sign out on a shared computer.

The secrets and recovery codes are encrypted with the app's `APP_KEY`. Do not change that key while anyone uses two-factor, because their stored secrets would stop working. If it ever has to change, run `php artisan mfa:reset <email>` for each account first.

## Turning on email

The admin sends mail for one thing, confirming an email change. Without a mailer an address change from the profile page takes effect at once and asks for the current password. With one, the new address is sent a link, the old address is sent a notice that can block the change, and the address changes only when the link is opened.

It turns itself on whenever `MAIL_MAILER` is something other than `log` or `array`. We send through the company mailbox, `hello@konstelasi.co.id`, whose outgoing server is `mx3.mailspace.id` on port 465. Port 465 is TLS from the first byte, so the scheme is `smtps`. Only the outgoing settings matter, because the app sends and never reads mail. Put these in the `.env` of the machine, and ask the owner for the password.

```
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=mx3.mailspace.id
MAIL_PORT=465
MAIL_USERNAME=hello@konstelasi.co.id
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=hello@konstelasi.co.id
MAIL_FROM_NAME="Konstelasi Blog"
QUEUE_CONNECTION=sync
```

`QUEUE_CONNECTION=sync` matters. These mails are queued, and the host runs no queue worker, so with the default `database` queue they would wait in the `jobs` table forever. With `sync` they are sent while the page saves, which costs a second or two and makes a save fail with an error if the mail server cannot be reached, instead of losing the mail. A failed save leaves the address unchanged. The site rebuild after a post change is not affected.

On the host, add the same lines to `~/apps/blog/.env` over SSH, because `deploy.sh` never ships `.env`, then run `php artisan config:cache`. The password lives only in `.env` files, which git ignores. Never put it in `.env.example`, this README, a commit or a workflow, because the repository is public.

To check it, change your email address to one you can read. A message should arrive from `hello@konstelasi.co.id`, and the address on your profile changes after you open the link in it. If it lands in spam, check the mailbox's SPF and DKIM records with the mail provider.

## Importing the old posts

The two posts from the old WordPress site were kept as Markdown in the parent repo, one file per language, and have been imported once into the live database. The Markdown files were then removed from the parent, so they exist only in its git history. This command reads a folder of that shape into the database as published posts.

```bash
php artisan posts:import some/path  # any folder with en/ and id/ inside
```

It matches posts by slug, so running it twice updates the same posts instead of adding copies.

## Writing a post

Sign in at `/admin` and open Posts. A post has a Publishing section (status, date, slug and the writer, which is filled in and can't be edited) and one tab per language with a title, a short description and a Markdown body. Images dropped into a body are stored on the server and linked from the Markdown. In the list, the EN and ID columns show a tick when that language has a title, a description and a body, so a draft that isn't ready to publish is easy to spot.

The writer is the account that created the post, and readers see that account's name as the author, so each writer's account needs the name they want printed. Renaming an account renames it on all their posts, and when any of them is live the admin starts a site rebuild at once so the new name appears in a few minutes. Posts that came in through the import keep the author text they were imported with, and so does a post whose writer's account is deleted.

The slug comes from the English title and becomes the post's address, `konstelasi.co.id/blog/<slug>/`. Once a post is published the slug can't be changed, because that would break a live link. A published post has a "View on site" action in the list, which opens that address in a new tab (`SITE_URL` in `.env.example` says which site).

The admin checks the house writing style as you save. It rejects an em dash or an en dash in any field, a hyphen with a space on each side standing in for one, and a colon or semicolon in a title or a Markdown heading. It also rejects an image with no alt text, so write a short description between the square brackets of `![](...)`. Each message gives the line and quotes the words around the problem, so it can be found without counting lines.

A Writer saves drafts only. Their status and date controls are greyed out, and the admin refuses to publish for them even if the page is tampered with. An Editor or an Admin opens the draft, sets the status to Published and saves, and the post keeps the Writer's name as its author.

To put a post on the site, an Editor or an Admin sets its status to Published and saves. The public pages are static files, so the site has to be rebuilt, and saving does that: this app asks GitHub to run the website's Deploy workflow, whose build fetches `/api/posts` and turns each post into a page in both languages. The change is live about two or three minutes later. Editing a draft starts nothing. Setting a live post back to Draft, or deleting it, asks first and names the two addresses that will stop working.

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
7. Over SSH, in `~/apps/blog`, create the admin account with `php artisan make:filament-user`, then give it the Admin role with `php artisan rbac:assign <email> admin`. To bring the old posts over, copy the old Markdown posts up (the parent no longer has them, so check them out of its git history) and run `php artisan posts:import <that folder>`, or import locally and move the rows across.
8. Check that `https://blog.konstelasi.co.id/api/posts` answers, that `/admin` signs you in, and that an image uploaded into a post opens from its `/storage/...` address.
