/**
 * SproutRoute Seller Module
 * Dashboard, Products (pop-ups, farms, categories, placeholders), Orders & Logistics
 */

const LOOKUPS_URL = '../api/lookups/lookups.php';

// Global variables (declared at top to avoid temporal dead zone)
let sellerFarmId = null;

document.addEventListener('DOMContentLoaded', async () => {
  initModalHelpers();

  // Load farm data for all Seller pages
  await loadSellerFarm();

  // Initialize notifications (non-blocking)
  initNotifications();

  if (document.getElementById('statProducts')) initDashboard();
  if (document.getElementById('productsTableBody')) await initProductsPage();
  if (document.getElementById('ordersTableBody')) initOrdersPage();
});

/* ==========================================================================
   Helpers
   ========================================================================== */
function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, ch => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]
  ));
}

/* ---------- Toast Notifications ---------- */
let toastContainer = null;

function ensureToastContainer() {
  if (!toastContainer) {
    toastContainer = document.createElement('div');
    toastContainer.id = 'toast-container';
    toastContainer.setAttribute('role', 'status');
    toastContainer.setAttribute('aria-live', 'polite');
    toastContainer.setAttribute('aria-atomic', 'true');
    Object.assign(toastContainer.style, {
      position: 'fixed',
      top: '1rem',
      right: '1rem',
      zIndex: '9999',
      display: 'flex',
      flexDirection: 'column',
      gap: '0.5rem',
      maxWidth: '400px',
      pointerEvents: 'none'
    });
    document.body.appendChild(toastContainer);
  }
  return toastContainer;
}

function showToast(message, type = 'info', duration = 4000) {
  const container = ensureToastContainer();
  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;

  const icons = {
    success: '<i class="fa-solid fa-check-circle"></i>',
    error: '<i class="fa-solid fa-circle-exclamation"></i>',
    loading: '<i class="fa-solid fa-spinner fa-spin"></i>',
    info: '<i class="fa-solid fa-circle-info"></i>'
  };

  const bgColors = {
    success: '#2e7d32',
    error: '#c62828',
    loading: '#1976d2',
    info: '#0288d1'
  };

  Object.assign(toast.style, {
    backgroundColor: bgColors[type] || bgColors.info,
    color: '#fff',
    padding: '0.75rem 1rem',
    borderRadius: '0.5rem',
    boxShadow: '0 2px 8px rgba(0,0,0,0.2)',
    display: 'flex',
    alignItems: 'center',
    gap: '0.75rem',
    fontSize: '0.9rem',
    pointerEvents: 'auto',
    opacity: '0',
    transform: 'translateX(20px)',
    transition: 'opacity 0.3s ease, transform 0.3s ease'
  });

  toast.innerHTML = `${icons[type] || icons.info} <span>${esc(message)}</span>`;

  container.appendChild(toast);

  requestAnimationFrame(() => {
    toast.style.opacity = '1';
    toast.style.transform = 'translateX(0)';
  });

  if (type !== 'loading') {
    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateX(20px)';
      setTimeout(() => toast.remove(), 300);
    }, duration);
  }

  return toast;
}

function hideToast(toast) {
  if (toast) {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(20px)';
    setTimeout(() => toast.remove(), 300);
  }
}

function clearLoadingToasts() {
  if (toastContainer) {
    const loadingToasts = toastContainer.querySelectorAll('.toast-loading');
    loadingToasts.forEach(t => hideToast(t));
  }
}

function peso(value) {
  const n = parseFloat(value);
  return '₱' + (Number.isFinite(n) ? n : 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function openModal(id) {
  const m = document.getElementById(id);
  if (m) { m.classList.add('active'); document.body.style.overflow = 'hidden'; }
}

function closeModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove('active');
  if (!document.querySelector('.seller-modal.active')) document.body.style.overflow = '';

  // Remove id parameter from URL when closing order detail modal
  if (id === 'orderDetailModal') {
    const url = new URL(window.location);
    url.searchParams.delete('id');
    window.history.replaceState({}, '', url);
  }
}

/* Close any pop-up by clicking the dark backdrop or pressing Escape */
function initModalHelpers() {
  document.querySelectorAll('.seller-modal').forEach(modal => {
    modal.addEventListener('mousedown', e => { if (e.target === modal) closeModal(modal.id); });
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.seller-modal.active').forEach(m => closeModal(m.id));
    }
  });
}

function wire(id, event, handler) {
  const el = document.getElementById(id);
  if (el) el.addEventListener(event, handler);
}

async function api(url, options = {}) {
  const res = await fetch(url, {
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    ...options
  });
  let data = null;
  try { data = await res.json(); } catch (_) { /* non-JSON response */ }
  if (!res.ok) {
    const msg = (data && (data.error || data.message)) || `Request failed (${res.status})`;
    if (res.status === 401) {
      showToast('Please sign in again.', 'error');
      setTimeout(() => {
        window.location.href = '../home/login.html';
      }, 2000);
    }
    throw new Error(msg);
  }
  return data;
}

