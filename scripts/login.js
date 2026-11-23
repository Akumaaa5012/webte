(function () {
  "use strict";

  const REDIRECTS = {
    buyer: "../buyer/browse.html",
    seller: "../seller/seller-dashboard.html",
    logistics: "../logistics/logistics-dashboard.html"
  };

  const rolePicker = document.querySelector("#role-picker");
  const loginFormCard = document.querySelector("#login-form-card");
  const roleInput = document.querySelector("#login-role");
  const roleDescription = document.querySelector("#login-role-description");
  const loginForm = loginFormCard.querySelector("form");
  const errorMessage = document.querySelector("#login-error");
  const submitButton = loginForm.querySelector('button[type="submit"]');
  const role = new URLSearchParams(window.location.search).get("role");

  if (role === "buyer" || role === "seller" || role === "logistics") {
    rolePicker.hidden = true;
    loginFormCard.hidden = false;
    roleInput.value = role;
    if (role === "seller") {
      roleDescription.textContent = "Sign in to manage your farm and products.";
    } else if (role === "logistics") {
      roleDescription.textContent = "Sign in to manage your delivery assignments.";
    } else {
      roleDescription.textContent = "Sign in to discover products from local farms.";
    }
  } else if (role) {
    window.location.replace("login.html");
  }

  loginForm.addEventListener("submit", async function (event) {
    event.preventDefault();
    errorMessage.textContent = "";
    submitButton.disabled = true;

    try {
      const response = await fetch("../api/auth/login.php", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({
          email: loginForm.querySelector("#email").value.trim(),
          password: loginForm.querySelector("#password").value,
          role: roleInput.value
        })
      });

      let payload;
      try {
        payload = await response.json();
      } catch (error) {
        throw new Error("The login service did not return JSON. Open the project through WAMP localhost.");
      }

      if (!response.ok || !payload.ok) {
        throw new Error(payload.error || "Login failed. Please try again.");
      }

      const authenticatedPortal = payload.user && payload.user.portal;
      if (authenticatedPortal !== "buyer" && authenticatedPortal !== "seller" && authenticatedPortal !== "logistics") {
        throw new Error("This account does not have an available workspace.");
      }
      window.location.href = REDIRECTS[authenticatedPortal];
    } catch (error) {
      errorMessage.textContent = error.message || "Unable to sign in. Please try again.";
    } finally {
      submitButton.disabled = false;
    }
  });
}());
