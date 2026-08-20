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
