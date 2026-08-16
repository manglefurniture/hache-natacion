(function(){
    if (window.location.pathname !== '/sesiones.php') return;
    const originalFetch = window.fetch.bind(window);
    window.fetch = function(input, init){
        let url = typeof input === 'string' ? input : (input && input.url) || '';
        if (url.startsWith('/api/sesiones.php')) {
            url = url.replace('/api/sesiones.php', '/api/sesiones-laborables.php');
            input = url;
        }
        return originalFetch(input, init);
    };
})();
