/**
 * SproutRoute Logistics Module
 * Dashboard for managing delivery assignments and tracking shipment progress
 */

document.addEventListener('DOMContentLoaded', () => {
    initModalHelpers();
    initDashboard();
});

/* ==========================================================================
   Helpers
   ========================================================================== */
function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]
    ));
}

function openModal(id) {
    const m = document.getElementById(id);
    if (m) { m.classList.add('active'); document.body.style.overflow = 'hidden'; }
}

function closeModal(id) {
    const m = document.getElementById(id);
    if (m) m.classList.remove('active');
    if (!document.querySelector('.seller-modal.active')) document.body.style.overflow = '';
}

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
        throw new Error(msg);
    }
    return data;
}

/* ==========================================================================
   Dashboard
   ========================================================================== */
let allDeliveries = [];
let myProviders = [];

function initDashboard() {
    loadMyProviders();
    loadDeliveries();

    wire('statusFilter', 'change', filterDeliveries);
    wire('closeDeliveryDetailModalBtn', 'click', () => closeModal('deliveryDetailModal'));
}

function loadMyProviders() {
    fetch('../api/logistics_providers/current.php', { credentials: 'same-origin' })
        .then(res => { if (!res.ok) throw new Error('providers'); return res.json(); })
        .then(data => {
            myProviders = data.providers || [];
        })
        .catch(() => { myProviders = []; });
}

function loadDeliveries() {
    fetch('../api/deliveries/provider.php', { credentials: 'same-origin' })
        .then(res => { if (!res.ok) throw new Error('deliveries'); return res.json(); })
        .then(data => {
            allDeliveries = Array.isArray(data) ? data : [];
            updateStats();
            filterDeliveries();
        })
        .catch(() => {
            allDeliveries = [];
            document.getElementById('deliveriesTableBody').innerHTML =
                `<tr><td colspan="7" class="empty-cell">Could not load deliveries. Check your connection.</td></tr>`;
        });
}

function updateStats() {
    const assigned = allDeliveries.filter(d => d.status === 'assigned').length;
    const inTransit = allDeliveries.filter(d => d.status === 'in_transit').length;
    const delivered = allDeliveries.filter(d => d.status === 'delivered').length;
    const pending = allDeliveries.filter(d => d.status === 'assigned' || d.status === 'picked_up').length;

    document.getElementById('statAssigned').textContent = assigned;
    document.getElementById('statInTransit').textContent = inTransit;
    document.getElementById('statDelivered').textContent = delivered;
    document.getElementById('statPending').textContent = pending;
}

function filterDeliveries() {
    const status = document.getElementById('statusFilter').value;

    const filtered = allDeliveries.filter(d => !status || d.status === status);
    renderDeliveries(filtered);
}

function renderDeliveries(deliveries) {
    const tbody = document.getElementById('deliveriesTableBody');
    if (!deliveries.length) {
        tbody.innerHTML = `<tr><td colspan="7" class="empty-cell">No deliveries found.</td></tr>`;
        return;
    }

    tbody.innerHTML = deliveries.map(d => {
        const status = d.status || 'pending';
        const pickupTime = d.pickup_at ? new Date(d.pickup_at).toLocaleString() : '-';
        return `
    <tr>
      <td data-label="Delivery ID"><strong>#${esc(d.delivery_id)}</strong></td>
      <td data-label="Order ID">#${esc(d.order_id)}</td>
      <td data-label="Restaurant">${esc(d.restaurant_name || 'N/A')}</td>
      <td data-label="Farm">${esc(d.farm_name || 'N/A')}</td>
      <td data-label="Status"><span class="status-badge status-${esc(status)}">${esc(status.replace('_', ' '))}</span></td>
      <td data-label="Pickup Time">${esc(pickupTime)}</td>
      <td data-label="Actions"><button class="btn btn-sm btn-primary" onclick="viewDeliveryDetail(${Number(d.delivery_id)})">View / Update</button></td>
    </tr>`;
    }).join('');
}

