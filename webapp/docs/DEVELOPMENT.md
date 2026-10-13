# Local development

How to run OrvynCRM and its public website on a Windows machine with Laragon, and the routine for a normal day. (Production setup is in [DEPLOYMENT.md](DEPLOYMENT.md).)

## The two projects

| Project | Folder | What it is | Local address (Laragon) |
|---|---|---|---|
| OrvynCRM (`webapp`) | `D:\Work\OrvynCRM\webapp` | The product: Laravel, Inertia, Vue, Tailwind | `http://app.orvyncrm.test:8080` |
| Website (`website`) | `D:\Work\OrvynCRM\website` | The public marketing site: Astro, static output | `http://orvyncrm.test:8080` |

They are two separate projects (each with its own `package.json`, dependencies and deploy) that live side by side in one repository, `D:\Work\OrvynCRM`. In production the website is `www.<domain>` and the CRM is `app.<domain>`.

The `:8080` is there because Laragon's Apache listens on 8080 on this machine (something else holds port 80). If you free port 80 and set Apache to 80 in Laragon (Menu, Preferences, Services & Ports), drop `:8080` everywhere below.

## One-time setup

1. **Laragon** with Apache, MySQL 8 and **PHP 8.3** (Menu, PHP, Add if its PHP folder is empty), and Node 22 for the front end.
2. **Make Laragon see both projects.** Laragon turns each folder in `D:\laragon\www` into `<folder>.test`, so link the projects in with junctions (run once, in a Windows command prompt):

```bash
mklink /J D:\laragon\www\app.orvyncrm D:\Work\OrvynCRM\webapp
```

```bash
mklink /J D:\laragon\www\orvyncrm D:\Work\OrvynCRM\website\dist
```

   Then Menu, Apache, Reload. Laragon serves the CRM from its `public` folder and the website from `dist`, and adds both names to the Windows hosts file.
3. **CRM `.env`** (copy `.env.example`, then `php artisan key:generate`; set `DB_*`; run `php artisan migrate --seed`):

```ini
APP_URL=http://app.orvyncrm.test:8080
SANCTUM_STATEFUL_DOMAINS=app.orvyncrm.test:8080,app.orvyncrm.test,localhost:8000,127.0.0.1:8000
```

   The port must be in `SANCTUM_STATEFUL_DOMAINS`, or sign-in from the browser fails.
4. **Website `.env`** (copy `.env.example`):

```ini
PUBLIC_APP_URL=http://app.orvyncrm.test:8080
SITE_URL=http://orvyncrm.test:8080
```

   Every `PUBLIC_` value ends up in the built pages, so nothing secret goes in this file. Run `npm install` in both projects the first time.
5. **PHP upload limits** (for the sign-in background video and other uploads): in Laragon's `php.ini` set `upload_max_filesize = 30M` and `post_max_size = 32M`, then restart Apache.

## A normal day

**Start (once):** start Laragon (Apache and MySQL). The CRM also needs its background workers, or e-mail, SMS and automations will not run. In `D:\Work\OrvynCRM\webapp`, in two terminals:

```bash
php artisan queue:work --queue=ticks,automations,webhooks,messages,default
```

```bash
php artisan schedule:work
```

**Working on the CRM:**
- PHP changes need nothing: reload the page.
- For front-end changes, run Vite for instant updates:

```bash
npm run dev
```

- Do not use `composer run dev` with Laragon: it also starts `php artisan serve`, which Laragon replaces.
- When you finish, stop Vite with Ctrl+C and run `npm run build`, so the built files in `public/build` are current.

**Working on the website:**
- Edit with live reload at `http://localhost:4321`:

```bash
npm run dev
```

- `http://orvyncrm.test:8080` shows the **built** `dist` folder, not the dev server. To see your changes there:

```bash
npm run build
```

**Before you commit, demo or share:** run `npm run build` in both projects so the `.test` addresses show the finished result. Then run the tests when you decide to:

```bash
php artisan test
```

```bash
npx playwright test
```

The browser tests start their own isolated app on port 8123 with a throw-away database, so they never touch your Laragon sites or data.

## Rules of thumb

- A live-reload server changes what you see while you work. The `.test` address shows only what has been built.
- While `npm run dev` runs in the CRM it creates `public/hot`, and the CRM then loads its scripts and styles from Vite. Stop it with Ctrl+C so the file is removed.
- If something looks stale, hard-refresh with `Ctrl+F5`.

## When something is wrong

| Symptom | Likely cause and fix |
|---|---|
| The CRM page is blank after stopping `npm run dev` | A stale `public/hot` file. Delete it, then run `npm run build`. |
| "Unable to locate file in Vite manifest" | The front end was never built. Run `npm run build`. |
| Sign-in does not stick, or API calls return 401 or 419 | The address (including `:8080`) is missing from `SANCTUM_STATEFUL_DOMAINS`, or `APP_URL` is wrong. Run `php artisan config:clear` after editing `.env`. |
| The website at `orvyncrm.test` is out of date | It shows `dist`. Run `npm run build` in the website folder. |
| The website's Sign in button goes to the wrong place | `PUBLIC_APP_URL` in the website `.env`. Rebuild after changing it. |
| An upload fails with "The POST data is too large" (413) | PHP's `upload_max_filesize` and `post_max_size` are too low (Laragon's `php.ini`). |
| An automation, e-mail or SMS never goes out | The queue worker or scheduler is not running, or the e-mail server or Twilio account is not set up (the automation editor warns about this). |
| `orvyncrm.test` does not resolve | Laragon's Apache was not reloaded after adding the junction, or Laragon is not running as administrator so it could not edit the hosts file. |

## Useful commands

```bash
php artisan migrate
```

```bash
php artisan tenants:migrate
```

Run both after pulling changes that add migrations: the first updates the central database, the second updates every company's database.

```bash
php artisan config:clear
```

```bash
vendor/bin/pint --dirty --format agent
```

The last one formats the PHP files you changed, as the project's style requires.
