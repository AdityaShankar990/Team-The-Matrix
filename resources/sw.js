// sw.js

const CACHE_NAME = "beuclub-cache-v1";

const STALE_WHILE_REVALIDATE_RE = /\/assets\/data\//;

self.addEventListener("install", () => {
	self.skipWaiting();
});
self.addEventListener("activate", (event) => {
	event.waitUntil(
		(async () => {
			// Clean up any caches left over from a previous CACHE_NAME.
			const keys = await caches.keys();
			await Promise.all(
				keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
			);
			await self.clients.claim();
		})()
	);
});

self.addEventListener("fetch", (event) => {
	const req = event.request;

	// Only handle simple GETs.
	if (req.method !== "GET") return;

	let url;
	try { url = new URL(req.url); } catch (e) { return; }
	if (url.origin !== self.location.origin) return;

	const pathname = url.pathname;

	if (STALE_WHILE_REVALIDATE_RE.test(pathname)) {
		event.respondWith(handleStaleWhileRevalidate(req, event));
		return;
	}

	event.respondWith(handleRequest(req));
});
async function handleStaleWhileRevalidate(req, event) {
	// Cache API access can be blocked entirely or otherwise throw, treat
	// that the same as "nothing cached yet" instead of letting it reject
	// this whole handler and turn into a broken response for the page.
	const cache = await caches.open(CACHE_NAME).catch(() => null);
	const cached = cache ? await cache.match(req).catch(() => null) : null;

	const revalidate = fetch(req).then((networkResponse) => {
		if (cache && networkResponse && networkResponse.ok) {
			cache.put(req, networkResponse.clone()).catch(() => {}); // storage blocked
		}
		return networkResponse;
	}).catch(() => null); // offline

	if (cached) {
		if (event && event.waitUntil) event.waitUntil(revalidate);
		return cached;
	}

	// Nothing cached
	const fresh = await revalidate;
	if (fresh) return fresh;
	throw new Error("Network error and no cached copy available");
}

