(() => {
  const modal = document.querySelector('[data-coupon-modal]');
  const open = document.querySelector('[data-coupon-open]');
  if (!modal || !open) return;
  const close = () => { modal.hidden = true; document.body.style.overflow = ''; };
  open.addEventListener('click', () => { modal.hidden = false; document.body.style.overflow = 'hidden'; });
  modal.querySelectorAll('[data-coupon-close]').forEach((button) => button.addEventListener('click', close));
  modal.querySelectorAll('[data-copy-coupon]').forEach((button) => button.addEventListener('click', async () => { const code = button.dataset.copyCoupon; try { await navigator.clipboard.writeText(code); button.textContent = '✓ COPIADO'; } catch { window.prompt('Copie o cupom:', code); } }));
})();
