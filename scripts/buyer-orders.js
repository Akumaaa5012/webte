(function () {
  "use strict";

  const CART_KEY = "sproutroute.cart";
  const LOCAL_ORDERS_KEY = "sproutroute.localOrders";
  const SCHEDULES_KEY = "sproutroute.localSchedules";
  const CONFIRMATION_KEY = "sproutroute.lastConfirmation";
  const RESTAURANT_KEY = "sproutroute.restaurantId";
  const OFFLINE_USER_KEY = "sproutroute.offlineUser";

  const page = document.body && document.body.getAttribute("data-page");

  function text(value) {
    return value === null || value === undefined ? "" : String(value).trim();
  }

  function number(value, fallback) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : fallback;
  }

  function todayIso() {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, "0");
    const day = String(now.getDate()).padStart(2, "0");
    return now.getFullYear() + "-" + month + "-" + day;
  }

  function nowDateTime() {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, "0");
    const minutes = String(now.getMinutes()).padStart(2, "0");
    const seconds = String(now.getSeconds()).padStart(2, "0");
    return todayIso() + " " + hours + ":" + minutes + ":" + seconds;
  }

  function makeUuid() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return window.crypto.randomUUID();
    }
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, function (char) {
      const rand = Math.random() * 16 | 0;
      const value = char === "x" ? rand : (rand & 0x3 | 0x8);
      return value.toString(16);
    });
  }

  function readJson(key, fallback) {
    try {
      const raw = window.localStorage.getItem(key);
      if (!raw) {
        return fallback;
      }
      const parsed = JSON.parse(raw);
      return parsed === null || parsed === undefined ? fallback : parsed;
    } catch (error) {
      return fallback;
    }
  }

  function writeJson(key, value) {
    window.localStorage.setItem(key, JSON.stringify(value));
  }

  function money(amount) {
    return new Intl.NumberFormat(undefined, {
      style: "currency",
      currency: "PHP"
    }).format(number(amount, 0));
  }

  function el(tag, className, content) {
    const node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (content !== undefined && content !== null) {
      node.textContent = content;
    }
    return node;
  }

  function setMessage(message, isError) {
    const box = document.getElementById("page-message");
    if (!box) {
      return;
    }
    box.textContent = message || "";
    if (isError) {
      box.setAttribute("role", "alert");
    } else {
      box.setAttribute("role", "status");
    }
  }

  async function tryApi(url, options) {
    const request = options ? Object.assign({}, options) : {};
    request.credentials = "same-origin";
    request.headers = Object.assign(
        { Accept: "application/json" },
        request.headers || {}
    );

    try {
      const response = await fetch(url, request);
      const raw = await response.text();
      if (!raw.trim()) {
        return { ok: false, empty: true, status: response.status };
      }
      let data;
      try {
        data = JSON.parse(raw);
      } catch (error) {
        return { ok: false, empty: true, status: response.status };
      }
      if (!response.ok || data.ok === false) {
        return {
          ok: false,
          status: response.status,
          error: data.error || ("Request failed (" + response.status + ")."),
          data: data
        };
      }
      return { ok: true, status: response.status, data: data };
    } catch (error) {
      return { ok: false, network: true, error: error.message };
    }
  }

  function cartItems() {
    const items = readJson(CART_KEY, []);
    return Array.isArray(items) ? items : [];
  }

  function saveCart(items) {
    writeJson(CART_KEY, items);
    updateCartCount();
  }

  function cartCount() {
    return cartItems().reduce(function (sum, item) {
      return sum + 1;
    }, 0);
  }

  function updateCartCount() {
    const count = cartCount();
    document.querySelectorAll("[data-cart-count]").forEach(function (node) {
      node.textContent = count ? "(" + count + ")" : "";
    });
  }

  function groupedCart(items) {
    const groups = [];
    const index = {};
    (items || cartItems()).forEach(function (item) {
      const key = String(item.farmId || "unknown");
      if (!index[key]) {
        index[key] = {
          farmId: item.farmId,
          farmName: item.farmName || "Unknown farm",
          items: []
        };
        groups.push(index[key]);
      }
      index[key].items.push(item);
    });
    return groups;
  }

  function lineTotal(item) {
    return number(item.price, 0) * number(item.quantity, 0);
  }

  function groupTotal(group) {
    return group.items.reduce(function (sum, item) {
      return sum + lineTotal(item);
    }, 0);
  }

  function addToCart(product, quantity) {
    const qty = number(quantity, 0);
    const minQty = number(product.minOrderKg || product.minimumOrderQuantity, 1);
    if (!(qty > 0)) {
      throw new Error("Enter a quantity greater than zero.");
    }
    if (qty + 1e-9 < minQty) {
      throw new Error("This product requires a minimum of " + minQty + " kg.");
    }

    const productId = text(product.id || product.productId);
    if (!productId) {
      throw new Error("This product is missing an ID.");
    }

    const items = cartItems();
    const existing = items.find(function (item) {
      return String(item.productId) === productId;
    });

    if (existing) {
      const nextQty = number(existing.quantity, 0) + qty;
      if (existing.stockKg !== null && nextQty > existing.stockKg) {
        throw new Error("Only " + existing.stockKg + " kg is available.");
      }
      existing.quantity = nextQty;
    } else {
      const stock = product.stockKg === null || product.stockKg === undefined
          ? product.stock
          : product.stockKg;
      items.push({
        productId: productId,
        name: text(product.name),
        farmId: number(product.farmId || product.farm_id, 0),
        farmName: text(product.farmName || product.farm),
        price: number(product.price, 0),
        unit: text(product.unit) || "kg",
        minOrderKg: minQty,
        stockKg: stock === null || stock === undefined ? null : number(stock, null),
        quantity: qty,
        image: text(product.image)
      });
    }

    saveCart(items);
    return items;
  }

  function updateQuantity(productId, quantity) {
    const items = cartItems();
    const item = items.find(function (entry) {
      return String(entry.productId) === String(productId);
    });
    if (!item) {
      return items;
    }
    const qty = number(quantity, 0);
    if (qty <= 0) {
      return removeItem(productId);
    }
    if (qty + 1e-9 < number(item.minOrderKg, 1)) {
      throw new Error(item.name + " requires a minimum of " + item.minOrderKg + " kg.");
    }
    if (item.stockKg !== null && qty > item.stockKg) {
      throw new Error("Only " + item.stockKg + " kg of " + item.name + " is available.");
    }
    item.quantity = qty;
    saveCart(items);
    return items;
  }

  function removeItem(productId) {
    const items = cartItems().filter(function (item) {
      return String(item.productId) !== String(productId);
    });
    saveCart(items);
    return items;
  }

  const Offline = window.SproutRouteOffline;

  function localOrders() {
    const rows = readJson(LOCAL_ORDERS_KEY, []);
    return Array.isArray(rows) ? rows : [];
  }

  function saveLocalOrders(rows) {
    writeJson(LOCAL_ORDERS_KEY, rows);
  }

  function upsertLocalOrder(order) {
    const rows = localOrders();
    const index = rows.findIndex(function (row) {
      return row.client_uuid === order.client_uuid || (order.id && row.id === order.id);
    });
    if (index >= 0) {
      rows[index] = Object.assign({}, rows[index], order);
    } else {
      rows.unshift(order);
    }
    saveLocalOrders(rows);
    return order;
  }

  function localSchedules() {
    const rows = readJson(SCHEDULES_KEY, []);
    return Array.isArray(rows) ? rows : [];
  }

  function saveLocalSchedules(rows) {
    writeJson(SCHEDULES_KEY, rows);
  }

  function saveOfflineUser(user) {
    if (!user || !user.user_id) {
      return;
    }
    const existing = getOfflineUser();
    writeJson(OFFLINE_USER_KEY, {
      user_id: user.user_id,
      restaurant_id: user.restaurant_id || (existing && existing.restaurant_id) || null,
      role: user.role || (existing && existing.role) || "restaurant",
      verified_at: new Date().toISOString()
    });
  }

  function getOfflineUser() {
    const stored = readJson(OFFLINE_USER_KEY, null);
    if (!stored || !stored.user_id || !stored.restaurant_id) {
      return null;
    }
    return stored;
  }

  function clearOfflineUser() {
    window.localStorage.removeItem(OFFLINE_USER_KEY);
  }

  function logout() {
    clearOfflineUser();
    window.localStorage.removeItem(RESTAURANT_KEY);
    return fetch("../api/auth/logout.php", {
      method: "POST",
      credentials: "same-origin"
    }).catch(function () {
      return { ok: true };
    });
  }

  const OrdersApi = {
    async currentUser() {
      const session = await tryApi("../api/auth/session.php");
      if (session.ok && session.data && session.data.user) {
        saveOfflineUser(session.data.user);
        return session.data.user;
      }
      // Only use offline context if the request failed due to network issues, not auth errors
      // 401 = unauthorized, 403 = forbidden - these mean the session is invalid/expired
      if (session.status === 401 || session.status === 403) {
        clearOfflineUser();
        return null;
      }
      const offlineUser = getOfflineUser();
      if (offlineUser) {
        return {
          user_id: offlineUser.user_id,
          restaurant_id: offlineUser.restaurant_id,
          role: offlineUser.role
        };
      }
      return null;
    },

    async restaurantId() {
      const stored = number(readJson(RESTAURANT_KEY, 0), 0);
      if (stored > 0) {
        return stored;
      }
      const user = await OrdersApi.currentUser();
      if (user) {
        if (user.restaurant_id) {
          writeJson(RESTAURANT_KEY, user.restaurant_id);
          // Update offline context with the restaurant_id
          saveOfflineUser(user);
          return user.restaurant_id;
        }
        if (Offline.isOnline()) {
          const lookups = await tryApi("../api/lookups/restaurants.php");
          const restaurants = lookups.ok
              ? (lookups.data.restaurants || [])
              : [];
          const match = restaurants.find(function (row) {
            return Number(row.user_id) === Number(user.user_id);
          });
          if (match) {
            const id = number(match.restaurant_id || match.id, 1);
            writeJson(RESTAURANT_KEY, id);
            // Update offline context with the restaurant_id
            saveOfflineUser({
              user_id: user.user_id,
              restaurant_id: id,
              role: user.role
            });
            return id;
          }
        }
      }
      const offlineUser = getOfflineUser();
      if (offlineUser && offlineUser.restaurant_id) {
        writeJson(RESTAURANT_KEY, offlineUser.restaurant_id);
        return offlineUser.restaurant_id;
      }
      throw new Error("Could not determine your restaurant. Please sign in online first.");
    },

    buildPayload: function (group, details, restaurantId) {
      const items = group.items.map(function (item) {
        return {
          product_id: Number(item.productId),
          quantity_kg: number(item.quantity, 0),
          unit_price: number(item.price, 0),
          name: item.name
        };
      });
      const total = items.reduce(function (sum, item) {
        return sum + item.quantity_kg * item.unit_price;
      }, 0);

      return {
        client_uuid: makeUuid(),
        restaurant_id: restaurantId,
        farm_id: group.farmId,
        farm_name: group.farmName,
        status: "pending",
        ordered_at: nowDateTime(),
        requested_date: details.requestedDate,
        delivery_notes: details.notes,
        delivery_lat: details.lat,
        delivery_lng: details.lng,
        items: items,
        total_amount: Number(total.toFixed(2))
      };
    },

    recordResult(queued, stored, results) {
      const result = (results || []).find(function (row) {
        return row.client_uuid === queued.client_uuid;
      });

      if (result && (result.status === "created" || result.status === "duplicate") && result.order) {
        const submitted = Object.assign({}, queued, result.order, {
          id: result.order.id || result.order.order_id || queued.id,
          status: result.order.status || "pending",
          syncState: "synced",
          submitted: true
        });
        upsertLocalOrder(submitted);
        return { ok: true, order: submitted };
      }

      const pending = Object.assign({}, queued, stored || {}, {
        status: "waiting_to_sync"
      });
      upsertLocalOrder(pending);
      return { ok: false, order: pending };
    },

    async placeGroups(groups, details) {
      const user = await OrdersApi.currentUser();
      if (!user) {
        const offlineUser = getOfflineUser();
        if (offlineUser) {
          throw new Error("Could not verify your restaurant. Please sign in online first.");
        }
        throw new Error("You must sign in online before using offline checkout. Orders will be saved and synced once you reconnect.");
      }
      const restaurantId = await OrdersApi.restaurantId();
      if (!restaurantId) {
        throw new Error("Could not determine your restaurant. Please sign in online first.");
      }
      const queuedOrders = [];
      for (let i = 0; i < groups.length; i += 1) {
        const payload = OrdersApi.buildPayload(groups[i], details, restaurantId);
        queuedOrders.push(await Offline.queueOrder(payload, user.user_id));
      }

      const results = await Offline.retryAll();
      const placed = [];
      for (let i = 0; i < queuedOrders.length; i += 1) {
        const queued = queuedOrders[i];
        const stored = await Offline.getOrder(queued.client_uuid);
        placed.push(OrdersApi.recordResult(queued, stored, results).order);
      }
      return placed;
    },

    async list() {
      const pending = await Offline.listPending();
      const local = localOrders();
      let remote = [];
      let apiSucceeded = false;

      if (Offline.isOnline()) {
        const listed = await tryApi("../api/orders/list.php");
        if (listed.ok) {
          remote = listed.data.orders || listed.data.data || [];
          Offline.cacheOrders(remote);
          apiSucceeded = true;
        } else {
          // Only use cached orders if network request failed (not if API returned empty)
          remote = Offline.getCachedOrders();
        }
      } else {
        // Offline: use cached orders
        remote = Offline.getCachedOrders();
      }

      const merged = [];
      const seen = {};

      function push(order, source) {
        if (!order) {
          return;
        }
        const key = order.client_uuid || ("id-" + (order.id || order.order_id));
        if (seen[key]) {
          return;
        }
        seen[key] = true;
        merged.push(normalizeListedOrder(order, source));
      }

      // Always include genuine pending/failed orders from IndexedDB
      pending.forEach(function (order) {
        push(order, "queue");
      });

      // Include server orders (either fresh from API or cached)
      remote.forEach(function (order) {
        push(order, "remote");
      });

      // Only merge localStorage orders when API failed or offline
      // When API succeeds, localStorage historical snapshots are stale
      if (!apiSucceeded) {
        local.forEach(function (order) {
          // Exclude cancelled orders from active list
          if (order.syncState !== "synced" && order.syncState !== "cancelled") {
            push(order, "local");
          }
        });
      }

      merged.sort(function (a, b) {
        return String(b.ordered_at || "").localeCompare(String(a.ordered_at || ""));
      });
      return merged;
    },

    async cancel(order) {
      if (order.syncState === "queued" || order.syncState === "failed" || order.syncState === "syncing") {
        await Offline.removeOrder(order.client_uuid);
      }
      const cancelled = Object.assign({}, order, {
        status: "cancelled",
        syncState: order.syncState === "synced" ? "synced" : "cancelled"
      });
      upsertLocalOrder(cancelled);

      if (order.id && Offline.isOnline()) {
        const result = await tryApi("../api/orders/update.php?id=" + encodeURIComponent(order.id), {
          method: "PUT",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            status: "cancelled"
          })
        });
        if (!result.ok) {
          setMessage("Could not cancel order: " + (result.error || "Unknown error"), true);
          return order; // Return original order on failure
        }
      }
      return cancelled;
    },

    async fixRestaurantId(order) {
      const user = await OrdersApi.currentUser();
      if (!user) {
        throw new Error("You must be logged in to fix this order.");
      }

      // Verify the order belongs to the current user
      if (order.user_id && Number(order.user_id) !== Number(user.user_id)) {
        throw new Error("This order was created by another user and cannot be reassigned.");
      }

      // For legacy orders without user_id, we can't verify ownership
      // The user must confirm they want to reassign it
      if (!order.user_id) {
        const confirmed = confirm(
            "This order was created before user tracking was added. " +
            "It may have been created by a different account. " +
            "Do you want to reassign it to your current restaurant?"
        );
        if (!confirmed) {
          throw new Error("Fix cancelled by user.");
        }
      }

      // Get the user's verified restaurant_id
      const correctRestaurantId = await OrdersApi.restaurantId();

      // Update the order in IndexedDB
      const updated = await Offline.updateOrderRestaurantId(order.client_uuid, correctRestaurantId);
      if (!updated) {
        throw new Error("Could not find the order in offline storage.");
      }

      // Also update localStorage for local orders
      const local = localOrders();
      const index = local.findIndex(function (row) {
        return row.client_uuid === order.client_uuid;
      });
      if (index >= 0) {
        local[index].restaurant_id = correctRestaurantId;
        local[index].syncState = "queued";
        local[index].lastError = "";
        saveLocalOrders(local);
      }

      return updated;
    }
  };

  function normalizeListedOrder(order, source) {
    const syncState = order.syncState || (source === "queue" ? "queued" : "synced");
    let status = text(order.status) || "pending";
    if (syncState === "queued" || syncState === "syncing" || syncState === "failed") {
      status = "waiting_to_sync";
    }
    return {
      id: order.id || order.order_id || null,
      client_uuid: order.client_uuid,
      farm_id: order.farm_id || order.farmId,
      farm_name: order.farm_name || order.farmName || "Farm order",
      status: status,
      serverStatus: order.status || "pending",
      syncState: syncState,
      submitted: syncState === "synced",
      total_amount: number(order.total_amount, 0),
      requested_date: order.requested_date,
      ordered_at: order.ordered_at || order.queuedAt || new Date().toISOString(),
      lastError: order.lastError || "",
      items: order.items || [],
      delivery_notes: order.delivery_notes || "",
      source: source
    };
  }

  function statusLabel(order) {
    if (order.syncState === "queued" || order.syncState === "syncing") {
      return "Waiting to synchronize";
    }
    if (order.syncState === "failed") {
      return "Sync failed";
    }
    if (order.status === "cancelled") {
      return "Cancelled";
    }
    if (order.submitted || order.syncState === "synced") {
      return order.serverStatus === "pending" ? "Submitted (pending farm)" : titleCase(order.serverStatus);
    }
    return titleCase(order.status);
  }

  function statusClass(order) {
    if (order.status === "cancelled") {
      return "status status-cancelled";
    }
    if (order.syncState === "queued" || order.syncState === "syncing" || order.syncState === "failed") {
      return "status status-waiting-to-sync";
    }
    if (order.submitted || order.syncState === "synced") {
      return "status status-submitted";
    }
    return "status status-pending";
  }

  function titleCase(value) {
    return text(value).replace(/_/g, " ").replace(/\b\w/g, function (char) {
      return char.toUpperCase();
    });
  }

  function refreshConnectionBanner() {
    if (window.SproutRouteConnection && window.SproutRouteConnection.updateBanner) {
      window.SproutRouteConnection.updateBanner();
    }
  }

  function bindConnection() {
    window.addEventListener("sproutroute:orders-synced", function () {
      refreshConnectionBanner();
      if (page === "orders") {
        renderOrdersPage();
      }
    });
  }

  function renderEmpty(container, heading, message) {
    container.replaceChildren();
    container.append(el("h2", "", heading), el("p", "", message));
  }

  function renderCartPage() {
    const container = document.getElementById("cart-content");
    if (!container) {
      return;
    }
    const items = cartItems();
    if (items.length === 0) {
      renderEmpty(container, "Your cart is empty", "Browse products and add items to start an order.");
      return;
    }

    container.replaceChildren();
    groupedCart(items).forEach(function (group) {
      const article = el("article", "farm-group");
      article.append(el("h2", "", group.farmName));
      article.append(el("p", "", "This farm will be placed as its own order at checkout."));

      group.items.forEach(function (item) {
        const row = el("div", "cart-item");
        row.append(el("h3", "", item.name));
        row.append(el("p", "", money(item.price) + " / " + item.unit));

        const controls = el("div", "cart-item-row");
        const label = el("label", "", "Quantity (" + item.unit + ")");
        const input = document.createElement("input");
        input.type = "number";
        input.min = String(item.minOrderKg);
        input.step = "0.5";
        input.value = String(item.quantity);
        input.setAttribute("aria-label", "Quantity for " + item.name);
        input.addEventListener("change", function () {
          try {
            updateQuantity(item.productId, input.value);
            setMessage("");
            renderCartPage();
          } catch (error) {
            setMessage(error.message, true);
            input.value = String(item.quantity);
          }
        });
        label.append(input);

        const remove = el("button", "button button-secondary", "Remove");
        remove.type = "button";
        remove.addEventListener("click", function () {
          removeItem(item.productId);
          setMessage(item.name + " was removed from your cart.");
          renderCartPage();
        });

        controls.append(label, el("strong", "", money(lineTotal(item))), remove);
        row.append(controls);
        row.append(el("p", "", "Minimum order: " + item.minOrderKg + " " + item.unit));
        article.append(row);
      });

      article.append(el("p", "", "Farm subtotal: " + money(groupTotal(group))));
      container.append(article);
    });
  }

  function renderCheckoutPage() {
    const container = document.getElementById("checkout-content");
    if (!container) {
      return;
    }
    const items = cartItems();
    if (items.length === 0) {
      renderEmpty(container, "Nothing to check out", "Add products to your cart before placing an order.");
      return;
    }

    container.replaceChildren();
    const groups = groupedCart(items);
    groups.forEach(function (group) {
      const article = el("article", "farm-group");
      article.append(el("h2", "", group.farmName));
      const list = document.createElement("ul");
      group.items.forEach(function (item) {
        const line = document.createElement("li");
        line.textContent = item.quantity + " " + item.unit + " of " + item.name + " — " + money(lineTotal(item));
        list.append(line);
      });
      article.append(list);
      article.append(el("p", "", "Order total: " + money(groupTotal(group))));
      container.append(article);
    });

    const form = document.createElement("form");
    form.id = "checkout-form";

    function field(id, labelText, input) {
      const wrap = el("div", "field");
      const label = el("label", "", labelText);
      label.setAttribute("for", id);
      input.id = id;
      wrap.append(label, input);
      return wrap;
    }

    const dateInput = document.createElement("input");
    dateInput.type = "date";
    dateInput.required = true;
    dateInput.min = todayIso();
    dateInput.value = todayIso();

    const notes = document.createElement("textarea");
    notes.rows = 4;
    notes.placeholder = "Gate code, receiving hours, or handling notes";

    const lat = document.createElement("input");
    lat.type = "number";
    lat.step = "0.000001";
    lat.placeholder = "Latitude";
    const lng = document.createElement("input");
    lng.type = "number";
    lng.step = "0.000001";
    lng.placeholder = "Longitude";

    const gpsButton = el("button", "button button-secondary", "Use my location");
    gpsButton.type = "button";
    gpsButton.addEventListener("click", function () {
      if (!navigator.geolocation) {
        setMessage("GPS is not available in this browser.", true);
        return;
      }
      gpsButton.disabled = true;
      navigator.geolocation.getCurrentPosition(
          function (position) {
            lat.value = String(position.coords.latitude);
            lng.value = String(position.coords.longitude);
            gpsButton.disabled = false;
            setMessage("Delivery location captured from GPS.");
          },
          function () {
            gpsButton.disabled = false;
            setMessage("Could not read your location. You can enter coordinates manually.", true);
          },
          { enableHighAccuracy: true, timeout: 10000 }
      );
    });

    const submit = el("button", "button", "Place " + groups.length + " separate order" + (groups.length === 1 ? "" : "s"));
    submit.type = "submit";

    form.append(
        field("requested-date", "Requested delivery date", dateInput),
        field("delivery-notes", "Delivery notes", notes),
        field("delivery-lat", "Delivery latitude", lat),
        field("delivery-lng", "Delivery longitude", lng),
        gpsButton,
        submit
    );

    form.addEventListener("submit", async function (event) {
      event.preventDefault();
      if (!lat.value || !lng.value) {
        setMessage("Choose a GPS delivery location before placing the order.", true);
        return;
      }
      submit.disabled = true;
      try {
        const placed = await OrdersApi.placeGroups(groups, {
          requestedDate: dateInput.value,
          notes: notes.value.trim(),
          lat: number(lat.value, null),
          lng: number(lng.value, null)
        });
        saveCart([]);
        writeJson(CONFIRMATION_KEY, {
          placedAt: new Date().toISOString(),
          orders: placed
        });
        window.location.href = "order-confirmation.html";
      } catch (error) {
        setMessage(error.message || "Could not place the order.", true);
        submit.disabled = false;
      }
    });

    container.append(form);
  }

  function renderConfirmationPage() {
    const container = document.getElementById("confirmation-content");
    if (!container) {
      return;
    }
    const payload = readJson(CONFIRMATION_KEY, null);
    container.replaceChildren();
    container.append(el("h1", "", "Order Confirmation"));
    if (!payload || !Array.isArray(payload.orders) || payload.orders.length === 0) {
      container.append(el("p", "", "No recent order was found. Check My Orders or return to the cart."));
      return;
    }

    container.append(el("p", "", "Products from different farms were submitted as separate orders."));
    payload.orders.forEach(function (order) {
      const article = el("article", "farm-group");
      article.append(el("h2", "", order.farm_name || "Farm order"));
      const badge = el("p", statusClass(normalizeListedOrder(order, order.syncState === "synced" ? "remote" : "queue")));
      badge.textContent = order.syncState === "synced"
          ? "Submitted"
          : "Waiting to synchronize";
      article.append(badge);
      if (order.id) {
        article.append(el("p", "", "Order ID: " + order.id));
      }
      article.append(el("p", "", "Reference: " + order.client_uuid));
      article.append(el("p", "", "Total: " + money(order.total_amount)));
      const list = document.createElement("ul");
      (order.items || []).forEach(function (item) {
        const line = document.createElement("li");
        line.textContent = (item.quantity_kg || item.quantity) + " kg of " + (item.name || ("Product " + item.product_id));
        list.append(line);
      });
      article.append(list);
      container.append(article);
    });
  }

  function orderMatchesFilter(order, filter) {
    if (filter === "all" || !filter) {
      return true;
    }
    if (filter === "waiting_to_sync") {
      return order.status === "waiting_to_sync";
    }
    if (filter === "submitted") {
      return order.submitted && order.status !== "cancelled";
    }
    if (filter === "pending") {
      return order.serverStatus === "pending" || order.status === "pending";
    }
    return order.status === filter || order.serverStatus === filter;
  }

  async function renderOrdersPage() {
    const container = document.getElementById("orders-content");
    if (!container) {
      return;
    }
    const orders = await OrdersApi.list();
    const filter = (document.getElementById("order-filter") || {}).value || "all";
    const visible = orders.filter(function (order) {
      return orderMatchesFilter(order, filter);
    });

    container.replaceChildren();
    if (visible.length === 0) {
      renderEmpty(container, "No orders to show", Offline.isOnline()
          ? "Placed orders will appear here."
          : "No cached orders are available offline yet.");
      return;
    }

    visible.forEach(function (order) {
      const article = el("article", "order-card");
      article.append(el("h2", "", order.farm_name));
      const badge = el("p", statusClass(order), statusLabel(order));
      article.append(badge);
      if (order.id) {
        article.append(el("p", "", "Order #" + order.id));
      }
      article.append(el("p", "", "Requested: " + (order.requested_date || "—")));
      article.append(el("time", "", "Placed " + new Date(order.ordered_at).toLocaleString()));
      article.append(el("p", "", "Total: " + money(order.total_amount)));
      if (order.lastError && order.syncState === "failed") {
        article.append(el("p", "", order.lastError));
      }
      const list = document.createElement("ul");
      (order.items || []).forEach(function (item) {
        const line = document.createElement("li");
        line.textContent = (item.quantity_kg || item.quantity || "") + " kg · " + (item.name || ("Product " + item.product_id));
        list.append(line);
      });
      if (order.items && order.items.length) {
        article.append(list);
      }

      const canCancel = order.status === "pending" || order.status === "waiting_to_sync";
      if (canCancel) {
        const cancel = el("button", "button button-secondary", "Cancel order");
        cancel.type = "button";
        cancel.addEventListener("click", async function () {
          cancel.disabled = true;
          await OrdersApi.cancel(order);
          setMessage("The order was cancelled.");
          renderOrdersPage();
        });
        article.append(cancel);
      }

      // Show fix button for failed orders with restaurant_id mismatch
      if (order.syncState === "failed" && order.lastError &&
          (order.lastError.includes("restaurant_id") || order.lastError.includes("own restaurant"))) {
        const fix = el("button", "button", "Fix and retry");
        fix.type = "button";
        fix.addEventListener("click", async function () {
          fix.disabled = true;
          try {
            await OrdersApi.fixRestaurantId(order);
            setMessage("Order fixed. Retrying synchronization...");
            await Offline.retryAll();
            refreshConnectionBanner();
            renderOrdersPage();
          } catch (error) {
            setMessage("Could not fix order: " + (error.message || "Unknown error"), true);
            fix.disabled = false;
          }
        });
        article.append(fix);
      }
      container.append(article);
    });
  }

  function scheduleProducts(allProducts, farmId) {
    return allProducts.filter(function (product) {
      return Number(product.farmId || product.farm_id) === Number(farmId);
    });
  }

  async function loadProductsForBuyer() {
    if (window.SproutRoute && typeof window.SproutRoute.fetchProducts === "function" && Offline.isOnline()) {
      try {
        const products = await window.SproutRoute.fetchProducts();
        Offline.cacheProducts(products);
        return products;
      } catch (error) {
        const cached = Offline.getCachedProducts();
        if (cached.length) {
          return cached;
        }
      }
    }
    const cached = Offline.getCachedProducts();
    if (cached.length) {
      return cached;
    }
    return [
      { id: "1", farmId: 1, farm: "Benguet Highland Farm", name: "Baguio Lettuce", price: 85, minOrderKg: 2, stock: 40, category: "Leafy Greens", unit: "kg" },
      { id: "2", farmId: 1, farm: "Benguet Highland Farm", name: "Heirloom Tomato", price: 95, minOrderKg: 1, stock: 25.5, category: "Vegetables", unit: "kg" },
      { id: "3", farmId: 2, farm: "Mountain Harvest Farm", name: "Benguet Potato", price: 60, minOrderKg: 5, stock: 80, category: "Root Crops", unit: "kg" },
      { id: "4", farmId: 2, farm: "Mountain Harvest Farm", name: "Fresh Basil", price: 150, minOrderKg: 0.5, stock: 8, category: "Herbs", unit: "kg" }
    ];
  }

  const RecurringApi = {
    async list() {
      if (Offline.isOnline()) {
        const remote = await tryApi("../api/schedules/list.php");
        if (remote.ok) {
          const rows = remote.data.schedules || [];
          saveLocalSchedules(rows);
          return rows;
        }
      }
      return localSchedules();
    },
    async save(schedule) {
      const isNew = !schedule.schedule_id;

      if (Offline.isOnline()) {
        const response = await tryApi(isNew ? "../api/schedules/create.php" : "../api/schedules/update.php?id=" + schedule.schedule_id, {
          method: isNew ? "POST" : "PUT",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(schedule)
        });

        if (response && response.ok && response.data && response.data.success) {
          // Access the schedule from the wrapped response.data object
          const serverSchedule = response.data.schedule;
          if (serverSchedule && serverSchedule.schedule_id) {
            // FIX #2: Only save to localStorage after successful server response
            const rows = localSchedules();
            if (isNew) {
              // Avoid duplicates: check if schedule with this server ID already exists
              const existingIndex = rows.findIndex(function (row) {
                return Number(row.schedule_id) === Number(serverSchedule.schedule_id);
              });
              if (existingIndex < 0) {
                rows.unshift(serverSchedule);
              } else {
                rows[existingIndex] = serverSchedule;
              }
            } else {
              const index = rows.findIndex(function (row) {
                return Number(row.schedule_id) === Number(serverSchedule.schedule_id);
              });
              if (index >= 0) {
                rows[index] = serverSchedule;
              } else {
                rows.unshift(serverSchedule);
              }
            }
            saveLocalSchedules(rows);
            return serverSchedule;
          }
        }
        // If API failed or response invalid, throw error
        const errorMsg = response ? (response.error || response.message || "Server rejected the schedule") : "Network error";
        throw new Error(errorMsg);
      }

      // Offline fallback - save with temporary ID only when offline
      const rows = localSchedules();
      if (isNew) {
        schedule.schedule_id = Date.now();
        schedule.status = schedule.status || "active";
        rows.unshift(schedule);
      } else {
        const index = rows.findIndex(function (row) {
          return Number(row.schedule_id) === Number(schedule.schedule_id);
        });
        if (index >= 0) {
          rows[index] = schedule;
        } else {
          rows.unshift(schedule);
        }
      }
      saveLocalSchedules(rows);
      return schedule;
    }
  };

  async function generateOrderForSchedule(schedule, farm, refreshSchedules) {
    const nextDate = schedule.next_delivery_date;
    if (!nextDate) {
      setMessage("This schedule has no upcoming delivery dates.", true);
      return;
    }

    if (!confirm(`Generate order for ${farm.name} on ${nextDate}?\n\nSchedule ID: ${schedule.schedule_id}\nItems: ${(schedule.items || []).length}`)) {
      return;
    }

    const generateBtn = document.activeElement;
    if (generateBtn) {
      generateBtn.disabled = true;
      generateBtn.textContent = "Generating...";
    }

    try {
      const response = await fetch("../api/schedules/generate.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({
          schedule_id: schedule.schedule_id,
          date: nextDate
        })
      });

      const data = await response.json();

      if (!response.ok) {
        const errorMsg = data.error || data.message || "Could not generate order";
        setMessage(`Error: ${errorMsg}`, true);
        return;
      }

      if (data.success) {
        if (data.duplicate) {
          setMessage(`Order already exists for ${nextDate}. Order #${data.order.order_id}.`, false);
        } else {
          setMessage(`Order #${data.order.order_id} generated for ${nextDate}. Next delivery: ${data.next_delivery_date || "No more deliveries"}.`, false);
        }
        // Refresh schedule list to show updated next_delivery_date
        await refreshSchedules();
      }
    } catch (error) {
      // FIX #3: Display actual error instead of generic message
      const errorMsg = error.message || "Could not generate order. Check your connection.";
      setMessage(errorMsg, true);
    } finally {
      if (generateBtn) {
        generateBtn.disabled = false;
        generateBtn.textContent = "Generate Next Delivery";
      }
    }
  }

  async function renderRecurringPage() {
    const farmSelect = document.getElementById("schedule-farm");
    const productBox = document.getElementById("schedule-products");
    const listBox = document.getElementById("schedules-content");
    const form = document.getElementById("schedule-form");
    if (!farmSelect || !productBox || !listBox || !form) {
      return;
    }

    const products = await loadProductsForBuyer();
    const farms = [];
    const seen = {};
    products.forEach(function (product) {
      const id = Number(product.farmId || product.farm_id);
      if (!id || seen[id]) {
        return;
      }
      seen[id] = true;
      farms.push({ id: id, name: product.farm || product.farmName || ("Farm " + id) });
    });

    const selected = farmSelect.value;
    farmSelect.replaceChildren(el("option", "", "Select a farm"));
    farmSelect.querySelector("option").value = "";
    farms.forEach(function (farm) {
      const option = document.createElement("option");
      option.value = String(farm.id);
      option.textContent = farm.name;
      farmSelect.append(option);
    });
    if (selected) {
      farmSelect.value = selected;
    }

    function paintProducts() {
      const farmId = farmSelect.value;
      productBox.replaceChildren();
      if (!farmId) {
        productBox.append(el("p", "", "Select a farm to see its products."));
        return;
      }
      const rows = scheduleProducts(products, farmId);
      if (!rows.length) {
        productBox.append(el("p", "", "No products were found for this farm."));
        return;
      }
      rows.forEach(function (product) {
        const label = document.createElement("label");
        const checkbox = document.createElement("input");
        checkbox.type = "checkbox";
        checkbox.value = product.id;
        checkbox.dataset.min = String(product.minimumOrderQuantity || product.minOrderKg || 1);
        const qty = document.createElement("input");
        qty.type = "number";
        qty.min = checkbox.dataset.min;
        qty.step = "0.5";
        qty.value = checkbox.dataset.min;
        qty.disabled = true;
        qty.setAttribute("aria-label", "Quantity for " + product.name);
        checkbox.addEventListener("change", function () {
          qty.disabled = !checkbox.checked;
        });
        label.append(checkbox, document.createTextNode(product.name + " · " + money(product.price) + "/kg"));
        label.append(qty);
        productBox.append(label);
      });
    }

    farmSelect.addEventListener("change", paintProducts);
    paintProducts();

    if (!document.getElementById("schedule-start").value) {
      document.getElementById("schedule-start").value = todayIso();
    }

    async function paintSchedules() {
      const schedules = await RecurringApi.list();
      listBox.replaceChildren();
      if (!schedules.length) {
        renderEmpty(listBox, "No schedules yet", "Create a farm schedule to receive regular deliveries.");
        return;
      }
      schedules.forEach(function (schedule) {
        const card = el("article", "schedule-card");
        const farm = farms.find(function (row) {
          return Number(row.id) === Number(schedule.farm_id);
        });
        card.append(el("h3", "", (farm && farm.name) || ("Farm " + schedule.farm_id)));
        card.append(el("p", "schedule-status", titleCase(schedule.status || "active")));
        card.append(el("p", "", titleCase(schedule.frequency) + (schedule.preferred_day ? " · " + schedule.preferred_day : "") + " · " + (schedule.preferred_time || "")));
        const items = document.createElement("ul");
        (schedule.items || []).forEach(function (item) {
          const product = products.find(function (row) {
            return String(row.id) === String(item.product_id);
          });
          const line = document.createElement("li");
          line.textContent = (item.quantity_kg || item.quantity) + " kg · " + ((product && product.name) || ("Product " + item.product_id));
          items.append(line);
        });
        card.append(items);

        function setStatus(status) {
          schedule.status = status;
          RecurringApi.save(schedule).then(paintSchedules);
        }

        const edit = el("button", "button", "Edit");
        edit.type = "button";
        edit.addEventListener("click", function () {
          document.getElementById("schedule-id").value = schedule.schedule_id;
          farmSelect.value = String(schedule.farm_id);
          paintProducts();
          document.getElementById("schedule-frequency").value = schedule.frequency || "weekly";
          document.getElementById("schedule-day").value = schedule.preferred_day || "";
          document.getElementById("schedule-time").value = (schedule.preferred_time || "09:00").slice(0, 5);
          document.getElementById("schedule-start").value = schedule.start_date || todayIso();
          document.getElementById("schedule-end").value = schedule.end_date || "";
          document.getElementById("schedule-form-title").textContent = "Update Schedule";
          (schedule.items || []).forEach(function (item) {
            const checkbox = productBox.querySelector('input[type="checkbox"][value="' + item.product_id + '"]');
            if (!checkbox) {
              return;
            }
            checkbox.checked = true;
            const qty = checkbox.parentElement.querySelector('input[type="number"]');
            qty.disabled = false;
            qty.value = item.quantity_kg;
          });
        });

        const pause = el("button", "button button-secondary", schedule.status === "paused" ? "Resume" : "Pause");
        pause.type = "button";
        pause.addEventListener("click", function () {
          setStatus(schedule.status === "paused" ? "active" : "paused");
        });
        const cancel = el("button", "button button-secondary", "Cancel");
        cancel.type = "button";
        cancel.addEventListener("click", function () {
          setStatus("cancelled");
        });

        // Generate Next Delivery button for active schedules
        if (schedule.status === "active" && schedule.next_delivery_date) {
          const generate = el("button", "button", "Generate Next Delivery");
          generate.type = "button";
          generate.addEventListener("click", function () {
            generateOrderForSchedule(schedule,
                farm || { name: schedule.farm_name ||
                      ("Farm " + schedule.farm_id) },paintSchedules);
          });
          card.append(edit, pause, cancel, generate);
        } else {
          card.append(edit, pause, cancel);
        }
        listBox.append(card);
      });
    }

    if (!form.dataset.bound) {
      form.dataset.bound = "true";
      form.addEventListener("submit", async function (event) {
        event.preventDefault();

        // Prevent repeated submissions
        if (form.dataset.submitting === "true") {
          return;
        }

        const farmId = Number(farmSelect.value);
        if (!farmId) {
          setMessage("Select a farm for this schedule.", true);
          return;
        }
        const items = [];
        productBox.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
          if (!checkbox.checked) {
            return;
          }
          const qty = checkbox.parentElement.querySelector('input[type="number"]');
          const quantity = number(qty.value, 0);
          const minQty = number(checkbox.dataset.min, 1);
          if (quantity + 1e-9 < minQty) {
            throw new Error("A selected product is below its minimum order quantity.");
          }
          items.push({ product_id: Number(checkbox.value), quantity_kg: quantity });
        });
        if (!items.length) {
          setMessage("Select at least one product for the schedule.", true);
          return;
        }

        const submitBtn = form.querySelector('button[type="submit"]');
        const originalBtnText = submitBtn ? submitBtn.textContent : "";
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.textContent = "Saving...";
        }
        form.dataset.submitting = "true";

        try {
          const user = await OrdersApi.currentUser();
          if (!user) {
            const offlineUser = getOfflineUser();
            if (offlineUser) {
              throw new Error("Could not verify your restaurant. Please sign in online first.");
            }
            throw new Error("You must sign in online before using recurring schedules.");
          }
          const savedSchedule = await RecurringApi.save({
            schedule_id: document.getElementById("schedule-id").value
                ? Number(document.getElementById("schedule-id").value)
                : null,
            restaurant_id: await OrdersApi.restaurantId(),
            farm_id: farmId,
            frequency: document.getElementById("schedule-frequency").value,
            preferred_day: document.getElementById("schedule-day").value,
            preferred_time: document.getElementById("schedule-time").value,
            start_date: document.getElementById("schedule-start").value,
            end_date: document.getElementById("schedule-end").value || null,
            next_delivery_date: document.getElementById("schedule-start").value,
            status: "active",
            items: items
          });
          form.reset();
          document.getElementById("schedule-id").value = "";
          document.getElementById("schedule-form-title").textContent = "Create a Schedule";
          document.getElementById("schedule-time").value = "09:00";
          document.getElementById("schedule-start").value = todayIso();
          paintProducts();
          setMessage("Schedule saved successfully. Next delivery: " + (savedSchedule.next_delivery_date || "No more deliveries"));
          paintSchedules();
        } catch (error) {
          setMessage(error.message, true);
        } finally {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = originalBtnText;
          }
          form.dataset.submitting = "false";
        }
      });

      document.getElementById("reset-schedule").addEventListener("click", function () {
        form.reset();
        document.getElementById("schedule-id").value = "";
        document.getElementById("schedule-form-title").textContent = "Create a Schedule";
        document.getElementById("schedule-time").value = "09:00";
        document.getElementById("schedule-start").value = todayIso();
        paintProducts();
      });
    }

    paintSchedules();
  }

  function bindGlobalCartActions() {
    document.addEventListener("click", function (event) {
      const button = event.target.closest("[data-add-to-cart]");
      if (!button) {
        return;
      }
      event.preventDefault();
      try {
        const product = JSON.parse(button.getAttribute("data-add-to-cart"));
        const qty = number(button.getAttribute("data-quantity"), product.minOrderKg || product.minimumOrderQuantity || 1);
        addToCart(product, qty);
        setMessage(product.name + " was added to your cart.");
        button.textContent = "Added";
      } catch (error) {
        setMessage(error.message, true);
      }
    });
  }

  function bindProductForm() {
    const form = document.getElementById("add-to-cart-form");
    if (!form) {
      return;
    }
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      const qty = document.getElementById("order-qty");
      const message = document.getElementById("add-to-cart-message");
      try {
        const raw = form.getAttribute("data-product");
        if (!raw) {
          throw new Error("This product is still loading.");
        }
        const product = JSON.parse(raw);
        addToCart(product, qty.value);
        if (message) {
          message.textContent = product.name + " was added to your cart.";
        }
      } catch (error) {
        if (message) {
          message.textContent = error.message;
        }
      }
    });
  }

  window.SproutRouteCart = {
    add: addToCart,
    update: updateQuantity,
    remove: removeItem,
    items: cartItems,
    grouped: groupedCart,
    count: cartCount
  };

  document.addEventListener("DOMContentLoaded", function () {
    bindConnection();
    updateCartCount();
    bindGlobalCartActions();
    bindProductForm();

    // Initialize offline user context on buyer pages while online
    if (page === "cart" || page === "checkout" || page === "orders" || page === "recurring") {
      OrdersApi.currentUser().catch(function (error) {
        // Silently fail - the error will be shown when the user tries to place an order
      });
    }

    const logoutBtn = document.getElementById("logoutBtn");
    if (logoutBtn) {
      logoutBtn.addEventListener("click", async function (event) {
        event.preventDefault();
        await logout();
        window.location.href = "../home/index.html";
      });
    }

    if (page === "cart") {
      renderCartPage();
    }
    if (page === "checkout") {
      renderCheckoutPage();
    }
    if (page === "confirmation") {
      renderConfirmationPage();
    }
    if (page === "orders") {
      const filter = document.getElementById("order-filter");
      const sync = document.getElementById("sync-orders");
      if (filter) {
        filter.addEventListener("change", renderOrdersPage);
      }
      if (sync) {
        sync.addEventListener("click", async function () {
          sync.disabled = true;
          await Offline.retryAll();
          sync.disabled = false;
          setMessage("Queued orders were retried.");
          refreshConnectionBanner();
          renderOrdersPage();
        });
      }
      renderOrdersPage();
    }
    if (page === "recurring") {
      renderRecurringPage();
    }
  });
}());
