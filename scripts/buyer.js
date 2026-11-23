(function () {
  "use strict";

  function unique(values) {
    return values.filter(function (value, index, list) {
      return value && list.indexOf(value) === index;
    });
  }

  function fillSelect(select, values, allLabel) {
    if (!select) {
      return;
    }
    const current = select.value;
    select.replaceChildren();
    const all = document.createElement("option");
    all.value = "";
    all.textContent = allLabel;
    select.append(all);
    values.sort().forEach(function (value) {
      const option = document.createElement("option");
      option.value = value;
      option.textContent = value;
      select.append(option);
    });
    select.value = current;
  }

  function matches(product, search, category, farm) {
    const haystack = [product.name, product.farm, product.category, product.description]
        .join(" ")
        .toLowerCase();
    if (search && haystack.indexOf(search) === -1) {
      return false;
    }
    if (category && product.category !== category) {
      return false;
    }
    if (farm && product.farm !== farm) {
      return false;
    }
    return true;
  }

  function sortProducts(products, sort) {
    const copy = products.slice();
    if (sort === "name") {
      copy.sort(function (a, b) {
        return a.name.localeCompare(b.name);
      });
    }
    if (sort === "price_asc") {
      copy.sort(function (a, b) {
        return (a.price || 0) - (b.price || 0);
      });
    }
    if (sort === "price_desc") {
      copy.sort(function (a, b) {
        return (b.price || 0) - (a.price || 0);
      });
    }
    return copy;
  }

  function renderCards(container, products) {
    container.replaceChildren();
    if (!products.length) {
      window.SproutRoute.showState(
          container,
          "No products found",
          "Try another search or clear the filters.",
          false
      );
      return;
    }

    products.forEach(function (product) {
      const card = document.createElement("article");
      card.className = "product-card";

      const media = window.SproutRoute.imageOrPlaceholder(product, "image");
      const body = document.createElement("div");
      body.className = "card-body";

      const meta = document.createElement("div");
      meta.className = "product-meta";
      const category = document.createElement("span");
      category.textContent = product.category || "Produce";
      meta.append(category);

      const title = document.createElement("h3");
      const link = document.createElement("a");
      link.href = "product.html?id=" + encodeURIComponent(product.id);
      link.textContent = product.name;
      title.append(link);

      const farm = document.createElement("p");
      farm.className = "farm";
      farm.textContent = product.farm || "Local farm";

      const footer = document.createElement("div");
      footer.className = "card-footer";
      const price = document.createElement("span");
      price.className = "price";
      price.textContent = window.SproutRoute.formatPrice(product);

      const add = document.createElement("button");
      add.type = "button";
      add.className = "action";
      add.textContent = "Add to cart";
      add.setAttribute("data-add-to-cart", JSON.stringify({
        id: product.id,
        name: product.name,
        farmId: product.farmId,
        farm: product.farm,
        price: product.price,
        unit: product.unit,
        minOrderKg: product.minimumOrderQuantity,
        stock: product.stock,
        image: product.image
      }));
      add.setAttribute("data-quantity", String(product.minimumOrderQuantity || 1));

      footer.append(price, add);
      body.append(meta, title, farm, footer);
      card.append(media, body);
      container.append(card);
    });
  }

  function fallbackProducts() {
    return [
      { id: "1", farmId: 1, farm: "Benguet Highland Farm", name: "Baguio Lettuce", price: 85, currency: "PHP", unit: "kg", minOrderKg: 2, minimumOrderQuantity: 2, stock: 40, category: "Leafy Greens", image: "", description: "" },
      { id: "2", farmId: 1, farm: "Benguet Highland Farm", name: "Heirloom Tomato", price: 95, currency: "PHP", unit: "kg", minOrderKg: 1, minimumOrderQuantity: 1, stock: 25.5, category: "Vegetables", image: "", description: "" },
      { id: "3", farmId: 2, farm: "Mountain Harvest Farm", name: "Benguet Potato", price: 60, currency: "PHP", unit: "kg", minOrderKg: 5, minimumOrderQuantity: 5, stock: 80, category: "Root Crops", image: "", description: "" },
      { id: "4", farmId: 2, farm: "Mountain Harvest Farm", name: "Fresh Basil", price: 150, currency: "PHP", unit: "kg", minOrderKg: 0.5, minimumOrderQuantity: 0.5, stock: 8, category: "Herbs", image: "", description: "" }
    ];
  }

  async function loadProducts() {
    try {
      const products = await window.SproutRoute.fetchProducts();
      if (window.SproutRouteOffline && typeof window.SproutRouteOffline.cacheProducts === "function") {
        window.SproutRouteOffline.cacheProducts(products);
      }
      return products;
    } catch (error) {
      if (window.SproutRouteOffline && typeof window.SproutRouteOffline.getCachedProducts === "function") {
        const cached = window.SproutRouteOffline.getCachedProducts();
        if (cached.length) {
          return cached;
        }
      }
      return fallbackProducts();
    }
  }

  async function initBrowse() {
    const container = document.querySelector(".products");
    if (!container || !window.SproutRoute) {
      return;
    }

    const search = document.getElementById("product-search");
    const categoryFilter = document.getElementById("category-filter");
    const farmFilter = document.getElementById("farm-filter");
    const sortFilter = document.getElementById("sort-filter");
    let products = [];

    function paint() {
      const filtered = sortProducts(
          products.filter(function (product) {
            return matches(
                product,
                (search.value || "").trim().toLowerCase(),
                categoryFilter.value,
                farmFilter.value
            );
          }),
          sortFilter.value
      );
      renderCards(container, filtered);
    }

    try {
      products = await loadProducts();
      fillSelect(categoryFilter, unique(products.map(function (product) {
        return product.category;
      })), "All categories");
      fillSelect(farmFilter, unique(products.map(function (product) {
        return product.farm;
      })), "All farms");
      paint();
    } catch (error) {
      window.SproutRoute.showState(
          container,
          "Products are unavailable",
          error.message || "The product list could not be loaded.",
          true
      );
    }

    [search, categoryFilter, farmFilter, sortFilter].forEach(function (control) {
      if (control) {
        control.addEventListener("input", paint);
        control.addEventListener("change", paint);
      }
    });
  }

  async function initProduct() {
    const article = document.querySelector(".detail");
    const form = document.getElementById("add-to-cart-form");
    if (!article || !window.SproutRoute) {
      return;
    }

    const productId = new URLSearchParams(window.location.search).get("id");
    if (!productId) {
      return;
    }

    try {
      const products = await loadProducts();
      const product = products.find(function (row) {
        return String(row.id) === String(productId);
      });
      if (!product) {
        throw new Error("That product was not found.");
      }

      const category = article.querySelector(".eyebrow");
      const title = article.querySelector("h1");
      const farm = article.querySelector(".detail-farm");
      const price = article.querySelector(".detail-price");
      const description = article.querySelector(".description");
      const cells = article.querySelectorAll(".details-table td");
      const imageHost = article.querySelector(".image, .image-placeholder");

      if (category) {
        category.textContent = product.category || "Produce";
      }
      if (title) {
        title.textContent = product.name;
      }
      if (farm) {
        farm.textContent = product.farm || "Local farm";
      }
      if (price) {
        price.textContent = "Price: " + window.SproutRoute.formatPrice(product) + " / " + (product.unit || "kg");
      }
      if (description) {
        description.textContent = product.description || "Fresh produce from " + (product.farm || "a local farm") + ".";
      }
      if (cells[0]) {
        cells[0].textContent = product.stock === null ? "—" : product.stock + " " + (product.unit || "kg");
      }
      if (cells[1]) {
        cells[1].textContent = product.minimumOrderQuantity === null
            ? "—"
            : product.minimumOrderQuantity + " " + (product.unit || "kg");
      }
      if (imageHost) {
        imageHost.replaceWith(window.SproutRoute.imageOrPlaceholder(product, "image"));
      }
      if (form) {
        form.setAttribute("data-product", JSON.stringify({
          id: product.id,
          name: product.name,
          farmId: product.farmId,
          farm: product.farm,
          price: product.price,
          unit: product.unit,
          minOrderKg: product.minimumOrderQuantity,
          stock: product.stock,
          image: product.image
        }));
        const qty = document.getElementById("order-qty");
        if (qty) {
          qty.min = String(product.minimumOrderQuantity || 1);
          qty.value = String(product.minimumOrderQuantity || 1);
        }
      }
    } catch (error) {
      const info = article.querySelector(".details-info");
      if (info) {
        window.SproutRoute.showState(info, "Product unavailable", error.message, true);
      }
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    if (document.querySelector(".products") && document.getElementById("product-search")) {
      initBrowse();
    }
    if (document.querySelector(".detail")) {
      initProduct();
    }
  });
}());
