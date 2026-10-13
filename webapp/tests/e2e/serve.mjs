// Boots an isolated app instance for the E2E suite: a fresh central SQLite database plus one SQLite file per company,
// built assets, sync queue. Companies: "demo" (admin@orvyncrm.test, the one nearly every spec uses), "other" (owner@other.test)
// and a person who belongs to both (multi@orvyncrm.test) for the company-chooser specs. platform@orvyncrm.test is the platform admin.
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..', '..');
const PORT = 8123;
const dbFile = path.join(root, 'storage', 'e2e', 'e2e.sqlite');
mkdirSync(path.dirname(dbFile), { recursive: true });
rmSync(dbFile, { force: true });
writeFileSync(dbFile, '');
rmSync(path.join(root, 'storage', 'e2e', 'framework'), { recursive: true, force: true });
const tenantsDir = path.join(root, 'storage', 'e2e', 'tenants');
rmSync(tenantsDir, { recursive: true, force: true });

const env = {
    ...process.env,
    APP_ENV: 'local', APP_DEBUG: 'true', APP_URL: `http://127.0.0.1:${PORT}`,
    DB_CONNECTION: 'sqlite', DB_DATABASE: dbFile, DB_BUSY_TIMEOUT: '10000', DB_JOURNAL_MODE: 'WAL', DB_SYNCHRONOUS: 'NORMAL',
    SESSION_DRIVER: 'database', CACHE_STORE: 'database', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'log',
    SANCTUM_STATEFUL_DOMAINS: `127.0.0.1:${PORT},localhost:${PORT}`,
    ADMIN_EMAIL: 'admin@orvyncrm.test', ADMIN_PASSWORD: 'E2e-Passw0rd!x', PLATFORM_EMAIL: 'platform@orvyncrm.test', PLATFORM_PASSWORD: 'E2e-Passw0rd!x',
    TENANCY_SQLITE_PATH: tenantsDir, TENANCY_DB_PREFIX: 'tenant', DEFAULT_MAX_USERS: '1000', DEFAULT_MAX_DATA_OBJECTS: '1000', DEFAULT_MAX_AUTOMATIONS_PER_OBJECT: '1000',
    VITE_HOT_FILE: path.join(root, 'storage', 'e2e', 'no-hot'),      // never use a dev server started by `npm run dev`
    PHP_CLI_SERVER_WORKERS: '4', API_RATE_PER_MINUTE: '100000',    // the suite polls the API far faster than a human
    LOG_CHANNEL: 'single', LOG_LEVEL: 'debug',
};

const run = (cmd, args) => {
    const r = spawnSync(cmd, args, { cwd: root, env, stdio: 'inherit', shell: process.platform === 'win32' });
    if (r.status !== 0) { console.error(`${cmd} ${args.join(' ')} failed`); process.exit(1); }
};

run('npm', ['run', 'build']);
run('php', ['artisan', 'migrate:fresh', '--seed', '--force']);           // platform admin + the "demo" company with its sample data
run('php', ['artisan', 'orvyn:company:create', '"Other Co"', '--slug=other', '--owner-name="Other Owner"', '--owner-email=owner@other.test', '--owner-password=E2e-Passw0rd!x']);
run('php', ['artisan', 'orvyn:company:member', 'demo', 'multi@orvyncrm.test', '--name="Multi Person"', '--role=user', '--template=Sales', '--password=E2e-Passw0rd!x']);
run('php', ['artisan', 'orvyn:company:member', 'other', 'multi@orvyncrm.test', '--role=admin']);
run('php', ['artisan', 'storage:link', '--force']);

const server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${PORT}`], { cwd: root, env, stdio: 'inherit', shell: process.platform === 'win32' });
const stop = () => { server.kill(); process.exit(0); };
process.on('SIGINT', stop); process.on('SIGTERM', stop);
server.on('exit', (c) => process.exit(c ?? 0));
