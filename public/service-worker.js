const CACHE_NAME='hache-pwa-v6';
const STATIC_ASSETS=['/offline.html','/manifest.webmanifest','/assets/icons/hache-icon.svg'];

self.addEventListener('install',event=>{
  event.waitUntil(caches.open(CACHE_NAME).then(cache=>cache.addAll(STATIC_ASSETS)));
  self.skipWaiting();
});

self.addEventListener('activate',event=>{
  event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key!==CACHE_NAME).map(key=>caches.delete(key)))));
  self.clients.claim();
});

self.addEventListener('push',event=>{
  let data={};
  try{data=event.data?event.data.json():{};}catch(_){data={title:'Hache Natación',body:event.data?event.data.text():'Nueva notificación',url:'/dashboard.php'};}
  const title=data.title||'Hache Natación';
  const options={
    body:data.body||'Tienes una nueva notificación',
    icon:'/assets/icons/hache-icon.svg',
    badge:'/assets/icons/hache-icon.svg',
    tag:data.tag||'hache-notification',
    renotify:true,
    data:{url:data.url||'/dashboard.php',...(data.data||{})}
  };
  event.waitUntil(self.registration.showNotification(title,options));
});

self.addEventListener('notificationclick',event=>{
  event.notification.close();
  const target=new URL((event.notification.data&&event.notification.data.url)||'/dashboard.php',self.location.origin).href;
  event.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(list=>{
    for(const client of list){if('focus'in client){client.navigate(target);return client.focus();}}
    return clients.openWindow?clients.openWindow(target):undefined;
  }));
});

self.addEventListener('fetch',event=>{
  const request=event.request;if(request.method!=='GET') return;const url=new URL(request.url);if(url.origin!==self.location.origin) return;if(url.pathname.startsWith('/api/')) return;
  if(request.mode==='navigate'){event.respondWith(fetch(request,{cache:'no-store'}).catch(()=>caches.match('/offline.html')));return;}
  if(url.pathname.startsWith('/assets/')&&!url.pathname.startsWith('/assets/icons/')){event.respondWith(fetch(request,{cache:'no-store'}).catch(()=>caches.match(request)));return;}
  if(url.pathname.startsWith('/assets/icons/')||url.pathname==='/manifest.webmanifest'){event.respondWith(caches.match(request).then(cached=>cached||fetch(request).then(response=>{if(response.ok){const copy=response.clone();caches.open(CACHE_NAME).then(cache=>cache.put(request,copy));}return response;})));}
});