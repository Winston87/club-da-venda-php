(() => {
  const wireDashboardFilter = (root = document) => {
    const select = root.querySelector('[data-dashboard-range]');
    if (!select || select.dataset.wired) return;
    select.dataset.wired = 'true';
    select.addEventListener('change', async () => {
      const url = new URL(window.location.href);
      url.searchParams.set('range', select.value);
      try {
        const response = await fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) throw new Error();
        const next = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('#dashboard-data');
        const current = document.querySelector('#dashboard-data');
        if (!next || !current) throw new Error();
        current.replaceWith(next);
        window.history.replaceState({}, '', url);
        wireDashboardFilter(next);
      } catch {
        window.location.href = url.toString();
      }
    });
  };
  wireDashboardFilter();
})();
