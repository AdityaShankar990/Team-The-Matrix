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
async function handleRequest(req) {
	const cache = await caches.open(CACHE_NAME).catch(() => null);
	const cached = cache ? await cache.match(req).catch(() => null) : null;

	// If we already have a cached copy, ask the server to confirm whether
	// it's still current using its checksum-equivalent headers, instead of
	// blindly re-downloading the whole file every time.
	let networkRequest = req;
	if (cached) {
		const etag = cached.headers.get("ETag");
		const lastModified = cached.headers.get("Last-Modified");
		if (etag || lastModified) {
			const headers = new Headers(req.headers);
			if (etag) headers.set("If-None-Match", etag);
			if (lastModified) headers.set("If-Modified-Since", lastModified);
			networkRequest = new Request(req.url, {
				method: "GET",
				headers,
				credentials: req.credentials,
				// "navigate" isn't a legal mode to set manually on a new Request.
				mode: req.mode === "navigate" ? "same-origin" : req.mode,
				redirect: "follow",
				cache: "no-store",
			});
		}
	}


