(() => {
  const CART = 'club-venda-php-cart';
  const FAVORITES = 'club-venda-php-favorites';
  const COUPON = 'club-venda-php-coupon';
  const money = (cents) => `R$ ${(Number(cents || 0) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`;
  const read = (key) => { try { const value = JSON.parse(localStorage.getItem(key) || '[]'); return Array.isArray(value) ? value : []; } catch { return []; } };
  const write = (key, value) => localStorage.setItem(key, JSON.stringify(value));
  const cart = () => read(CART);
  const favorites = () => read(FAVORITES);
  const coupon = () => { try { return JSON.parse(localStorage.getItem(COUPON) || 'null'); } catch { return null; } };
  const couponDiscount = (subtotal) => { const item = coupon(); if (!item) return 0; return Math.min(subtotal, item.type === 'PERCENT' ? Math.round(subtotal * Number(item.value || 0) / 100) : Number(item.value || 0)); };
  const lineId = (item, selections = {}) => `${item.id}__${Object.entries(selections).sort().map(([key, value]) => `${key}:${value}`).join('__')}`;
  const cartCount = () => cart().reduce((sum, item) => sum + Number(item.quantity || 0), 0);
  const setCartBadge = () => {
    document.querySelectorAll('.headerCart').forEach((node) => {
      let badge = node.querySelector('span');
      const count = cartCount();
      if (!count) { badge?.remove(); return; }
      if (!badge) { badge = document.createElement('span'); node.appendChild(badge); }
      badge.textContent = count;
    });
  };
  const updateFavoriteButtons = () => {
    const ids = new Set(favorites().map((item) => item.id));
    document.querySelectorAll('[data-favorite]').forEach((button) => {
      let product; try { product = JSON.parse(button.dataset.favorite); } catch { return; }
      const active = ids.has(product.id);
      button.classList.toggle('selected', active);
      button.textContent = button.classList.contains('detailFavorite') ? (active ? '♥ Favorito' : '♡ Favoritar') : (active ? '♥' : '♡');
    });
  };
  const addToCart = (product, selections = {}) => {
    const items = cart(); const id = lineId(product, selections); const found = items.find((item) => item.lineId === id);
    if (found) found.quantity += 1;
    else items.push({ ...product, selections, quantity: 1, lineId: id });
    write(CART, items); setCartBadge();
  };
  document.querySelectorAll('.productImage[data-images], .mainMedia[data-images]').forEach((gallery) => {
    let images; try { images = JSON.parse(gallery.dataset.images); } catch { images = []; }
    if (images.length < 2) return;
    let index = 0; const image = gallery.querySelector('img'); const dots = gallery.querySelectorAll('.photoDots i');
    const set = (next) => { index = (next + images.length) % images.length; if (image) image.src = images[index]; dots.forEach((dot, position) => dot.classList.toggle('active', position === index)); document.querySelectorAll('[data-gallery-index]').forEach((thumb) => thumb.classList.toggle('selected', Number(thumb.dataset.galleryIndex) === index)); };
    gallery.querySelector('.previous')?.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); set(index - 1); });
    gallery.querySelector('.next')?.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); set(index + 1); });
    document.querySelectorAll('[data-gallery-index]').forEach((thumb) => thumb.addEventListener('click', () => set(Number(thumb.dataset.galleryIndex))));
    set(0);
  });
  document.addEventListener('click', (event) => {
    const favorite = event.target.closest('[data-favorite]');
    if (favorite) {
      event.preventDefault(); event.stopPropagation(); let product; try { product = JSON.parse(favorite.dataset.favorite); } catch { return; }
      const items = favorites(); const index = items.findIndex((item) => item.id === product.id);
      index >= 0 ? items.splice(index, 1) : items.push(product); write(FAVORITES, items); updateFavoriteButtons();
    }
    const add = event.target.closest('[data-add-cart]');
    if (add) {
      if (add.dataset.added === 'true') { window.location.href = '/carrinho'; return; }
      let product; try { product = JSON.parse(add.dataset.addCart); } catch { return; }
      const selections = {};
      document.querySelectorAll('[data-variant-group].selected').forEach((option) => { selections[option.dataset.variantGroup] = option.dataset.variantValue; });
      const required = product.variations || [];
      if (required.some((group) => !selections[group.id])) { add.textContent = 'ESCOLHA AS OPÇÕES'; return; }
      addToCart(product, selections); add.dataset.added = 'true'; add.textContent = '✓ ITEM ADICIONADO - VER CARRINHO';
    }
    const variant = event.target.closest('[data-variant-group]');
    if (variant) { document.querySelectorAll(`[data-variant-group="${variant.dataset.variantGroup}"]`).forEach((item) => item.classList.remove('selected')); variant.classList.add('selected'); }
  });
  const cartPage = document.querySelector('[data-cart-page]');
  if (cartPage) {
    const render = () => {
      const items = cart();
      if (!items.length) { cartPage.innerHTML = '<p class="eyebrow">SEU CARRINHO</p><h1>Itens escolhidos</h1><div class="empty"><p>Seu carrinho está vazio.</p><a href="/">VER CATÁLOGO</a></div>'; return; }
      const subtotal = items.reduce((sum, item) => sum + item.price * item.quantity, 0);
      const activeCoupon = coupon(); const discount = couponDiscount(subtotal); const total = subtotal - discount;
      cartPage.innerHTML = `<p class="eyebrow">SEU CARRINHO</p><h1>Itens escolhidos</h1><div class="cartList">${items.map((item) => `<article class="cartItem" data-line="${item.lineId}"><div class="cartPhoto" style="background-image:url('${item.images?.[0] || ''}')"></div><div><h2>${item.name}</h2><p>${money(item.price)}</p><small class="cartVariant">${Object.values(item.selections || {}).join(' · ')}</small><div class="quantity"><button data-quantity="-1">−</button><span>${item.quantity}</span><button data-quantity="1">+</button><button class="remove" data-remove>Remover</button></div></div><b>${money(item.price * item.quantity)}</b></article>`).join('')}</div><section class="couponBox"><p class="couponHint">🏷 Verifique se existe cupom disponível e economize na sua compra.</p>${activeCoupon ? `<p class="couponApplied">Cupom ativo: <b>${activeCoupon.code}</b> <button type="button" data-remove-coupon>remover</button></p>` : `<label>Tem um cupom?<div class="couponInput"><input data-coupon-input placeholder="Digite ou cole o cupom"><button type="button" data-apply-coupon>APLICAR</button></div></label>`}<p class="couponMessage" data-coupon-message></p></section><div class="cartTotal"><span>Subtotal</span><b>${money(subtotal)}</b>${discount ? `<span>Desconto${activeCoupon ? ` (${activeCoupon.code})` : ''}</span><b class="discount">− ${money(discount)}</b>` : ''}<span>Total</span><b>${money(total)}</b><a href="/checkout">FINALIZAR PEDIDO</a><a href="/#catalogo" class="continueShopping">CONTINUAR COMPRANDO</a></div>`;
      cartPage.querySelectorAll('[data-quantity]').forEach((button) => button.addEventListener('click', () => { const line = button.closest('[data-line]').dataset.line; const items = cart(); const item = items.find((entry) => entry.lineId === line); item.quantity += Number(button.dataset.quantity); if (item.quantity < 1) items.splice(items.indexOf(item), 1); write(CART, items); setCartBadge(); render(); }));
      cartPage.querySelectorAll('[data-remove]').forEach((button) => button.addEventListener('click', () => { const line = button.closest('[data-line]').dataset.line; write(CART, cart().filter((item) => item.lineId !== line)); setCartBadge(); render(); }));
      cartPage.querySelector('[data-remove-coupon]')?.addEventListener('click', () => { localStorage.removeItem(COUPON); render(); });
      cartPage.querySelector('[data-apply-coupon]')?.addEventListener('click', async () => { const code = cartPage.querySelector('[data-coupon-input]').value.trim().toUpperCase(); const message = cartPage.querySelector('[data-coupon-message]'); if (!code) { message.textContent = 'Digite um cupom.'; return; } try { const response = await fetch(`/api/cupom/${encodeURIComponent(code)}`); const data = await response.json(); if (!response.ok) throw new Error(data.error); localStorage.setItem(COUPON, JSON.stringify(data)); render(); } catch (error) { message.textContent = error.message || 'Não foi possível aplicar o cupom.'; } });
    }; render();
  }
  const favoritesPage = document.querySelector('[data-favorites-page]');
  if (favoritesPage) {
    const items = favorites();
    favoritesPage.innerHTML = `<p class="eyebrow">SEUS FAVORITOS</p><h1>Peças que você amou</h1>${items.length ? `<div class="grid favoriteGrid">${items.map((item) => `<article class="product"><a class="productImage" href="/produto/${item.id}"><img src="${item.images?.[0] || ''}" alt="${item.name}"><button class="favorite selected" type="button" data-favorite='${JSON.stringify(item).replace(/'/g, '&#39;')}'>♥</button></a><h2>${item.name}</h2><p>${money(item.price)}</p><a class="productDetails" href="/produto/${item.id}">VER DETALHES</a></article>`).join('')}</div>` : '<div class="empty"><p>Você ainda não favoritou nenhuma peça.</p><a href="/">VER CATÁLOGO</a></div>'}`;
  }
  const checkout = document.querySelector('[data-checkout-form]');
  if (checkout) {
    const summary = document.querySelector('[data-checkout-summary]'); const items = cart(); const subtotal = items.reduce((sum, item) => sum + item.price * item.quantity, 0); const activeCoupon = coupon(); const discount = couponDiscount(subtotal);
    summary.innerHTML = `<p>RESUMO DO PEDIDO</p>${items.map((item) => `<div class="summaryItem"><span>${item.quantity}× ${item.name}<small>${Object.values(item.selections || {}).join(' · ')}</small></span><b>${money(item.price * item.quantity)}</b></div>`).join('')}<hr><div><span>Subtotal</span><b>${money(subtotal)}</b></div>${discount ? `<div><span>Desconto (${activeCoupon.code})</span><b>− ${money(discount)}</b></div>` : ''}<div class="totalLine"><span>Total dos produtos</span><b>${money(subtotal - discount)}</b></div><small>O estoque, o valor do frete e o pagamento serão confirmados pelo atendimento.</small>`;
    const phone = checkout.elements.phone; const cep = checkout.elements.cep; const state = checkout.elements.state; const status = checkout.querySelector('[data-cep-status]');
    const showCepStatus = (message) => { status.hidden = !message; status.textContent = message; };
    const formatPhone = () => { const digits = phone.value.replace(/\D/g, '').slice(0, 11); phone.value = digits.length <= 10 ? digits.replace(/^(\d{0,2})(\d{0,4})(\d{0,4})$/, (_, ddd, first, last) => [ddd && `(${ddd}`, ddd.length === 2 && ') ', first, last && `-${last}`].filter(Boolean).join('')) : digits.replace(/^(\d{2})(\d{5})(\d{4})$/, '($1) $2-$3'); };
    const formatCep = () => { const digits = cep.value.replace(/\D/g, '').slice(0, 8); cep.value = digits.length > 5 ? `${digits.slice(0, 5)}-${digits.slice(5)}` : digits; return digits; };
    const lookupCep = async () => { const digits = formatCep(); if (digits.length !== 8) { showCepStatus('Digite um CEP com 8 números.'); return; } showCepStatus('Buscando endereço...'); try { const response = await fetch(`/api/cep/${digits}`); const data = await response.json(); if (!response.ok) throw new Error(data.error); checkout.elements.street.value = data.street || ''; checkout.elements.neighborhood.value = data.neighborhood || ''; checkout.elements.city.value = data.city || ''; state.value = (data.state || '').toUpperCase(); showCepStatus('Endereço encontrado. Agora informe o número.'); } catch (problem) { showCepStatus(problem.message || 'Não foi possível consultar o CEP. Preencha o endereço manualmente.'); } };
    phone.addEventListener('input', formatPhone); cep.addEventListener('input', () => { const digits = formatCep(); showCepStatus(''); if (digits.length === 8) lookupCep(); }); cep.addEventListener('blur', lookupCep); checkout.querySelector('[data-lookup-cep]').addEventListener('click', lookupCep); state.addEventListener('input', () => { state.value = state.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2); });
    checkout.addEventListener('submit', async (event) => { event.preventDefault(); const data = new FormData(checkout); const error = document.querySelector('[data-checkout-error]'); const phoneDigits = String(data.get('phone') || '').replace(/\D/g, ''); const cepDigits = String(data.get('cep') || '').replace(/\D/g, ''); if (!items.length) { error.hidden = false; error.textContent = 'Seu carrinho está vazio.'; return; } if (phoneDigits.length < 10) { error.hidden = false; error.textContent = 'Informe um WhatsApp válido com DDD.'; return; } if (cepDigits.length !== 8) { error.hidden = false; error.textContent = 'Informe um CEP válido com 8 números.'; return; } const address = `${data.get('street')}, ${data.get('number')}${data.get('complement') ? `, ${data.get('complement')}` : ''} - ${data.get('neighborhood')}, ${data.get('city')}/${data.get('state')} - CEP ${data.get('cep')}`; try { const response = await fetch('/api/orders', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ name: data.get('name'), phone: phoneDigits, address, notes: data.get('notes'), payment: data.get('payment'), coupon: activeCoupon?.code || '', items }) }); const body = await response.json(); if (!response.ok) throw new Error(body.error || 'Não foi possível criar o pedido.'); write(CART, []); localStorage.removeItem(COUPON); window.location.href = `/pedido/${body.order.id}`; } catch (problem) { error.hidden = false; error.textContent = problem.message; } });
  }
  updateFavoriteButtons(); setCartBadge();
  document.querySelectorAll('[data-category-open]').forEach((button) => button.addEventListener('click', () => { const modal = document.getElementById(button.dataset.categoryOpen); if (modal) modal.hidden = false; }));
  document.querySelectorAll('[data-category-close]').forEach((button) => button.addEventListener('click', () => { const modal = button.closest('.categoryModal'); if (modal) modal.hidden = true; }));
  document.querySelectorAll('[data-position-range]').forEach((range) => range.addEventListener('input', () => { const modal = range.closest('.categoryModal'); const value = `${range.value}%`; modal.querySelector('[data-position-value]').value = `50% ${value}`; modal.querySelector('.modalPhotoPreview').style.backgroundPosition = `50% ${value}`; }));
  document.querySelectorAll('.categoryModal input[name="photo"]').forEach((input) => input.addEventListener('change', () => { const file = input.files?.[0]; const preview = input.closest('.categoryModal')?.querySelector('.modalPhotoPreview'); if (file && preview) preview.style.backgroundImage = `url("${URL.createObjectURL(file)}")`; }));
  document.querySelectorAll('[data-copy-catalog-link]').forEach((button) => button.addEventListener('click', async () => {
    const link = `${window.location.origin}/`;
    const original = button.textContent;
    try {
      if (!navigator.clipboard?.writeText) throw new Error('clipboard unavailable');
      await navigator.clipboard.writeText(link);
      button.textContent = 'LINK COPIADO ✓';
      button.classList.add('isCopied');
    } catch (_) {
      window.prompt('Copie o link do catálogo:', link);
    }
    window.setTimeout(() => { button.textContent = original; button.classList.remove('isCopied'); }, 2200);
  }));
  const readyCampaigns = [...document.querySelectorAll('[data-ready-campaign]')];
  if (readyCampaigns.length || document.querySelector('[data-ready-share]')) {
    const count = document.querySelector('[data-ready-count]');
    const bulk = document.querySelector('[data-ready-share]');
    const notice = document.querySelector('[data-ready-notice]');
    const tell = (message) => { if (!notice) return; notice.textContent = message; notice.hidden = false; };
    const share = async (title, message) => {
      try {
        if (navigator.share) { await navigator.share({ title, text: message }); tell('Compartilhamento aberto. Escolha o WhatsApp e depois o grupo.'); return; }
        if (!navigator.clipboard?.writeText) throw new Error('clipboard unavailable');
        await navigator.clipboard.writeText(message); tell('Mensagem copiada. Cole no WhatsApp para enviar.');
      } catch (error) { if (error?.name !== 'AbortError') tell('Não foi possível compartilhar agora.'); }
    };
    const messageFor = (card, kind = 'items') => String(kind === 'catalog' ? card.dataset.shareCatalogMessage || '' : card.dataset.shareItemsMessage || '').replaceAll('/promocao/', `${window.location.origin}/promocao/`).replaceAll('/produto/', `${window.location.origin}/produto/`);
    document.querySelectorAll('[data-ready-url]').forEach((item) => { item.textContent = `${window.location.origin}${item.textContent}`; });
    const refreshReady = () => {
      const selected = readyCampaigns.filter((card) => card.querySelector('[data-ready-select]')?.checked);
      readyCampaigns.forEach((card) => card.classList.toggle('isSelected', card.querySelector('[data-ready-select]')?.checked));
      if (count) count.textContent = String(selected.length);
      if (bulk) bulk.disabled = !selected.length;
    };
    readyCampaigns.forEach((card) => {
      const select = card.querySelector('[data-ready-select]');
      select?.addEventListener('change', () => { if (select.checked && readyCampaigns.filter((item) => item.querySelector('[data-ready-select]')?.checked).length > 5) { select.checked = false; tell('Você pode selecionar até 5 campanhas por vez.'); } refreshReady(); });
      card.querySelector('[data-share-items]')?.addEventListener('click', () => share('Campanha', messageFor(card, 'items')));
      card.querySelector('[data-share-catalog]')?.addEventListener('click', () => share('Catálogo promocional', messageFor(card, 'catalog')));
    });
    bulk?.addEventListener('click', () => { const selected = readyCampaigns.filter((card) => card.querySelector('[data-ready-select]')?.checked); if (!selected.length) return tell('Selecione ao menos uma campanha.'); share(`${selected.length} campanhas`, selected.map(messageFor).join('\n\n━━━━━━━━━━━━\n\n')); });
    refreshReady();
  }
  const campaignForm = document.querySelector('[data-campaign-form]');
  if (campaignForm) {
    const checks = [...campaignForm.querySelectorAll('[data-product-check]')];
    const search = campaignForm.querySelector('[data-product-search]'); const category = campaignForm.querySelector('[data-product-category]');
    const selectAll = campaignForm.querySelector('[data-select-all]'); const count = campaignForm.querySelector('[data-selected-count]');
    const previewCount = document.querySelector('[data-preview-products]'); const previewGroups = document.querySelector('[data-preview-groups]');
    const bubble = document.querySelector('[data-preview-bubble]'); const text = campaignForm.querySelector('textarea[name="message"]'); const textCount = campaignForm.querySelector('[data-message-count]');
    const rulesField = campaignForm.querySelector('[data-promotion-rules]'); const rulesBox = campaignForm.querySelector('[data-offer-rules]'); const offerProducts = campaignForm.querySelector('[data-offer-products]'); let rules = [];
    const selectedProducts = () => checks.filter((check) => check.checked).map((check) => ({ id: check.value, name: check.closest('[data-product]').querySelector('b').textContent, price: check.closest('[data-product]').querySelector('small').textContent, image: check.closest('[data-product]').querySelector('img').src }));
    const render = () => { const selected = selectedProducts(); count.textContent = `${selected.length} selecionado${selected.length === 1 ? '' : 's'}`; previewCount.textContent = selected.length; rulesField.value = JSON.stringify(rules); offerProducts.innerHTML = selected.length ? selected.map((product) => `<article><span>${product.name}</span><small>${product.price.split(' · ').pop()}</small></article>`).join('') : '<p>Primeiro selecione produtos acima.</p>'; rulesBox.innerHTML = rules.map((rule, index) => `<article class="offerRule"><span><b>${rule.quantity} peças por R$ ${(rule.bundlePriceCents / 100).toLocaleString('pt-BR',{minimumFractionDigits:2})}</b><br>${rule.mode === 'SAME_PRODUCT' ? 'Da mesma peça' : 'Pode combinar'} · ${rule.productIds.length} produtos</span><button type="button" data-remove-rule="${index}">REMOVER</button></article>`).join(''); const previewCards = selected.map((product) => `<article class="previewProduct"><img src="${product.image}" alt=""><span><b>${product.name}</b>${product.price.split(' · ').pop()}</span></article>`).join(''); bubble.innerHTML = `${String(text.value || 'Sua mensagem aparecerá aqui.').replace(/</g, '&lt;').replace(/\n/g, '<br>')}${previewCards}<small>agora ✓✓</small>`; rulesBox.querySelectorAll('[data-remove-rule]').forEach((button) => button.addEventListener('click', () => { rules.splice(Number(button.dataset.removeRule), 1); render(); })); };
    const filter = () => { const term = search.value.toLocaleLowerCase(); const currentCategory = category.value; let visible = 0; campaignForm.querySelectorAll('[data-product]').forEach((row) => { const show = row.dataset.name.includes(term) && (!currentCategory || row.dataset.category === currentCategory); row.hidden = !show; if (show) visible += 1; }); selectAll.textContent = `SELECIONAR TODOS DA BUSCA (${visible})`; };
    checks.forEach((check) => check.addEventListener('change', render)); search.addEventListener('input', filter); category.addEventListener('change', filter);
    selectAll.addEventListener('click', () => { const rows = [...campaignForm.querySelectorAll('[data-product]')].filter((row) => !row.hidden); const all = rows.length && rows.every((row) => row.querySelector('[data-product-check]').checked); rows.forEach((row) => { row.querySelector('[data-product-check]').checked = !all; }); selectAll.textContent = all ? `SELECIONAR TODOS DA BUSCA (${rows.length})` : `LIMPAR ${rows.length} DA BUSCA`; render(); });
    campaignForm.querySelector('[data-add-offer]').addEventListener('click', () => { const selected = selectedProducts(); const quantity = Number(campaignForm.querySelector('[data-offer-quantity]').value); const price = Number(String(campaignForm.querySelector('[data-offer-price]').value).replace(',', '.')); const mode = campaignForm.querySelector('[data-offer-mode]').value; if (!selected.length || !Number.isInteger(quantity) || quantity < 2 || quantity > 10 || !price) return; rules.push({ id: crypto.randomUUID(), productIds: selected.map((product) => product.id), mode, quantity, bundlePriceCents: Math.round(price * 100) }); campaignForm.querySelector('[data-offer-price]').value = ''; render(); });
    text.addEventListener('input', () => { textCount.textContent = `${text.value.length} caracteres`; render(); });
    campaignForm.querySelector('[data-generate-message]').addEventListener('click', () => { const selected = selectedProducts(); text.value = `✨ Novidades da loja\n\n${selected.length ? selected.map((product) => product.name).join(' • ') : 'Nossas novidades'}\n\nAcesse o catálogo e fale com a gente pelo WhatsApp para garantir a sua!`; text.dispatchEvent(new Event('input')); });
    const sendCampaign = campaignForm.querySelector('button[name="save_as"][value="send"]');
    const groupWarning = document.createElement('p'); groupWarning.className = 'groupRequired'; groupWarning.textContent = 'Selecione ao menos um grupo de destino para enviar a campanha.';
    campaignForm.querySelector('.campaignActions')?.before(groupWarning);
    const syncGroups = () => { const total = campaignForm.querySelectorAll('input[name="group_ids[]"]:checked').length; previewGroups.textContent = total; sendCampaign.disabled = total === 0; groupWarning.hidden = total > 0; };
    campaignForm.querySelectorAll('input[name="group_ids[]"]').forEach((input) => input.addEventListener('change', syncGroups)); render(); filter(); syncGroups(); text.dispatchEvent(new Event('input'));
  }
  const wireCampaignHistory = (root = document) => {
    root.querySelectorAll('form[action="/admin/campanhas"]').forEach((form) => {
      if (form.dataset.campaignWired) return;
      form.dataset.campaignWired = 'true';
      form.addEventListener('submit', async (event) => {
        const action = form.querySelector('input[name="action"]')?.value;
        if (!['delete', 'restore', 'archive', 'duplicate', 'cancel', 'queue'].includes(action)) return;
        const query = new URLSearchParams(window.location.search);
        if (query.has('arquivadas')) [['history_archived', '1'], ['history_month', query.get('mes') || '']].forEach(([name, value]) => { if (!form.querySelector(`[name="${name}"]`)) { const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.appendChild(input); } });
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]'); if (button?.disabled) return;
        const originalLabel = button?.textContent || ''; if (button) { button.disabled = true; button.textContent = 'AGUARDE…'; }
        try {
          const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
          const body = await response.json().catch(() => ({ ok: true }));
          if (!response.ok || body?.ok === false) throw new Error();
          const row = form.closest('article');
          if (['delete', 'restore', 'archive'].includes(action)) row?.remove();
          else if (button) button.textContent = action === 'duplicate' ? 'CÓPIA CRIADA' : 'ATUALIZADA';
        } catch { if (button) { button.disabled = false; button.textContent = originalLabel || 'TENTAR NOVAMENTE'; } }
      });
    });
  };
  wireCampaignHistory();
  const wireCampaignTabs = (root = document) => root.querySelectorAll('.historyControls a').forEach((link) => {
    if (link.dataset.campaignWired) return;
    link.dataset.campaignWired = 'true';
    link.addEventListener('click', async (event) => {
      event.preventDefault();
      try {
        const response = await fetch(link.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) throw new Error();
        const next = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('#historico');
        const current = document.querySelector('#historico');
        if (!next || !current) throw new Error();
        current.replaceWith(next); window.history.pushState({}, '', link.href); wireCampaignHistory(next); wireCampaignTabs(next);
      } catch { window.location.href = link.href; }
    });
  });
  wireCampaignTabs();
})();