const PLACEHOLDER_IMG = 'data:image/svg+xml;utf8,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="88" height="88" viewBox="0 0 88 88">' +
    '<rect width="88" height="88" rx="14" fill="#e8ebd4"/>' +
    '<path d="M44 62V40M44 40c0-9 6-15 15-15 0 9-6 15-15 15zM44 48c0-7-5-12-12-12 0 7 5 12 12 12z" ' +
    'fill="none" stroke="#60712f" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
);

/* ---- Placeholder data (shown while there is nothing real to display) ---- */
const PLACEHOLDER_PRODUCTS = [
  { id: 'ph1', name: 'Sample Tomatoes', category_name: 'Vegetables', farm_name: 'Sample Farm', price_per_kg: 85, stock_kg: 120 },
  { id: 'ph2', name: 'Sample Carrots',  category_name: 'Vegetables', farm_name: 'Sample Farm', price_per_kg: 60, stock_kg: 75 },
  { id: 'ph3', name: 'Sample Mangoes',  category_name: 'Fruits',     farm_name: 'Sample Orchard', price_per_kg: 140, stock_kg: 40 }
];
const PLACEHOLDER_FARMS = [
  { id: 'ph1', name: 'Sample Farm', address: 'Your farm address will appear here' },
  { id: 'ph2', name: 'Sample Orchard', address: 'Your farm address will appear here' }
];
const PLACEHOLDER_CATEGORIES = [
  { id: 'ph1', name: 'Vegetables' }, { id: 'ph2', name: 'Fruits' }, { id: 'ph3', name: 'Grains' }
];

/* ==========================================================================
   1. Notifications Bell
   ========================================================================== */
const NOTIFICATION_ENDPOINTS = [
  'new_orders',
  'status_changes',
  'low_stock'
];
const SEEN_NOTIFICATIONS_KEY = 'sproutroute-seen-notifications';

function notificationTitle(event) {
  const titles = {
    new_order: 'New order',
    order_status_changed: 'Order updated',
    low_stock: 'Low stock',
    out_of_stock: 'Out of stock'
  };
  return titles[event.type] || 'SproutRoute';
}

function notificationUrl(event) {
  if (event.type === 'low_stock' || event.type === 'out_of_stock') {
    return './seller/products.html';
  }

  const orderId = event.order_id ?? (event.order && event.order.order_id);
  return orderId
      ? `./seller/orders.html?id=${encodeURIComponent(orderId)}`
      : './seller/orders.html';
}

function notificationKey(event) {
  return `${event.sourceType}:${event.event_id}:${event.occurred_at || ''}`;
}

function readSeenNotifications() {
  try {
    const saved = JSON.parse(localStorage.getItem(SEEN_NOTIFICATIONS_KEY) || '[]');
    return Array.isArray(saved) ? saved : [];
  } catch (_) {
    return [];
  }
}

function markNotificationsSeen(events) {
  const seen = readSeenNotifications();

  events.forEach(event => {
    const key = notificationKey(event);
    if (!seen.includes(key)) {
      seen.push(key);
    }
  });

  localStorage.setItem(SEEN_NOTIFICATIONS_KEY, JSON.stringify(seen.slice(-200)));
}

function showSystemNotifications(events) {
  if (!('Notification' in window) || Notification.permission !== 'granted') {
    return;
  }
  if (!('serviceWorker' in navigator)) {
    return;
  }

  navigator.serviceWorker.ready.then(registration => {
    if (!registration.active) {
      return;
    }

    events.forEach(event => {
      registration.active.postMessage({
        type: 'SHOW_NOTIFICATION',
        title: notificationTitle(event),
        body: event.message || 'You have a new update.',
        url: notificationUrl(event)
      });
    });

    markNotificationsSeen(events);
  });
}

function fetchNotificationEvents() {
  // Pass farm_id for security compatibility with authenticated endpoints
  // If sellerFarmId is not available, fetch without it (backend will handle authorization)
  const farmParam = sellerFarmId ? `&farm_id=${sellerFarmId}` : '';

  return Promise.all(
      NOTIFICATION_ENDPOINTS.map(type =>
          fetch(`../api/notifications.php?type=${type}&limit=10${farmParam}`, { credentials: 'same-origin' })
              .then(response => {
                if (!response.ok) {
                  throw new Error(`Notification request failed (${response.status})`);
                }
                return response.json();
              })
              .then(data => (data.events || []).map(event => ({ ...event, sourceType: type })))
      )
  ).then(results => [].concat(...results));
}

