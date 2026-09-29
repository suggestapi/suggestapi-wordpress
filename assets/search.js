document.querySelectorAll('.suggestapi-search').forEach((form) => {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const out = form.querySelector('.suggestapi-results');
    const q = new FormData(form).get('q');
    out.textContent = 'Searching…';
    try {
      const url = new URL(form.dataset.endpoint);
      url.searchParams.set('q', q);
      url.searchParams.set('limit', form.dataset.limit || '8');
      if (form.dataset.mode) url.searchParams.set('endpoint', form.dataset.mode);
      const r = await fetch(url);
      if (!r.ok) throw new Error('Search unavailable');
      const data = await r.json();
      out.replaceChildren();
      // Real SuggestAPI shapes: {results:[{label,title,url}]} or {suggestions:[{title,url}]}
      const items = Array.isArray(data) ? data : data.results || data.suggestions || data.hits || [];
      if (!items.length) {
        out.textContent = 'No results';
        return;
      }
      const list = document.createElement('ul');
      items.forEach((item) => {
        const li = document.createElement('li');
        const a = document.createElement('a');
        const href = item.url || item.product_url || item.source_url || '';
        if (href) {
          try {
            const u = new URL(href, location.origin);
            if (!['http:', 'https:'].includes(u.protocol)) return;
            a.href = u.href;
          } catch {
            return;
          }
        } else {
          a.href = '#';
          a.setAttribute('aria-disabled', 'true');
        }
        const title = item.label || item.title || item.name || 'Product';
        const meta = item.manufacturer || item.brand || item.price || item.sku || '';
        a.textContent = meta ? `${title} — ${meta}` : title;
        li.append(a);
        list.append(li);
      });
      out.append(list);
    } catch {
      out.textContent = 'Search is temporarily unavailable.';
    }
  });
});
