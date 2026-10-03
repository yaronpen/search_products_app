(() => {
  'use strict';

  const API_URL = 'api/search';
  const DEBOUNCE_MS = 350;
  const MIN_CHARS = 2;

  const form = document.getElementById('search-form');
  const input = document.getElementById('q');
  const clearBtn = document.getElementById('clear');
  const suggestions = document.getElementById('suggestions');
  const title = document.getElementById('results-title');
  const status = document.getElementById('status');
  const notice = document.getElementById('notice');
  const grid = document.getElementById('grid');
  const message = document.getElementById('message');
  const cardTemplate = document.getElementById('card-template');

  const priceFormat = new Intl.NumberFormat('he-IL', { style: 'currency', currency: 'ILS', maximumFractionDigits: 0 });

  let controller = null;
  let debounceTimer = null;
  let lastQuery = '';

  function search(query, { updateUrl = true } = {}) {
    query = query.trim();
    clearTimeout(debounceTimer);
    if (query === lastQuery && !grid.dataset.error) return;
    lastQuery = query;

    if (updateUrl) {
      const url = new URL(location.href);
      query ? url.searchParams.set('q', query) : url.searchParams.delete('q');
      history.replaceState(null, '', url);
    }

    controller?.abort();
    if (query.length < MIN_CHARS) {
      renderIdle();
      return;
    }

    controller = new AbortController();
    renderLoading();

    fetch(`${API_URL}?q=${encodeURIComponent(query)}`, { signal: controller.signal })
      .then(async (res) => {
        const body = await res.json().catch(() => null);
        if (!res.ok || !body) throw new Error(body?.message || `HTTP ${res.status}`);
        return body;
      })
      .then((body) => renderResults(body))
      .catch((err) => {
        if (err.name === 'AbortError') return;
        console.error(err);
        renderError();
      });
  }

  function renderIdle() {
    grid.replaceChildren();
    grid.setAttribute('aria-busy', 'false');
    title.hidden = true;
    status.textContent = '';
    notice.hidden = true;
    message.hidden = true;
    delete grid.dataset.error;
  }

  function renderLoading() {
    title.hidden = false;
    title.textContent = 'מחפשים…';
    status.textContent = '';
    notice.hidden = true;
    message.hidden = true;
    grid.setAttribute('aria-busy', 'true');
    const skeletons = Array.from({ length: 8 }, () => {
      const li = document.createElement('li');
      li.className = 'card skeleton';
      li.setAttribute('aria-hidden', 'true');
      li.innerHTML = '<div class="card-media"></div><div class="card-body"><div class="sk-line"></div><div class="sk-line short"></div></div>';
      return li;
    });
    grid.replaceChildren(...skeletons);
  }

  function renderResults({ results, count, took_ms, warning }) {
    grid.setAttribute('aria-busy', 'false');
    delete grid.dataset.error;
    notice.hidden = warning !== 'semantic_unavailable';
    notice.textContent = 'החיפוש החכם אינו זמין כרגע — מוצגות התאמות לפי מילות החיפוש בלבד.';

    if (count === 0) {
      grid.replaceChildren();
      title.hidden = true;
      status.textContent = '';
      showMessage('לא מצאנו מוצרים מתאימים', 'נסו לנסח אחרת, לחפש לפי סוג מוצר או מותג, או לבחור אחד מהחיפושים לדוגמה.');
      return;
    }

    message.hidden = true;
    title.hidden = false;
    title.textContent = 'מצאנו מוצרים בשבילכם';
    status.textContent = `${count} מוצרים · ${took_ms} מ״ש`;
    grid.replaceChildren(...results.map(renderCard));
  }

  function renderCard(product) {
    const node = cardTemplate.content.firstElementChild.cloneNode(true);
    const img = node.querySelector('img');
    img.src = product.image_url;
    img.alt = product.name;
    img.addEventListener('error', () => img.classList.add('broken'), { once: true });
    node.querySelector('.card-brand').textContent = product.brand || '';
    node.querySelector('.card-name').textContent = product.name;
    node.querySelector('.card-price').textContent = priceFormat.format(product.price);
    return node;
  }

  function renderError() {
    grid.replaceChildren();
    grid.setAttribute('aria-busy', 'false');
    grid.dataset.error = '1';
    title.hidden = true;
    status.textContent = '';
    showMessage('משהו השתבש', 'לא הצלחנו להשלים את החיפוש. בדקו את החיבור ונסו שוב.', true);
  }

  function showMessage(heading, text, isError = false) {
    message.hidden = false;
    message.className = isError ? 'message error' : 'message';
    message.replaceChildren();
    const h = document.createElement('h3');
    h.textContent = heading;
    const p = document.createElement('p');
    p.textContent = text;
    message.append(h, p);
    if (isError) {
      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'retry';
      retry.textContent = 'נסו שוב';
      retry.addEventListener('click', () => { lastQuery = ''; search(input.value); });
      message.append(retry);
    }
  }

  function setQuery(value) {
    input.value = value;
    clearBtn.hidden = value === '';
  }

  // --- Events ---
  input.addEventListener('input', () => {
    clearBtn.hidden = input.value === '';
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => search(input.value), DEBOUNCE_MS);
  });

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    lastQuery = lastQuery === input.value.trim() && grid.dataset.error ? '' : lastQuery;
    search(input.value);
    input.blur(); // closes the mobile keyboard so results are visible
  });

  clearBtn.addEventListener('click', () => {
    setQuery('');
    search('');
    input.focus();
  });

  suggestions.addEventListener('click', (e) => {
    const button = e.target.closest('button');
    if (!button) return;
    setQuery(button.textContent);
    search(button.textContent);
  });

  // Restore a shared/bookmarked search (?q=...)
  const initial = new URLSearchParams(location.search).get('q');
  if (initial) {
    setQuery(initial);
    search(initial, { updateUrl: false });
  } else {
    input.focus();
  }
})();