window.viewDeliveryDetail = function (id) {
    fetch(`../api/deliveries/read.php?id=${encodeURIComponent(id)}`, { credentials: 'same-origin' })
        .then(res => { if (!res.ok) throw new Error('delivery'); return res.json(); })
        .then(delivery => {
            const status = delivery.status || 'pending';
            document.getElementById('modalDeliveryId').textContent = delivery.delivery_id;

            const allowedTransitions = {
                'pending': ['assigned', 'cancelled'],
                'assigned': ['picked_up', 'cancelled'],
                'picked_up': ['in_transit', 'cancelled'],
                'in_transit': ['delivered', 'cancelled'],
                'delivered': [],
                'cancelled': []
            };

            const nextStatuses = allowedTransitions[status] || [];

            const sel = (v, cur) => (v === cur ? 'selected' : '');
            document.getElementById('deliveryModalBody').innerHTML = `
        <div class="detail-panel">
          <div>
            <h5>Delivery Information</h5>
            <div><strong>Delivery ID:</strong> #${esc(delivery.delivery_id)}</div>
            <div><strong>Order ID:</strong> #${esc(delivery.order_id)}</div>
            <div><strong>Current Status:</strong> <span class="status-badge status-${esc(status)}">${esc(status.replace('_', ' '))}</span></div>
            <div><strong>Provider:</strong> ${esc(delivery.provider_name || 'Unassigned')}</div>
          </div>
          <div>
            <h5>Locations</h5>
            <div><strong>Restaurant:</strong> ${esc(delivery.restaurant_name || 'N/A')}</div>
            <div><strong>Address:</strong> ${esc(delivery.restaurant_address || 'N/A')}</div>
            <div><strong>Farm:</strong> ${esc(delivery.farm_name || 'N/A')}</div>
          </div>
        </div>

        <div class="detail-panel">
          <div>
            <h5>Timestamps</h5>
            <div><strong>Pickup At:</strong> ${delivery.pickup_at ? esc(new Date(delivery.pickup_at).toLocaleString()) : 'Not yet'}</div>
            <div><strong>Estimated Arrival:</strong> ${delivery.estimated_arrival ? esc(new Date(delivery.estimated_arrival).toLocaleString()) : 'Not set'}</div>
            <div><strong>Delivered At:</strong> ${delivery.delivered_at ? esc(new Date(delivery.delivered_at).toLocaleString()) : 'Not yet'}</div>
          </div>
          <div>
            <h5>Order Status</h5>
            <div><strong>Order Status:</strong> <span class="status-badge status-${esc(delivery.order_status || 'pending')}">${esc((delivery.order_status || 'pending').replace('_', ' '))}</span></div>
          </div>
        </div>

        <h4 style="margin:0 0 0.5rem;">Update Delivery Status</h4>
        <form id="deliveryStatusForm" class="logistics-form" onsubmit="updateDeliveryStatus(event, ${Number(delivery.delivery_id)})">
          <div class="form-row">
            <div class="form-group">
              <label for="deliveryStatusSelect">New Status</label>
              <select id="deliveryStatusSelect">
                ${nextStatuses.map(s =>
                `<option value="${s}">${s.replace('_', ' ').replace(/^./, c => c.toUpperCase())}</option>`).join('')}
              </select>
            </div>
          </div>
          <div class="form-group">
            <label for="deliveryRemarks">Remarks (optional)</label>
            <input type="text" id="deliveryRemarks" placeholder="Add any notes about this delivery..." maxlength="255">
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">Update Status</button>
          </div>
        </form>`;

            openModal('deliveryDetailModal');
        })
        .catch(() => alert('Could not load this delivery.'));
};

window.updateDeliveryStatus = function (e, id) {
    e.preventDefault();
    const newStatus = document.getElementById('deliveryStatusSelect').value;
    const remarks = document.getElementById('deliveryRemarks').value.trim() || null;

    fetch(`../api/deliveries/update_status.php?id=${encodeURIComponent(id)}`, {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            status: newStatus,
            remarks: remarks
        })
    })
        .then(res => res.json())
        .then(data => {
            if (data.success || data.ok) {
                alert('Delivery status updated successfully.');
                closeModal('deliveryDetailModal');
                loadDeliveries();
            } else {
                alert('Could not update the delivery: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(() => alert('Could not update the delivery.'));
};
