<?php
// index.php
// Simple full-page proxy + UI. Put this file in your webroot.
// GET parameter 'u' = URL to fetch. If not present, show UI.

error_reporting(0);

// If `u` is present -> behave as proxy
if (isset($_GET['u'])) {
    proxy_mode($_GET['u']);
    exit;
}

// Otherwise show the UI (the same UI you pasted, but wired to open the proxied view)
show_ui();
exit;


function show_ui() {
    // Minimal UI: similar style you gave, but the button will navigate to ?u=...
    // You can replace with your original UI markup; this is the essential wiring.
    $self = htmlspecialchars($_SERVER['SCRIPT_NAME']);
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Math-Practice</title>
<style>
/* (copy your CSS if you want — abbreviated here for brevity) */
body{font-family:Arial;background:#111;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center}
.container{background:#222;padding:30px;border-radius:12px;width:100%;max-width:650px}
label{display:block;margin-bottom:8px;color:#ccc}
input,select,button{width:100%;padding:12px;margin-bottom:12px;border-radius:8px;border:none;background:#333;color:#fff}
button{background:#007BFF;cursor:pointer}
</style>
</head>
<body>
<div class="container">
  <h1>Math-Practice</h1>
  <label for="url-input">Enter Answer:</label>
  <input id="url-input" placeholder="example.com or https://example.com" />
  <button id="go">Enter</button>
  <p style="color:#bbb;font-size:0.9em">Math Fun!</p>
</div>
<script>
const normalize = (u)=> {
  if (!u) return '';
  u = u.trim();
  if (!u) return '';
  if (!/^https?:\/\//i.test(u)) return 'https://' + u;
  return u;
};
document.getElementById('go').addEventListener('click', () => {
  const url = normalize(document.getElementById('url-input').value);
  if (!url) return alert('Enter a URL');
  // navigate to proxy endpoint
  window.location = '{$self}?u=' + encodeURIComponent(url);
});
document.getElementById('url-input').addEventListener('keydown', (e)=>{ if(e.key==='Enter') document.getElementById('go').click(); });
</script>
</body>
</html>
HTML;
}


function proxy_mode($rawUrl) {
    // Normalize
    $url = trim($rawUrl);
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }

    // Basic URL parse sanity
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['host'])) {
        header('HTTP/1.1 400 Bad Request');
        echo "Bad URL";
        return;
    }

    // Fetch with cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_HEADER, true); // we want headers to extract content-type and final url
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mask3rProxy/1.0 (+https://example.local)');
    curl_setopt($ch, CURLOPT_ENCODING, ''); // accept gzip
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    // Optionally disable SSL verification on dev servers:
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $resp = curl_exec($ch);
    if ($resp === false) {
        header('HTTP/1.1 502 Bad Gateway');
        echo "Error fetching: " . htmlspecialchars(curl_error($ch));
        curl_close($ch);
        return;
    }

    $info = curl_getinfo($ch);
    curl_close($ch);

    // Separate headers and body
    $header_len = $info['header_size'] ?? 0;
    $headers_raw = substr($resp, 0, $header_len);
    $body = substr($resp, $header_len);

    // Determine content-type
    $content_type = '';
    if (!empty($info['content_type'])) $content_type = $info['content_type'];
    else {
        if (preg_match('/Content-Type:\s*([^\r\n;]+)/i', $headers_raw, $m)) $content_type = trim($m[1]);
    }
    $content_type = strtolower($content_type);

    // Remove CSP headers from the remote response so the proxied page can run scripts/styles inline.
    // We'll set our own headers instead.
    header_remove(); // remove PHP default headers
    header('X-Proxy-By: Mask3r');

    // For HTML, rewrite asset URLs so they route through this proxy.
    if (strpos($content_type, 'text/html') !== false || strpos($content_type, 'application/xhtml+xml') !== false) {
        // Determine effective URL (after redirects)
        $effectiveUrl = $info['url'] ?? $url;
        $rewritten = rewrite_html_assets($body, $effectiveUrl);
        header('Content-Type: text/html; charset=utf-8');
        echo $rewritten;
        return;
    }

    // For CSS, rewrite url(...) inside CSS to pass through proxy
    if (strpos($content_type, 'text/css') !== false) {
        $effectiveUrl = $info['url'] ?? $url;
        $body = rewrite_css_urls($body, $effectiveUrl);
        header('Content-Type: text/css; charset=utf-8');
        echo $body;
        return;
    }

    // For images, fonts, JS, etc. just pass content-type and send bytes
    if (!empty($info['content_type'])) header('Content-Type: ' . $info['content_type']);
    else header('Content-Type: application/octet-stream');

    // Cache headers could be proxied; for simplicity we won't forward remote caching headers.

    // Pass body
    echo $body;
    return;
}

/* ----------------- helpers ----------------- */

function absolute_url($base, $rel) {
    // If rel is already absolute
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $rel)) return $rel;
    // Data or javascript or mailto or tel or # anchor
    if (preg_match('#^([#]|data:|javascript:|mailto:|tel:)#i', $rel)) return $rel;

    // Protocol-relative //example.com/path
    if (strpos($rel, '//') === 0) {
        $p = parse_url($base);
        $scheme = isset($p['scheme']) ? $p['scheme'] : 'https';
        return $scheme . ':' . $rel;
    }

    // Parse base
    $b = parse_url($base);
    $scheme = isset($b['scheme']) ? $b['scheme'] : 'https';
    $host = $b['host'] ?? '';
    $port = isset($b['port']) ? ':' . $b['port'] : '';
    $basePath = $b['path'] ?? '/';
    // If rel starts with '/', it's root-relative
    if (substr($rel,0,1) === '/') {
        return $scheme . '://' . $host . $port . $rel;
    }
    // Otherwise remove filename from base path
    $dir = preg_replace('#/[^/]*$#', '/', $basePath);
    $abs = $scheme . '://' . $host . $port . rtrim($dir, '/') . '/' . $rel;

    // Normalize '/../' and '/./'
    $parts = parse_url($abs);
    $path = $parts['path'] ?? '/';
    // resolve .. and .
    $segments = explode('/', $path);
    $resolved = [];
    foreach ($segments as $seg) {
        if ($seg === '' || $seg === '.') {
            if ($seg === '') continue;
            continue;
        }
        if ($seg === '..') {
            array_pop($resolved);
            continue;
        }
        $resolved[] = $seg;
    }
    $path = '/' . implode('/', $resolved);
    $final = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port'])? ':' . $parts['port'] : '') . $path;
    if (!empty($parts['query'])) $final .= '?' . $parts['query'];
    return $final;
}

function proxy_url_for($absolute) {
    // Return URL to this script that will proxy $absolute
    $script = $_SERVER['SCRIPT_NAME']; // e.g. /index.php
    return $script . '?u=' . urlencode($absolute);
}

function rewrite_html_assets($html, $base) {
    // Use DOMDocument to find and rewrite attributes
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    // Ensure UTF-8
    $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    if (!$loaded) {
        // fallback: regex rewrite if DOM fails
        return rewrite_html_by_regex($html, $base);
    }
    $xpath = new DOMXPath($dom);

    // Attributes to rewrite
    $attrTargets = [
        'img' => ['src','srcset','data-src','data-lazy','data-original'],
        'script' => ['src'],
        'link' => ['href'],
        'a' => ['href'],
        'iframe' => ['src'],
        'source' => ['src','srcset'],
        'video' => ['src','poster'],
        'audio' => ['src'],
        'embed' => ['src'],
        'form' => ['action'],
        // any other tag with style attribute containing url(...)
        '*' => ['style']
    ];

    foreach ($attrTargets as $tag => $attrs) {
        $nodes = ($tag === '*') ? $xpath->query('//*[@style]') : $dom->getElementsByTagName($tag);
        if (!$nodes) continue;
        foreach ($nodes as $node) {
            foreach ($attrs as $attr) {
                if (!$node->hasAttribute($attr)) continue;
                $val = $node->getAttribute($attr);
                if (!$val) continue;

                if (in_array($attr, ['srcset'])) {
                    // srcset can contain multiple urls -> rewrite each
                    $parts = array_map('trim', explode(',', $val));
                    $newParts = [];
                    foreach ($parts as $part) {
                        $sub = preg_split('/\s+/', $part);
                        $urlPart = $sub[0];
                        $rest = array_slice($sub,1);
                        $abs = absolute_url($base, $urlPart);
                        $new = proxy_url_for($abs);
                        if (!empty($rest)) $new .= ' ' . implode(' ', $rest);
                        $newParts[] = $new;
                    }
                    $node->setAttribute($attr, implode(', ', $newParts));
                } elseif ($attr === 'style') {
                    // rewrite url(...) inside inline style
                    $newStyle = preg_replace_callback('#url\((["\']?)(.*?)\1\)#i', function($m) use ($base) {
                        $u = trim($m[2]);
                        $abs = absolute_url($base, $u);
                        $p = proxy_url_for($abs);
                        return "url(\"$p\")";
                    }, $val);
                    $node->setAttribute('style', $newStyle);
                } else {
                    // normal single url attribute
                    $u = $val;
                    // skip anchors and javascript: and data:
                    if (preg_match('#^([#]|javascript:|data:|mailto:|tel:)#i', $u)) continue;
                    $abs = absolute_url($base, $u);
                    $node->setAttribute($attr, proxy_url_for($abs));
                }
            }
        }
    }

    // meta refresh rewriting: <meta http-equiv="refresh" content="5;url=/...">
    $metas = $dom->getElementsByTagName('meta');
    foreach ($metas as $m) {
        $httpEquiv = strtolower($m->getAttribute('http-equiv'));
        if ($httpEquiv === 'refresh') {
            $c = $m->getAttribute('content');
            if (preg_match('/url=([^;]+)$/i', $c, $mm)) {
                $urlPart = trim($mm[1], "\"' \t\n\r");
                $abs = absolute_url($base, $urlPart);
                $m->setAttribute('content', preg_replace('/url=([^;]+)$/i', 'url=' . proxy_url_for($abs), $c));
            }
        }
    }

    // Add a <base> element so relative links in the document resolve visually — but since we already rewrote urls
    // it's optional. We'll add it to help the page resolve relative anchors and such.
    $bases = $dom->getElementsByTagName('base');
    if ($bases->length === 0) {
        $head = $dom->getElementsByTagName('head')->item(0);
        if ($head) {
            $baseEl = $dom->createElement('base');
            $baseEl->setAttribute('href', $base);
            $head->insertBefore($baseEl, $head->firstChild);
        }
    }

    // Serialize back
    $htmlOut = $dom->saveHTML();

    // Finally, rewrite any inline <style> blocks' url(...) occurrences (DOM may not rewrite content of style tags)
    $htmlOut = preg_replace_callback('#<style\b[^>]*>(.*?)</style>#is', function($m) use ($base) {
        $content = $m[1];
        $rew = preg_replace_callback('#url\((["\']?)(.*?)\1\)#i', function($mm) use ($base) {
            $u = trim($mm[2]);
            if (preg_match('#^([#]|data:|javascript:|mailto:|tel:)#i', $u)) return $mm[0];
            $abs = absolute_url($base, $u);
            return 'url("' . proxy_url_for($abs) . '")';
        }, $content);
        return str_replace($content, $rew, $m[0]);
    }, $htmlOut);

    return $htmlOut;
}

function rewrite_css_urls($css, $base) {
    // rewrite url(...) in CSS to route through proxy
    $out = preg_replace_callback('#url\((["\']?)(.*?)\1\)#i', function($m) use ($base) {
        $u = trim($m[2]);
        if (preg_match('#^([#]|data:|javascript:|mailto:|tel:)#i', $u)) return $m[0];
        $abs = absolute_url($base, $u);
        return 'url("' . proxy_url_for($abs) . '")';
    }, $css);
    return $out;
}

function rewrite_html_by_regex($html, $base) {
    // Fallback crude regex-based rewriting (used only when DOM parsing fails)
    $patterns = [
        // src, href, poster, action
        '/(src|href|poster|action)\s*=\s*([\'"])(.*?)\2/i' => function($m) use ($base) {
            $attr = $m[1];
            $quote = $m[2];
            $val = $m[3];
            if (preg_match('#^([#]|javascript:|data:|mailto:|tel:)#i', $val)) return $m[0];
            $abs = absolute_url($base, $val);
            return $attr . '=' . $quote . proxy_url_for($abs) . $quote;
        },
        // srcset
        '/srcset\s*=\s*([\'"])(.*?)\1/i' => function($m) use ($base) {
            $quote = $m[1];
            $val = $m[2];
            $parts = array_map('trim', explode(',', $val));
            $new = [];
            foreach ($parts as $p) {
                $sub = preg_split('/\s+/', $p);
                $urlPart = $sub[0];
                $rest = array_slice($sub,1);
                $abs = absolute_url($base, $urlPart);
                $new[] = proxy_url_for($abs) . (empty($rest) ? '' : ' ' . implode(' ', $rest));
            }
            return 'srcset=' . $quote . implode(', ', $new) . $quote;
        },
        // inline style url(...)
        '/style\s*=\s*([\'"])(.*?)\1/i' => function($m) use ($base) {
            $quote = $m[1];
            $val = $m[2];
            $new = preg_replace_callback('#url\((["\']?)(.*?)\1\)#i', function($mm) use ($base) {
                $u = trim($mm[2]);
                if (preg_match('#^([#]|data:|javascript:|mailto:|tel:)#i', $u)) return $mm[0];
                $abs = absolute_url($base, $u);
                return 'url("' . proxy_url_for($abs) . '")';
            }, $val);
            return 'style=' . $quote . $new . $quote;
        }
    ];
    foreach ($patterns as $pat => $cb) {
        $html = preg_replace_callback($pat, $cb, $html);
    }
    // meta refresh
    $html = preg_replace_callback('/<meta[^>]+http-equiv=["\']?refresh["\']?[^>]*>/i', function($m) use ($base) {
        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $m[0], $mm)) {
            $c = $mm[1];
            if (preg_match('/url=([^;]+)/i', $c, $u)) {
                $abs = absolute_url($base, trim($u[1], "\"' "));
                $c2 = preg_replace('/url=([^;]+)/i', 'url=' . proxy_url_for($abs), $c);
                return str_replace($mm[0], 'content="' . htmlspecialchars($c2) . '"', $m[0]);
            }
        }
        return $m[0];
    }, $html);

    return $html;
}
?>
