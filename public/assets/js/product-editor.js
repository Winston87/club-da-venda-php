(() => {
  const editor = document.querySelector('[data-product-editor]');
  if (!editor) return;
  const rows = editor.querySelector('[data-variation-rows]');
  const grid = editor.querySelector('[data-stock-grid]');
  const basic = editor.querySelector('.basicStockField');
  let previous = {};
  try { previous = JSON.parse(grid.dataset.stock || '{}'); } catch { previous = {}; }

  const presets = {
    regular: [['Tamanho', 'P, M, G, GG'], ['Cor', 'Preto, Branco']],
    plus: [['Tamanho', 'G1, G2, G3, G4'], ['Cor', 'Preto, Branco']],
    unique: [['Tamanho', 'Único'], ['Cor', 'Preto, Branco']],
    footwear: [['Numeração', '34, 35, 36, 37, 38, 39'], ['Cor', 'Preto, Caramelo']],
  };
  const row = (name = '', values = '', id = `variacao-${rows.children.length + 1}`) => {
    const item = document.createElement('div');
    item.className = 'variationRow';
    item.innerHTML = `<input type="hidden" name="variation_id[]" value="${escapeHtml(id)}"><input name="variation_name[]" placeholder="Tamanho ou Cor" value="${escapeHtml(name)}"><input name="variation_values[]" placeholder="P, M, G" value="${escapeHtml(values)}"><button type="button" data-remove-variation>×</button>`;
    return item;
  };
  const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
  const groups = () => [...rows.querySelectorAll('.variationRow')].map((item, index) => {
    const inputs = item.querySelectorAll('input');
    return { id: inputs[0].value || `variacao-${index + 1}`, name: inputs[1].value.trim(), values: inputs[2].value.split(',').map((value) => value.trim()).filter(Boolean) };
  }).filter((group) => group.name && group.values.length);
  const combinations = (items) => items.reduce((all, group) => all.flatMap((prefix) => group.values.map((value) => `${prefix ? `${prefix}|` : ''}${group.id}=${value}`)), ['']);
  const updateStock = () => {
    const active = groups();
    if (!active.length) { grid.innerHTML = ''; basic.hidden = false; return; }
    basic.hidden = true;
    const fallback = Number(editor.querySelector('[name="basic_stock"]')?.value || 0);
    grid.innerHTML = combinations(active).map((key) => {
      const text = key.split('|').map((item) => item.split('=')[1]).join(' · ');
      const value = previous[key] ?? fallback;
      return `<label>${escapeHtml(text)}<input type="number" min="0" name="stock_map[${escapeHtml(key)}]" value="${Number(value) || 0}"></label>`;
    }).join('');
  };
  rows.addEventListener('input', updateStock);
  rows.addEventListener('click', (event) => { if (event.target.closest('[data-remove-variation]')) { event.target.closest('.variationRow').remove(); updateStock(); } });
  editor.querySelector('[data-add-variation]').addEventListener('click', () => { if (rows.children.length < 3) { rows.append(row()); updateStock(); } });
  editor.querySelectorAll('[data-product-preset]').forEach((button) => button.addEventListener('click', () => { rows.innerHTML = ''; presets[button.dataset.productPreset].forEach(([name, values]) => rows.append(row(name, values))); updateStock(); }));
  updateStock();
})();
