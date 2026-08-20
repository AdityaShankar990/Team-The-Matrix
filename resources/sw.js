// sw.js

const CACHE_NAME = "beuclub-cache-v1";

const STALE_WHILE_REVALIDATE_RE = /\/assets\/data\//;

self.addEventListener("install", () => {
	self.skipWaiting();
});
