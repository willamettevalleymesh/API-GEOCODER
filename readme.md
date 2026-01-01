# mesh-node-locator API

A lightweight **PHP JSON API** that helps you answer the question:

> “Given a mesh client IP in **10.x.x.x**, what **AREDN/mesh node (router)** is it behind — and where is that node located?”

It does this by **inferring the router IP** from the client IP (common AREDN LAN sizes), fetching the node’s
`/cgi-bin/sysinfo.json` (node name + GPS), optionally doing **reverse DNS**, and optionally doing **reverse geocoding**
(GPS → city/county/state) via **Geoapify**.

This is intended for dashboards, troubleshooting tools, inventory tagging, and anything else that needs to turn a raw
mesh IP into something human-friendly.

---

## Don’t want to host this yourself?

If you don’t want to run your own copy, you can use the **WVMN Geocoder** endpoint:

- `http://wvmn-tileserver.local.mesh/geocoder.json`
- `http://wvmn-tileserver.local.mesh/geocoder.json?ip=10.190.71.239`

### Nginx: serve it as `/geocoder.json`

To expose the PHP endpoint at a nice `.json` URL, add this to your Nginx site config:

```nginx
location = /geocoder.json {
    rewrite ^ /geocoder/index.php last;
}
```

(Adjust `/geocoder/index.php` to match where you deployed the script.)

---


---

## Features

- **Client-level cache** (1 day)
  - `cache/client_<client-ip>.json`
- **Router inference** for clients in 10.x.x.x
  - Tries common AREDN LAN sizes: `/27`, `/28`, `/29`, `/30`
  - Assumes **router is the first usable host** (`network + 1`)
- **Fetches router sysinfo**
  - `http://<router-ip>/cgi-bin/sysinfo.json`
  - Extracts: `node`, `lat`, `lon`, `gridsquare`
- **Reverse DNS (PTR)** for client and router (optional)
  - `client_rdns`, `router_rdns`
  - Router PTR names that begin with `lan.` are normalized by trimming `lan.`
- **Reverse geocoding** using Geoapify (optional)
  - Adds: `country`, `state`, `state_code`, `city`, `county`
- **GPS → Geoapify cache** (30 days)
  - Rounds `lat/lon` to 2 decimals and caches the geocode result
  - Greatly reduces API calls while keeping the location “close enough” for dashboards
- **Consistent JSON shape**
  - All fields always exist; missing values are `null`
- **CORS-friendly (optional)**
  - Includes an `OPTIONS` handler and preflight caching header
  - You can enable `Access-Control-Allow-Origin` headers if you want browser-based AJAX usage

---

## Why this exists / common uses

- **Dashboards / maps**  
  Show a marker popup like “K9RCP-Edge (Dundee, OR)” instead of a mystery 10.x address.
- **Support & troubleshooting**  
  When someone says “my device is 10.190.71.239”, you can immediately identify the upstream node/router.
- **Telemetry enrichment**  
  Tag logs, metrics, or alerts with `node`, `gridsquare`, and city/county/state for better filtering and grouping.
- **Network inventory**  
  Quickly correlate clients to their upstream router for documentation and reporting.

---

## Geoapify API Key (Required for reverse-geocoding)

If you want the API to return `country/state/city/county`, you must add a **free Geoapify API key**.

1. Get a free key at:
   - https://www.geoapify.com

2. Put your key into the config line:

```php
define('GEOAPIFY_API_KEY', 'ENTER_YOUR_API_KEY_HERE'); // get a free key at https://www.geoapify.com
```

If you don’t want geocoding at all:

```php
define('ENABLE_GEOAPIFY', false);
```

---

## Requirements

- PHP 7.4+ (8.x recommended)
- Web server (nginx/apache) capable of running PHP
- Router/nodes accessible from the server over HTTP on port 80:
  - `http://<router-ip>/cgi-bin/sysinfo.json`
- Writable cache directory:
  - `./cache/` must be writable by the PHP/web user

---

## Installation

1. Copy `index.php` into a web-accessible directory (or into its own folder).
2. Ensure the cache directory exists and is writable:
   - Create `cache/` next to `index.php`
   - Or let the script create it automatically (it tries to `mkdir()`).
3. Add your Geoapify key (optional but recommended).

---

## Usage

### Default (uses the caller’s IP)
```text
/index.php
```

