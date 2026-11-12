(() => {
  const selector = '#dashboard-data[data-auto-refresh], #orders-data[data-auto-refresh], #customers-data[data-auto-refresh]';
  let busy = false;
  const refresh = async () => {
    const current = document.querySelector(selector);
    if (!current || busy || document.visibilityState !== 'visible') return;
    busy = true;
    try {
      const response = await fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
      if (!response.ok) return;
      const documentCopy = new DOMParser().parseFromString(await response.text(), 'text/html');
      const next = documentCopy.querySelector(`#${current.id}`);
      if (next) current.replaceWith(next);
    } catch { /* próxima atualização tenta novamente */ } finally { busy = false; }
  };
  const current = document.querySelector(selector);
  if (current) window.setInterval(refresh, Number(current.dataset.autoRefresh || 15000));
})();
