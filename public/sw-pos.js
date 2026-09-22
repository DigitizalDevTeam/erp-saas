/**
 * POS PWA Service Worker – offline-first for POS page and assets.
 */
const CACHE_NAME = 'pos-pwa-v3';

function isPosPageUrl(url) {
  var path = url.pathname.replace(/\/+$/, '') || '/';
  return path === '/pos'
    || path === '/pos-restaurant'
    || path.slice(-4) === '/pos'
    || path.slice(-15) === '/pos-restaurant';
}

function shouldNotCache(url) {
  const path = url.pathname;
  return path.indexOf('/pos/sync') !== -1 ||
    path.indexOf('/pos/data/store') !== -1 ||
    path.indexOf('/search-products') !== -1 ||
    path.indexOf('/product-categories') !== -1 ||
    path.indexOf('/add-to-cart') !== -1 ||
    path.indexOf('/update-cart') !== -1 ||
    path.indexOf('/remove-from-cart') !== -1 ||
    path.indexOf('/cartdiscount') !== -1 ||
    path.indexOf('/warehouse-empty-cart') !== -1 ||
    path.indexOf('/pos/warehouses-by-branch') !== -1 ||
    path.indexOf('/pos-table/tables-by-branch') !== -1;
}

function canCacheRequest(request, url) {
  return (url.protocol === 'http:' || url.protocol === 'https:')
    && request.method === 'GET';
}

function putInCache(request, response) {
  if (!canCacheRequest(request, new URL(request.url))) {
    return Promise.resolve();
  }
  return caches.open(CACHE_NAME).then(function (cache) {
    return cache.put(request, response).catch(function () {});
  }).catch(function () {});
}

// Static assets to cache on install (relative to origin)
const STATIC_URLS = [
  '/css/pos.css',
  '/css/site.css',
  '/css/custom.css',
  '/js/jquery.min.js',
  '/assets/fonts/tabler-icons.min.css',
  '/assets/css/style.css',
  '/assets/js/plugins/bootstrap.min.js'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(function (cache) {
        return cache.addAll(STATIC_URLS.map(function (u) {
          return new Request(self.location.origin + u, { cache: 'reload' });
        })).catch(function () {});
      })
      .then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (key) {
        if (key !== CACHE_NAME) return caches.delete(key);
      }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  if (event.request.method !== 'GET') return;

  var url;
  try {
    url = new URL(event.request.url);
  } catch (e) {
    return;
  }

  // Never intercept extension / non-http schemes (causes Cache.put failures + can break pages)
  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    return;
  }

  if (shouldNotCache(url)) return;

  // POS pages: network first, then cache; when offline serve from cache
  if (isPosPageUrl(url)) {
    event.respondWith(
      fetch(event.request)
        .then(function (response) {
          if (response && response.status === 200 && response.type === 'basic') {
            putInCache(event.request, response.clone());
          }
          return response;
        })
        .catch(function () {
          return caches.match(event.request).then(function (cached) {
            return cached
              || caches.match(self.location.origin + '/pos-restaurant')
              || caches.match(self.location.origin + '/pos');
          });
        })
    );
    return;
  }

  // Static assets: network first, cache fallback
  if (url.pathname.match(/\.(css|js|woff2?|ttf|eot|png|jpg|jpeg|gif|svg|ico)$/) ||
      url.pathname.indexOf('/css/') === 0 || url.pathname.indexOf('/assets/') === 0) {
    event.respondWith(
      fetch(event.request).then(function (response) {
        if (response && response.status === 200 && response.type === 'basic') {
          putInCache(event.request, response.clone());
        }
        return response;
      }).catch(function () {
        return caches.match(event.request);
      })
    );
  }
});