### Override IP (testing / remote use)
```text
/index.php?ip=10.190.71.239
```

---

## Sample success response

```json
{
  "status": "ok",
  "error": null,
  "client_ip": "10.190.71.239",
  "client_rdns": null,
  "router_ip": "10.190.71.225",
  "router_rdns": "K9RCP-Edge.local.mesh",
  "netmask_cidr": 27,
  "netmask": "255.255.255.224",
  "node": "K9RCP-Edge",
  "lat": 45.2755,
  "lon": -123.01778,
  "gridsquare": "CN85lg",
  "country": "United States",
  "state": "Oregon",
  "state_code": "OR",
  "city": "Dundee",
  "county": "Yamhill County"
}
```

---

## Error handling

Errors return a consistent JSON shape with:
- `status` = short error code (ex: `invalid_ip`, `not_mesh_ip`, `router_unreachable`)
- `error`  = human-readable message
- all other fields present and set to `null`

### Example: Not in mesh space (HTTP 403)

```json
{
  "status": "not_mesh_ip",
  "error": "Client IP is not in 10.x.x.x mesh space.",
  "client_ip": "192.168.1.10",
  "client_rdns": null,
  "router_ip": null,
  "router_rdns": null,
  "netmask_cidr": null,
  "netmask": null,
  "node": null,
  "lat": null,
  "lon": null,
  "gridsquare": null,
  "country": null,
  "state": null,
  "state_code": null,
  "city": null,
  "county": null
}
```

### Example: Router unreachable (HTTP 502)

```json
{
  "status": "router_unreachable",
  "error": "Unable to reach router sysinfo for any inferred router in /27, /28, /29, /30 around 10.x.x.x. Is the node/router up?",
  "client_ip": "10.x.x.x",
  "client_rdns": null,
  "router_ip": null,
  "router_rdns": null,
  "netmask_cidr": null,
  "netmask": null,
  "node": null,
  "lat": null,
  "lon": null,
  "gridsquare": null,
  "country": null,
  "state": null,
  "state_code": null,
  "city": null,
  "county": null
}
```

---

## How it works (detailed)

### 1) Client IP selection
- Uses `REMOTE_ADDR` by default
- Allows override via `?ip=` (handy for testing)

Only IPv4 is accepted, and only **10.x.x.x** is allowed. Anything else returns `403 not_mesh_ip`.

### 2) Router inference (/27–/30)
For each mask in `/27, /28, /29, /30`:
1. Compute `network = client & mask`
2. Assume router is the first usable host: `router = network + 1`
3. Try `http://router/cgi-bin/sysinfo.json`

The first router that returns valid JSON wins.

### 3) NAT-mode fallback (/32)
Some NAT setups can make the “client IP” appear to be the node/router itself.
If no inferred router responds, the API can attempt sysinfo on the client IP:
- If it responds, the API treats it as the router with a `/32` netmask.

### 4) rDNS (optional)
Looks up PTR records for:
- client IP (`client_rdns`)
- router IP (`router_rdns`)
and normalizes router PTRs starting with `lan.`.

### 5) Reverse geocoding + cache (optional)
If enabled, lat/lon are reverse-geocoded via Geoapify to produce:
- country/state/state_code/city/county

To limit API calls:
- lat/lon are rounded to 2 decimals for a cache key
- cached for 30 days

---

## Caching

### Client cache (TTL 1 day)
- Keyed by client IP:
  - `cache/client_10.190.71.239.json`

### Geo cache (TTL 30 days)
- Keyed by rounded coordinates:
  - `cache/geo_45.28_-123.02.json`

---

## CORS / browser usage

The script includes preflight handling (`OPTIONS`) and a max-age header, but to allow browser cross-origin AJAX you’ll need to enable these headers in the script (or restrict to your domain):

```php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
```

> Tip: If this endpoint is publicly reachable, consider restricting `Allow-Origin` to your dashboard domain rather than `*`.

---

## Notes / Tips

- Timeouts are intentionally short to keep the API responsive.
- This API assumes routers provide `sysinfo.json` over HTTP. If your nodes require HTTPS or auth, you’ll need to adapt `try_fetch_sysinfo()` accordingly.
- If you expose this publicly, consider restricting or protecting the `?ip=` override to avoid unwanted probing of internal addresses.
- Keep `cache/` writable by the PHP user (web server).

---

## License

See LICENSE file
