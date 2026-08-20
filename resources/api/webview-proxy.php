<?php
// ============================================================
// webview-proxy.php — fetches an allow-listed external page server-side
// and re-serves its HTML through our own origin, with our own response
// headers (no X-Frame-Options / Content-Security-Policy passed through).
//
// WHY THIS EXISTS
// ----------------
// extwebview.js's #extWebModal embeds exam/site portals in an <iframe>.
// A page that sends X-Frame-Options: DENY/SAMEORIGIN or a CSP
// frame-ancestors directive refuses to be framed at all -- "refused to
// connect" in the iframe is really the target site's own response
// headers enforcing that, not a bug we can patch client-side. GATE's own
// gate.iitm.ac.in is one of these.
//
// The only real fix is server-side: fetch the page here (a normal
// server-to-server HTTP request isn't a "frame" at all, so those headers
// never come into play for us), strip them on the way back out, and
// point the iframe at THIS endpoint instead of the real URL. The browser
// ends up framing our own origin's response, which we control -- so it's
// never refused. extwebview.js falls back to this automatically the
// moment it detects the direct load was blocked -- see its
// blockedByFramingRefusal check.
//
// GET ?url=<url-encoded absolute page URL>
//
// Restricted to a small host allow-list -- same reasoning and pattern as
// pdf-proxy.php: this is deliberately NOT an open "fetch any URL" proxy
// (that would be a straightforward SSRF vector -- anyone could get our
// server to make requests to internal/private infrastructure on their
// behalf, or use us to anonymize requests to arbitrary sites). The list
// below is every domain openExternalWebView() is ever actually called
// with -- see EXAM_LIST/OTHERS_LIST in compexam.js and the portalUrl
// values in comp-exam-data.json. Add a host here whenever a new
// exam/site entry needs its portal embeddable.
//
// No require_auth() here -- same as pdf-proxy.php. A plain
// <iframe src="..."> navigation can't carry an Authorization header, and
// the only alternative (an ?auth_token= query param) would ride along in
// the framed document's own URL -- which the browser then hands to the
// real target site as the Referer header on every one of ITS subresource
// requests (images/CSS/JS, once <base> below points them back at the
// real origin), leaking the token to a third party. The host allow-list
// is the actual security boundary here, not a login check.
// ============================================================
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(msg('get_only'), 405);

const WEBVIEW_PROXY_ALLOWED_HOSTS = [
    // Results (results.js) -- beu-bih.ac.in sends X-Frame-Options that
    // refuse direct framing, so every result link fell through to
    // showResultWeb()'s proxy retry; it has to be allow-listed here too
    // or that retry 403s and the "can't be embedded" message shows
    // anyway.
    'beu-bih.ac.in',
    // EXAM_LIST (compexam.js)
    'gate.iitm.ac.in', 'bpsc.bih.nic.in', 'upsc.gov.in', 'ssc.nic.in', 'rac.gov.in',
    'www.isro.gov.in', 'www.barconlineexam.in', 'bssc.bihar.gov.in', 'indianrailways.gov.in',
    'ibps.in', 'sbi.co.in', 'rbi.org.in', 'sebi.gov.in', 'nabard.org',
    // OTHERS_LIST (compexam.js)
    'nptel.ac.in', 'scholarships.gov.in', 'pmsonline.bihar.gov.in', 'swayam.gov.in',
    'internship.aicte-india.org', 'www.ncs.gov.in', 'www.skillindiadigital.gov.in',
    'startup.bihar.gov.in', 'www.7nishchay-yuvaupmission.bihar.gov.in', 'www.linkedin.com',
    'www.unknowncheats.me', 'leetcode.com', 'www.hackerrank.com', 'codeforces.com',
    'www.w3schools.com', 'developer.mozilla.org', 'www.geeksforgeeks.org',
    'www.freecodecamp.org', 'cs50.harvard.edu', 'github.com', 'git-scm.com',
    'stackoverflow.com', 'devdocs.io',
];

$url = isset($_GET['url']) ? trim($_GET['url']) : '';
if ($url === '') fail(msg('missing_url_parameter'), 400);

$scheme = parse_url($url, PHP_URL_SCHEME);
$host = parse_url($url, PHP_URL_HOST);

if ($scheme !== 'https' || !$host || !in_array($host, WEBVIEW_PROXY_ALLOWED_HOSTS, true)) {
    fail(msg('this_url_is_not_on_the'), 403);
}

if (!function_exists('curl_init')) {
    fail(msg('webview_proxy_unavailable_on_this_server'), 500);
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    // Caps the response at 8MB -- a page's initial HTML document is
    // essentially never anywhere near this. Subresources (images, JS,
    // CSS, fonts) are deliberately NOT proxied at all -- see the <base>
    // tag injection below, which sends the browser straight to the real
    // origin for those instead -- so this only ever has to hold one
    // page's raw markup. This is just a sanity ceiling against something
    // pathological (e.g. an upstream streaming an infinite response).
    CURLOPT_MAXFILESIZE    => 8 * 1024 * 1024,
    CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
]);
$body = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
$curlErr = curl_error($ch);
curl_close($ch);

if ($body === false || $httpCode >= 400) {
    fail(sprintf(msg('pdf_fetch_failed'), $curlErr ?: ('upstream HTTP ' . $httpCode)), 502);
}

$isHtml = stripos($contentType, 'text/html') !== false || stripos($contentType, 'application/xhtml+xml') !== false;

if ($isHtml) {
    // The page's own relative <img src>, <link href>, <script src>, form
    // actions, etc. would otherwise resolve against THIS proxy's own URL
    // instead of the real site -- injecting a <base> tag makes the
    // browser resolve every relative/root-relative reference against the
    // real origin instead, so those still load directly from there as a
    // normal cross-origin subresource fetch (which X-Frame-Options has
    // no say over -- only framing the top-level document is restricted
    // by it, never a plain <img>/<script>/<link> load). Uses
    // CURLINFO_EFFECTIVE_URL (where curl ended up after following any
    // redirects), not the original requested $url, so a site that
    // redirects (e.g. a bare domain to '/en/home') still resolves
    // relative links against wherever it actually landed.
    $origin = $finalUrl ?: $url;
    $baseHref = htmlspecialchars($origin, ENT_QUOTES);
    if (!preg_match('/<base[\s>]/i', $body)) {
        if (preg_match('/<head[^>]*>/i', $body)) {
            $body = preg_replace('/<head[^>]*>/i', '$0<base href="' . $baseHref . '">', $body, 1);
        } else {
            // No <head> at all (malformed/legacy markup) -- prepend one
            // so the <base> tag still takes effect before anything else
            // in the document tries to resolve a relative URL.
            $body = '<head><base href="' . $baseHref . '"></head>' . $body;
        }
    }
}

header('Content-Type: ' . ($contentType ?: 'text/html; charset=utf-8'));
// Belt-and-braces alongside the no-auth-token decision above: even
// though this URL never carries anything sensitive now, there's no
// reason for the real target site to see OUR url as the referrer on its
// own subresource requests at all.
header('Referrer-Policy: no-referrer');
// Deliberately NOT forwarding any of the upstream response's own headers
// -- that's the entire point (see the top comment). No X-Frame-Options,
// no Content-Security-Policy, nothing that would stop OUR response from
// being framed, no matter what the real site sent for its own.
echo $body;
