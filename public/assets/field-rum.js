(() => {
  'use strict';

  const script = document.currentScript;
  if (!script) return;

  const routeGroup = String(script.dataset.routeGroup || '').trim();
  const buildId = String(script.dataset.buildId || '').trim();
  const sampleRate = Number(script.dataset.sampleRate || '1');
  const allowedRoutes = new Set(['home', 'registration', 'admin_payments']);

  if (!allowedRoutes.has(routeGroup) || buildId !== 'pilot-c-field-v1') return;
  if (!Number.isFinite(sampleRate) || sampleRate < 0 || sampleRate > 1) return;
  if (Math.random() >= sampleRate) return;
  if (!('PerformanceObserver' in window)) return;

  const formFactor = window.innerWidth < 768 ? 'mobile' : 'desktop';
  const endpoint = '/api/rum-web-vitals.php';
  const sent = new Set();

  function validMetricValue(metric, value) {
    if (!Number.isFinite(value) || value < 0) return false;
    if (metric === 'CLS') return value <= 10;
    return value <= 120000;
  }

  function send(metric, value) {
    if (sent.has(metric) || !validMetricValue(metric, value)) return;
    sent.add(metric);

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

  // CLS: official-style session windows (max 5 s, gaps below 1 s), excluding recent input shifts.
  let clsMax = 0;
  let clsSessionValue = 0;
  let clsSessionStart = 0;
  let clsSessionEnd = 0;
  let clsObserver = null;
  try {
    clsObserver = new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) {
        if (entry.hadRecentInput) continue;
        const value = Number(entry.value);
        if (!Number.isFinite(value) || value < 0) continue;
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
    });
    clsObserver.observe({type: 'layout-shift', buffered: true});
  } catch {
    clsObserver = null;
  }

  // INP: retain the 10 longest interactions and use the same p98 candidate rule
  // as the web-vitals algorithm: candidate index floor(interactionCount / 50).
  const interactions = new Map();
  let minInteractionId = Infinity;
  let maxInteractionId = 0;
  let firstInputDuration = null;
  let inpObserver = null;

  function interactionCountEstimate() {
    if ('interactionCount' in performance) {
      const nativeCount = Number(performance.interactionCount);
      if (Number.isFinite(nativeCount) && nativeCount >= 0) return nativeCount;
    }
    if (!maxInteractionId || !Number.isFinite(minInteractionId)) return 0;
    return Math.max(1, (maxInteractionId - minInteractionId) / 7 + 1);
  }

  function processEventEntry(entry) {
    const duration = Number(entry.duration);
    if (!Number.isFinite(duration) || duration < 0) return;

    if (entry.entryType === 'first-input') {
      firstInputDuration = firstInputDuration === null
        ? duration
        : Math.max(firstInputDuration, duration);
    }

    const id = Number(entry.interactionId || 0);
    if (!id) return;

    minInteractionId = Math.min(minInteractionId, id);
    maxInteractionId = Math.max(maxInteractionId, id);
    const previous = interactions.get(id) || 0;
    if (duration > previous) interactions.set(id, duration);

    if (interactions.size > 20) {
      const keep = [...interactions.entries()]
        .sort((a, b) => b[1] - a[1])
        .slice(0, 10);
      interactions.clear();
      for (const [keepId, keepDuration] of keep) interactions.set(keepId, keepDuration);
    }
  }

  try {
    inpObserver = new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) processEventEntry(entry);
    });
    inpObserver.observe({type: 'event', buffered: true, durationThreshold: 16});
    try {
      inpObserver.observe({type: 'first-input', buffered: true});
    } catch {}
  } catch {
    inpObserver = null;
  }

  function finalizeInp() {
    if (inpObserver) {
      try {
        for (const entry of inpObserver.takeRecords()) processEventEntry(entry);
        inpObserver.disconnect();
      } catch {}
      inpObserver = null;
    }

    const longest = [...interactions.values()].sort((a, b) => b - a).slice(0, 10);
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
        const records = clsObserver.takeRecords();
        for (const entry of records) {
          if (entry.hadRecentInput) continue;
          const value = Number(entry.value);
          if (!Number.isFinite(value) || value < 0) continue;
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
        clsObserver.disconnect();
      } catch {}
      clsObserver = null;
    }
    send('CLS', clsMax);
    finalizeInp();
  }

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') finalize();
  }, {capture: true});
  addEventListener('pagehide', finalize, {capture: true});
})();
