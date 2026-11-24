(function () {
    "use strict";

    var CALCULATE_DELAY = 900;
    var submitting = false;
    var mounted = [];

    function parseConfig(root) {
        try {
            return JSON.parse(root.getAttribute("data-config") || "{}");
        } catch (error) {
            return null;
        }
    }

    function t(root, attr, fallback) {
        return root.getAttribute(attr) || fallback;
    }

    function setDisplay(root, name, display) {
        var primary = root.querySelector(
            '[data-unipayment-display="' + name + '-primary"]',
        );
        if (primary) primary.textContent = (display && display.primary) || "";
    }

    function setup(root) {
        if (root.dataset.unipaymentReady === "1") return;
        var config = parseConfig(root);
        if (!config || !config.schemes) return;
        root.dataset.unipaymentReady = "1";

        var form = root.closest("form") || root.querySelector("form");
        var select = root.querySelector("[data-unipayment-scheme]");
        var schemeHidden = root.querySelector(
            "[data-unipayment-scheme-hidden]",
        );
        var kop = root.querySelector("[data-unipayment-kop]");
        var first = root.querySelector("[data-unipayment-first]");
        var firstRow = root.querySelector("[data-unipayment-first-row]");
        var errorBox = root.querySelector("[data-unipayment-checkout-error]");
        var calculateTimer = null;
        var calculateRequest = null;
        var lastCalculation = null;
        var previousFirstLocked = null;
        var sequence = 0;
        var consents = window.createUnipaymentShopConsents(root);
        var consentGeneration = 0;
        var disposed = false;
        mounted.push(root);

        function showError(message) {
            if (!errorBox) return;
            if (!message) {
                errorBox.textContent = "";
                errorBox.hidden = true;
                return;
            }
            errorBox.textContent = message;
            errorBox.hidden = false;
        }

        function selectedScheme() {
            var key = select ? select.value : "";
            return (
                (config.schemes || []).filter(function (scheme) {
                    return scheme.key === key;
                })[0] || null
            );
        }

        function syncHidden() {
            var scheme = selectedScheme();
            if (schemeHidden)
                schemeHidden.value = scheme ? scheme.key : select.value;
            if (kop) kop.value = scheme ? scheme.kop_code : "";
        }

        function applyLocalScheme() {
            var scheme = selectedScheme();
            if (!scheme) return;
            syncHidden();
            if (first) {
                var locked = !!scheme.first_installment_locked;
                first.readOnly = locked;
                if (locked) {
                    first.value = String(Math.trunc(scheme.first_installment));
                } else if (previousFirstLocked === true) {
                    // locked → editable: do not carry the previous locked amount
                    first.value = "0";
                } else if (first.value === "") {
                    first.value = "0";
                }
                previousFirstLocked = locked;
            }
            if (firstRow) {
                firstRow.classList.toggle(
                    "unipayment-checkout__row--hidden",
                    !config.show_first_installment &&
                        !scheme.first_installment_locked &&
                        !scheme.show_first_installment,
                );
            }
            setDisplay(root, "price", config.cart_total_display);
            setDisplay(root, "financed_amount", scheme.financed_amount_display);
            setDisplay(
                root,
                "monthly_installment",
                scheme.monthly_installment_display,
            );
            setDisplay(root, "total_payable", scheme.total_payable_display);
            var glp = root.querySelector('[data-unipayment-display="glp"]');
            var gpr = root.querySelector('[data-unipayment-display="gpr"]');
            if (glp) glp.textContent = (scheme.glp_display || "0.00") + "%";
            if (gpr) gpr.textContent = (scheme.gpr_display || "0.00") + "%";
            consentGeneration = consents.begin(selectionIdentity());
            lastCalculation = consents.apply(consentGeneration, scheme.consent_context) ? scheme : null;
            showError(lastCalculation ? "" : t(root, "data-consent-context-invalid-message", "Обновете страницата и изберете схема отново."));
        }

        function applyCalculation(calculation) {
            if (!consents.apply(consentGeneration, calculation.consent_context)) throw new Error("consent_context");
            lastCalculation = calculation;
            var cartSnapshot = root.querySelector('input[name="unipayment_cart_snapshot"]');
            if (cartSnapshot && calculation.cart_snapshot) cartSnapshot.value = calculation.cart_snapshot;
            setDisplay(root, "price", calculation.price_display);
            setDisplay(
                root,
                "financed_amount",
                calculation.financed_amount_display,
            );
            setDisplay(
                root,
                "monthly_installment",
                calculation.monthly_installment_display,
            );
            setDisplay(
                root,
                "total_payable",
                calculation.total_payable_display,
            );
            var glp = root.querySelector('[data-unipayment-display="glp"]');
            var gpr = root.querySelector('[data-unipayment-display="gpr"]');
            if (glp)
                glp.textContent = (calculation.glp_display || "0.00") + "%";
            if (gpr)
                gpr.textContent = (calculation.gpr_display || "0.00") + "%";
            if (first && calculation.first_installment_locked) {
                first.value = String(Math.trunc(calculation.first_installment));
                first.readOnly = true;
            }
            if (firstRow) {
                firstRow.classList.toggle(
                    "unipayment-checkout__row--hidden",
                    !calculation.show_first_installment &&
                        !calculation.first_installment_locked,
                );
            }
            showError("");
        }

        function selectionIdentity() {
            var scheme = selectedScheme() || {};
            return JSON.stringify([scheme.kop_code, scheme.months, scheme.filter_id, scheme.scheme_type]);
        }

        function invalidateCalculation() {
            sequence += 1;
            lastCalculation = null;
            consentGeneration = consents.begin(selectionIdentity());
        }

        function requestCalculation() {
            invalidateCalculation();
            var requestSequence = sequence;
            var endpoint = root.getAttribute("data-calculate-endpoint");
            var scheme = selectedScheme();
            if (!endpoint || !scheme)
                return Promise.reject(new Error("selection"));
            syncHidden();
            if (
                calculateRequest &&
                typeof calculateRequest.abort === "function"
            ) {
                calculateRequest.abort();
            }
            calculateRequest =
                typeof AbortController === "function"
                    ? new AbortController()
                    : null;
            var payload = new URLSearchParams();
            payload.set("token", root.getAttribute("data-popup-token") || "");
            payload.set("scheme_key", scheme.key);
            payload.set("kop_code", scheme.kop_code);
            payload.set(
                "first_installment",
                String((first && first.value) || "0"),
            );
            var options = {
                method: "POST",
                credentials: "same-origin",
                body: payload,
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    "Content-Type":
                        "application/x-www-form-urlencoded;charset=UTF-8",
                },
            };
            if (calculateRequest) options.signal = calculateRequest.signal;
            return fetch(endpoint, options)
                .then(function (response) {
                    return response.json().then(function (body) {
                        if (disposed || root.isConnected === false || requestSequence !== sequence) throw new Error("stale");
                        if (!response.ok || !body.success)
                            throw new Error(body.message || "calculation");
                        applyCalculation(body.calculation);
                        return body;
                    });
                })
                .catch(function (error) {
                    if (error.name === "AbortError" || error.message === "stale" || disposed || root.isConnected === false || requestSequence !== sequence) throw error;
                    lastCalculation = null;
                    showError(
                        error.message === "consent_context" ? t(root, "data-consent-context-invalid-message", "Обновете страницата и изберете схема отново.") : t(
                            root,
                            "data-calculate-failed-message",
                            "Неуспешно изчисление. Моля, опитайте отново.",
                        ),
                    );
                    throw error;
                });
        }

        function scheduleCalculate() {
            invalidateCalculation();
            window.clearTimeout(calculateTimer);
            calculateTimer = window.setTimeout(function () {
                requestCalculation().catch(function () {});
            }, CALCULATE_DELAY);
        }

        function consentsOk() {
            return consents.ready();
        }

        function validateBeforeSubmit() {
            if (submitting) {
                showError(
                    t(
                        root,
                        "data-submitting-message",
                        "Заявката вече се обработва. Моля, изчакайте.",
                    ),
                );
                return false;
            }
            if (disposed || root.isConnected === false || !lastCalculation || !consents.resolved()) {
                showError("Изчакайте изчислението или обновете страницата.");
                return false;
            }
            if (!consentsOk()) {
                showError(
                    t(
                        root,
                        "data-consents-required-message",
                        "Моля, приемете всички задължителни съгласия.",
                    ),
                );
                return false;
            }
            if (!lastCalculation && !selectedScheme()) {
                showError(
                    t(
                        root,
                        "data-calculate-failed-message",
                        "Неуспешно изчисление. Моля, опитайте отново.",
                    ),
                );
                return false;
            }
            showError("");
            return true;
        }

        function markSubmitting() {
            submitting = true;
            root.classList.add("unipayment-checkout--submitting");
            var confirmBtn = document.querySelector(
                "#payment-confirmation button",
            );
            if (confirmBtn) {
                confirmBtn.disabled = true;
                confirmBtn.classList.add("disabled");
            }
        }

        if (select) {
            select.addEventListener("change", function () {
                applyLocalScheme();
                scheduleCalculate();
            });
        }
        if (first) {
            first.addEventListener("input", function () {
                if (first.readOnly) return;
                first.value = first.value.replace(/\D/g, "");
                scheduleCalculate();
            });
            first.addEventListener("change", function () {
                if (first.readOnly) return;
                window.clearTimeout(calculateTimer);
                requestCalculation().catch(function () {});
            });
        }
        root.querySelectorAll("[data-unipayment-consent-checkbox]").forEach(
            function (box) {
                box.addEventListener("change", function () {
                    if (consentsOk()) showError("");
                });
            },
        );
        function submit(event) {
            if (!validateBeforeSubmit()) {
                event.preventDefault();
                event.stopImmediatePropagation();
                return false;
            }
            syncHidden();
            markSubmitting();
            return true;
        }
        if (form) {
            // PrestaShop triggers jQuery submit after its asynchronous orderability check.
            if (window.jQuery) window.jQuery(form).on("submit.unipaymentConsents", submit);
            else form.addEventListener("submit", submit);
        }

        function confirmClick(event) {
            var button = event.target.closest(
                "#payment-confirmation button",
            );
            if (!button) return;
            var payment = document.querySelector(
                'input[name="payment-option"][data-module-name="unipayment"]',
            );
            if (!payment || !payment.checked) return;
            if (!root.offsetParent && root.hidden) return;
            if (!validateBeforeSubmit()) {
                event.preventDefault();
                event.stopPropagation();
            }
        }
        document.addEventListener("click", confirmClick, true);
        root.unipaymentCheckoutInvalidate = function () {
            window.clearTimeout(calculateTimer);
            invalidateCalculation();
        };
        root.unipaymentCheckoutRecalculate = function () {
            requestCalculation().catch(function () {});
        };
        root.unipaymentCheckoutDestroy = function () {
            disposed = true;
            root.unipaymentCheckoutInvalidate();
            consents.destroy();
            if (calculateRequest) calculateRequest.abort();
            if (form && window.jQuery) window.jQuery(form).off("submit.unipaymentConsents", submit);
            else if (form) form.removeEventListener("submit", submit);
            document.removeEventListener("click", confirmClick, true);
        };

        if (
            config.preselect_payment &&
            document.body.dataset.unipaymentPaymentPreselected !== "1"
        ) {
            var paymentOption = document.querySelector(
                'input[name="payment-option"][data-module-name="unipayment"]',
            );
            if (paymentOption && !paymentOption.checked) paymentOption.click();
            if (paymentOption)
                document.body.dataset.unipaymentPaymentPreselected = "1";
        }

        applyLocalScheme();
        if (
            Number(config.default_first_installment) > 0 ||
            (first && first.value && first.value !== "0")
        ) {
            scheduleCalculate();
        }
    }

    function initialize() {
        mounted = mounted.filter(function (root) {
            if (root.isConnected !== false) return true;
            root.unipaymentCheckoutDestroy();
            return false;
        });
        document.querySelectorAll("[data-unipayment-checkout]").forEach(setup);
    }

    function invalidateForms() {
        mounted.forEach(function (root) { root.unipaymentCheckoutInvalidate(); });
    }
    function refreshForms() {
        invalidateForms();
        initialize();
        mounted.forEach(function (root) { root.unipaymentCheckoutRecalculate(); });
    }
    // Invalidate before delivery/cart AJAX, not only after its response.
    document.addEventListener("change", function (event) {
        if (event.target.closest && event.target.closest("#js-delivery, #checkout-addresses-step")) invalidateForms();
    }, true);
    if (typeof MutationObserver === "function") {
        new MutationObserver(initialize).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initialize);
    } else {
        initialize();
    }
    if (window.prestashop && typeof window.prestashop.on === "function") {
        window.prestashop.on("updateCart", invalidateForms);
        window.prestashop.on("updatedCart", refreshForms);
        window.prestashop.on("updatedDeliveryForm", refreshForms);
        window.prestashop.on("changedCheckoutStep", initialize);
    }
})();
