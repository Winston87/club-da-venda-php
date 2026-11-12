(() => {
  const close = (modal) => { modal.remove(); document.body.style.overflow = ''; };
  document.addEventListener('click', async (event) => {
    const link = event.target.closest('[data-customer-history]');
    if (!link) return;
    event.preventDefault();
    if (document.querySelector('.customerModal')) return;
    try {
      const response = await fetch(link.dataset.historyUrl, { cache: 'no-store' });
      if (!response.ok) throw new Error();
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      const modal = page.querySelector('.customerModal');
      if (!modal) throw new Error();
      document.body.append(modal);
      document.body.style.overflow = 'hidden';
      modal.querySelectorAll('a[href="/admin/clientes"], .couponModalShade').forEach((item) => item.addEventListener('click', (closeEvent) => { closeEvent.preventDefault(); close(modal); }));
    } catch { window.alert('Não foi possível carregar o histórico desta cliente. Tente novamente.'); }
  }, true);
})();
