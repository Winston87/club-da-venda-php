(() => {
  const form = document.querySelector('.settingsEditor');
  const phone = document.querySelector('[data-settings-preview]');
  if (!form || !phone) return;
  const map = { name: 'store', hero_eyebrow: 'eyebrow', hero_title: 'title', hero_highlight: 'highlight', hero_description: 'description', cta_label: 'cta' };
  const apply = () => {
    Object.entries(map).forEach(([inputName, target]) => { const input = form.querySelector(`[name="${inputName}"]`); const element = phone.querySelector(`[data-preview-${target}]`); if (input && element) element.textContent = input.value || (inputName === 'name' ? 'Sua loja' : ''); });
    const secondary = form.querySelector('[name="secondary_color"]')?.value || '#15100B'; phone.style.backgroundColor = secondary;
    [['hero_eyebrow', 'hero_eyebrow_color'], ['hero_title', 'hero_title_color'], ['hero_highlight', 'hero_highlight_color'], ['hero_description', 'hero_description_color']].forEach(([target, color]) => { const element = phone.querySelector(`[data-preview-${map[target]}]`); const input = form.querySelector(`[name="${color}"]`); if (element && input) element.style.color = input.value; });
    const store = phone.querySelector('[data-preview-store]'); const storeColor = form.querySelector('[name="store_name_color"]'); if (store && storeColor) store.style.color = storeColor.value;
    const hero = phone.querySelector('[data-preview-hero]'); const banner = form.querySelector('[name="banner_url"]'); if (hero && banner?.value) hero.style.backgroundImage = `linear-gradient(90deg, ${secondary}dd, transparent), url("${banner.value}")`;
    const logo = phone.querySelector('[data-preview-logo]'); const logoInput = form.querySelector('[name="logo_url"]'); if (logo && logoInput?.value) { logo.src = logoInput.value; logo.hidden = false; }
  };
  form.addEventListener('input', apply); form.addEventListener('change', apply); apply();
})();
