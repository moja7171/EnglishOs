/**
 * Cloudflare Worker version of scripts/ai-relay.py — same protocol, no VPS.
 *
 * Why it exists: the VPS relay behind relay.growwise.ir works for small
 * requests but the Iran-hosted app's bigger ones (Sage / Check prompts,
 * a few KB) hang on the way there and come back as cURL error 52/28. A
 * Worker removes the Cloudflare -> VPS leg, and doubles as the test of
 * whether that leg was the problem.
 *
 * Protocol (see App\Services\Concerns\UsesOutboundProxy, the Laravel side):
 * any request with header X-Relay-Url set to the real destination and
 * X-Relay-Auth matching the RELAY_SECRET secret is forwarded verbatim
 * (method, headers, body) and the real response is returned as-is. The
 * destination must be one of ALLOWED_HOSTS, so a leaked secret can only
 * ever reach those providers, never act as an open relay.
 *
 * Setup (Cloudflare dashboard -> Workers & Pages -> Create -> Hello World
 * -> Deploy, then "Edit code"): paste this file, Deploy. Then Settings ->
 * Variables and Secrets -> add a *Secret* named RELAY_SECRET with the same
 * value as AI_PROXY_SECRET in the app's .env. Then set AI_PROXY_URL in the
 * app's .env to the worker's URL (https://<name>.<account>.workers.dev/).
 */

const ALLOWED_HOSTS = new Set([
  'generativelanguage.googleapis.com',
  'api.groq.com',
  'api.pexels.com',
  'images.pexels.com',
  'videos.pexels.com',
]);

// Headers that must not be copied to the destination: hop-by-hop ones,
// plus everything Cloudflare adds about the caller (the provider should
// see a plain request, not the app's address).
const STRIP_REQUEST_HEADERS = new Set([
  'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
  'te', 'trailers', 'transfer-encoding', 'upgrade', 'host', 'content-length',
  'cdn-loop', 'x-real-ip', 'x-forwarded-for', 'x-forwarded-proto',
]);

// fetch() already decompresses the body, so passing the original
// content-encoding / content-length on would make the client try to
// decompress plain bytes (the same bug ai-relay.py's comment describes).
const STRIP_RESPONSE_HEADERS = new Set([
  'connection', 'keep-alive', 'transfer-encoding', 'content-encoding', 'content-length',
]);

function respond(status, text) {
  return new Response(text, { status, headers: { 'content-type': 'text/plain; charset=utf-8' } });
}

/** Compares in constant time so the secret can't be guessed byte by byte. */
function secretsMatch(given, expected) {
  const a = new TextEncoder().encode(given);
  const b = new TextEncoder().encode(expected);
  let diff = a.length ^ b.length;

  for (let i = 0; i < Math.max(a.length, b.length); i++) {
    diff |= (a[i] ?? 0) ^ (b[i] ?? 0);
  }

  return diff === 0;
}

export default {
  async fetch(request, env) {
    if (!env.RELAY_SECRET) {
      return respond(500, 'RELAY_SECRET is not configured');
    }

    if (!secretsMatch(request.headers.get('X-Relay-Auth') ?? '', env.RELAY_SECRET)) {
      return respond(401, 'bad auth');
    }

    let target;

    try {
      target = new URL(request.headers.get('X-Relay-Url') ?? '');
    } catch {
      return respond(403, 'target not allowed');
    }

    if (target.protocol !== 'https:' || !ALLOWED_HOSTS.has(target.hostname)) {
      return respond(403, 'target not allowed');
    }

    const headers = new Headers();

    for (const [name, value] of request.headers) {
      const lower = name.toLowerCase();

      if (STRIP_REQUEST_HEADERS.has(lower) || lower.startsWith('x-relay-') || lower.startsWith('cf-')) {
        continue;
      }

      headers.set(name, value);
    }

    const hasBody = request.method !== 'GET' && request.method !== 'HEAD';

    let upstream;

    try {
      upstream = await fetch(target.toString(), {
        method: request.method,
        headers,
        body: hasBody ? await request.arrayBuffer() : undefined,
        redirect: 'manual',
      });
    } catch (error) {
      return respond(502, `relay fetch failed: ${error}`);
    }

    const outHeaders = new Headers();

    for (const [name, value] of upstream.headers) {
      if (!STRIP_RESPONSE_HEADERS.has(name.toLowerCase())) {
        outHeaders.set(name, value);
      }
    }

    return new Response(upstream.body, { status: upstream.status, headers: outHeaders });
  },
};
