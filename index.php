<?php
/**
 * mesh-node-locator API
 *
 * Features:
 *  - Client-level cache:
 *      cache/client_<client-ip>.json (TTL 1 day)
 *  - Auto-detect router for a client in 10.x.x.x using /27, /28, /29, /30
 *  - Fetch /cgi-bin/sysinfo.json from router to get node + GPS
 *  - rDNS:
 *      client_rdns, router_rdns  (router rdns trims leading "lan.")
 *  - Reverse geocode router GPS (lat/lon) via Geoapify:
 *      country, state, state_code, city, county
 *      (NO country_code or county_code)
 *  - GPS → Geoapify cache:
 *      quantize lat/lon to ~0.5 mile (round to 2 decimals) and cache for 30 days
 *  - CORS support so other sites can call with AJAX
 *
 * Usage:
 *   /index.php                      -> uses $_SERVER['REMOTE_ADDR']
 *   /index.php?ip=10.190.71.239     -> override client IP (for testing / remote use)
 */

header('Content-Type: application/json; charset=utf-8');

/* ---- CORS SUPPORT ---- */
//header('Access-Control-Allow-Origin: *');
//header('Access-Control-Allow-Methods: GET, OPTIONS');
//header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Max-Age: 86400'); // cache preflight 1 day

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---- CONFIG ----
define('CACHE_DIR', __DIR__ . '/cache');
define('CLIENT_CACHE_TTL', 86400);     // 1 day for client cache
define('GEO_CACHE_TTL',    86400*30);  // 30 days for GPS->Geoapify cache

// Enable / disable rDNS lookups (PTR)
define('ENABLE_RDNS', true);

// Geoapify reverse geocoding (GPS -> country/state/city/county)
define('ENABLE_GEOAPIFY', true);
define('GEOAPIFY_API_KEY', 'ENTER_YOUR_API_KEY_HERE'); // get a free key at https://www.geoapify.com

/**
 * Standardized error response:
 *  - status: short code ("invalid_ip", "not_mesh_ip", "router_unreachable", ...)
 *  - error: descriptive message
 *  - all other fields exist and are null
 */
function json_error($statusStr, $message, $code = 400, $clientIp = null)
{
    http_response_code($code);

    echo json_encode([
        'status'       => $statusStr,
        'error'        => $message,
        'client_ip'    => $clientIp,
        'client_rdns'  => null,
        'router_ip'    => null,
        'router_rdns'  => null,
        'netmask_cidr' => null,
        'netmask'      => null,
        'node'         => null,
        'lat'          => null,
        'lon'          => null,
        'gridsquare'   => null,
        'country'      => null,
        'state'        => null,
        'state_code'   => null,
        'city'         => null,
        'county'       => null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    exit;
}

/**
 * Get client IP from ?ip= or REMOTE_ADDR
 */
function get_client_ip()
{
    if (!empty($_GET['ip'])) {
        $ip = trim($_GET['ip']);
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    }

    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        json_error('invalid_ip', 'Invalid or missing IPv4 address for client.');
    }

    return $ip;
}

/**
 * Convert CIDR prefix length to dotted-quad netmask.
 */
function cidr_to_netmask($cidr)
{
    $cidr = (int)$cidr;
    if ($cidr < 0 || $cidr > 32) {
        return null;
    }
    $mask = $cidr === 0 ? 0 : (0xFFFFFFFF << (32 - $cidr)) & 0xFFFFFFFF;
    return long2ip($mask);
}

/**
 * Given client+router IPs, *guess* which netmask (CIDR) fits,
 * assuming the LAN prefix is one of /27, /28, /29, /30 and that
 * the router is the first usable address (network+1).
 */
function detect_netmask_prefix($clientIp, $routerIp)
{
    $clientLong = ip2long($clientIp);
    $routerLong = ip2long($routerIp);

    if ($clientLong === false || $routerLong === false) {
        return null;
    }

    // Try from least specific to most specific
    $candidates = [27, 28, 29, 30, 32];

    foreach ($candidates as $cidr) {
        $maskLong  = ip2long(cidr_to_netmask($cidr));
        $netRouter = $routerLong & $maskLong;
        $netClient = $clientLong & $maskLong;

        // Router must be first host
        if ($routerLong !== ($netRouter + 1)) {
            continue;
        }

        // Client must be on same network and not the router
        if ($netClient === $netRouter && $clientLong !== $routerLong) {
            return $cidr;
        }
    }

    return null;
}

/**
 * Try to fetch sysinfo.json from the router.
 * Returns array on success, false on failure (no json_error here).
 */
function try_fetch_sysinfo($routerIp)
{
    $url = "http://{$routerIp}/cgi-bin/sysinfo.json";

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 1.5, // seconds - keep this short
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return false;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return false;
    }

    return $data;
}

