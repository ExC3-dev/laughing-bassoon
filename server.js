// server.js
const express = require('express');
const fetch = require('node-fetch'); // use node-fetch@2 for CommonJS
const cheerio = require('cheerio');

const app = express();
app.use(express.static('public'));

function rewriteCssUrls(css, baseUrl) {
  return css.replace(/url\(([^)]+)\)/g, (m, g1) => {
    let urlStr = g1.trim().replace(/^['"]|['"]$/g, '');
    try {
      const abs = new URL(urlStr, baseUrl).href;
      return `url('/proxy?url=${encodeURIComponent(abs)}')`;
    } catch (e) {
      return `url(${g1})`;
    }
  });
}

function safeSetHeader(res, name, value) {
  // avoid Content-Length override etc
  const blocked = [
    'content-length',
    'content-security-policy',
    'x-frame-options',
    'x-xss-protection',
    'strict-transport-security',
    'referrer-policy'
  ];
  if (!blocked.includes(name.toLowerCase())) res.set(name, value);
}

app.get('/proxy', async (req, res) => {
  const target = req.query.url;
  if (!target) return res.status(400).send('Missing url parameter.');

  let targetUrl;
  try {
    targetUrl = new URL(target);
  } catch (e) {
    return res.status(400).send('Invalid URL.');
  }

  try {
    const upstream = await fetch(targetUrl.href, {
      method: 'GET',
      headers: {
        'user-agent': req.headers['user-agent'] || 'NodeProxy/1.0',
        // You may forward cookies or auth if needed:
        'accept': req.headers['accept'] || '*/*',
      },
      redirect: 'manual' // handle redirects ourselves
    });

    // Handle redirects (3xx)
    if (upstream.status >= 300 && upstream.status < 400) {
      const loc = upstream.headers.get('location');
      if (loc) {
        const abs = new URL(loc, targetUrl).href;
        const proxied = '/proxy?url=' + encodeURIComponent(abs);
        return res.status(upstream.status).set('location', proxied).send();
      }
    }

    // Copy headers except ones we want to remove/override
    upstream.headers.forEach((v, k) => safeSetHeader(res, k, v));
    // Allow cross origin from browser
    res.set('Access-Control-Allow-Origin', '*');

    const contentType = (upstream.headers.get('content-type') || '').toLowerCase();

    const buffer = await upstream.buffer();

    if (contentType.includes('text/html')) {
      const html = buffer.toString('utf8');
      const $ = cheerio.load(html, { decodeEntities: false });

      // tags and their attributes to rewrite
      const rewriteMap = {
        'a': 'href',
        'link': 'href',
        'script': 'src',
        'img': 'src',
        'iframe': 'src',
        'form': 'action',
        'source': 'src',
        'video': 'src',
        'audio': 'src',
        'embed': 'src',
        'object': 'data'
      };

      for (const tag in rewriteMap) {
        const attr = rewriteMap[tag];
        $(tag).each((i, el) => {
          const $el = $(el);
          const val = $el.attr(attr);
          if (!val) return;
          try {
            const abs = new URL(val, targetUrl).href;
            $el.attr(attr, '/proxy?url=' + encodeURIComponent(abs));
          } catch (err) {
            // leave it if can't parse
          }
        });
      }

      // img srcset
      $('img').each((i, el) => {
        const ss = $(el).attr('srcset');
        if (!ss) return;
        const newss = ss.split(',').map(part => {
          const trimmed = part.trim();
          const pieces = trimmed.split(/\s+/);
          const urlPart = pieces[0];
          const rest = pieces.slice(1).join(' ');
          try {
            const abs = new URL(urlPart, targetUrl).href;
            return '/proxy?url=' + encodeURIComponent(abs) + (rest ? ' ' + rest : '');
          } catch (e) {
            return part;
          }
        }).join(', ');
        $(el).attr('srcset', newss);
      });

      // Rewrite URLs inside inline <style> tags
      $('style').each((i, el) => {
        const css = $(el).html() || '';
        $(el).html(rewriteCssUrls(css, targetUrl.href));
      });

      // Rewrite inline style attributes
      $('[style]').each((i, el) => {
        const st = $(el).attr('style') || '';
        $(el).attr('style', rewriteCssUrls(st, targetUrl.href));
      });

      // If there's a base tag, remove or rewrite it (we've rewritten links so removing is safer)
      $('base').remove();

      // Optionally inject a small script to rewrite dynamically added links (not necessary for many cases)
      // Convert final HTML back to buffer
      const final = $.html();
      res.set('content-type', 'text/html; charset=utf-8');
      return res.send(Buffer.from(final, 'utf8'));
    }

    // If CSS, rewrite url() occurrences to proxy too
    if (contentType.includes('text/css')) {
      const css = buffer.toString('utf8');
      const rewritten = rewriteCssUrls(css, targetUrl.href);
      res.set('content-type', contentType);
      return res.send(Buffer.from(rewritten, 'utf8'));
    }

    // For binary assets and everything else, just pass along
    res.set('content-type', contentType || 'application/octet-stream');
    return res.send(buffer);

  } catch (err) {
    console.error('proxy error', err);
    return res.status(500).send('Proxy error: ' + err.message);
  }
});

const port = process.env.PORT || 3000;
app.listen(port, () => console.log(`Proxy server listening on http://localhost:${port}`));