function initNotifications() {
  const bell = document.getElementById('notifBell');
  const dropdown = document.getElementById('notifDropdown');
  const badge = document.getElementById('notifBadge');
  const list = document.getElementById('notifList');
  if (!bell) return;

  const empty = msg => {
    list.innerHTML = `<div class="notification-item" style="color:#7d887c;">${msg}</div>`;
    badge.style.display = 'none';
  };

  const render = events => {
    if (events.length === 0) {
      empty('No new notifications.');
      return;
    }

    badge.textContent = events.length;
    badge.style.display = 'inline-block';
    list.innerHTML = events.map(event => `
      <div class="notification-item ${event.type === 'low_stock' || event.type === 'out_of_stock' ? 'low-stock' : 'new-order'}">
        <strong>${esc(notificationTitle(event))}</strong>
        <div>${esc(event.message || '')}</div>
        <div class="time">${esc(event.occurred_at || 'Just now')}</div>
      </div>`).join('');
  };

  const refresh = () => {
    fetchNotificationEvents()
        .then(events => {
          events.sort((a, b) => String(b.occurred_at).localeCompare(String(a.occurred_at)));
          render(events);

          if ('Notification' in window && Notification.permission === 'granted') {
            const seen = readSeenNotifications();
            const unseen = events.filter(event => !seen.includes(notificationKey(event)));
            if (unseen.length > 0) {
              showSystemNotifications(unseen);
            }
          }
        })
        .catch(() => empty('Notification service offline.'));
  };

  const requestPermission = () => {
    if (!('Notification' in window) || Notification.permission !== 'default') {
      return;
    }

    Notification.requestPermission()
        .then(permission => {
          if (permission === 'granted') {
            refresh();
          }
        })
        .catch(error => console.warn('Notification permission request failed.', error));
  };

  bell.addEventListener('click', e => {
    e.stopPropagation();
    dropdown.classList.toggle('active');
    requestPermission();
  });
  dropdown.addEventListener('click', e => e.stopPropagation());
  document.addEventListener('click', () => dropdown.classList.remove('active'));

  refresh();
  setInterval(refresh, 60000);
}

/* ==========================================================================
   2. Dashboard
   ========================================================================== */
let dashboardInitialized = false;

function initDashboard() {
  if (dashboardInitialized) {
    return;
  }
  dashboardInitialized = true;

  const body = document.getElementById('recentOrdersBody');

  fetch('../api/summary.php', { credentials: 'same-origin' })
      .then(res => res.json())
      .then(data => {
        const set = (id, v) => { document.getElementById(id).textContent = v || 0; };

        // Orders section
        if (data.orders) {
          set('statOrders', data.orders.total);
          set('statInTransit', data.orders.by_status?.in_transit?.count || 0);
        }

        // Products section
        if (data.products) {
          set('statProducts', data.products.total);
        }

        // Stock section
        if (data.stock) {
          set('statLowStock', data.stock.low_stock);

          if (data.stock.low_stock_items && data.stock.low_stock_items.length > 0) {
            document.getElementById('lowStockAlertSection').style.display = 'block';
            document.getElementById('lowStockList').innerHTML = data.stock.low_stock_items.map(item => `
            <li><strong>${esc(item.name)}</strong> - Remaining stock: ${esc(item.stock_kg)} kg (Farm: ${esc(item.farm_name || 'N/A')})</li>`).join('');
          }
        }

        // Recent orders
        const recentOrders = data.orders?.recent_orders || [];

        if (recentOrders.length === 0) {
          body.innerHTML = `<tr><td colspan="6" class="empty-cell">No recent orders yet.</td></tr>`;
          return;
        }

        const rowsHtml = recentOrders.map(o => {
          const id = o.order_id;
          const status = o.status || 'pending';
          return `
        <tr>
          <td data-label="Order ID">#${esc(id)}</td>
          <td data-label="Restaurant">${esc(o.restaurant_name)}</td>
          <td data-label="Items">${esc(o.item_count ?? 0)} items</td>
          <td data-label="Total">${peso(o.total_amount)}</td>
          <td data-label="Status"><span class="status-badge status-${esc(status)}">${esc(status.replace('_', ' '))}</span></td>
          <td data-label="Actions"><a href="orders.html?id=${encodeURIComponent(id)}" class="btn btn-sm btn-outline">View Details</a></td>
        </tr>`;
        }).join('');

        body.innerHTML = rowsHtml;
      })
      .catch(err => {
        body.innerHTML = `<tr><td colspan="6" class="empty-cell">Could not load dashboard data. Check your connection.</td></tr>`;
      });
}

/* ==========================================================================
   3. Products & Categories
   ========================================================================== */
let allProducts = [];
let lookupCategories = [];
let usingPlaceholderProducts = false;

async function initProductsPage() {
  await Promise.all([
    loadCategories(),
    loadProducts()
  ]);

  wire('productSearchInput', 'input', filterProducts);
  wire('categoryFilter', 'change', filterProducts);

  // Product pop-up
  wire('openAddProductModalBtn', 'click', () => openProductModal());
  wire('closeProductModalBtn', 'click', () => closeModal('productModal'));
  wire('cancelProductBtn', 'click', () => closeModal('productModal'));
  wire('productForm', 'submit', handleProductSubmit);

  wire('productPhotoInput', 'change', e => {
    const file = e.target.files[0];
    const preview = document.getElementById('photoPreview');
    if (!file) return;
    const reader = new FileReader();
    reader.onload = evt => { preview.innerHTML = `<img src="${evt.target.result}" alt="Preview">`; };
    reader.readAsDataURL(file);
  });

  // Categories pop-up
  wire('openFarmCatModalBtn', 'click', () => openModal('farmCatModal'));
  wire('closeFarmCatModalBtn', 'click', () => closeModal('farmCatModal'));
  wire('addCatForm', 'submit', handleCategorySubmit);
}