/**
 * rDNS lookup helper.
 */
function safe_rdns($ip)
{
    if (!ENABLE_RDNS) {
        return null;
    }

    $host = @gethostbyaddr($ip);
    if ($host === false || $host === $ip) {
        return null;
    }
    return $host;
}

/**
 * Router-specific rDNS normalizer:
 *  - if PTR is "lan.K9RCP-Edge.local.mesh", return "K9RCP-Edge.local.mesh"
 *  - otherwise return the PTR as-is
 */
function safe_router_rdns($ip)
{
    $host = safe_rdns($ip);
    if ($host === null) {
        return null;
    }

    if (strpos($host, 'lan.') === 0) {
        $host = substr($host, 4); // strip leading "lan."
    }

    return $host;
}

/**
 * Ensure cache directory exists.
 */
function ensure_cache_dir()
{
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0777, true);
    }
}

/**
 * Build a safe cache filename from a prefix + key.
 */
function build_cache_file($prefix, $key)
{
    $safeKey = preg_replace('/[^A-Za-z0-9_.-]/', '_', $key);
    return CACHE_DIR . "/{$prefix}_{$safeKey}.json";
}

/* ---------- CLIENT CACHE (per client IP) ---------- */

function client_cache_file($clientIp)
{
    return build_cache_file('client', $clientIp);
}

function load_client_cache($clientIp)
{
    ensure_cache_dir();
    $file = client_cache_file($clientIp);

    if (!file_exists($file)) {
        return false;
    }

    if ((time() - filemtime($file)) > CLIENT_CACHE_TTL) {
        return false;
    }

    $raw = @file_get_contents($file);
    if ($raw === false) {
        return false;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return false;
    }

    return $data;
}

