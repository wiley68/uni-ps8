(function () {
    "use strict";

    var selector = "[data-unipayment-calculator]";
    var productDetailsSelector =
        "#product-details[data-product], .js-product-details[data-product]";
    var productDetailsElementSelector = "#product-details, .js-product-details";
    var unrelatedProductSelector = ".product-miniature, .quickview";
    var quantitySelector = '#quantity_wanted, input[name="qty"], input[name="quantity"]';
    var domRefreshTimer = null;
    var pendingProductUpdateHint = null;
    var productSelectionUpdating = false;
    var productRequiringLiveAttribute = null;
    var productSelectionRevision = 0;

    function parseConfig(root) {
        try {
            return JSON.parse(root.getAttribute("data-calculator") || "{}");
        } catch (error) {
            return null;
        }
    }

    function nonNegativeInteger(value) {
        if (typeof value === "string" && /^\d+$/.test(value.trim())) {
            value = Number(value);
        }
        return Number.isSafeInteger(value) && value >= 0 ? value : null;
    }

    function readProductDetails(details) {
        if (!details) return null;
        try {
            return JSON.parse(details.getAttribute("data-product"));
        } catch (error) {
            return null;
        }
    }

    function productForm(doc, root, productId) {
        function ownsProduct(form) {
            var field = form.elements.namedItem("id_product");
            return field && nonNegativeInteger(field.value) === productId;
        }

        var form = root.closest("form");
        if (form) {
            return form.id === "add-to-cart-or-refresh" && ownsProduct(form) ? form : null;
        }
        // displayProductAdditionalInfo can render next to the main form.
        var forms = doc.querySelectorAll("form#add-to-cart-or-refresh");
        return forms.length === 1 && ownsProduct(forms[0]) ? forms[0] : null;
    }

    function productAttributeId(doc, root, updateHint, fallback) {
        var productId = nonNegativeInteger(root.getAttribute("data-product-id"));
        if (!productId) return 0;
        var confirmed = updateHint && updateHint.productId === productId
            ? updateHint.attributeId
            : null;

        // Hummingbird's details can sit outside the main form and product container.
        var details = doc.querySelectorAll(productDetailsSelector);
        var selected = null;
        for (var index = 0; index < details.length; index += 1) {
            if (details[index].closest(unrelatedProductSelector)) continue;
            var state = readProductDetails(details[index]);
            if (!state || nonNegativeInteger(state.id_product) !== productId)
                continue;
            var attributeId = nonNegativeInteger(state.id_product_attribute);
            if (attributeId === null) continue;
            if (selected !== null && attributeId !== selected) return null;
            selected = attributeId;
        }
        if (selected === null) {
            var form = productForm(doc, root, productId);
            var field = form && form.elements.namedItem("id_product_attribute");
            if (field) selected = nonNegativeInteger(field.value);
        }
        if (selected !== null)
            return confirmed === null || selected === confirmed ? selected : null;
        if (confirmed !== null) return confirmed;
        return fallback === undefined ? 0 : fallback;
    }

    function productUpdateHint(doc, payload) {
        if (
            !payload ||
            payload.is_quick_view ||
            typeof payload.product_details !== "string"
        ) return null;
        var attributeId = nonNegativeInteger(payload.id_product_attribute);
        if (attributeId === null) return null;

        // Native refresh identifies the product in the returned details fragment.
        var fragment = doc.createElement("template");
        fragment.innerHTML = payload.product_details;
        var details = fragment.content.querySelectorAll(productDetailsSelector);
        if (details.length !== 1) return null;
        var state = readProductDetails(details[0]);
        var productId = state && nonNegativeInteger(state.id_product);
        if (!productId) return null;
        return state.id_product_attribute === undefined ||
            nonNegativeInteger(state.id_product_attribute) === attributeId
            ? { productId: productId, attributeId: attributeId }
            : null;
    }

    function buttonInstallmentLabel(offer) {
        return offer && typeof offer.installment_label === "string"
            ? offer.installment_label
            : "";
    }

    function popupCalculationIdentity(action, selected, lastCalculation) {
        if (action !== "calculate" && lastCalculation) {
            return lastCalculation;
        }
        return selected || null;
    }

    function popupCalculationSchemeFields(identity, selected, activeType) {
        var source = identity || selected || {};
        var selectedScheme = selected || {};
        return {
            scheme_key:
                source.scheme_key || source.key || selectedScheme.key || "",
            scheme_type:
                source.scheme_type ||
                selectedScheme.scheme_type ||
                activeType ||
                "",
            kop_code: source.kop_code || selectedScheme.kop_code || "",
            months:
                source.months != null
                    ? source.months
                    : selectedScheme.months || 0,
            filter_id:
                source.filter_id != null
                    ? source.filter_id
                    : selectedScheme.filter_id || 0,
        };
    }

    function secondaryActionUsesNativeAddToCart(buttonAction) {
        return buttonAction !== "buy";
    }

    function resolveCheckoutRedirectUrl(body, fallbackUrl) {
        if (body && body.checkout_url) {
            return body.checkout_url;
        }
        return fallbackUrl || "";
    }

    function createPreselectOperationToken() {
        var cryptoRef =
            (typeof globalThis !== "undefined" && globalThis.crypto) ||
            (typeof window !== "undefined" && window.crypto);
        if (cryptoRef && typeof cryptoRef.getRandomValues === "function") {
            var bytes = new Uint8Array(16);
            cryptoRef.getRandomValues(bytes);
            var hex = "";
            var index;
            for (index = 0; index < bytes.length; index += 1) {
                hex += ("0" + bytes[index].toString(16)).slice(-2);
            }
            return hex;
        }

        return (
            Date.now().toString(16) +
            Math.floor(Math.random() * 1e9).toString(16)
        );
    }

    function attachPreselectOperationToken(payload, action, operationToken) {
        if (action === "preselect" && operationToken) {
            payload.set("preselect_operation_token", operationToken);
        }
        return payload;
    }

    if (typeof module === "object" && module.exports) {
        module.exports.productAttributeId = productAttributeId;
        module.exports.productUpdateHint = productUpdateHint;
        module.exports.buttonInstallmentLabel = buttonInstallmentLabel;
        module.exports.popupCalculationIdentity = popupCalculationIdentity;
        module.exports.popupCalculationSchemeFields =
            popupCalculationSchemeFields;
        module.exports.secondaryActionUsesNativeAddToCart =
            secondaryActionUsesNativeAddToCart;
        module.exports.resolveCheckoutRedirectUrl = resolveCheckoutRedirectUrl;
        module.exports.createPreselectOperationToken =
            createPreselectOperationToken;
        module.exports.attachPreselectOperationToken =
            attachPreselectOperationToken;
        return;
    }

    function quantity(root, productId) {
        var field;
        if (root) {
            var form = productForm(document, root, productId);
            field = form && form.querySelector(quantitySelector);
            if (!field) {
                var fields = Array.prototype.filter.call(
                    document.querySelectorAll(quantitySelector),
                    function (input) { return !input.closest("form"); },
                );
                var namedQuantity = fields.filter(function (input) { return input.id === "quantity_wanted"; });
                if (namedQuantity.length === 1) field = namedQuantity[0];
                else if (fields.length === 1) field = fields[0];
            }
        } else {
            field = document.querySelector(quantitySelector);
        }
        return field ? Math.max(1, parseInt(field.value, 10) || 1) : 1;
    }

    function applyVisualConfig(root, config) {
        var available = config || {};
        root.classList.toggle(
            "unipayment-product-calculator--dark",
            !!available.dark_button,
        );
        root.classList.toggle(
            "unipayment-product-calculator--no-installment",
            !available.show_installment,
        );
        root.classList.toggle(
            "unipayment-product-calculator--stacked",
            available.buttons_in_row === false,
        );
        root.style.setProperty(
            "--unipayment-button-width",
            (parseInt(available.button_width, 10) || 290) + "px",
        );
        root.style.setProperty(
            "--unipayment-button-height",
            (parseInt(available.button_height, 10) || 56) + "px",
        );
        var heading = root.querySelector(
            ".unipayment-product-calculator__heading",
        );
        if (heading && typeof available.heading === "string") {
            heading.textContent = available.heading;
            heading.hidden = available.heading === "";
        }
        var logo = root.querySelector("[data-unipayment-logo]");
        if (logo)
            logo.src =
                root.getAttribute(
                    available.dark_button
                        ? "data-logo-alternative"
                        : "data-logo-standard",
                ) || logo.src;
    }

    function setup(root) {
        if (root.dataset.unipaymentReady === "1") return;
        root.dataset.unipaymentReady = "1";
        var config = parseConfig(root);
        var modal = root.querySelector("[data-unipayment-modal]");
        var dialog = modal ? modal.querySelector('[role="dialog"]') : null;
        var backgroundInertRecords = [];
        var modalFocusInGuard = null;
        var FOCUSABLE_SELECTOR =
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
        var select = root.querySelector("[data-unipayment-schemes]");
        var first = root.querySelector("[data-unipayment-first]");
        var firstRow = root.querySelector("[data-unipayment-first-row]");
        var step1 = root.querySelector('[data-unipayment-step="1"]');
        var step2 = root.querySelector('[data-unipayment-step="2"]');
        var step3 = root.querySelector('[data-unipayment-step="3"]');
        var processing = root.querySelector("[data-unipayment-processing]");
        var processingDefaultMarkup = processing ? processing.innerHTML : "";
        var customerForm =
            root.querySelector("[data-unipayment-customer-form]") || step2;
        var submitError = root.querySelector("[data-unipayment-submit-error]");
        var applyButton = root.querySelector("[data-unipayment-apply]");
        var secondaryButton = root.querySelector("[data-unipayment-secondary]");
        var submitButton = root.querySelector("[data-unipayment-submit]");
        var errorBox = root.querySelector("[data-unipayment-popup-error]");
        var isCartSource =
            root.getAttribute("data-unipayment-source") === "cart" ||
            root.getAttribute("data-hide-secondary") === "1";
        var activeType = "";
        var consents = window.createUnipaymentShopConsents(root);
        var consentGeneration = 0;

        function selectionKey(productId, attributeId, currentQuantity) {
            return productId + ":" + attributeId + ":" + currentQuantity;
        }

        function readSelection() {
            var productId = root.getAttribute("data-product-id") || "0";
            if (isCartSource) {
                return { productId: productId, attributeId: 0, quantity: quantity() };
            }
            if (productSelectionUpdating) return null;
            var numericProductId = nonNegativeInteger(productId);
            var previousAttribute = verifiedProductId === productId ? verifiedAttribute : null;
            var fallback = verifiedRevision === productSelectionRevision ? previousAttribute : null;
            if (fallback === null && previousAttribute <= 0 && numericProductId !== productRequiringLiveAttribute) {
                fallback = 0;
            }
            var attributeId = productAttributeId(document, root, pendingProductUpdateHint, fallback);
            if (attributeId === null) return null;
            verifiedAttribute = attributeId;
            verifiedProductId = productId;
            verifiedRevision = productSelectionRevision;
            var currentQuantity = quantity(root, numericProductId);
            return {
                productId: productId,
                attributeId: attributeId,
                quantity: currentQuantity,
                key: selectionKey(productId, attributeId, currentQuantity),
            };
        }

        function matchesSelection(key) {
            if (isCartSource) return true;
            var selection = readSelection();
            return !!selection && selection.key === key;
        }

        function setSecondaryDisabled(disabled) {
            if (secondaryButton) secondaryButton.disabled = !!disabled;
        }
        var initialProductId = root.getAttribute("data-product-id");
        var initialAttributeId = !isCartSource && nonNegativeInteger(initialProductId)
            ? productAttributeId(document, root, null, null)
            : null;
        var verifiedAttribute = initialAttributeId;
        var verifiedProductId = initialProductId;
        var verifiedRevision = productSelectionRevision;
        // displayProductAdditionalInfo renders the offer for one unit.
        var initialOfferQuantity = 1;
        var offerSelectionKey = initialAttributeId === null
            ? ""
            : selectionKey(initialProductId, initialAttributeId, initialOfferQuantity);
        var observedSelectionKey = offerSelectionKey;
        var calculationSelectionKey = "";
        var lastCalculation = null;
        var lastOpenTrigger = null;
        var calculateTimer = null;
        var calculateRequest = null;
        var calculateSequence = 0;
        var submissionSequence = 0;
        var applyInFlight = false;
        var refreshTimer = null;
        var refreshRequest = null;
        var refreshRequestKey = "";
        var refreshSequence = 0;
        var refreshedOfferKey = "";
        var redirectPending = false;
        var pendingPreselect = null;
        var popupSubmissionToken = "";
        var preselectOperationToken = "";

        function t(attribute, fallback) {
            return root.getAttribute(attribute) || fallback;
        }

        function isModalOpen() {
            return !!modal && modal.hidden === false;
        }

        function supportsInert() {
            return "inert" in HTMLElement.prototype;
        }

        function getDialogContainer() {
            return dialog || modal;
        }

        function isElementHidden(element, stopAt) {
            var node = element;
            while (node && node !== stopAt) {
                if (
                    node.hidden ||
                    node.getAttribute("aria-hidden") === "true"
                ) {
                    return true;
                }
                node = node.parentElement;
            }
            return false;
        }

        function getFocusableElements(container) {
            if (!container) return [];
            var nodes = container.querySelectorAll(FOCUSABLE_SELECTOR);
            var focusables = [];
            var index;
            for (index = 0; index < nodes.length; index += 1) {
                var element = nodes[index];
                if (element.disabled) continue;
                if (isElementHidden(element, container)) continue;
                var style = window.getComputedStyle(element);
                if (style.visibility === "hidden" || style.display === "none") {
                    continue;
                }
                focusables.push(element);
            }
            return focusables;
        }

        function focusDialogContainer() {
            var container = getDialogContainer();
            if (!container || typeof container.focus !== "function") return;
            if (!container.hasAttribute("tabindex")) {
                container.setAttribute("tabindex", "-1");
            }
            container.focus();
        }

        function focusInitialInModal() {
            if (
                select &&
                !select.disabled &&
                !isElementHidden(select, getDialogContainer())
            ) {
                select.focus();
                return;
            }
            var focusables = getFocusableElements(getDialogContainer());
            if (focusables.length) {
                focusables[0].focus();
                return;
            }
            focusDialogContainer();
        }

        function applyBackgroundInert() {
            if (!supportsInert() || !modal) return;
            backgroundInertRecords = [];
            var seen = [];
            function remember(element) {
                if (
                    !element ||
                    element === modal ||
                    seen.indexOf(element) !== -1
                ) {
                    return;
                }
                seen.push(element);
                backgroundInertRecords.push({
                    el: element,
                    inert: element.inert,
                });
                element.inert = true;
            }
            var index;
            var child;
            if (document.body) {
                for (
                    index = 0;
                    index < document.body.children.length;
                    index += 1
                ) {
                    child = document.body.children[index];
                    if (!child.contains(modal)) remember(child);
                }
            }
            var anchor = modal;
            while (anchor && anchor !== document.body) {
                var parent = anchor.parentElement;
                if (parent) {
                    for (
                        index = 0;
                        index < parent.children.length;
                        index += 1
                    ) {
                        child = parent.children[index];
                        if (child !== anchor && !child.contains(modal)) {
                            remember(child);
                        }
                    }
                }
                anchor = parent;
            }
        }

        function restoreBackgroundInert() {
            backgroundInertRecords.forEach(function (record) {
                if (record.el && supportsInert()) {
                    record.el.inert = record.inert;
                }
            });
            backgroundInertRecords = [];
        }

        function enableModalFocusGuard() {
            if (modalFocusInGuard) return;
            modalFocusInGuard = function (event) {
                if (!isModalOpen()) return;
                var container = getDialogContainer();
                if (!container || container.contains(event.target)) return;
                event.stopPropagation();
                var focusables = getFocusableElements(container);
                if (focusables.length) focusables[0].focus();
                else focusDialogContainer();
            };
            document.addEventListener("focusin", modalFocusInGuard, true);
        }

        function disableModalFocusGuard() {
            if (!modalFocusInGuard) return;
            document.removeEventListener("focusin", modalFocusInGuard, true);
            modalFocusInGuard = null;
        }

        function handleModalTabKey(event) {
            if (!isModalOpen()) return;
            var container = getDialogContainer();
            var focusables = getFocusableElements(container);
            if (!focusables.length) {
                event.preventDefault();
                focusDialogContainer();
                return;
            }
            var first = focusables[0];
            var last = focusables[focusables.length - 1];
            var active = document.activeElement;
            if (!container.contains(active)) {
                event.preventDefault();
                (event.shiftKey ? last : first).focus();
                return;
            }
            if (event.shiftKey && active === first) {
                event.preventDefault();
                last.focus();
                return;
            }
            if (!event.shiftKey && active === last) {
                event.preventDefault();
                first.focus();
            }
        }

        var STEP_TRANSITION_MS = 600;
        var currentStep = 1;
        var stepTimer = null;

        function prefersReducedMotion() {
            return (
                window.matchMedia &&
                window.matchMedia("(prefers-reduced-motion: reduce)").matches
            );
        }

        function clearStepStyles(el) {
            if (!el) return;
            el.style.height = "";
            el.style.opacity = "";
            el.style.overflow = "";
            el.style.transition = "";
            el.classList.remove(
                "unipayment-product-calculator__step--animating",
            );
        }

        function applyStepVisibility(number) {
            step1.hidden = number !== 1;
            step1.classList.toggle(
                "unipayment-product-calculator__step--active",
                number === 1,
            );
            step2.hidden = number !== 2;
            step2.classList.toggle(
                "unipayment-product-calculator__step--active",
                number === 2,
            );
            if (step3) {
                step3.hidden = number !== 3;
                step3.classList.toggle(
                    "unipayment-product-calculator__step--active",
                    number === 3,
                );
            }
            clearStepStyles(step1);
            clearStepStyles(step2);
            clearStepStyles(step3);
        }

        function animateSteps(leaving, entering, number) {
            entering.hidden = false;
            entering.classList.add(
                "unipayment-product-calculator__step--active",
            );
            leaving.classList.add(
                "unipayment-product-calculator__step--animating",
            );
            entering.classList.add(
                "unipayment-product-calculator__step--animating",
            );
            var fromHeight = leaving.scrollHeight;
            var toHeight = entering.scrollHeight;
            leaving.style.overflow = "hidden";
            entering.style.overflow = "hidden";
            leaving.style.height = fromHeight + "px";
            leaving.style.opacity = "1";
            entering.style.height = "0px";
            entering.style.opacity = "0";
            void entering.offsetHeight;
            var transition =
                "height " +
                STEP_TRANSITION_MS +
                "ms ease-in-out, opacity " +
                STEP_TRANSITION_MS +
                "ms ease-in-out";
            leaving.style.transition = transition;
            entering.style.transition = transition;
            leaving.style.height = "0px";
            leaving.style.opacity = "0";
            entering.style.height = toHeight + "px";
            entering.style.opacity = "1";
            stepTimer = window.setTimeout(function () {
                stepTimer = null;
                applyStepVisibility(number);
            }, STEP_TRANSITION_MS);
        }

        function setStep(number, options) {
            var animate =
                !!(options && options.animate) && !prefersReducedMotion();
            var from = currentStep;
            var betweenFirstTwo =
                (from === 1 && number === 2) || (from === 2 && number === 1);
            if (stepTimer) {
                window.clearTimeout(stepTimer);
                stepTimer = null;
            }
            if (!animate || !betweenFirstTwo || from === number) {
                currentStep = number;
                applyStepVisibility(number);
                return;
            }
            applyStepVisibility(from);
            currentStep = number;
            animateSteps(
                from === 1 ? step1 : step2,
                number === 1 ? step1 : step2,
                number,
            );
        }

        function customerField(name) {
            return customerForm.querySelector('[name="' + name + '"]');
        }
        function fieldError(name) {
            return customerForm.querySelector(
                '[data-unipayment-field-error="' + name + '"]',
            );
        }
        function nonEmpty(value) {
            return String(value || "").trim() !== "";
        }
        function validPhone(value) {
            var phone = String(value || "").trim();
            return (
                phone !== "" && /^[-0-9+() ]+$/.test(phone) && /\d/.test(phone)
            );
        }
        function validEmail(value) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(
                String(value || "").trim(),
            );
        }
        /** Mirrors PrestaShop Validate::isName for Step 2 UX (server remains authoritative). */
        function validName(value) {
            return /^[^0-9!<>,;?=+()@#"°{}_$%:¤|]*$/u.test(
                String(value || "").trim(),
            );
        }
        /** Mirrors PrestaShop Validate::isAddress for Step 2 UX (server remains authoritative). */
        function validAddressLine(value) {
            var address = String(value || "").trim();
            return address === "" || /^[^!<>?=+@{}_$%]*$/u.test(address);
        }

        function consentCheckboxes() {
            return root.querySelectorAll("[data-unipayment-consent-checkbox]");
        }

        function areMandatoryConsentsChecked() {
            return consents.ready();
        }

        function resetConsents() {
            consentCheckboxes().forEach(function (input) {
                input.checked = false;
            });
        }

        function appendAcceptedConsents(payload) {
            consents.acceptedIds().forEach(function (id) {
                payload.append("unipayment_consent[]", id);
            });
        }

        function customerErrors() {
            var errors = {};
            var firstName = customerField("first_name").value;
            if (!nonEmpty(firstName))
                errors.first_name = t(
                    "data-required-field-message",
                    "Полето е задължително.",
                );
            else if (!validName(firstName))
                errors.first_name = t(
                    "data-invalid-first-name-message",
                    "Името може да съдържа само букви, интервал, тире и апостроф.",
                );
            var lastName = customerField("last_name").value;
            if (!nonEmpty(lastName))
                errors.last_name = t(
                    "data-required-field-message",
                    "Полето е задължително.",
                );
            else if (!validName(lastName))
                errors.last_name = t(
                    "data-invalid-last-name-message",
                    "Фамилията може да съдържа само букви, интервал, тире и апостроф.",
                );
            var address = customerField("address").value;
            if (!nonEmpty(address))
                errors.address = t(
                    "data-required-field-message",
                    "Полето е задължително.",
                );
            else if (!validAddressLine(address))
                errors.address = t(
                    "data-invalid-address-message",
                    "Адресът може да съдържа букви, цифри, интервали и стандартни знаци. Не използвайте символи като <, >, =, +, @, {, }, _, $, %, !, ?.",
                );
            var phone = customerField("phone").value;
            if (!nonEmpty(phone))
                errors.phone = t(
                    "data-required-field-message",
                    "Полето е задължително.",
                );
            else if (!validPhone(phone))
                errors.phone = t(
                    "data-invalid-phone-message",
                    "Телефонът може да съдържа цифри, интервали, +, -, ( и ).",
                );
            var email = customerField("email").value;
            if (!nonEmpty(email))
                errors.email = t(
                    "data-required-field-message",
                    "Полето е задължително.",
                );
            else if (!validEmail(email))
                errors.email = t(
                    "data-invalid-email-message",
                    "Въведете валиден e-mail адрес, например name@example.com.",
                );
            return errors;
        }

        function showCustomerErrors(errors) {
            var fields = [
                "first_name",
                "last_name",
                "address",
                "phone",
                "email",
            ];
            var firstInvalid = null;
            fields.forEach(function (name) {
                var el = fieldError(name);
                var field = customerField(name);
                if (!el || !field) return;
                var message = errors[name] || "";
                el.textContent = message;
                field.setAttribute("aria-invalid", message ? "true" : "false");
                if (message && !firstInvalid) firstInvalid = field;
            });
            if (firstInvalid && typeof firstInvalid.focus === "function") {
                firstInvalid.focus();
            }
        }

        function showCustomerFieldError(name, message) {
            fieldError(name).textContent = message || "";
            customerField(name).setAttribute(
                "aria-invalid",
                message ? "true" : "false",
            );
        }

        function updateSubmitState(showErrors) {
            var errors = customerErrors();
            if (showErrors) showCustomerErrors(errors);
            var valid =
                !redirectPending &&
                Object.keys(errors).length === 0 &&
                !!lastCalculation &&
                areMandatoryConsentsChecked();
            submitButton.disabled = !valid;
            submitButton.setAttribute(
                "aria-disabled",
                valid ? "false" : "true",
            );
            return valid;
        }

        function resetCustomerForm() {
            if (!customerForm || !submitButton) return;
            customerForm.querySelectorAll("input").forEach(function (input) {
                if (input.type === "checkbox") input.checked = false;
                else input.value = input.defaultValue;
            });
            resetConsents();
            showCustomerErrors({});
            submitError.textContent = "";
            updateSubmitState(false);
        }

        function resetPopup() {
            submissionSequence += 1;
            applyInFlight = false;
            window.clearTimeout(calculateTimer);
            calculateTimer = null;
            abortCalculationRequest();
            calculateSequence += 1;
            consentGeneration = consents.begin(null);
            lastCalculation = null;
            calculationSelectionKey = "";
            redirectPending = false;
            pendingPreselect = null;
            popupSubmissionToken = "";
            preselectOperationToken = "";
            root.unipaymentPopupSubmissionToken = "";
            if (!isCartSource) root.unipaymentSelectedFinancing = null;
            activeType = "";
            root.classList.remove("unipayment-product-calculator--error");
            first.value = "0";
            first.readOnly = false;
            firstRow.hidden = true;
            select.textContent = "";
            errorBox.textContent = "";
            applyButton.disabled = true;
            setSecondaryDisabled(true);
            resetCustomerForm();
            setStep(1);
        }

        function close() {
            if (redirectPending) return;
            modal.hidden = true;
            modal.setAttribute("aria-hidden", "true");
            document.body.classList.remove("unipayment-modal-open");
            disableModalFocusGuard();
            restoreBackgroundInert();
            setProcessingState(false);
            resetPopup();
            if (lastOpenTrigger && document.body.contains(lastOpenTrigger))
                lastOpenTrigger.focus();
            lastOpenTrigger = null;
        }

        function setProcessingState(active) {
            if (!processing) return;
            if (active) {
                root.classList.remove("unipayment-product-calculator--error");
                if (processingDefaultMarkup !== "") {
                    processing.innerHTML = processingDefaultMarkup;
                }
                root.classList.add("unipayment-product-calculator--processing");
                processing.removeAttribute("hidden");
                modal.setAttribute("aria-busy", "true");
                return;
            }

            root.classList.remove("unipayment-product-calculator--processing");
            processing.setAttribute("hidden", "hidden");
            modal.setAttribute("aria-busy", "false");
        }

        function optionText(scheme) {
            var text = (
                root.getAttribute("data-months-label") || "%d месеца"
            ).replace("%d", scheme.months);
            return (
                text + (scheme.description ? " - " + scheme.description : "")
            );
        }

        function rebuildSchemes(type) {
            var offer = config && config.offers ? config.offers[type] : null;
            select.textContent = "";
            if (!offer || !offer.schemes || !offer.schemes.length) return false;
            offer.schemes.forEach(function (scheme) {
                var option = document.createElement("option");
                option.value =
                    scheme.key ||
                    (scheme.scheme_type === "promo" ? "p:" : "") +
                        scheme.months +
                        ":" +
                        scheme.filter_id;
                option.textContent = optionText(scheme) + "\u00a0\u00a0\u00a0";
                option.selected = option.value === offer.preferred_scheme_key;
                select.appendChild(option);
            });
            select.disabled = false;
            return true;
        }

        function selectedScheme() {
            var offer =
                config && config.offers ? config.offers[activeType] : null;
            if (!offer || !offer.schemes) return null;
            var key = select.value;
            return (
                offer.schemes.filter(function (scheme) {
                    var schemeKey =
                        scheme.key ||
                        (scheme.scheme_type === "promo" ? "p:" : "") +
                            scheme.months +
                            ":" +
                            scheme.filter_id;
                    return schemeKey === key;
                })[0] || null
            );
        }

        function displayAmount(target, display) {
            var element = root.querySelector(
                '[data-unipayment-display="' + target + '"]',
            );
            if (element)
                element.textContent = display ? display.primary : "";
        }

        function applyCalculation(calculation, key) {
            if (!consents.apply(consentGeneration, calculation.consent_context)) {
                lastCalculation = null;
                throw new Error("consent_context");
            }
            lastCalculation = calculation;
            calculationSelectionKey = key;
            displayAmount("price", calculation.price_display);
            displayAmount(
                "financed_amount",
                calculation.financed_amount_display,
            );
            displayAmount(
                "monthly_installment",
                calculation.monthly_installment_display,
            );
            displayAmount("total_payable", calculation.total_payable_display);
            root.querySelector('[data-unipayment-display="glp"]').textContent =
                calculation.glp_display + "%";
            root.querySelector('[data-unipayment-display="gpr"]').textContent =
                calculation.gpr_display + "%";
            var firstInstallmentLocked = !!calculation.first_installment_locked;
            var firstInstallment = Number(calculation.first_installment || 0);
            first.value = firstInstallmentLocked
                ? firstInstallment.toFixed(2)
                : String(Math.trunc(firstInstallment));
            first.readOnly = firstInstallmentLocked;
            firstRow.hidden =
                !calculation.show_first_installment &&
                !calculation.first_installment_locked;
            errorBox.textContent = "";
            applyButton.disabled = false;
            setSecondaryDisabled(false);
            updateSubmitState(false);
        }

        function calculationPayload(action, selection) {
            if (!selection || (!isCartSource && action !== "calculate" &&
                (!lastCalculation || calculationSelectionKey !== selection.key)))
                return null;
            var scheme = selectedScheme();
            if (!scheme) return null;
            var identity = popupCalculationIdentity(
                action,
                scheme,
                lastCalculation,
            );
            var fields = popupCalculationSchemeFields(
                identity,
                scheme,
                activeType,
            );
            var payload = new URLSearchParams();
            payload.set("token", root.getAttribute("data-popup-token") || "");
            payload.set("popup_action", action || "calculate");
            payload.set("id_product", selection.productId);
            payload.set("id_product_attribute", String(selection.attributeId));
            payload.set("quantity", String(selection.quantity));
            payload.set("popup_offer_type", activeType);
            payload.set("scheme_key", fields.scheme_key);
            payload.set("scheme_type", fields.scheme_type);
            payload.set("kop_code", fields.kop_code);
            payload.set("months", String(fields.months || 0));
            payload.set("filter_id", String(fields.filter_id || 0));
            payload.set(
                "first_installment",
                String(
                    action !== "calculate" &&
                        lastCalculation &&
                        lastCalculation.first_installment != null
                        ? lastCalculation.first_installment
                        : first.value || "0",
                ),
            );
            attachPreselectOperationToken(
                payload,
                action || "calculate",
                preselectOperationToken,
            );
            return payload;
        }

        function invalidateCalculation() {
            window.clearTimeout(calculateTimer);
            calculateTimer = null;
            var scheme = selectedScheme() || {};
            calculateSequence += 1;
            lastCalculation = null;
            calculationSelectionKey = "";
            consentGeneration = consents.begin(JSON.stringify([scheme.kop_code, scheme.months, scheme.filter_id, scheme.scheme_type]));
            applyButton.disabled = true;
            setSecondaryDisabled(true);
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.setAttribute("aria-disabled", "true");
            }
        }

        function invalidatePreview() {
            invalidateCalculation();
            if (applyInFlight) return;
            popupSubmissionToken = "";
            root.unipaymentPopupSubmissionToken = "";
            if (!isCartSource) root.unipaymentSelectedFinancing = null;
        }

        function showCalculationRetry() {
            errorBox.textContent = t(
                "data-calculate-failed-message",
                "Неуспешно изчисление. Моля, опитайте отново.",
            );
        }

        function abortCalculationRequest() {
            if (calculateRequest && calculateRequest.controller) {
                calculateRequest.controller.abort();
            }
            calculateRequest = null;
        }

        function hasCurrentCalculationRequest(selection) {
            var request = calculateRequest;
            if (!request || request.sequence !== calculateSequence || request.offer !== config ||
                request.endpoint !== root.getAttribute("data-popup-endpoint") ||
                (request.controller && request.controller.signal.aborted)) return false;
            var payload = calculationPayload("calculate", selection);
            return !!payload && request.previewBody === payload.toString();
        }

        function requestCalculation(action, selection) {
            window.clearTimeout(calculateTimer);
            calculateTimer = null;
            if (applyInFlight || modal.hidden || !root.isConnected) return Promise.reject(new Error("submission"));
            var payload = calculationPayload(action, selection);
            var endpoint = root.getAttribute("data-popup-endpoint");
            if (!payload || !endpoint)
                return Promise.reject(new Error("selection"));
            if (!isCartSource) {
                if (!offerSelectionKey) offerSelectionKey = selection.key;
                observedSelectionKey = selection.key;
            }
            invalidateCalculation();
            abortCalculationRequest();
            var request = {
                controller: typeof AbortController === "function" ? new AbortController() : null,
                sequence: ++calculateSequence,
                offer: config,
                endpoint: endpoint,
                previewBody: action === "calculate" ? payload.toString() : null,
            };
            calculateRequest = request;
            var sequence = request.sequence;
            applyButton.disabled = true;
            setSecondaryDisabled(true);
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
            if (request.controller) options.signal = request.controller.signal;
            return fetch(endpoint, options)
                .then(function (response) {
                    return response.json().then(function (body) {
                        if (sequence !== calculateSequence || applyInFlight || root.isConnected === false || modal.hidden)
                            throw new Error("stale");
                        if (!matchesSelection(selection.key)) {
                            if (action === "calculate") calculateNow();
                            throw new Error("stale");
                        }
                        if (!response.ok || !body.success)
                            throw new Error(body.message || "calculation");
                        applyCalculation(body.calculation, selection.key);
                        return body;
                    });
                })
                .then(function (body) {
                    if (calculateRequest === request) calculateRequest = null;
                    return body;
                }, function (error) {
                    if (calculateRequest === request) calculateRequest = null;
                    if (
                        sequence === calculateSequence && !applyInFlight && root.isConnected !== false && !modal.hidden &&
                        error.name !== "AbortError" &&
                        error.message !== "stale"
                    ) {
                        if (error.message === "consent_context")
                            errorBox.textContent = t("data-consent-context-invalid-message", "Обновете страницата и изберете схема отново.");
                        else showCalculationRetry();
                    }
                    throw error;
                });
        }

        function calculateNow() {
            if (applyInFlight || redirectPending || modal.hidden || !root.isConnected) return;
            var selection = readSelection();
            if (!selection || (!isCartSource && !matchesOffer(selection))) {
                invalidatePreview();
                setStep(1);
                if (selection) root.unipaymentRefresh();
                else showCalculationRetry();
                return;
            }
            requestCalculation("calculate", selection).catch(function () {});
        }

        function matchesOffer(selection) {
            if (offerSelectionKey) return offerSelectionKey === selection.key;
            return selection.productId === initialProductId && selection.quantity === initialOfferQuantity;
        }

        function currentCalculationSelection() {
            if (!lastCalculation) return null;
            var selection = readSelection();
            if (selection && (isCartSource || selection.key === calculationSelectionKey))
                return selection;
            invalidatePreview();
            setStep(1);
            calculateNow();
            return null;
        }

        function open(type, trigger) {
            if (redirectPending || !config) return;
            resetPopup();
            activeType = type;
            lastOpenTrigger = trigger || null;
            if (!rebuildSchemes(type)) return;
            modal.hidden = false;
            modal.setAttribute("aria-hidden", "false");
            document.body.classList.add("unipayment-modal-open");
            applyBackgroundInert();
            enableModalFocusGuard();
            calculateNow();
            focusInitialInModal();
        }

        function nativeAddButton() {
            return document.querySelector(
                '.product-add-to-cart button[data-button-action="add-to-cart"], .product-add-to-cart button.add-to-cart, button[data-button-action="add-to-cart"]',
            );
        }

        function redirectToCheckout(redirectUrl) {
            if (!redirectUrl) {
                errorBox.textContent = t(
                    "data-add-to-cart-failed-message",
                    "Не може да се добави в количката.",
                );
                redirectPending = false;
                return;
            }
            window.location.assign(redirectUrl);
        }

        function addToCart(redirectUrl) {
            var button = nativeAddButton();
            if (
                !button ||
                button.disabled ||
                button.classList.contains("disabled")
            ) {
                errorBox.textContent = t(
                    "data-add-to-cart-failed-message",
                    "Не може да се добави в количката.",
                );
                redirectPending = false;
                return;
            }
            if (
                redirectUrl &&
                window.prestashop &&
                typeof window.prestashop.on === "function"
            ) {
                redirectPending = true;
                window.prestashop.on("updatedCart", function () {
                    if (redirectPending) window.location.assign(redirectUrl);
                });
            } else {
                close();
            }
            button.click();
        }

        function handleSecondary() {
            if (isCartSource || redirectPending) return;
            var selection = currentCalculationSelection();
            if (!selection) return;
            if (
                secondaryActionUsesNativeAddToCart(
                    root.getAttribute("data-button-action"),
                )
            ) {
                addToCart("");
                return;
            }
            redirectPending = true;
            var preselect = {};
            pendingPreselect = preselect;
            preselectOperationToken = createPreselectOperationToken();
            var request = requestCalculation("preselect", selection);
            var sequence = calculateSequence;
            request
                .then(function (body) {
                    if (sequence !== calculateSequence || !matchesSelection(selection.key)) throw new Error("stale");
                    pendingPreselect = null;
                    applyButton.disabled = true;
                    setSecondaryDisabled(true);
                    redirectToCheckout(
                        resolveCheckoutRedirectUrl(
                            body,
                            root.getAttribute("data-checkout-url"),
                        ),
                    );
                })
                .catch(function (error) {
                    if (pendingPreselect !== preselect) return;
                    pendingPreselect = null;
                    redirectPending = false;
                    var offer = preselect.offer;
                    if (offer && applyRefreshedOffer(offer.sequence, offer.key, offer.payload)) return;
                    if (error.message === "stale") {
                        calculateNow();
                    }
                });
        }

        function transitionToStep2() {
            if (redirectPending || !consents.resolved()) return;
            var selection = currentCalculationSelection();
            if (!selection) return;
            if (!customerForm || !submitButton || !step3) {
                errorBox.textContent = t(
                    "data-customer-form-missing-message",
                    "Формата за лични данни не се зареди. Моля, презаредете страницата.",
                );
                return;
            }
            var state = {
                productId:
                    parseInt(selection.productId, 10) || 0,
                productAttributeId: selection.attributeId,
                quantity: selection.quantity,
                type: activeType,
                schemeType: lastCalculation.scheme_type,
                kopCode: lastCalculation.kop_code,
                months: lastCalculation.months,
                filterId: lastCalculation.filter_id,
                firstInstallment: lastCalculation.first_installment,
                calculation: lastCalculation,
            };
            root.unipaymentSelectedFinancing = state;
            root.dispatchEvent(
                new CustomEvent("unipayment:schemeSelected", {
                    bubbles: true,
                    detail: state,
                }),
            );

            function enterStep2() {
                setStep(2, { animate: true });
                showCustomerErrors({});
                submitError.textContent = "";
                updateSubmitState(false);
            }

            if (isCartSource) {
                enterStep2();
                return;
            }

            var transitionSequence = calculateSequence;
            ensurePopupSubmissionToken(true)
                .then(function () {
                    if (transitionSequence === calculateSequence && !modal.hidden && root.isConnected !== false && consents.resolved()) enterStep2();
                })
                .catch(function () {
                    if (transitionSequence !== calculateSequence || modal.hidden || root.isConnected === false) return;
                    errorBox.textContent = t(
                        "data-validation-failed-message",
                        "Данните не могат да бъдат валидирани.",
                    );
                });
        }

        function ensurePopupSubmissionToken(forceIssue) {
            if (isCartSource) {
                return Promise.resolve("");
            }
            if (!forceIssue && popupSubmissionToken) {
                return Promise.resolve(popupSubmissionToken);
            }
            var selection = readSelection();
            var payload = calculationPayload("issue_submission_token", selection);
            var endpoint = root.getAttribute("data-popup-endpoint");
            if (!payload || !endpoint) {
                return Promise.reject(new Error("token"));
            }
            if (popupSubmissionToken) {
                payload.set("popup_submission_token", popupSubmissionToken);
            }
            if (applyButton) applyButton.disabled = true;
            var sequence = calculateSequence;
            return fetch(endpoint, {
                method: "POST",
                credentials: "same-origin",
                body: payload,
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    "Content-Type":
                        "application/x-www-form-urlencoded;charset=UTF-8",
                },
            })
                .then(function (response) {
                    return response.json().then(function (body) {
                        if (sequence !== calculateSequence || root.isConnected === false || modal.hidden) throw new Error("stale");
                        if (!matchesSelection(selection.key)) {
                            currentCalculationSelection();
                            throw new Error("stale");
                        }
                        if (
                            !response.ok ||
                            !body.success ||
                            !body.popup_submission_token
                        ) {
                            throw new Error((body && body.message) || "token");
                        }
                        applyCalculation(body.calculation, selection.key);
                        popupSubmissionToken = body.popup_submission_token;
                        root.unipaymentPopupSubmissionToken =
                            popupSubmissionToken;
                        return popupSubmissionToken;
                    });
                })
                .then(function (token) {
                    if (applyButton && lastCalculation && sequence === calculateSequence && consents.resolved())
                        applyButton.disabled = false;
                    return token;
                })
                .catch(function (error) {
                    if (applyButton && lastCalculation && sequence === calculateSequence && consents.resolved())
                        applyButton.disabled = false;
                    throw error;
                });
        }

        function requestStep2Validation() {
            if (redirectPending) return;
            if (!customerForm || !submitButton || !step3) return;
            var selection = currentCalculationSelection();
            if (!selection) return;
            if (!updateSubmitState(true)) return;
            var endpoint = root.getAttribute("data-popup-endpoint");
            if (!endpoint) return;
            window.clearTimeout(calculateTimer);
            calculateTimer = null;
            window.clearTimeout(refreshTimer);
            refreshTimer = null;
            cancelOfferRequest();
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.setAttribute("aria-disabled", "true");
            }
            submitError.textContent = "";
            var submitSequence = calculateSequence;
            var operation = ++submissionSequence;
            var applyDispatched = false;
            redirectPending = true;
            setProcessingState(true);
            showSmartUcfLoading();
            setStep(3);

            ensurePopupSubmissionToken(false)
                .then(function (token) {
                    if (submitSequence !== calculateSequence || modal.hidden || !root.isConnected ||
                        !consents.ready() || !matchesSelection(selection.key)) throw new Error("stale");
                    var payload = calculationPayload("apply", selection);
                    if (!payload) throw new Error("selection");
                    if (!isCartSource) {
                        if (!token && !popupSubmissionToken) {
                            throw new Error("token");
                        }
                        payload.set(
                            "popup_submission_token",
                            token || popupSubmissionToken,
                        );
                    }
                    [
                        "first_name",
                        "last_name",
                        "address",
                        "phone",
                        "email",
                    ].forEach(function (name) {
                        payload.set(name, customerField(name).value.trim());
                    });
                    appendAcceptedConsents(payload);
                    // The sent operation has its own identity. Preview changes may not discard its result.
                    applyInFlight = true;
                    applyDispatched = true;
                    return fetch(endpoint, {
                        method: "POST",
                        credentials: "same-origin",
                        body: payload,
                        headers: {
                            "X-Requested-With": "XMLHttpRequest",
                            "Content-Type":
                                "application/x-www-form-urlencoded;charset=UTF-8",
                        },
                    });
                })
                .then(function (response) {
                    return response.json().then(function (body) {
                        if (operation !== submissionSequence || modal.hidden || root.isConnected === false) throw new Error("stale");
                        if (body && body.popup_submission_token)
                            popupSubmissionToken = body.popup_submission_token;
                        if (!response.ok || !body.success) {
                            applyInFlight = false;
                            if (body && body.selection_changed) {
                                popupSubmissionToken = "";
                                root.unipaymentPopupSubmissionToken = "";
                            }
                            if (body && body.errors)
                                showCustomerErrors(body.errors);
                            // Prefer field-level guidance; keep top message for consents / non-field errors.
                            if (
                                body &&
                                body.errors &&
                                (body.errors.first_name ||
                                    body.errors.last_name ||
                                    body.errors.address ||
                                    body.errors.phone ||
                                    body.errors.email)
                            ) {
                                submitError.textContent = body.errors.consents
                                    ? body.errors.consents
                                    : "";
                            } else {
                                submitError.textContent =
                                    body.message ||
                                    t(
                                        "data-validation-failed-message",
                                        "Данните не могат да бъдат валидирани.",
                                    );
                            }
                            redirectPending = false;
                            setProcessingState(false);
                            throw new Error(body.message || "validation");
                        }
                        if (body.step === "processing") {
                            showSmartUcfLoading();
                            setStep(3);
                            return;
                        }
                        applyInFlight = false;
                        if (body.step === "outcome_unknown") {
                            setProcessingState(false);
                            showSmartUcfError(
                                body.cp_error ||
                                    body.smartucf_error ||
                                    body.message ||
                                    t(
                                        "data-smartucf-error-default",
                                        "Възникна грешка при обработката на заявката.",
                                    ),
                                { omitRetry: !!(body.final || body.cp_error) },
                            );
                            setStep(3);
                            return;
                        }
                        root.unipaymentOrderResult = body.order;
                        if (body.redirect_url) {
                            window.location.assign(body.redirect_url);
                            return;
                        }
                        setProcessingState(false);
                        if (body.cp_error || body.smartucf_error) {
                            showSmartUcfError(body.cp_error || body.smartucf_error, {
                                omitRetry: !!(body.final || body.cp_error),
                            });
                            setStep(3);
                            return;
                        }
                        showOrderConfirmation(
                            body.order,
                            body.post_order_error || body.email_error || "",
                        );
                        setStep(3);
                    });
                })
                .catch(function () {
                    if (operation !== submissionSequence || modal.hidden || root.isConnected === false) return;
                    applyInFlight = false;
                    redirectPending = false;
                    setProcessingState(false);
                    if (!isCartSource && !applyDispatched && (!lastCalculation || !matchesSelection(selection.key))) {
                        setStep(1);
                        calculateNow();
                        return;
                    }
                    setStep(2);
                    if (!lastCalculation || !matchesSelection(calculationSelectionKey)) {
                        submitError.textContent = t("data-consent-context-invalid-message", "Обновете страницата и изберете схема отново.");
                        if (lastCalculation) currentCalculationSelection();
                    }
                    if (!step2.hidden) updateSubmitState(false);
                });
        }

        function showSmartUcfLoading() {
            if (!step3) return;
            var processingTitle = t(
                "data-processing-title",
                "Обработване на заявката",
            );
            var processingMessage = t(
                "data-processing-message",
                "Моля, изчакайте...",
            );
            step3.innerHTML =
                '<div class="unipayment-product-calculator__confirmation">' +
                '<h2 class="unipayment-product-calculator__popup-title">' +
                processingTitle +
                "</h2>" +
                "<p>" +
                processingMessage +
                "</p>" +
                "</div>";
        }

        function showSmartUcfError(errorMessage, options) {
            if (!processing) return;
            options = options || {};
            var omitRetry = options.omitRetry === true;
            var errorDefaultMessage = t(
                "data-smartucf-error-default",
                "Възникна грешка при обработката на заявката.",
            );
            var errorRetryMessage = t(
                "data-smartucf-error-retry",
                "Моля, опитайте по-късно.",
            );
            var closeLabel = t("data-close-label", "Затвори");
            root.classList.remove("unipayment-product-calculator--processing");
            root.classList.add("unipayment-product-calculator--error");
            modal.setAttribute("aria-busy", "false");
            processing.removeAttribute("hidden");
            processing.innerHTML =
                '<div class="unipayment-product-calculator__processing-panel" role="alertdialog" aria-live="assertive" aria-busy="false">' +
                '<p class="unipayment-product-calculator__processing-error">' +
                (errorMessage || errorDefaultMessage) +
                "</p>" +
                (omitRetry
                    ? ""
                    : '<p class="unipayment-product-calculator__processing-text">' +
                      errorRetryMessage +
                      "</p>") +
                '<div class="unipayment-product-calculator__popup-actions unipayment-product-calculator__popup-actions--centered">' +
                '<button type="button" class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--primary" data-unipayment-close>' +
                "<span><b>" +
                closeLabel +
                "</b></span></button></div></div>";
            redirectPending = false;
        }

        function showOrderConfirmation(order, smartucfError) {
            if (!step3) return;
            var closeLabel = t("data-close-label", "Затвори");
            var orderSuccessTitle = t(
                "data-order-success-title",
                "Заявката е изпратена успешно",
            );
            var orderNumberLabel = t(
                "data-order-number-label",
                "Номер на поръчка:",
            );
            var orderConfirmationMessage = t(
                "data-order-confirmation-message",
                "Очаквайте потвърждение от УниКредит.",
            );
            var warning = smartucfError
                ? '<p class="unipayment-product-calculator__popup-row--note">' +
                  smartucfError +
                  "</p>"
                : "";
            step3.innerHTML =
                '<div class="unipayment-product-calculator__confirmation">' +
                '<h2 class="unipayment-product-calculator__popup-title">' +
                orderSuccessTitle +
                "</h2>" +
                "<p>" +
                orderNumberLabel +
                " <strong>" +
                (order.order_reference || "") +
                "</strong></p>" +
                warning +
                "<p>" +
                orderConfirmationMessage +
                "</p>" +
                '<div class="unipayment-product-calculator__popup-actions">' +
                '<button type="button" class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--primary" data-unipayment-close>' +
                "<span><b>" +
                closeLabel +
                "</b></span></button></div></div>";
            redirectPending = false;
        }

        root.addEventListener("click", function (event) {
            var offerButton = event.target.closest("[data-unipayment-offer]");
            if (offerButton)
                open(
                    offerButton.getAttribute("data-unipayment-offer"),
                    offerButton,
                );
            if (event.target.closest("[data-unipayment-close]")) close();
            if (event.target.closest("[data-unipayment-secondary]"))
                handleSecondary();
            if (event.target.closest("[data-unipayment-apply]"))
                transitionToStep2();
            if (event.target.closest("[data-unipayment-back]"))
                setStep(1, { animate: true });
            if (event.target.closest("[data-unipayment-submit]"))
                requestStep2Validation();
        });
        if (customerForm) {
            customerForm.addEventListener("input", function (event) {
                if (event.target && event.target.name === "phone")
                    event.target.value = event.target.value.replace(
                        /[^0-9+() -]/g,
                        "",
                    );
                if (event.target && event.target.name)
                    showCustomerFieldError(
                        event.target.name,
                        customerErrors()[event.target.name] || "",
                    );
                submitError.textContent = "";
                updateSubmitState(false);
            });
            customerForm.addEventListener("change", function (event) {
                if (event.target && event.target.name)
                    showCustomerFieldError(
                        event.target.name,
                        customerErrors()[event.target.name] || "",
                    );
                updateSubmitState(false);
            });
        }
        if (step2) {
            step2.addEventListener("change", function (event) {
                if (
                    event.target &&
                    event.target.matches("[data-unipayment-consent-checkbox]")
                )
                    updateSubmitState(false);
            });
            step2.addEventListener("mousedown", function (event) {
                var consentLink = event.target.closest(
                    ".unipayment-product-calculator__consent-label a",
                );
                if (consentLink) event.stopPropagation();
            });
        }
        select.addEventListener("change", function () {
            if (applyInFlight) {
                invalidateCalculation();
                return;
            }
            first.value = "0";
            first.readOnly = false;
            invalidatePreview();
            calculateNow();
        });
        first.addEventListener("input", function () {
            if (first.readOnly) return;
            first.value = first.value.replace(/\D/g, "");
            invalidatePreview();
            if (applyInFlight) return;
            window.clearTimeout(calculateTimer);
            calculateTimer = window.setTimeout(calculateNow, 800);
        });
        first.addEventListener("change", function () {
            if (first.readOnly) return;
            invalidatePreview();
            calculateNow();
        });
        root.addEventListener("keydown", function (event) {
            if (modal.hidden) return;
            if (event.key === "Escape") {
                close();
                return;
            }
            if (event.key === "Tab") handleModalTabKey(event);
        });

        root.unipaymentUpdate = function (next) {
            if (!isCartSource) {
                if (redirectPending || applyInFlight) return;
                var selection = readSelection();
                invalidatePreview();
                applyOffer(next, next && selection ? selection.key : "");
                return;
            }
            invalidateCalculation();
            if (applyInFlight) return;
            applyOffer(next);
        };

        function applyOffer(next, requestKey) {
            config = next;
            root.setAttribute("data-calculator", JSON.stringify(next || {}));
            root.hidden = !next;
            if (next) applyVisualConfig(root, next);
            root.querySelectorAll("[data-unipayment-offer]").forEach(
                function (button) {
                    var type = button.getAttribute("data-unipayment-offer");
                    var offer = next && next.offers ? next.offers[type] : null;
                    button.hidden = !offer;
                    var price = button.querySelector(
                        "[data-unipayment-preferred-price]",
                    );
                    if (price && offer)
                        price.textContent = buttonInstallmentLabel(offer);
                },
            );
            if (requestKey !== undefined) {
                refreshedOfferKey = requestKey;
                offerSelectionKey = requestKey;
            }
            if (
                !next ||
                (activeType && (!next.offers || !next.offers[activeType]))
            )
                close();
            else if (!modal.hidden && activeType) {
                first.value = "0";
                rebuildSchemes(activeType);
                calculateNow();
            }
        }

        function applyRefreshedOffer(sequence, requestKey, payload) {
            if (sequence !== refreshSequence || !root.isConnected || applyInFlight || !matchesSelection(requestKey))
                return false;
            if (redirectPending) {
                // A successful Buy navigates away. A stale Buy can still use this refresh.
                if (pendingPreselect) {
                    pendingPreselect.offer = { sequence: sequence, key: requestKey, payload: payload };
                }
                return false;
            }
            var next = payload && payload.success ? payload.calculator : null;
            window.clearTimeout(refreshTimer);
            refreshTimer = null;
            invalidatePreview();
            applyOffer(next, next ? requestKey : "");
            return true;
        }

        function cancelOfferRequest() {
            refreshSequence += 1;
            refreshRequestKey = "";
            if (refreshRequest) refreshRequest.abort();
            refreshRequest = null;
        }

        root.unipaymentInvalidatePopup = function () {
            if (!isCartSource) {
                if (redirectPending && !pendingPreselect && !applyInFlight) return;
                var selection = readSelection();
                var key = selection ? selection.key : null;
                if (key === observedSelectionKey) return;
                observedSelectionKey = key;
            }
            cancelOfferRequest();
            if (modal.hidden) return;
            invalidatePreview();
            if (!isCartSource && !redirectPending && !applyInFlight) setStep(1);
        };

        root.unipaymentRefresh = function () {
            if (isCartSource || applyInFlight || (redirectPending && !pendingPreselect)) return;
            window.clearTimeout(refreshTimer);
            refreshTimer = window.setTimeout(function () {
                refreshTimer = null;
                if (applyInFlight || !root.isConnected || (redirectPending && !pendingPreselect)) return;
                var endpoint = root.getAttribute("data-endpoint");
                if (!endpoint || !nonNegativeInteger(root.getAttribute("data-product-id"))) return;
                var selection = readSelection();
                if (!selection) {
                    if (!modal.hidden && activeType) showCalculationRetry();
                    return;
                }
                var requestKey = selection.key;
                if (requestKey === refreshedOfferKey) {
                    if (!lastCalculation && !modal.hidden && activeType && !hasCurrentCalculationRequest(selection)) {
                        calculateNow();
                    }
                    return;
                }
                if (requestKey === refreshRequestKey ||
                    (pendingPreselect && pendingPreselect.offer &&
                        pendingPreselect.offer.sequence === refreshSequence && pendingPreselect.offer.key === requestKey))
                    return;
                cancelOfferRequest();
                refreshRequest =
                    typeof AbortController === "function"
                        ? new AbortController()
                        : null;
                refreshRequestKey = requestKey;
                var sequence = refreshSequence;
                var url =
                    endpoint +
                    (endpoint.indexOf("?") === -1 ? "?" : "&") +
                    "id_product=" +
                    encodeURIComponent(selection.productId) +
                    "&id_product_attribute=" +
                    encodeURIComponent(selection.attributeId) +
                    "&quantity=" +
                    encodeURIComponent(selection.quantity);
                var options = {
                    credentials: "same-origin",
                    headers: { "X-Requested-With": "XMLHttpRequest" },
                };
                if (refreshRequest) options.signal = refreshRequest.signal;
                fetch(url, options)
                    .then(function (response) {
                        if (!response.ok) throw new Error("calculator");
                        return response.json();
                    })
                    .then(function (payload) {
                        if (sequence !== refreshSequence) return;
                        refreshRequestKey = "";
                        refreshRequest = null;
                        applyRefreshedOffer(sequence, requestKey, payload);
                    })
                    .catch(function (error) {
                        if (sequence !== refreshSequence) return;
                        refreshRequestKey = "";
                        refreshRequest = null;
                        if (!error || error.name !== "AbortError")
                            applyRefreshedOffer(sequence, requestKey, null);
                    });
            }, 80);
        };
    }

    function initialize() {
        document.querySelectorAll(selector).forEach(setup);
    }
    function refresh() {
        document.querySelectorAll(selector).forEach(function (root) {
            setup(root);
            root.unipaymentInvalidatePopup();
            root.unipaymentRefresh();
        });
    }
    function invalidateProductPopups() {
        document.querySelectorAll(selector).forEach(function (root) {
            if (root.unipaymentInvalidatePopup) root.unipaymentInvalidatePopup();
        });
    }
    function scheduleDomRefresh(payload) {
        if (payload !== undefined) {
            if (payload && payload.is_quick_view) return;
            var hint = productUpdateHint(document, payload);
            if (hint) {
                var roots = document.querySelectorAll(selector);
                var owned = Array.prototype.some.call(roots, function (root) {
                    return nonNegativeInteger(root.getAttribute("data-product-id")) === hint.productId;
                });
                if (roots.length && !owned) return;
                // Keep the confirmed selection across the deferred refresh and root replacement.
                pendingProductUpdateHint = hint;
                productRequiringLiveAttribute = null;
            }
            productSelectionUpdating = false;
            initialize();
        }
        invalidateProductPopups();
        window.clearTimeout(domRefreshTimer);
        // Let the theme finish replacing details and additional-info markup.
        domRefreshTimer = window.setTimeout(refresh, 0);
    }
    function initializeProductObservers() {
        if (typeof MutationObserver !== "function" || !document.body) return;
        var productRootSelector = selector + "[data-product-id]";
        var observedMarkup = productDetailsElementSelector + ", " + productRootSelector;
        var ignoredTargets = selector + ", " + unrelatedProductSelector;

        function productDomState() {
            var roots = document.querySelectorAll(productRootSelector);
            var state = [];
            roots.forEach(function (root) {
                if (!root.closest(unrelatedProductSelector)) {
                    state.push({
                        root: root,
                        attribute: productAttributeId(document, root, null, null),
                    });
                }
            });
            return state;
        }

        function containsProductMarkup(node) {
            return node.nodeType === 1 && !node.closest(unrelatedProductSelector) &&
                (node.matches(observedMarkup) || node.querySelector(observedMarkup));
        }

        function touchesProductMarkup(mutation) {
            var target = mutation.target;
            if (target.nodeType !== 1 || !target.isConnected || target.closest(ignoredTargets)) return false;
            if (mutation.type === "attributes") return target.matches(productDetailsElementSelector);
            return Array.prototype.some.call(mutation.addedNodes, containsProductMarkup) ||
                Array.prototype.some.call(mutation.removedNodes, containsProductMarkup);
        }

        var previous = productDomState();
        var observer = new MutationObserver(function (mutations) {
            if (!mutations.some(touchesProductMarkup)) return;
            var current = productDomState();
            var changed = current.length !== previous.length || current.some(function (state, index) {
                return state.root !== previous[index].root || state.attribute !== previous[index].attribute;
            });
            previous = current;
            if (changed && current.length) scheduleDomRefresh();
        });
        // Details and their enclosing block can both be replaced by the theme.
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ["data-product"],
        });
    }
    function start() {
        initialize();
        initializeProductObservers();
    }
    if (document.readyState === "loading")
        document.addEventListener("DOMContentLoaded", start);
    else start();

    // updatedProductCombination is an updateProduct eventType.
    if (window.prestashop && typeof window.prestashop.on === "function") {
        window.prestashop.on("updateProduct", function (payload) {
            if (!payload || payload.is_quick_view) return;
            var target = payload.event && payload.event.target;
            var quantityUpdate = payload.eventType === "updatedProductQuantity" ||
                (target && target.matches && target.matches(quantitySelector));
            if (!quantityUpdate && !target && payload.eventType !== "updatedProductCombination") return;
            if (!quantityUpdate) {
                // An unverified completion needs live state before it can replace a known combination.
                if (pendingProductUpdateHint && pendingProductUpdateHint.attributeId > 0)
                    productRequiringLiveAttribute = pendingProductUpdateHint.productId;
                pendingProductUpdateHint = null;
                productSelectionUpdating = true;
                productSelectionRevision += 1;
            }
            invalidateProductPopups();
        });
        window.prestashop.on("updatedProduct", function (payload) {
            scheduleDomRefresh(payload || null);
        });
    }
    document.addEventListener("updatedProduct", function (event) {
        scheduleDomRefresh(event && event.detail ? event.detail : null);
    });
    document.addEventListener("input", function (event) {
        if (
            event.target &&
            event.target.matches(quantitySelector)
        )
            refresh();
    });
    document.addEventListener("change", function (event) {
        if (
            event.target &&
            event.target.matches(quantitySelector)
        )
            refresh();
    });
})();