/* ---------- Lookups (categories) ---------- */
function normalizeCategory(c) {
  return { id: c.category_id ?? c.id, name: c.name };
}

function loadCategories() {
  return fetch(`${LOOKUPS_URL}?action=categories`, { credentials: 'same-origin' })
      .then(res => { if (!res.ok) throw new Error('categories'); return res.json(); })
      .then(data => {
        lookupCategories = (data.categories || []).map(normalizeCategory);
      })
      .catch(() => { lookupCategories = []; })
      .finally(renderCategories);
}

function loadSellerFarm() {
  return fetch(`${LOOKUPS_URL}?action=farms`, { credentials: 'same-origin' })
      .then(res => { if (!res.ok) throw new Error('farms'); return res.json(); })
      .then(data => {
        const farms = data.farms || [];
        if (farms.length > 0) {
          sellerFarmId = farms[0].farm_id || farms[0].id;
        }
      })
      .catch(() => { sellerFarmId = null; });
}

function renderCategories() {
  const catsEmpty = lookupCategories.length === 0;

  fillSelect('categoryFilter', lookupCategories, 'All Categories');
  fillSelect('productCategory', lookupCategories, catsEmpty ? 'No categories yet - add one first' : 'Select Category');

  document.getElementById('catList').innerHTML = catsEmpty
      ? PLACEHOLDER_CATEGORIES.map(c => `
        <li class="is-placeholder">
          <div class="item-meta"><span class="item-icon"><i class="fa-solid fa-tag"></i></span>
            <div class="item-text">${esc(c.name)}<small>Sample category</small></div></div>
        </li>`).join('')
      : lookupCategories.map(c => `
        <li>
          <div class="item-meta"><span class="item-icon"><i class="fa-solid fa-tag"></i></span>
            <div class="item-text">${esc(c.name)}</div></div>
        </li>`).join('');
}

function fillSelect(id, items, firstLabel) {
  const s = document.getElementById(id);
  if (!s) return;
  const current = s.value;
  s.innerHTML = `<option value="">${esc(firstLabel)}</option>` +
      items.map(i => `<option value="${esc(i.id)}">${esc(i.name)}</option>`).join('');
  s.value = current;
}

/* ---------- Products ---------- */
function normalizeProduct(p) {
  return {
    id: p.product_id ?? p.id,
    name: p.name || '',
    category_id: p.category_id,
    category_name: p.category_name,
    farm_id: p.farm_id,
    farm_name: p.farm_name,
    price: p.price_per_kg ?? p.price ?? 0,
    min_order: p.min_order_kg ?? 1,
    stock: p.stock_kg ?? p.stock_quantity ?? 0,
    harvest_date: p.harvest_date || '',
    image: p.photo || p.image_url || ''
  };
}

function loadProducts() {
  return fetch('../api/products/read.php', { credentials: 'same-origin' })
      .then(res => { if (!res.ok) throw new Error('products'); return res.json(); })
      .then(data => {
        const list = Array.isArray(data) ? data : (data.products || []);
        allProducts = list.map(normalizeProduct);
      })
      .catch(() => { allProducts = []; })
      .finally(filterProducts);
}

function renderProducts(products) {
  const tbody = document.getElementById('productsTableBody');
  const note = document.getElementById('placeholderNote');

  // Nothing real at all -> show placeholder rows
  if (allProducts.length === 0) {
    usingPlaceholderProducts = true;
    if (note) note.style.display = 'flex';
    tbody.innerHTML = PLACEHOLDER_PRODUCTS.map(p => `
      <tr class="is-placeholder">
        <td data-label="Photo"><img class="product-thumb" src="${PLACEHOLDER_IMG}" alt=""></td>
        <td data-label="Product"><strong>${esc(p.name)}</strong></td>
        <td data-label="Category">${esc(p.category_name)}</td>
        <td data-label="Farm">${esc(p.farm_name)}</td>
        <td data-label="Price">${peso(p.price_per_kg)}</td>
        <td data-label="Stock">${esc(p.stock_kg)} kg</td>
        <td data-label="Actions"><span style="font-size:0.8rem;">Sample only</span></td>
      </tr>`).join('');
    return;
  }

  usingPlaceholderProducts = false;
  if (note) note.style.display = 'none';

  if (products.length === 0) {
    tbody.innerHTML = `<tr><td colspan="7" class="empty-cell">No products match your search.</td></tr>`;
    return;
  }

  tbody.innerHTML = products.map(p => `
    <tr>
      <td data-label="Photo"><img class="product-thumb" src="${esc(p.image || PLACEHOLDER_IMG)}" alt="${esc(p.name)}" onerror="this.onerror=null;this.src='${PLACEHOLDER_IMG}'"></td>
      <td data-label="Product"><strong>${esc(p.name)}</strong></td>
      <td data-label="Category">${esc(p.category_name || 'N/A')}</td>
      <td data-label="Price">${peso(p.price)}</td>
      <td data-label="Stock">
        <input type="number" class="stock-input" value="${esc(p.stock)}" min="0" step="0.01"
          onchange="quickUpdateStock(${Number(p.id)}, this.value)" aria-label="Stock for ${esc(p.name)}">
      </td>
      <td data-label="Actions">
        <div class="row-actions">
          <button class="btn btn-sm btn-outline" onclick="editProduct(${Number(p.id)})" aria-label="Edit"><i class="fa-solid fa-pen"></i></button>
          <button class="btn btn-sm btn-danger" onclick="deleteProduct(${Number(p.id)})" aria-label="Delete"><i class="fa-solid fa-trash"></i></button>
        </div>
      </td>
    </tr>`).join('');
}