function save_client_cache($clientIp, array $response)
{
    ensure_cache_dir();
    $file = client_cache_file($clientIp);
    @file_put_contents(
        $file,
        json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

/* ---------- GEO CACHE (per ~0.5 mile grid) ---------- */

function geo_cache_file_for_latlon($lat, $lon, &$latQ = null, &$lonQ = null)
{
    $latQ = round((float)$lat, 2);
    $lonQ = round((float)$lon, 2);

    $key = $latQ . '_' . $lonQ;
    return build_cache_file('geo', $key);
}

function load_geo_cache($lat, $lon, &$latQ = null, &$lonQ = null)
{
    ensure_cache_dir();
    $file = geo_cache_file_for_latlon($lat, $lon, $latQ, $lonQ);

    if (!file_exists($file)) {
        return false;
    }

    if ((time() - filemtime($file)) > GEO_CACHE_TTL) {
        return false;
    }

    $raw = @file_get_contents($file);
    if ($raw === false) {
        return false;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return false;
    }

    return $data;
}

function save_geo_cache($latQ, $lonQ, array $data)
{
    ensure_cache_dir();
    $key  = $latQ . '_' . $lonQ;
    $file = build_cache_file('geo', $key);
    @file_put_contents(
        $file,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

/**
 * Reverse-geocode lat/lon using Geoapify Reverse Geocoding API.
 * Uses a ~0.5 mile grid (rounded to 2 decimals) and caches results.
 *
 * Returns array with country/state/city/county fields or null on failure.
 */
function reverse_geocode_geoapify($lat, $lon)
{
    if (!ENABLE_GEOAPIFY) {
        return null;
    }

    $key = GEOAPIFY_API_KEY;
    if (!$key) {
        return null;
    }

    if ($lat === null || $lon === null) {
        return null;
    }

    $latQ = $lonQ = null;
    $cached = load_geo_cache($lat, $lon, $latQ, $lonQ);
    if ($cached !== false) {
        return $cached;
    }

    $params = [
        'lat'   => $latQ,
        'lon'   => $lonQ,
        'limit' => 1,
        'apiKey'=> $key,
    ];

    $url = 'https://api.geoapify.com/v1/geocode/reverse?' . http_build_query($params);

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 3,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['features'][0]['properties'])) {
        return null;
    }

    $p = $data['features'][0]['properties'];

    $result = [
        'country'     => $p['country']     ?? null,
        'state'       => $p['state']       ?? null,
        'state_code'  => $p['state_code']  ?? null,
        'city'        => $p['city']        ?? null,
        'county'      => $p['county']      ?? null,
    ];

    save_geo_cache($latQ, $lonQ, $result);

    return $result;
}

/**
 * Find router for a given client IP by trying /27, /28, /29, /30
 * and using the first router that responds.
 *
 * Returns routerData array or null if none found.
 */
function find_router_info_for_client($clientIp)
{
    $clientLong = ip2long($clientIp);
    if ($clientLong === false) {
        return null;
    }

    // Try inferred routers first: /27, /28, /29, /30
    $prefixes = [27, 28, 29, 30];
    $triedRouters = [];

    foreach ($prefixes as $cidrCandidate) {
        $maskLong   = ip2long(cidr_to_netmask($cidrCandidate));
        $network    = $clientLong & $maskLong;
        $routerLong = $network + 1;
        $routerIp   = long2ip($routerLong);

        if (isset($triedRouters[$routerIp])) {
            continue;
        }
        $triedRouters[$routerIp] = true;

        $sysinfo = try_fetch_sysinfo($routerIp);
        if ($sysinfo === false) {
            continue;
        }

        $node       = $sysinfo['node']       ?? null;
        $lat        = $sysinfo['lat']        ?? null;
        $lon        = $sysinfo['lon']        ?? null;
        $gridsquare = $sysinfo['gridsquare'] ?? null;

        $cidr    = detect_netmask_prefix($clientIp, $routerIp);
        $netmask = $cidr !== null ? cidr_to_netmask($cidr) : null;

        $routerData = [
            'router_ip'    => $routerIp,
            'netmask_cidr' => $cidr,
            'netmask'      => $netmask,
            'node'         => $node,
            'lat'          => $lat,
            'lon'          => $lon,
            'gridsquare'   => $gridsquare,
            'country'      => null,
            'state'        => null,
            'state_code'   => null,
            'city'         => null,
            'county'       => null,
        ];

        $geo = reverse_geocode_geoapify($lat, $lon);
        if ($geo !== null) {
            $routerData = array_merge($routerData, $geo);
        }

        return $routerData;
    }

    // NAT-mode fallback LAST: client IP might be the node itself
    $sysinfoSelf = try_fetch_sysinfo($clientIp);
    if ($sysinfoSelf !== false) {
        $node       = $sysinfoSelf['node']       ?? null;
        $lat        = $sysinfoSelf['lat']        ?? null;
        $lon        = $sysinfoSelf['lon']        ?? null;
        $gridsquare = $sysinfoSelf['gridsquare'] ?? null;

        $routerData = [
            'router_ip'    => $clientIp,
            'netmask_cidr' => 32,
            'netmask'      => '255.255.255.255',
            'node'         => $node,
            'lat'          => $lat,
            'lon'          => $lon,
            'gridsquare'   => $gridsquare,
            'country'      => null,
            'state'        => null,
            'state_code'   => null,
            'city'         => null,
            'county'       => null,
        ];

        $geo = reverse_geocode_geoapify($lat, $lon);
        if ($geo !== null) {
            $routerData = array_merge($routerData, $geo);
        }

        return $routerData;
    }

    return null;
}

// ------------------- MAIN -------------------

$clientIp = get_client_ip();

// Only handle 10.x.x.x clients
if (strpos($clientIp, '10.') !== 0) {
    json_error('not_mesh_ip', 'Client IP is not in 10.x.x.x mesh space.', 403, $clientIp);
}

// Try client cache first
$cachedResponse = load_client_cache($clientIp);
if ($cachedResponse !== false) {
    echo json_encode($cachedResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// No cache: compute fresh
$routerData = find_router_info_for_client($clientIp);
if ($routerData === null) {
    json_error(
        'router_unreachable',
        "Unable to reach router sysinfo for any inferred router in /27, /28, /29, /30 around {$clientIp}. Is the node/router up?",
        502,
        $clientIp
    );
}

// Normalize final response: every field present, missing → null
$routerIp = $routerData['router_ip'] ?? null;

$response = [
    'status'       => 'ok',
    'error'        => null,
    'client_ip'    => $clientIp,
    'client_rdns'  => safe_rdns($clientIp),
    'router_ip'    => $routerIp,
    'router_rdns'  => $routerIp ? safe_router_rdns($routerIp) : null,
    'netmask_cidr' => $routerData['netmask_cidr'] ?? null,
    'netmask'      => $routerData['netmask']      ?? null,
    'node'         => $routerData['node']         ?? null,
    'lat'          => $routerData['lat']          ?? null,
    'lon'          => $routerData['lon']          ?? null,
    'gridsquare'   => $routerData['gridsquare']   ?? null,
    'country'      => $routerData['country']      ?? null,
    'state'        => $routerData['state']        ?? null,
    'state_code'   => $routerData['state_code']   ?? null,
    'city'         => $routerData['city']         ?? null,
    'county'       => $routerData['county']       ?? null,
];

// Save under client IP
save_client_cache($clientIp, $response);

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
