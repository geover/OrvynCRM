import { defineConfig } from '@playwright/test';

// End-to-end tests drive the REAL UI (built assets) against a throw-away server + SQLite database.
//   npm run test:e2e          (uses the locally installed Google Chrome)
const PORT = 8123;

export default defineConfig({
    testDir: './tests/e2e',
    timeout: 60_000,
    expect: { timeout: 8_000 },
    fullyParallel: false,
    workers: 1,                       // specs share one database and build on each other's data
    retries: 1,                       // Windows can run out of socket buffers mid-suite (net::ERR_NO_BUFFER_SPACE); a serial group reruns whole
    reporter: [['list']],
    use: {
        baseURL: `http://127.0.0.1:${PORT}`,
        channel: 'chrome',
        headless: true,
        viewport: { width: 1360, height: 900 },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    webServer: {
        command: 'node tests/e2e/serve.mjs',
        url: `http://127.0.0.1:${PORT}/login`,
        timeout: 120_000,
        reuseExistingServer: false,
        stdout: 'ignore',
        stderr: 'pipe',
    },
});