function filterProducts() {
  const search = (document.getElementById('productSearchInput')?.value || '').toLowerCase();
  const cat = document.getElementById('categoryFilter')?.value || '';

  renderProducts(allProducts.filter(p =>
      p.name.toLowerCase().includes(search) &&
      (!cat || String(p.category_id) === String(cat))
  ));
}

function openProductModal(product = null) {
  const preview = document.getElementById('photoPreview');
  document.getElementById('productFormError').textContent = '';
  document.getElementById('productForm').reset();

  if (!sellerFarmId) {
    document.getElementById('productFormError').textContent = 'You do not have a farm profile. Please contact support.';
    showToast('You do not have a farm profile. Please contact support.', 'error');
    openModal('productModal');
    return;
  }

  if (lookupCategories.length === 0) {
    document.getElementById('productFormError').textContent = 'No categories available. Please add a category first.';
    showToast('No categories available. Please add a category first.', 'error');
    return;
  }

  if (product) {
    document.getElementById('modalTitle').textContent = 'Edit Product';
    document.getElementById('productId').value = product.id;
    document.getElementById('productName').value = product.name;
    document.getElementById('productCategory').value = product.category_id || '';
    document.getElementById('productPrice').value = product.price;
    document.getElementById('productStock').value = product.stock;
    document.getElementById('productMinOrder').value = product.min_order;
    document.getElementById('productHarvest').value = (product.harvest_date || '').slice(0, 10);
    preview.innerHTML = product.image
        ? `<img src="${esc(product.image)}" alt="Preview">`
        : `<span style="color:#7d887c; font-size:0.75rem;">No Image</span>`;
  } else {
    document.getElementById('modalTitle').textContent = 'Add New Product';
    document.getElementById('productId').value = '';
    document.getElementById('productMinOrder').value = 1;
    document.getElementById('productHarvest').value = new Date().toISOString().slice(0, 10);
    preview.innerHTML = `<span style="color:#7d887c; font-size:0.75rem;">No Image</span>`;
  }
  openModal('productModal');
}

function readPhotoDataUrl() {
  const file = document.getElementById('productPhotoInput').files[0];
  return new Promise(resolve => {
    if (!file) return resolve(null);
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = () => resolve(null);
    reader.readAsDataURL(file);
  });
}

