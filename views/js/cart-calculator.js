(function () {
    "use strict";

    /** Cart page refresh helper — popup UI/flow is handled by product-calculator.js. */
    var selector = "[data-unipayment-cart-calculator]";

    function refresh() {
        var root = document.querySelector(selector);
        if (!root) {
            return;
        }
        var endpoint = root.getAttribute("data-endpoint");
        if (!endpoint) {
            return;
        }
        fetch(endpoint, {
            credentials: "same-origin",
            headers: { "X-Requested-With": "XMLHttpRequest" },
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                var next = payload.success ? payload.calculator : null;
                if (typeof root.unipaymentUpdate === "function") {
                    root.unipaymentUpdate(next);
                    return;
                }
                root.hidden = !next;
            })
            .catch(function () {
                if (typeof root.unipaymentUpdate === "function") {
                    root.unipaymentUpdate(null);
                } else {
                    root.hidden = true;
                }
            });
    }

    if (window.prestashop && typeof window.prestashop.on === "function") {
        window.prestashop.on("updatedCart", refresh);
    }
})();
