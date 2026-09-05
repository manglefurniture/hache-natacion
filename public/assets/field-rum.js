(() => {
  'use strict';

  const script = document.currentScript;
  if (!script) return;

  const routeGroup = String(script.dataset.routeGroup || '').trim();
  const sampleRate = Number(script.dataset.sampleRate || '1');
  const allowedRoutes = new Set(['home', 'registration', 'admin_payments']);

  if (!allowedRoutes.has(routeGroup)) return;
  if (!Number.isFinite(sampleRate) || sampleRate < 0 || sampleRate > 1) return;
  if (Math.random() >= sampleRate) return;
  if (!('PerformanceObserver' in window)) return;

  const formFactor = window.innerWidth < 768 ? 'mobile' : 'desktop';
  const endpoint = '/api/rum-web-vitals.php';
  const sent = new Set();
  const buildIdPromise = fetch('/api/rum-build.php', {
    method: 'GET',
    mode: 'same-origin',
    credentials: 'omit',
    cache: 'no-store',
    redirect: 'error',
    referrerPolicy: 'no-referrer',
  }).then(async (response) => {
    if (!response.ok) return null;
    const data = await response.json();
    const buildId = String(data?.build_id || '');
    return /^git-[a-f0-9]{12}$/.test(buildId) ? buildId : null;
  }).catch(() => null);

  function validMetricValue(metric, value) {
    if (!Number.isFinite(value) || value < 0) return false;
    if (metric === 'CLS') return value <= 10;
    return value <= 120000;
  }

  function send(metric, value) {
    if (sent.has(metric) || !validMetricValue(metric, value)) return;
    sent.add(metric);

    void buildIdPromise.then((buildId) => {
      if (!buildId) return;
      const payload = {
        schema_version: 1,
        metric,
        value,
        route_group: routeGroup,
        build_id: buildId,
        form_factor: formFactor,
      };

      try {
        void fetch(endpoint, {
          method: 'POST',
          mode: 'same-origin',
          credentials: 'omit',
          cache: 'no-store',
          redirect: 'error',
          referrerPolicy: 'no-referrer',
          keepalive: true,
          headers: {'content-type': 'application/json'},
          body: JSON.stringify(payload),
        }).catch(() => {});
      } catch {
        // RUM must never affect the page CUF.
      }
    }).catch(() => {});
  }

  // LCP: keep the last buffered candidate and freeze it at first interaction or page hide.
  let lcpValue = null;
  let lcpObserver = null;
  try {
    lcpObserver = new PerformanceObserver((list) => {
      const entries = list.getEntries();
      const last = entries.at(-1);
      if (last && Number.isFinite(last.startTime)) lcpValue = last.startTime;
    });
    lcpObserver.observe({type: 'largest-contentful-paint', buffered: true});
  } catch {
    lcpObserver = null;
  }

  function finalizeLcp() {
    if (lcpObserver) {
      try {
        const records = lcpObserver.takeRecords();
        const last = records.at(-1);
        if (last && Number.isFinite(last.startTime)) lcpValue = last.startTime;
        lcpObserver.disconnect();
      } catch {}
      lcpObserver = null;
    }
    if (lcpValue !== null) send('LCP', lcpValue);
  }

  addEventListener('pointerdown', finalizeLcp, {once: true, capture: true, passive: true});
  addEventListener('keydown', finalizeLcp, {once: true, capture: true});

  // CLS: session windows (max 5 s, gaps below 1 s), excluding shifts after recent input.
  let clsMax = 0;
  let clsSessionValue = 0;
  let clsSessionStart = 0;
  let clsSessionEnd = 0;
  let clsObserver = null;
  let clsSupported = false;

  function processClsEntry(entry) {
    if (entry.hadRecentInput) return;
    const value = Number(entry.value);
    if (!Number.isFinite(value) || value < 0) return;
    const start = Number(entry.startTime) || 0;

    if (
      clsSessionValue > 0
      && start - clsSessionEnd < 1000
      && start - clsSessionStart < 5000
    ) {
      clsSessionValue += value;
      clsSessionEnd = start;
    } else {
      clsSessionValue = value;
      clsSessionStart = start;
      clsSessionEnd = start;
    }
    clsMax = Math.max(clsMax, clsSessionValue);
  }

  try {
    clsObserver = new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) processClsEntry(entry);
    });
    clsObserver.observe({type: 'layout-shift', buffered: true});
    clsSupported = true;
  } catch {
    clsObserver = null;
    clsSupported = false;
  }

  // INP: only emit on browsers with Event Timing interaction IDs. Keep at most
  // the 10 longest interactions and use the same p98 candidate rule as web-vitals.
  const inpSupported = Boolean(
    window.PerformanceEventTiming
    && 'interactionId' in PerformanceEventTiming.prototype,
  );
  const interactions = new Map();
  let minInteractionId = Infinity;
  let maxInteractionId = 0;
  let firstInputDuration = null;
  let eventObserver = null;
  let firstInputObserver = null;

  function interactionCountEstimate() {
    if ('interactionCount' in performance) {
      const nativeCount = Number(performance.interactionCount);
      if (Number.isFinite(nativeCount) && nativeCount >= 0) return nativeCount;
    }
    if (!maxInteractionId || !Number.isFinite(minInteractionId)) return 0;
    return Math.max(1, (maxInteractionId - minInteractionId) / 7 + 1);
  }

  function addInteractionCandidate(id, duration) {
    if (interactions.has(id)) {
      if (duration > interactions.get(id)) interactions.set(id, duration);
      return;
    }
    if (interactions.size < 10) {
      interactions.set(id, duration);
      return;
    }

    let minId = null;
    let minDuration = Infinity;
    for (const [candidateId, candidateDuration] of interactions) {
      if (candidateDuration < minDuration) {
        minDuration = candidateDuration;
        minId = candidateId;
      }
    }
    if (duration > minDuration && minId !== null) {
      interactions.delete(minId);
      interactions.set(id, duration);
    }
  }

  function processEventEntry(entry) {
    const duration = Number(entry.duration);
    if (!Number.isFinite(duration) || duration < 0) return;

    const id = Number(entry.interactionId || 0);
    if (!id) return;
    minInteractionId = Math.min(minInteractionId, id);
    maxInteractionId = Math.max(maxInteractionId, id);
    addInteractionCandidate(id, duration);
  }

  function processFirstInputEntry(entry) {
    const duration = Number(entry.duration);
    if (!Number.isFinite(duration) || duration < 0) return;
    firstInputDuration = firstInputDuration === null
      ? duration
      : Math.max(firstInputDuration, duration);
    processEventEntry(entry);
  }

  if (inpSupported) {
    try {
      eventObserver = new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) processEventEntry(entry);
      });
      eventObserver.observe({type: 'event', buffered: true, durationThreshold: 40});
    } catch {
      eventObserver = null;
    }

    try {
      firstInputObserver = new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) processFirstInputEntry(entry);
      });
      firstInputObserver.observe({type: 'first-input', buffered: true});
    } catch {
      firstInputObserver = null;
    }
  }

  function finalizeInp() {
    if (!inpSupported) return;

    if (eventObserver) {
      try {
        for (const entry of eventObserver.takeRecords()) processEventEntry(entry);
        eventObserver.disconnect();
      } catch {}
      eventObserver = null;
    }
    if (firstInputObserver) {
      try {
        for (const entry of firstInputObserver.takeRecords()) processFirstInputEntry(entry);
        firstInputObserver.disconnect();
      } catch {}
      firstInputObserver = null;
    }

    const longest = [...interactions.values()].sort((a, b) => b - a);
    if (longest.length) {
      const index = Math.min(longest.length - 1, Math.floor(interactionCountEstimate() / 50));
      send('INP', longest[index]);
    } else if (firstInputDuration !== null) {
      send('INP', firstInputDuration);
    }
  }

  function finalize() {
    finalizeLcp();
    if (clsObserver) {
      try {
        for (const entry of clsObserver.takeRecords()) processClsEntry(entry);
        clsObserver.disconnect();
      } catch {}
      clsObserver = null;
    }
    if (clsSupported) send('CLS', clsMax);
    finalizeInp();
  }

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') finalize();
  }, {capture: true});
  addEventListener('pagehide', finalize, {capture: true});
})();