async function handleProductSubmit(e) {
  e.preventDefault();
  const errorEl = document.getElementById('productFormError');
  errorEl.textContent = '';

  if (!sellerFarmId) {
    errorEl.textContent = 'You do not have a farm profile. Please contact support.';
    showToast('You do not have a farm profile. Please contact support.', 'error');
    return;
  }

  const id = document.getElementById('productId').value;
  const existing = id ? allProducts.find(p => String(p.id) === String(id)) : null;

  // The API stores the photo as a short string (max 255 chars), so only keep
  // it if it fits; otherwise keep the existing photo.
  const photoData = await readPhotoDataUrl();
  const photo = photoData && photoData.length <= 255 ? photoData : (existing ? existing.image : null);

  const categoryId = parseInt(document.getElementById('productCategory').value, 10);
  const name = document.getElementById('productName').value.trim();
  const price = parseFloat(document.getElementById('productPrice').value);
  const minOrder = parseFloat(document.getElementById('productMinOrder').value);
  const stock = parseFloat(document.getElementById('productStock').value);
  const harvestDate = document.getElementById('productHarvest').value;

  // Frontend validation
  if (!categoryId || categoryId < 1) {
    errorEl.textContent = 'Please choose a valid category. You can add one using "Categories".';
    showToast('Please choose a valid category.', 'error');
    return;
  }
  if (!name || name.length === 0) {
    errorEl.textContent = 'Product name is required.';
    showToast('Product name is required.', 'error');
    return;
  }
  if (isNaN(price) || price <= 0) {
    errorEl.textContent = 'Price must be greater than 0.';
    showToast('Price must be greater than 0.', 'error');
    return;
  }
  if (isNaN(minOrder) || minOrder <= 0) {
    errorEl.textContent = 'Minimum order must be greater than 0.';
    showToast('Minimum order must be greater than 0.', 'error');
    return;
  }
  if (isNaN(stock) || stock < 0) {
    errorEl.textContent = 'Stock cannot be negative.';
    showToast('Stock cannot be negative.', 'error');
    return;
  }
  if (!harvestDate) {
    errorEl.textContent = 'Harvest date is required.';
    showToast('Harvest date is required.', 'error');
    return;
  }

  const payload = {
    farm_id: sellerFarmId,
    category_id: categoryId,
    name: name,
    price_per_kg: price,
    min_order_kg: minOrder,
    stock_kg: stock,
    harvest_date: harvestDate,
    photo: photo || null
  };

  const loadingToast = showToast(id ? 'Updating product...' : 'Saving product...', 'loading', 0);
  const submitBtn = e.target.querySelector('button[type="submit"]');
  const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
  }

  try {
    if (id) {
      await api(`../api/products/update.php?id=${encodeURIComponent(id)}`, { method: 'PUT', body: JSON.stringify(payload) });
      hideToast(loadingToast);
      showToast('Product updated successfully.', 'success');
      closeModal('productModal');
      await loadProducts();
    } else {
      await api('../api/products/create.php', { method: 'POST', body: JSON.stringify(payload) });
      hideToast(loadingToast);
      showToast('Product added successfully.', 'success');
      closeModal('productModal');
      await loadProducts();
    }
  } catch (err) {
    hideToast(loadingToast);
    const errorMessage = err.message || 'Failed to save product';
    errorEl.textContent = errorMessage;
    showToast(`Failed to save product: ${errorMessage}`, 'error');
  } finally {
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalBtnText;
    }
  }
}

window.quickUpdateStock = async function (id, newStock) {
  const p = allProducts.find(x => Number(x.id) === Number(id));
  if (!p) return;

  const stock = parseFloat(newStock);
  if (isNaN(stock) || stock < 0) {
    showToast('Stock cannot be negative.', 'error');
    loadProducts();
    return;
  }

  const loadingToast = showToast('Updating stock...', 'loading', 0);

  try {
    await api(`../api/products/update.php?id=${encodeURIComponent(id)}`, {
      method: 'PUT',
      body: JSON.stringify({
        farm_id: p.farm_id, category_id: p.category_id, name: p.name,
        price_per_kg: p.price, min_order_kg: p.min_order, harvest_date: (p.harvest_date || '').slice(0, 10),
        photo: p.image || null, stock_kg: stock
      })
    });
    hideToast(loadingToast);
    showToast('Stock updated successfully.', 'success');
    await loadProducts();
  } catch (err) {
    hideToast(loadingToast);
    const errorMessage = err.message || 'Failed to update stock';
    showToast(`Failed to update stock: ${errorMessage}`, 'error');
    loadProducts();
  }
};

window.editProduct = function (id) {
  const prod = allProducts.find(p => Number(p.id) === Number(id));
  if (prod) openProductModal(prod);
};

window.deleteProduct = async function (id) {
  if (!confirm('Are you sure you want to delete this product?')) return;

  const loadingToast = showToast('Deleting product...', 'loading', 0);

  try {
    await api(`../api/products/delete.php?id=${encodeURIComponent(id)}`, { method: 'DELETE' });
    hideToast(loadingToast);
    showToast('Product deleted successfully.', 'success');
    await loadProducts();
  } catch (err) {
    hideToast(loadingToast);
    const errorMessage = err.message || 'Failed to delete product';
    showToast(`Failed to delete product: ${errorMessage}`, 'error');
  }
};

/* ---------- Categories ---------- */
async function handleCategorySubmit(e) {
  e.preventDefault();
  const input = document.getElementById('newCatName');
  const name = input.value.trim();

  if (!name) {
    showToast('Category name is required.', 'error');
    return;
  }

  const loadingToast = showToast('Adding category...', 'loading', 0);
  const submitBtn = e.target.querySelector('button[type="submit"]');
  const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding...';
  }

  try {
    await api(`${LOOKUPS_URL}?action=add_category`, { method: 'POST', body: JSON.stringify({ name }) });
    hideToast(loadingToast);
    showToast('Category added successfully.', 'success');
    input.value = '';
    await loadCategories();
  } catch (err) {
    hideToast(loadingToast);
    const errorMessage = err.message || 'Failed to add category';
    showToast(`Failed to add category: ${errorMessage}`, 'error');
  } finally {
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalBtnText;
    }
  }
}

/* ==========================================================================
   4. Orders & Logistics
   ========================================================================== */
let allOrders = [];
let allProviders = [];

