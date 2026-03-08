(function() {
  'use strict';

  if (typeof shopwalkSearch === 'undefined') return;

  // State
  const state = {
    open: false,
    loading: false,
    query: '',
    results: [],
    total: 0,
    offset: 0,
    activeInput: null,
    debounceTimer: null,
    currentPage: 1,
  };

  // Helpers
  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function writeSession(query, results, clickedId) {
    try {
      const session = {
        lastQuery: query,
        lastResults: results.map(r => r.id),
        lastClickedProduct: clickedId || null,
        currentProductId: null,
      };
      sessionStorage.setItem('shopwalkSession', JSON.stringify(session));
    } catch(e) {
      // sessionStorage not available — silent fail
    }
  }

  function renderStatus(msg) {
    const status = document.querySelector('.shopwalk-overlay-status');
    if (status) status.textContent = msg;
  }

  // Overlay
  function injectOverlay() {
    const overlay = document.createElement('div');
    overlay.id = 'shopwalk-overlay';
    overlay.className = 'shopwalk-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Product search');
    overlay.innerHTML = `
      <div class="shopwalk-overlay-panel">
        <div class="shopwalk-overlay-header">
          <span class="shopwalk-overlay-query" aria-live="polite"></span>
          <button class="shopwalk-overlay-close" aria-label="Close search">✕</button>
        </div>
        <div class="shopwalk-overlay-status" aria-live="polite" aria-atomic="true"></div>
        <div class="shopwalk-overlay-grid" role="list"></div>
        <button class="shopwalk-load-more" style="display:none">Load more</button>
      </div>
    `;
    document.body.appendChild(overlay);
  }

  function openOverlay() {
    const overlay = document.getElementById('shopwalk-overlay');
    overlay.style.display = 'flex';
    // Force reflow for transition
    overlay.offsetHeight;
    overlay.classList.add('shopwalk-open');
    overlay.classList.remove('shopwalk-closing');
    state.open = true;
    // Prevent body scroll
    document.body.style.overflow = 'hidden';
  }

  function closeOverlay() {
    const overlay = document.getElementById('shopwalk-overlay');
    overlay.classList.add('shopwalk-closing');
    overlay.classList.remove('shopwalk-open');
    state.open = false;
    document.body.style.overflow = '';
    setTimeout(() => {
      overlay.style.display = 'none';
      overlay.classList.remove('shopwalk-closing');
    }, 210); // slightly longer than CSS transition
  }

  // Search
  function doSearch(query, offset) {
    offset = offset || 0;
    state.loading = true;
    state.query = query;
    renderStatus('Searching...');

    // Rate limit / error handling
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 3000); // 3s timeout

    fetch(shopwalkSearch.apiUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-API-Key': shopwalkSearch.apiKey,
      },
      body: JSON.stringify({ query: query, limit: 20, offset: offset }),
      signal: controller.signal,
    })
    .then(r => {
      clearTimeout(timeout);
      if (r.status === 429) {
        // Rate limit — silent fallback (let native WC search run)
        silentFallback();
        return null;
      }
      if (!r.ok) throw new Error('API error');
      return r.json();
    })
    .then(data => {
      if (!data) return;
      state.loading = false;
      if (offset === 0) {
        state.results = data.results;
      } else {
        state.results = state.results.concat(data.results);
      }
      state.total = data.total;
      state.offset = offset + data.results.length;
      renderResults(data.results, data.total, data.has_more, offset > 0);
      writeSession(query, data.results);
    })
    .catch(err => {
      clearTimeout(timeout);
      if (err.name === 'AbortError') {
        // Timeout — silent fallback
        silentFallback();
      } else {
        silentFallback();
      }
    });
  }

  function silentFallback() {
    // Close overlay, let native WC form submit
    closeOverlay();
    state.loading = false;
    if (state.activeInput) {
      const form = state.activeInput.closest('form');
      if (form) form.submit();
    }
  }

  // Render
  function renderResults(results, total, hasMore, append) {
    const grid = document.querySelector('.shopwalk-overlay-grid');
    const loadMore = document.querySelector('.shopwalk-load-more');
    const status = document.querySelector('.shopwalk-overlay-status');
    const queryEl = document.querySelector('.shopwalk-overlay-query');

    queryEl.textContent = state.query;

    if (!append) grid.innerHTML = '';

    if (results.length === 0 && !append) {
      // No results — show popular products
      renderNoResults();
      loadMore.style.display = 'none';
      return;
    }

    status.textContent = total + ' results';

    results.forEach(product => {
      const card = document.createElement('div');
      card.className = 'shopwalk-product-card';
      card.setAttribute('role', 'listitem');
      card.setAttribute('tabindex', '0');
      card.innerHTML = `
        <img src="${escapeHtml(product.image_url)}" alt="${escapeHtml(product.name)}" loading="lazy">
        <div class="shopwalk-card-body">
          <div class="name">${escapeHtml(product.name)}</div>
          <div class="price">${escapeHtml(product.price)}</div>
          <div class="stock">${escapeHtml(product.stock || '')}</div>
        </div>
      `;
      card.addEventListener('click', () => {
        writeSession(state.query, state.results, product.id);
        window.location.href = product.url;
      });
      card.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
          writeSession(state.query, state.results, product.id);
          window.location.href = product.url;
        }
      });
      grid.appendChild(card);
    });

    loadMore.style.display = hasMore ? 'block' : 'none';
  }

  function renderNoResults() {
    const grid = document.querySelector('.shopwalk-overlay-grid');
    const status = document.querySelector('.shopwalk-overlay-status');

    status.textContent = 'No results for "' + escapeHtml(state.query) + '"';

    const noResults = document.createElement('div');
    noResults.className = 'shopwalk-no-results';
    noResults.innerHTML = '<p>No results for <strong>"' + escapeHtml(state.query) + '"</strong></p>';
    grid.appendChild(noResults);

    if (shopwalkSearch.popularProducts && shopwalkSearch.popularProducts.length > 0) {
      const label = document.createElement('span');
      label.className = 'shopwalk-popular-label';
      label.textContent = 'Popular in our store:';
      grid.appendChild(label);

      renderResults(shopwalkSearch.popularProducts, shopwalkSearch.popularProducts.length, false, true);
    }
  }

  // Input attachment
  function attachToInput(input) {
    let debounceTimer = null;

    input.addEventListener('keyup', e => {
      const value = input.value.trim();

      if (!value) {
        closeOverlay();
        return;
      }

      state.activeInput = input;
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        if (value !== state.query || !state.open) {
          openOverlay();
          state.offset = 0;
          doSearch(value, 0);
        }
      }, 300);
    });

    // Prevent form submit on Enter when overlay is open
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter' && state.open) {
        e.preventDefault();
        // Load more or just stay in overlay — don't submit form
      }
    });
  }

  // Init
  function init() {
    // Create overlay in DOM
    injectOverlay();

    // Find all search inputs and deduplicate
    const selectors = [
      '.widget_product_search input[name="s"]',
      '.wp-block-woocommerce-product-search input',
      'form.woocommerce-product-search input[name="s"]',
      'input[type="search"]',
      'input[name="s"]',
    ];

    const seen = new Set();
    selectors.forEach(selector => {
      document.querySelectorAll(selector).forEach(input => {
        if (seen.has(input)) return;
        seen.add(input);
        attachToInput(input);
      });
    });

    // Close on backdrop click
    document.getElementById('shopwalk-overlay').addEventListener('click', e => {
      if (e.target === document.getElementById('shopwalk-overlay')) closeOverlay();
    });

    // Close button
    document.querySelector('.shopwalk-overlay-close').addEventListener('click', closeOverlay);

    // Escape key
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && state.open) closeOverlay();
    });

    // Load more
    document.querySelector('.shopwalk-load-more').addEventListener('click', () => {
      doSearch(state.query, state.offset);
    });
  }

  // Run on DOMContentLoaded
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
