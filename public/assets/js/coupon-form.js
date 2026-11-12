(() => {
  const form = document.querySelector('[data-coupon-form]');
  if (!form) return;
  const code = form.querySelector('[data-coupon-code]');
  const campaign = form.querySelector('[data-campaign-field]');
  const update = () => {
    code.value = code.value.toUpperCase().replace(/[^A-Z0-9_-]/g, '');
    const isCampaign = code.value.startsWith('CAP-');
    campaign.hidden = !isCampaign;
    campaign.querySelector('select').required = isCampaign;
  };
  code.addEventListener('input', update); update();
})();