function initOrdersPage() {
  loadProviders();
  loadOrders();

  wire('orderSearchInput', 'input', filterOrders);
  wire('statusFilter', 'change', filterOrders);
  wire('orderSort', 'change', filterOrders);
  wire('closeOrderDetailModalBtn', 'click', () => closeModal('orderDetailModal'));

  // Deep-link to order details if valid order ID in URL
  const orderId = new URLSearchParams(window.location.search).get('id');
  if (orderId) {
    const orderIdNum = parseInt(orderId, 10);
    if (!isNaN(orderIdNum) && orderIdNum > 0) {
      setTimeout(() => viewOrderDetail(orderIdNum), 300);
    }
  }
}

function loadProviders() {
  fetch('../api/logistics_providers/read.php', { credentials: 'same-origin' })
      .then(res => { if (!res.ok) throw new Error('providers'); return res.json(); })
      .then(data => {
        allProviders = Array.isArray(data) ? data : (data.providers || []);
      })
      .catch(() => { allProviders = []; });
}

function normalizeOrder(o) {
  return {
    ...o,
    id: o.order_id ?? o.id,
    created_at: o.ordered_at ?? o.created_at,
    total_amount: o.total_amount ?? 0
  };
}

function loadOrders() {
  return fetch('../api/orders/list.php', { credentials: 'same-origin' })
      .then(res => { if (!res.ok) throw new Error('orders'); return res.json(); })
      .then(data => { allOrders = (data.orders || []).map(normalizeOrder); })
      .catch(() => { allOrders = []; })
      .finally(filterOrders);
}

function filterOrders() {
  const search = document.getElementById('orderSearchInput').value.toLowerCase();
  const status = document.getElementById('statusFilter').value;
  const sort = document.getElementById('orderSort').value;

  const filtered = allOrders.filter(o =>
      (String(o.id).includes(search) || (o.restaurant_name || '').toLowerCase().includes(search)) &&
      (!status || o.status === status)
  );

  if (sort === 'newest') filtered.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
  if (sort === 'oldest') filtered.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
  if (sort === 'total_high') filtered.sort((a, b) => b.total_amount - a.total_amount);

  renderOrders(filtered);
}

function renderOrders(orders) {
  const tbody = document.getElementById('ordersTableBody');
  if (!orders.length) {
    tbody.innerHTML = `<tr><td colspan="7" class="empty-cell">No orders found.</td></tr>`;
    return;
  }
  tbody.innerHTML = orders.map(o => {
    const status = o.status || 'pending';
    const date = o.created_at ? new Date(o.created_at).toLocaleDateString() : '-';
    return `
    <tr>
      <td data-label="Order ID"><strong>#${esc(o.id)}</strong></td>
      <td data-label="Date">${esc(date)}</td>
      <td data-label="Restaurant">${esc(o.restaurant_name || 'N/A')}</td>
      <td data-label="Provider">${esc(o.logistics_provider || 'Unassigned')}</td>
      <td data-label="Status"><span class="status-badge status-${esc(status)}">${esc(status.replace('_', ' '))}</span></td>
      <td data-label="Total">${peso(o.total_amount)}</td>
      <td data-label="Actions"><button class="btn btn-sm btn-primary" onclick="viewOrderDetail(${Number(o.id)})">Manage / View</button></td>
    </tr>`;
  }).join('');
}

