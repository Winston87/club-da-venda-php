(() => {
  const historyAction = new Set(['archive', 'delete', 'restore', 'duplicate', 'cancel', 'queue']);

  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.getAttribute('action') !== '/admin/campanhas') return;
    const action = form.querySelector('input[name="action"]')?.value;
    const isNewCampaign = action === 'create' && form.matches('[data-campaign-form]');
    if (!historyAction.has(action) && !isNewCampaign) return;

    event.preventDefault();
    event.stopImmediatePropagation();

    const currentQuery = new URLSearchParams(window.location.search);
    if (currentQuery.has('arquivadas')) {
      [['history_archived', '1'], ['history_month', currentQuery.get('mes') || '']].forEach(([name, value]) => {
        if (form.querySelector(`[name="${name}"]`)) return;
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = name; input.value = value;
        form.appendChild(input);
      });
    }

    const button = event.submitter instanceof HTMLButtonElement ? event.submitter : form.querySelector('button[type="submit"]');
    const originalLabel = button?.textContent || '';
    if (button) { button.disabled = true; button.textContent = 'AGUARDE…'; }

    try {
      const response = await fetch('/admin/campanhas', {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      });
      if (!response.ok) throw new Error();
      if (isNewCampaign) {
        const documentFromResponse = new DOMParser().parseFromString(await response.text(), 'text/html');
        const nextHistory = documentFromResponse.querySelector('#historico');
        const currentHistory = document.querySelector('#historico');
        if (!nextHistory || !currentHistory) throw new Error();
        currentHistory.replaceWith(nextHistory);
        nextHistory.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (button) button.textContent = '✓ CAMPANHA SALVA';
      } else {
        const result = await response.json().catch(() => null);
        if (result?.ok === false) throw new Error();
        if (['archive', 'delete', 'restore'].includes(action)) form.closest('.campaignHistoryTable > article')?.remove();
        else if (button) button.textContent = 'ATUALIZADA';
      }
    } catch {
      if (button) { button.disabled = false; button.textContent = originalLabel || 'TENTAR NOVAMENTE'; }
    }
  }, true);
})();
