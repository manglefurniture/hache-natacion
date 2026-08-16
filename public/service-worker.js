const CACHE_NAME='hache-pwa-v2';
const STATIC_ASSETS=['/offline.html','/manifest.webmanifest','/assets/icons/hache-icon.svg'];

self.addEventListener('install',event=>{
  event.waitUntil(caches.open(CACHE_NAME).then(cache=>cache.addAll(STATIC_ASSETS)));
  self.skipWaiting();
});

self.addEventListener('activate',event=>{
  event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key!==CACHE_NAME).map(key=>caches.delete(key)))));
  self.clients.claim();
});

self.addEventListener('fetch',event=>{
  const request=event.request;
  if(request.method!=='GET') return;
  const url=new URL(request.url);
  if(url.origin!==self.location.origin) return;
  // Nunca cachear API, sesiones ni datos administrativos dinámicos.
  if(url.pathname.startsWith('/api/')) return;

  if(request.mode==='navigate'){
    event.respondWith(fetch(request,{cache:'no-store'}).catch(()=>caches.match('/offline.html')));
    return;
  }

  if(url.pathname.startsWith('/assets/') || url.pathname==='/manifest.webmanifest'){
    event.respondWith(
      fetch(request).then(response=>{
        if(response.ok){const copy=response.clone();caches.open(CACHE_NAME).then(cache=>cache.put(request,copy));}
        return response;
      }).catch(()=>caches.match(request))
    );
  }
});