window.viewOrderDetail = function (id) {
  fetch(`../api/orders/read.php?id=${encodeURIComponent(id)}`, { credentials: 'same-origin' })
      .then(res => { if (!res.ok) throw new Error('order'); return res.json(); })
      .then(raw => {
        const order = normalizeOrder(raw.order || raw);
        const status = order.status || 'pending';
        document.getElementById('modalOrderId').textContent = order.id;

        const delivery = order.delivery || null;
        const deliveryStatus = delivery ? delivery.delivery_status : 'pending';
        const currentProvider = delivery ? delivery.provider_name : 'Unassigned';
        const currentProviderId = delivery ? delivery.provider_id : null;
        const deliveryId = delivery ? delivery.delivery_id : null;

        const sel = (v, cur) => (v === cur ? 'selected' : '');
        const canAssignProvider = status === 'confirmed' && deliveryStatus === 'pending';

        let logisticsSection = '';
        if (deliveryId) {
          logisticsSection = `
        <h4 style="margin:1.25rem 0 0.5rem;">Delivery & Logistics</h4>
        <div class="detail-panel">
          <div>
            <h5>Delivery Status</h5>
            <div><strong>Status:</strong> <span class="status-badge status-${esc(deliveryStatus)}">${esc(deliveryStatus.replace('_', ' '))}</span></div>
            <div><strong>Provider:</strong> ${esc(currentProvider)}</div>
          </div>
          <div>
            <h5>Delivery ID</h5>
            <div><strong>#${esc(deliveryId)}</strong></div>
          </div>
        </div>`;

          if (canAssignProvider) {
            logisticsSection += `
        <h4 style="margin:1.25rem 0 0.5rem;">Assign Logistics Provider</h4>
        <form id="assignProviderForm" class="logistics-form" onsubmit="assignProvider(event, ${Number(deliveryId)}, ${Number(order.id)})">
          <div class="form-group">
            <label for="providerSelect">Select Provider</label>
            <select id="providerSelect" required>
              <option value="">Choose a provider...</option>
              ${allProviders.map(p =>
                `<option value="${esc(p.provider_id)}" ${sel(p.provider_id, currentProviderId)}>${esc(p.provider_name)} (${esc(p.provider_type)})</option>`
            ).join('')}
            </select>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">Assign Provider</button>
          </div>
        </form>`;
          }
        }

        document.getElementById('orderModalBody').innerHTML = `
        <div class="detail-panel">
          <div>
            <h5>Restaurant Info</h5>
            <div><strong>${esc(order.restaurant_name)}</strong></div>
            <div class="sub">${esc(order.delivery_address || 'Address on file')}</div>
            <div class="sub">Contact: ${esc(order.contact_phone || 'N/A')}</div>
          </div>
          <div>
            <h5>Order Summary</h5>
            <div>Status: <span class="status-badge status-${esc(status)}">${esc(status.replace('_', ' '))}</span></div>
            <div>Date: ${order.created_at ? esc(new Date(order.created_at).toLocaleString()) : '-'}</div>
            <div>Total: <strong>${peso(order.total_amount)}</strong></div>
          </div>
        </div>

        <h4 style="margin:0 0 0.5rem;">Ordered Products</h4>
        <div class="table-responsive" style="margin-bottom:1.25rem;">
          <table class="seller-table">
            <thead><tr><th>Product</th><th>Quantity</th><th>Unit Price</th><th>Subtotal</th></tr></thead>
            <tbody>
              ${(order.items || []).map(item => {
          const price = item.unit_price ?? item.price_per_kg ?? 0;
          const quantity = item.quantity_kg ?? item.quantity ?? 0;
          const subtotal = item.subtotal ?? (quantity * price);
          return `<tr>
                  <td data-label="Product">${esc(item.product_name || item.name)}</td>
                  <td data-label="Quantity">${esc(quantity)} kg</td>
                  <td data-label="Unit Price">${peso(price)}</td>
                  <td data-label="Subtotal">${peso(subtotal)}</td>
                </tr>`;
        }).join('') || '<tr><td colspan="4" class="empty-cell">No items.</td></tr>'}
            </tbody>
          </table>
        </div>

        ${logisticsSection}

        <h4 style="margin:1.25rem 0 0.5rem;">Order Status Update</h4>
        <form id="logisticsForm" class="logistics-form" onsubmit="updateLogistics(event, ${Number(order.id)})">
          <div class="form-row">
            <div class="form-group">
              <label for="orderStatusSelect">Order Status</label>
              <select id="orderStatusSelect">
                ${['pending', 'confirmed', 'in_transit', 'delivered', 'cancelled'].map(s =>
            `<option value="${s}" ${sel(s, status)}>${s.replace('_', ' ').replace(/^./, c => c.toUpperCase())}</option>`).join('')}
              </select>
            </div>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">Update Status</button>
          </div>
        </form>`;

        openModal('orderDetailModal');
      })
      .catch(() => showToast('Could not load this order.', 'error'));
};

window.updateLogistics = function (e, id) {
  e.preventDefault();
  const loadingToast = showToast('Updating order status...', 'loading', 0);

  fetch('../api/orders/update.php?id=' + encodeURIComponent(id), {
    method: 'PUT',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      status: document.getElementById('orderStatusSelect').value
    })
  })
      .then(res => res.json())
      .then(data => {
        if (data.success || data.ok) {
          hideToast(loadingToast);
          showToast('Order status updated successfully.', 'success');
          closeModal('orderDetailModal');
          loadOrders();
        } else {
          hideToast(loadingToast);
          showToast('Could not update the order: ' + (data.error || 'Unknown error'), 'error');
        }
      })
      .catch(err => {
        hideToast(loadingToast);
        showToast('Could not update the order.', 'error');
      });
};

window.assignProvider = function (e, deliveryId, orderId) {
  e.preventDefault();
  const providerId = document.getElementById('providerSelect').value;

  if (!providerId) {
    showToast('Please select a provider.', 'error');
    return;
  }

  const loadingToast = showToast('Assigning provider...', 'loading', 0);

  fetch(`../api/deliveries/assign.php?id=${encodeURIComponent(deliveryId)}`, {
    method: 'PATCH',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      provider_id: parseInt(providerId, 10)
    })
  })
      .then(res => res.json())
      .then(data => {
        if (data.success || data.ok) {
          hideToast(loadingToast);
          showToast('Provider assigned successfully!', 'success');
          closeModal('orderDetailModal');
          viewOrderDetail(orderId);
        } else {
          hideToast(loadingToast);
          showToast('Could not assign provider: ' + (data.error || 'Unknown error'), 'error');
        }
      })
      .catch(err => {
        hideToast(loadingToast);
        showToast('Could not assign provider.', 'error');
      });
};
