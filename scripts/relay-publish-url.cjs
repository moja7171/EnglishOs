#!/usr/bin/env node
/**
 * Keeps the "local" AI relay slot reachable from production without anyone
 * touching production's .env. Runs forever on the laptop that hosts the relay:
 *
 *  1. Restarts scripts/ai-relay.js if nothing answers on its port.
 *  2. Reads the newest https://<id>.lhr.life URL out of the tunnel's log (a
 *     free localhost.run tunnel gets a new random URL on every reconnect).
 *  3. Checks the tunnel really reaches the relay (a bare GET must come back
 *     401 "bad auth"), then publishes the URL to production through the
 *     token-gated /_diag/ai-relay-use route (see App\Console\Commands\AiRelayUse).
 *     Re-published periodically too, in case production never got it.
 *
 * Usage: node relay-publish-url.cjs <path-to-config.json>
 * Config: { "prodUrl": "https://...", "token": "<DEPLOY_TOKEN>", "relaySecret": "...",
 *           "relayScript": "C:/.../ai-relay.js", "tunnelLog": "C:/.../tunnel.log",
 *           "relayPort": 8899, "stateFile": "C:/.../published-url.txt" }
 */
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');

const configPath = process.argv[2];

if (!configPath) {
    console.error('Usage: node relay-publish-url.cjs <config.json>');
    process.exit(1);
}

const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
const relayPort = config.relayPort || 8899;
const CHECK_EVERY_MS = 20_000;
const REPUBLISH_EVERY_MS = 30 * 60_000;
const URL_PATTERN = /https:\/\/[a-z0-9-]+\.lhr\.life/g;

let lastPublishedAt = 0;
let lastFailureAt = 0;
const RETRY_AFTER_FAILURE_MS = 5 * 60_000;

function log(message) {
    console.log(`[${new Date().toISOString()}] ${message}`);
}

function readState() {
    try {
        return fs.readFileSync(config.stateFile, 'utf8').trim();
    } catch {
        return '';
    }
}

function latestTunnelUrl() {
    let text;

    try {
        const size = fs.statSync(config.tunnelLog).size;
        const fd = fs.openSync(config.tunnelLog, 'r');
        const length = Math.min(size, 200_000);
        const buffer = Buffer.alloc(length);

        fs.readSync(fd, buffer, 0, length, size - length);
        fs.closeSync(fd);
        text = buffer.toString('latin1');
    } catch {
        return null;
    }

    const matches = text.match(URL_PATTERN);

    return matches ? matches[matches.length - 1] : null;
}

async function relayAnswers(url) {
    try {
        const response = await fetch(url, { signal: AbortSignal.timeout(10_000) });

        return response.status === 401 && (await response.text()).includes('bad auth');
    } catch {
        return false;
    }
}

async function ensureRelayRunning() {
    if (await relayAnswers(`http://127.0.0.1:${relayPort}/`)) {
        return;
    }

    log('relay is not answering locally — starting it');

    spawn(process.execPath, [config.relayScript, String(relayPort)], {
        cwd: path.dirname(config.relayScript),
        env: { ...process.env, RELAY_SECRET: config.relaySecret },
        detached: true,
        stdio: 'ignore',
        windowsHide: true,
    }).unref();
}

async function publish(url) {
    const endpoint = `${config.prodUrl.replace(/\/$/, '')}/_diag/ai-relay-use?token=${encodeURIComponent(config.token)}&url=${encodeURIComponent(url)}`;
    const response = await fetch(endpoint, { signal: AbortSignal.timeout(30_000) });
    const body = await response.text();

    if (response.status !== 200 || !body.includes(`AI_PROXY_URL_LOCAL set to "${url}"`)) {
        throw new Error(`production answered ${response.status}: ${body.slice(0, 120).replace(/\s+/g, ' ')}`);
    }
}

async function tick() {
    await ensureRelayRunning();

    const url = latestTunnelUrl();

    if (!url) {
        return;
    }

    const changed = url !== readState();
    const stale = Date.now() - lastPublishedAt > REPUBLISH_EVERY_MS;

    if ((!changed && !stale) || Date.now() - lastFailureAt < RETRY_AFTER_FAILURE_MS) {
        return;
    }

    if (!(await relayAnswers(url))) {
        log(`tunnel ${url} does not reach the relay yet — waiting`);

        return;
    }

    try {
        await publish(url);
        fs.writeFileSync(config.stateFile, url);
        lastPublishedAt = Date.now();
        log(`published ${url} to production`);
    } catch (error) {
        lastFailureAt = Date.now();
        log(`could not publish ${url}: ${error.message}`);
    }
}

async function loop() {
    for (;;) {
        try {
            await tick();
        } catch (error) {
            log(`unexpected error: ${error.message}`);
        }

        await new Promise((resolve) => setTimeout(resolve, CHECK_EVERY_MS));
    }
}

loop();
