(function () {
  "use strict";

  const endpoint = window.SPROUTEROUTE_PRODUCTS_ENDPOINT || "../api/products/read.php";

  function text(value) {
    return value === null || value === undefined ? "" : String(value).trim();
  }

  function normalizeProduct(product) {
    if (!product || typeof product !== "object") {
      throw new Error("The product list contains an invalid item.");
    }

    const farm = product.farm_name || product.farmName || product.farm;
    const farmName = farm && typeof farm === "object"
        ? farm.name || farm.farm_name
        : farm;
    const rawPrice = product.price_per_kg ?? product.price;
    const price = rawPrice === null || rawPrice === undefined || rawPrice === ""
        ? null
        : Number(rawPrice);
    const stockValue = product.stock_kg
        ?? product.stock
        ?? product.stock_quantity
        ?? product.stockQuantity
        ?? product.quantity_available
        ?? product.quantityAvailable
        ?? product.inventory;
    const minimumOrderValue = product.min_order_kg
        ?? product.minimum_order_quantity
        ?? product.minimumOrderQuantity
        ?? product.min_order_quantity
        ?? product.minOrderQuantity
        ?? product.moq;

    return {
      id: text(product.id || product.product_id || product.productId),
      name: text(product.name || product.product_name || product.productName),
      description: text(product.description),
      category: text(product.category_name || product.categoryName || product.category),
      farmId: Number(product.farm_id || product.farmId) || 0,
      farm: text(farmName),
      price: price !== null && Number.isFinite(price) ? price : null,
      currency: text(product.currency) || "PHP",
      unit: text(product.unit || product.price_unit || product.priceUnit) || "kg",
      image: text(product.image_url || product.imageUrl || product.image || product.photo),
      availability: text(product.availability || product.status),
      location: text(product.location || product.farm_location || product.farmLocation),
      stock: stockValue === null || stockValue === undefined || stockValue === ""
          ? null
          : Number.isFinite(Number(stockValue))
              ? Number(stockValue)
              : null,
      minimumOrderQuantity: minimumOrderValue === null
      || minimumOrderValue === undefined
      || minimumOrderValue === ""
          ? null
          : Number.isFinite(Number(minimumOrderValue))
              ? Number(minimumOrderValue)
              : null
    };
  }

  async function fetchProducts(filters) {
    const requestUrl = new URL(endpoint, window.location.href);
    const requestOptions = {
      headers: { Accept: "application/json" }
    };

    if (filters) {
      Object.keys(filters).forEach(function (key) {
        if (key !== "signal" && filters[key]) {
          requestUrl.searchParams.set(key, filters[key]);
        }
      });
      if (filters.signal) {
        requestOptions.signal = filters.signal;
      }
    }

    const response = await fetch(requestUrl, requestOptions);
    if (!response.ok) {
      throw new Error("The product service returned status " + response.status + ".");
    }

    const payload = await response.json();
    const products = Array.isArray(payload)
        ? payload
        : payload && Array.isArray(payload.products)
            ? payload.products
            : null;

    if (!products) {
      throw new Error("The product service returned an unexpected response.");
    }

    return products.map(normalizeProduct).map(function (product) {
      if (!product.id || !product.name) {
        throw new Error("The product service returned an item without an ID or name.");
      }
      return product;
    });
  }

  function formatPrice(product) {
    if (product.price === null) {
      return "Price on request";
    }
    return new Intl.NumberFormat(undefined, {
      style: "currency",
      currency: /^[A-Z]{3}$/.test(product.currency) ? product.currency : "PHP"
    }).format(product.price);
  }

  function imageOrPlaceholder(product, className) {
    const imageUrl = product.image;
    const image = document.createElement("img");
    image.className = className;
    image.alt = product.name;
    image.loading = "lazy";
    image.decoding = "async";

    if (!imageUrl) {
      return placeholder(className);
    }

    image.src = imageUrl;
    image.addEventListener("error", function () {
      image.replaceWith(placeholder(className));
    }, { once: true });
    return image;
  }

  function placeholder(className) {
    const element = document.createElement("div");
    element.className = className + " image-placeholder";
    element.setAttribute("aria-hidden", "true");
    element.textContent = "S";
    return element;
  }

  function showState(container, heading, message, isError) {
    const panel = document.createElement("section");
    panel.className = "message";
    if (isError) {
      panel.setAttribute("role", "alert");
    }

    const title = document.createElement("h2");
    title.textContent = heading;
    const description = document.createElement("p");
    description.textContent = message;
    panel.append(title, description);
    container.replaceChildren(panel);
  }

  document.querySelectorAll("[data-current-year]").forEach(function (element) {
    element.textContent = String(new Date().getFullYear());
  });

  window.SproutRoute = {
    fetchProducts: fetchProducts,
    formatPrice: formatPrice,
    imageOrPlaceholder: imageOrPlaceholder,
    showState: showState
  };
}());
