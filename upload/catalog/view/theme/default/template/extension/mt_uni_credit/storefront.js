(function () {
  "use strict";

  function waitForJQuery(intervalMs, maxAttempts, callback) {
    var attempts = 0;
    function tick() {
      attempts += 1;
      if (window.jQuery) {
        callback(window.jQuery);
        return;
      }
      if (attempts >= maxAttempts) {
        return;
      }
      window.setTimeout(tick, intervalMs);
    }
    tick();
  }

  function parseBootstrap($root) {
    var $el = $root.find("[data-mtuc-bootstrap]").first();
    if (!$el.length) {
      $el = $root.nextAll("[data-mtuc-bootstrap]").first();
    }
    if (!$el.length) {
      return null;
    }
    try {
      return JSON.parse($el.text() || "{}");
    } catch (e) {
      return null;
    }
  }

  function findScheme(schemes, key) {
    var i;
    for (i = 0; i < schemes.length; i += 1) {
      if (schemes[i].key === key) {
        return schemes[i];
      }
    }
    return schemes.length ? schemes[0] : null;
  }

  function formatMoney(value) {
    var n = Number(value);
    if (isNaN(n)) {
      return "";
    }
    return n.toFixed(2);
  }

  function currencyDisplayLabel(currencyIso) {
    return String(currencyIso || "").toUpperCase() === "EUR" ? "евро" : "лв.";
  }

  function formatMoneyWithCurrency(value, currencyIso) {
    var amount = formatMoney(value);
    if (amount === "") {
      return "";
    }
    return amount + " " + currencyDisplayLabel(currencyIso);
  }

  function formatPercent(value) {
    var n = Number(value);
    if (isNaN(n)) {
      return "";
    }
    return n.toFixed(2) + "%";
  }

  function initRoot($, $root) {
    if ($root.attr("data-mtuc-bound") === "1") {
      return;
    }
    $root.attr("data-mtuc-bound", "1");

    var bootstrap = parseBootstrap($root) || {};
    var state = bootstrap.calculator || {};
    var entryPoint =
      $root.attr("data-entry-point") || bootstrap.entry_point || "product";
    var modalId =
      entryPoint === "cart"
        ? "#mt-uni-credit-cart-modal"
        : "#mt-uni-credit-product-modal";
    var $modal = $root.find(modalId);
    if (!$modal.length) {
      $modal = $(modalId);
    }
    if (!$modal.length) {
      return;
    }

    var modalHomeParent = $modal.parent()[0];
    var modalHomeNext = $modal[0].nextSibling;
    var $errorModal = $root.find(modalId + "-error");
    if (!$errorModal.length) {
      $errorModal = $(modalId + "-error");
    }
    if (!$errorModal.length) {
      $errorModal = $root.find("[data-mtuc-error-modal]").first();
    }
    var errorModalHomeParent = $errorModal.length
      ? $errorModal.parent()[0]
      : null;
    var errorModalHomeNext =
      $errorModal.length && $errorModal[0] ? $errorModal[0].nextSibling : null;
    var selectedOfferType = Object.keys(state.offers || {})[0] || "standard";
    var selectedSchemeKey =
      (state.offers &&
        state.offers[selectedOfferType] &&
        state.offers[selectedOfferType].preferred_scheme_key) ||
      "";
    var calcTimer = null;
    var firstInstallmentTimer = null;
    var sequence = 0;
    var abortController = null;
    // Terminal financing submit (Process 1 bank redirect): keep locked until navigation.
    var terminalSubmitInFlight = false;
    var width = $root.attr("data-mtuc-button-width");
    var height = $root.attr("data-mtuc-button-height");
    var topSpacing = $root.attr("data-mtuc-top-spacing");
    if (width) {
      $root.css("--mtuc-button-width", width + "px");
    }
    if (height) {
      $root.css("--mtuc-button-height", height + "px");
    }
    if (topSpacing) {
      $root.css("margin-top", topSpacing + "px");
    }

    function moveModalToBody() {
      if ($modal.parent()[0] !== document.body) {
        $(document.body).append($modal);
      }
    }

    function restoreModal() {
      if (!modalHomeParent) {
        return;
      }
      if (modalHomeNext && modalHomeNext.parentNode === modalHomeParent) {
        modalHomeParent.insertBefore($modal[0], modalHomeNext);
      } else {
        modalHomeParent.appendChild($modal[0]);
      }
    }

    function moveErrorModalToBody() {
      if (!$errorModal.length) {
        return;
      }
      if ($errorModal.parent()[0] !== document.body) {
        $(document.body).append($errorModal);
      }
    }

    function restoreErrorModal() {
      if (!$errorModal.length || !errorModalHomeParent) {
        return;
      }
      if (
        errorModalHomeNext &&
        errorModalHomeNext.parentNode === errorModalHomeParent
      ) {
        errorModalHomeParent.insertBefore($errorModal[0], errorModalHomeNext);
      } else {
        errorModalHomeParent.appendChild($errorModal[0]);
      }
    }

    function showErrorModal(message) {
      if (!$errorModal.length) {
        $modal
          .find("[data-mtuc-submit-error]")
          .text(message || "Заявката не беше успешна.");
        return;
      }
      moveErrorModalToBody();
      $errorModal
        .find("[data-mtuc-error-message]")
        .text(message || "Заявката не беше успешна.");
      $errorModal.removeAttr("hidden").attr("aria-hidden", "false");
      $errorModal.find(".mt-uni-credit-storefront__dialog").trigger("focus");
    }

    function closeErrorModal() {
      if (!$errorModal.length) {
        return;
      }
      $errorModal.attr("hidden", true).attr("aria-hidden", "true");
      $errorModal.find("[data-mtuc-error-message]").text("");
      restoreErrorModal();
    }

    function currentOffer() {
      return (state.offers && state.offers[selectedOfferType]) || null;
    }

    function isTerminalSubmitLocked() {
      return terminalSubmitInFlight === true;
    }

    function setProcessing(active) {
      // While terminal redirect navigation is pending, never unlock the modal.
      if (!active && isTerminalSubmitLocked()) {
        return;
      }
      var $panel = $modal.find("[data-mtuc-processing]");
      var $dialog = $modal.find(".mt-uni-credit-storefront__dialog");
      if (active) {
        $panel.removeAttr("hidden");
        $dialog.css({ opacity: "0.45", pointerEvents: "none" });
        $modal.attr("data-mtuc-processing-active", "1");
        $modal.addClass("mt-uni-credit-storefront--processing");
      } else {
        $panel.attr("hidden", true);
        $dialog.css({ opacity: "", pointerEvents: "" });
        $modal.removeAttr("data-mtuc-processing-active");
        $modal.removeClass("mt-uni-credit-storefront--processing");
      }
    }

    function setPopupError(message) {
      $modal.find("[data-mtuc-popup-error]").text(message || "");
    }

    function fillDisplays(scheme) {
      if (!scheme) {
        return;
      }
      var currencyIso = state.currency_iso || "";
      var price = scheme.price != null ? scheme.price : state.price;
      var financed =
        scheme.financed_amount != null
          ? scheme.financed_amount
          : scheme.financed;
      var monthly =
        scheme.monthly_installment != null
          ? scheme.monthly_installment
          : scheme.monthly;
      var total =
        scheme.total_payable != null ? scheme.total_payable : scheme.total;
      $modal
        .find('[data-mtuc-display="price"]')
        .text(formatMoneyWithCurrency(price, currencyIso));
      $modal
        .find('[data-mtuc-display="financed_amount"]')
        .text(formatMoneyWithCurrency(financed, currencyIso));
      $modal
        .find('[data-mtuc-display="monthly_installment"]')
        .text(formatMoneyWithCurrency(monthly, currencyIso));
      $modal
        .find('[data-mtuc-display="total_payable"]')
        .text(formatMoneyWithCurrency(total, currencyIso));
      $modal.find('[data-mtuc-display="glp"]').text(formatPercent(scheme.glp));
      $modal.find('[data-mtuc-display="gpr"]').text(formatPercent(scheme.gpr));
      $modal
        .find("[data-mtuc-first]")
        .val(formatMoney(scheme.first_installment || 0));
      if (scheme.first_installment_locked) {
        $modal.find("[data-mtuc-first]").attr("readonly", "readonly");
      } else {
        $modal.find("[data-mtuc-first]").removeAttr("readonly");
      }
      var showFirst =
        scheme.show_first_installment != null
          ? scheme.show_first_installment
          : state.show_first_installment;
      var $firstRow = $modal.find("[data-mtuc-first-row]");
      if (showFirst === false) {
        $firstRow.attr("hidden", true);
      } else {
        $firstRow.removeAttr("hidden");
      }
    }

    function fillSchemes() {
      var offer = currentOffer();
      var $select = $modal.find("[data-mtuc-schemes]");
      $select.empty();
      if (!offer || !offer.schemes) {
        return;
      }
      $.each(offer.schemes, function (_, scheme) {
        var selected = scheme.key === selectedSchemeKey ? " selected" : "";
        var label = scheme.label;
        if (!label) {
          label = scheme.months + " месеца";
          if (scheme.description) {
            label += " - " + scheme.description;
          }
        }
        $select.append(
          '<option value="' +
            scheme.key +
            '"' +
            selected +
            ">" +
            label +
            "\u00A0\u00A0\u00A0</option>",
        );
      });
      if (!selectedSchemeKey && offer.preferred_scheme_key) {
        selectedSchemeKey = offer.preferred_scheme_key;
      }
      $select.val(selectedSchemeKey);
      fillDisplays(findScheme(offer.schemes, selectedSchemeKey));
    }

    function focusStep2Field() {
      window.setTimeout(function () {
        $modal
          .find("[data-mtuc-form]")
          .find("input, select, textarea")
          .filter(":visible")
          .first()
          .trigger("focus");
        updateSubmitState(false);
      }, 0);
    }

    var PHONE_VALID_PATTERN = /^[-0-9+() ]+$/;
    var PHONE_ALLOWED_PATTERN = /[-0-9+() ]/;
    var EMAIL_VALID_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    function isNonEmpty(value) {
      return String(value || "").replace(/^\s+|\s+$/g, "") !== "";
    }

    function sanitizePhoneValue(value) {
      return String(value || "")
        .split("")
        .filter(function (char) {
          return PHONE_ALLOWED_PATTERN.test(char);
        })
        .join("");
    }

    function isValidPhone(value) {
      var phone = String(value || "").replace(/^\s+|\s+$/g, "");
      return (
        phone !== "" && PHONE_VALID_PATTERN.test(phone) && /\d/.test(phone)
      );
    }

    function isValidEmail(value) {
      var email = String(value || "").replace(/^\s+|\s+$/g, "");
      return email !== "" && EMAIL_VALID_PATTERN.test(email);
    }

    function isValidEgn(digits) {
      if (!/^\d{10}$/.test(digits)) {
        return false;
      }
      var year = parseInt(digits.slice(0, 4), 10);
      var month = parseInt(digits.slice(4, 6), 10);
      var day = parseInt(digits.slice(6, 8), 10);
      var date = new Date(year, month - 1, day);
      return (
        date.getFullYear() === year &&
        date.getMonth() === month - 1 &&
        date.getDate() === day
      );
    }

    function customerField(name) {
      return $modal.find('[data-mtuc-form] [name="' + name + '"]').get(0);
    }

    function consentCheckboxes() {
      return $modal.find("[data-mtuc-consent-checkbox]");
    }

    function areMandatoryConsentsChecked() {
      var $boxes = consentCheckboxes();
      if (!$boxes.length) {
        return false;
      }
      var ok = true;
      $boxes.each(function () {
        if (!this.checked) {
          ok = false;
          return false;
        }
      });
      return ok;
    }

    function getStep2FieldErrors() {
      var errors = {};
      if (
        !isNonEmpty(
          customerField("firstname") && customerField("firstname").value,
        )
      ) {
        errors.firstname = "required";
      }
      if (
        !isNonEmpty(
          customerField("lastname") && customerField("lastname").value,
        )
      ) {
        errors.lastname = "required";
      }
      if (
        !isNonEmpty(customerField("address") && customerField("address").value)
      ) {
        errors.address = "required";
      }
      var phoneEl = customerField("phone");
      var phone = phoneEl ? phoneEl.value : "";
      if (!isNonEmpty(phone)) {
        errors.phone = "required";
      } else if (!isValidPhone(phone)) {
        errors.phone = "invalid";
      }
      var emailEl = customerField("email");
      var email = emailEl ? emailEl.value : "";
      if (!isNonEmpty(email)) {
        errors.email = "required";
      } else if (!isValidEmail(email)) {
        errors.email = "invalid";
      }
      var egnField = customerField("egn");
      if (egnField) {
        var egn = String(egnField.value || "").replace(/\D/g, "");
        if (egn === "") {
          errors.egn = "required";
        } else if (!isValidEgn(egn)) {
          errors.egn = "invalid";
        }
      }
      var phone2Field = customerField("phone2");
      if (phone2Field) {
        var phone2 = phone2Field.value;
        if (!isNonEmpty(phone2)) {
          errors.phone2 = "required";
        } else if (!isValidPhone(phone2)) {
          errors.phone2 = "invalid";
        }
      }
      return errors;
    }

    function isStep2FormValid() {
      return (
        Object.keys(getStep2FieldErrors()).length === 0 &&
        areMandatoryConsentsChecked()
      );
    }

    function updateSubmitState() {
      var valid = isStep2FormValid();
      var $submit = $modal.find("[data-mtuc-submit]");
      var locked = isTerminalSubmitLocked();
      $submit.prop("disabled", locked || !valid);
      $submit.attr("aria-disabled", locked || !valid ? "true" : "false");
      $submit.toggleClass("is-disabled", locked || !valid);
      return valid;
    }

    function bindStep2ReadinessListeners() {
      var $form = $modal.find("[data-mtuc-form]");
      if (!$form.length || $form.data("mtucStep2Bound") === 1) {
        return;
      }
      $form.data("mtucStep2Bound", 1);
      $form.on("input.mtucStep2 change.mtucStep2", function (event) {
        var target = event.target;
        if (!target || !target.getAttribute) {
          return;
        }
        var name = target.getAttribute("name") || "";
        if (name === "phone" || name === "phone2") {
          var sanitized = sanitizePhoneValue(target.value);
          if (target.value !== sanitized) {
            target.value = sanitized;
          }
        }
        updateSubmitState();
      });
    }

    /**
     * OC4 contract: toggle [hidden] + __step--active, then focus Step 2.
     * Visual transition uses short opacity fade (Jet/UniCredit family hide/show('slow')
     * rhythm) while keeping OC4 class/hidden sequencing — no hard flash.
     */
    function setStep(step, options) {
      if (isTerminalSubmitLocked()) {
        return;
      }
      var opts = options || {};
      var animate = opts.animate !== false;
      var $steps = $modal.find("[data-mtuc-step]");
      var $target = $modal.find('[data-mtuc-step="' + step + '"]');
      var $current = $steps.filter(".mt-uni-credit-storefront__step--active");
      var currentStep = $current.attr("data-mtuc-step");

      function activateTarget() {
        $steps
          .attr("hidden", true)
          .removeClass(
            "mt-uni-credit-storefront__step--active is-transitioning-out is-transitioning-in",
          )
          .css("opacity", "");
        $target
          .removeAttr("hidden")
          .addClass("mt-uni-credit-storefront__step--active")
          .css("opacity", "");
        if (step === 2) {
          bindStep2ReadinessListeners();
          focusStep2Field();
        }
      }

      if (
        !animate ||
        !$current.length ||
        String(currentStep) === String(step) ||
        !$target.length
      ) {
        activateTarget();
        return;
      }

      $current.addClass("is-transitioning-out").css("opacity", "0");
      window.setTimeout(function () {
        $current
          .attr("hidden", true)
          .removeClass(
            "mt-uni-credit-storefront__step--active is-transitioning-out",
          )
          .css("opacity", "");
        $target
          .removeAttr("hidden")
          .addClass(
            "mt-uni-credit-storefront__step--active is-transitioning-in",
          )
          .css("opacity", "0");
        // Force reflow so opacity transition runs.
        void $target[0].offsetWidth;
        $target.css("opacity", "1");
        window.setTimeout(function () {
          $target.removeClass("is-transitioning-in").css("opacity", "");
          if (step === 2) {
            bindStep2ReadinessListeners();
            focusStep2Field();
          }
        }, 350);
      }, 350);
    }

    function openModal(offerType, preferredKey) {
      closeErrorModal();
      selectedOfferType = offerType || selectedOfferType;
      var offer = currentOffer();
      if (preferredKey) {
        selectedSchemeKey = preferredKey;
      } else if (offer && offer.preferred_scheme_key) {
        selectedSchemeKey = offer.preferred_scheme_key;
      }
      moveModalToBody();
      setProcessing(true);
      setPopupError("");
      fillSchemes();
      setStep(1, { animate: false });
      $modal.removeAttr("hidden").attr("aria-hidden", "false");
      $modal.find(".mt-uni-credit-storefront__dialog").trigger("focus");
      scheduleRecalculate(true);
    }

    function closeModal() {
      // Keep modal locked while Process 1 bank redirect navigation is pending.
      if (isTerminalSubmitLocked()) {
        return;
      }
      if (firstInstallmentTimer) {
        window.clearTimeout(firstInstallmentTimer);
        firstInstallmentTimer = null;
      }
      setProcessing(false);
      $modal.attr("hidden", true).attr("aria-hidden", "true");
      setStep(1, { animate: false });
      restoreModal();
    }

    /**
     * OC3 default theme: #product (not #form-product). Journal/custom may use either.
     * Jet binds [name=quantity] + [id^=input-option]; OC4 UniCredit also accepts #form-product.
     */
    function productContainer() {
      var $c = $("#product");
      if ($c.length) {
        return $c;
      }
      return $("#form-product");
    }

    function escapeHtml(text) {
      return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
    }

    function escapeAttr(text) {
      return escapeHtml(text).replace(/'/g, "&#39;");
    }

    function productFormData() {
      var $container = productContainer();
      var quantity = 1;
      var option = {};
      var $qty = $("#input-quantity");
      if (!$qty.length) {
        $qty = $('[name="quantity"]').eq(0);
      }
      if ($qty.length) {
        quantity = parseInt($qty.val(), 10) || 1;
      }
      if (quantity < 1) {
        quantity = 1;
      }
      var $fields = $container.length
        ? $container.find(
            "input[name^='option['], select[name^='option['], textarea[name^='option[']",
          )
        : $(
            "input[name^='option['], select[name^='option['], textarea[name^='option[']",
          );
      $fields.each(function () {
        var $el = $(this);
        var name = $el.attr("name") || "";
        var match = name.match(/^option\[(\d+)\](\[\])?$/);
        if (!match) {
          return;
        }
        var id = match[1];
        var type = ($el.attr("type") || "").toLowerCase();
        if (type === "file") {
          return;
        }
        if ((type === "checkbox" || type === "radio") && !$el.is(":checked")) {
          return;
        }
        var val = $el.val();
        if (val === null || val === undefined || String(val) === "") {
          return;
        }
        if (match[2] || type === "checkbox") {
          if (!option[id]) {
            option[id] = [];
          }
          option[id].push(val);
        } else {
          option[id] = val;
        }
      });
      return { quantity: quantity, option: option };
    }

    function setEntryError(message) {
      var $err = $root.find("[data-mtuc-entry-error]");
      if (!message) {
        $err.text("").attr("hidden", true);
        return;
      }
      $err.text(message).removeAttr("hidden");
    }

    function setOffersBusy(busy) {
      var $calc = $root.find(".mt-uni-credit-storefront__calculator").first();
      $calc.attr("aria-busy", busy ? "true" : "false");
      $root.find("[data-mtuc-offer]").prop("disabled", !!busy);
    }

    function syncHeading(calc) {
      var headingText =
        calc && calc.heading
          ? String(calc.heading).replace(/^\s+|\s+$/g, "")
          : "";
      var $calc = $root.find(".mt-uni-credit-storefront__calculator").first();
      var $existing = $calc.find("[data-mtuc-heading]");
      if (!headingText) {
        $existing.remove();
        return;
      }
      if ($existing.length) {
        $existing.text(headingText);
        return;
      }
      $("<p/>", {
        class: "mt-uni-credit-storefront__heading",
        "data-mtuc-heading": "",
        text: headingText,
      }).prependTo($calc);
    }

    function applyRootLayout(calc) {
      $root.toggleClass("mt-uni-credit-storefront--dark", !!calc.dark_button);
      $root.toggleClass(
        "mt-uni-credit-storefront--stacked",
        calc.buttons_in_row === false || calc.buttons_in_row === 0,
      );
      if (calc.button_width) {
        $root.css("--mtuc-button-width", calc.button_width + "px");
        $root.attr("data-mtuc-button-width", calc.button_width);
      }
      if (calc.button_height) {
        $root.css("--mtuc-button-height", calc.button_height + "px");
        $root.attr("data-mtuc-button-height", calc.button_height);
      }
    }

    function renderOfferButtons(calc) {
      var $wrap = $root.find("[data-mtuc-buttons]");
      if (!$wrap.length) {
        $wrap = $root.find(".mt-uni-credit-storefront__buttons");
      }
      if (!$wrap.length || !calc || !calc.offers) {
        return;
      }
      var dark = !!calc.dark_button;
      var logoUrl = dark
        ? bootstrap.logo_alternative_url || ""
        : bootstrap.logo_standard_url || "";
      var buttonTitle = bootstrap.button_title || "Купи на изплащане";
      var html = "";
      $.each(calc.offers, function (offerType, offer) {
        html +=
          '<button type="button" class="mt-uni-credit-storefront__button mt-uni-credit-storefront__button--' +
          escapeAttr(offerType) +
          '" data-mtuc-offer="' +
          escapeAttr(offerType) +
          '" data-preferred-key="' +
          escapeAttr(offer.preferred_scheme_key || "") +
          '">';
        html +=
          '<span class="mt-uni-credit-storefront__button-content">' +
          '<span class="mt-uni-credit-storefront__button-title">' +
          escapeHtml(buttonTitle) +
          "</span>" +
          '<span class="mt-uni-credit-storefront__button-price" data-mtuc-preferred-price>' +
          escapeHtml(offer.installment_label || "") +
          "</span></span>";
        if (offerType === "promo") {
          html +=
            '<span class="mt-uni-credit-storefront__badge" aria-hidden="true">0%</span>';
        } else {
          html +=
            '<span class="mt-uni-credit-storefront__logo"><img src="' +
            escapeAttr(logoUrl) +
            '" alt="UniCredit" data-mtuc-logo /></span>';
        }
        html += "</button>";
      });
      $wrap.html(html);
    }

    function syncBootstrapJson() {
      var $el = $root.find("[data-mtuc-bootstrap]").first();
      if (!$el.length) {
        $el = $root.nextAll("[data-mtuc-bootstrap]").first();
      }
      if (!$el.length) {
        return;
      }
      try {
        var payload = {
          calculator: state,
          entry_point: entryPoint,
          button_title: bootstrap.button_title || "",
          logo_standard_url: bootstrap.logo_standard_url || "",
          logo_alternative_url: bootstrap.logo_alternative_url || "",
        };
        if (bootstrap.cart_fingerprint) {
          payload.cart_fingerprint = bootstrap.cart_fingerprint;
        }
        $el.text(JSON.stringify(payload));
      } catch (e) {}
    }

    function applyCalculator(calc) {
      if (!calc || !calc.offers) {
        return;
      }
      state = calc;
      bootstrap.calculator = calc;
      if (!state.offers[selectedOfferType]) {
        selectedOfferType = Object.keys(state.offers)[0] || "standard";
      }
      var offer = currentOffer();
      var schemes = (offer && offer.schemes) || [];
      var previousKey = selectedSchemeKey;
      var previousStillValid = !!(
        previousKey && findScheme(schemes, previousKey)
      );
      if (previousStillValid) {
        selectedSchemeKey = previousKey;
      } else if (offer && offer.preferred_scheme_key) {
        selectedSchemeKey = offer.preferred_scheme_key;
      } else {
        selectedSchemeKey = schemes.length ? schemes[0].key : "";
      }
      syncHeading(calc);
      applyRootLayout(calc);
      renderOfferButtons(calc);
      syncBootstrapJson();
      $root.removeData("mtucStale");
      $root.show();
      setEntryError("");
      setOffersBusy(false);
    }

    function postJson(url, data, done) {
      if (window.AbortController) {
        if (abortController) {
          try {
            abortController.abort();
          } catch (e) {}
        }
        abortController = new AbortController();
      }
      $.ajax({
        url: url,
        type: "POST",
        dataType: "json",
        data: data,
        signal: abortController ? abortController.signal : undefined,
      })
        .done(function (response) {
          done(null, response);
        })
        .fail(function (_xhr, textStatus) {
          if (textStatus === "abort") {
            done("abort", null);
            return;
          }
          done(true, null);
        });
    }

    function scheduleCalculate() {
      if (entryPoint !== "product") {
        return;
      }
      window.clearTimeout(calcTimer);
      // OC4 UniCredit Product refresh debounce (250ms); covers quantity typing.
      calcTimer = window.setTimeout(runCalculate, 250);
    }

    function runCalculate() {
      var routes = $root.attr("data-route-calculate");
      if (!routes) {
        return;
      }
      sequence += 1;
      var localSeq = sequence;
      var form = productFormData();
      setOffersBusy(true);
      setEntryError("");
      postJson(
        routes,
        {
          csrf: $root.attr("data-csrf"),
          product_id: $root.attr("data-product-id"),
          quantity: form.quantity,
          option: form.option,
          sequence: localSeq,
        },
        function (err, response) {
          if (err === "abort") {
            return;
          }
          if (
            response &&
            response.sequence != null &&
            response.sequence !== localSeq
          ) {
            return;
          }
          if (response && response.unavailable) {
            setOffersBusy(false);
            setEntryError("");
            $root.hide();
            return;
          }
          if (err || !response || !response.success || !response.calculator) {
            setOffersBusy(false);
            $root.data("mtucStale", 1);
            $root.find("[data-mtuc-offer]").prop("disabled", true);
            setEntryError(
              "Неуспешно обновяване на финансирането. Променете опциите или количеството отново.",
            );
            return;
          }
          applyCalculator(response.calculator);
          if (!$modal.attr("hidden")) {
            fillSchemes();
            scheduleRecalculate(true);
          }
        },
      );
    }

    function scheduleRecalculate(immediate) {
      if (firstInstallmentTimer) {
        window.clearTimeout(firstInstallmentTimer);
        firstInstallmentTimer = null;
      }
      if (immediate) {
        runRecalculate();
        return;
      }
      firstInstallmentTimer = window.setTimeout(runRecalculate, 400);
    }

    function runRecalculate() {
      var route = $root.attr("data-route-recalculate");
      if (!route || $modal.attr("hidden")) {
        setProcessing(false);
        return;
      }
      if (!selectedSchemeKey) {
        setProcessing(false);
        setPopupError("Неуспешно изчисление.");
        return;
      }
      sequence += 1;
      var localSeq = sequence;
      setProcessing(true);
      setPopupError("");
      var data = {
        csrf: $root.attr("data-csrf"),
        scheme_key: selectedSchemeKey,
        first_installment: $modal.find("[data-mtuc-first]").val() || "0",
        sequence: localSeq,
      };
      if (entryPoint === "product") {
        var form = productFormData();
        data.product_id = $root.attr("data-product-id");
        data.quantity = form.quantity;
        data.option = form.option;
      } else {
        data.cart_fingerprint =
          $root.attr("data-cart-fingerprint") ||
          bootstrap.cart_fingerprint ||
          "";
      }
      postJson(route, data, function (err, response) {
        if (err === "abort") {
          return;
        }
        if (
          response &&
          response.sequence != null &&
          response.sequence !== localSeq
        ) {
          return;
        }
        setProcessing(false);
        if (err || !response || !response.success || !response.calculation) {
          setPopupError(
            (response && response.message) ||
              "Неуспешно изчисление. Моля, опитайте отново.",
          );
          return;
        }
        fillDisplays(response.calculation);
        setPopupError("");
      });
    }

    $root.off("click.mtuc").on("click.mtuc", "[data-mtuc-offer]", function (e) {
      e.preventDefault();
      if (isTerminalSubmitLocked()) {
        return;
      }
      if ($root.data("mtucStale") === 1 || $(this).prop("disabled")) {
        return;
      }
      var $btn = $(this);
      openModal(
        $btn.attr("data-mtuc-offer"),
        $btn.attr("data-preferred-key") || "",
      );
    });

    $modal.data("mtucApi", {
      closeModal: closeModal,
      closeErrorModal: closeErrorModal,
      showErrorModal: showErrorModal,
      setStep: setStep,
      setProcessing: setProcessing,
      isTerminalSubmitLocked: isTerminalSubmitLocked,
      selectScheme: function (key) {
        if (isTerminalSubmitLocked()) {
          return;
        }
        selectedSchemeKey = key;
        var offer = currentOffer();
        var scheme = offer
          ? findScheme(offer.schemes || [], selectedSchemeKey)
          : null;
        if (scheme) {
          fillDisplays(scheme);
        }
        scheduleRecalculate(true);
      },
      recalculate: function () {
        if (isTerminalSubmitLocked()) {
          return;
        }
        scheduleRecalculate(false);
      },
      secondary: function () {
        if (isTerminalSubmitLocked()) {
          return;
        }
        var action = $root.attr("data-button-action") || "add_to_cart";
        if (action === "buy") {
          postJson(
            $root.attr("data-route-stash"),
            {
              csrf: $root.attr("data-csrf"),
              product_id: $root.attr("data-product-id"),
              scheme_key: selectedSchemeKey,
            },
            function (err, response) {
              if (!err && response && response.success) {
                var form = productFormData();
                $.ajax({
                  url: "index.php?route=checkout/cart/add",
                  type: "POST",
                  data: {
                    product_id: $root.attr("data-product-id"),
                    quantity: form.quantity,
                    option: form.option,
                  },
                  dataType: "json",
                }).always(function () {
                  window.location =
                    response.redirect ||
                    $root.attr("data-checkout-url") ||
                    "index.php?route=checkout/checkout";
                });
              }
            },
          );
          return;
        }
        $("#button-cart").trigger("click");
        closeModal();
      },
      submit: function () {
        // Double-submit guard: one POST until unlock or browser navigation.
        if (isTerminalSubmitLocked()) {
          return;
        }
        var $form = $modal.find("[data-mtuc-form]");
        if (!$form.length) {
          return;
        }
        if (!updateSubmitState()) {
          $modal
            .find("[data-mtuc-submit-error]")
            .text(
              "Моля, попълнете всички задължителни полета и приемете условията.",
            );
          return;
        }
        terminalSubmitInFlight = true;
        setProcessing(true);
        $modal.find("[data-mtuc-submit-error]").text("");
        var process = $form.attr("data-mtuc-process") || "1";
        var data = {
          csrf: $root.attr("data-csrf"),
          application_token: $root.attr("data-application-token") || "",
          scheme_key: selectedSchemeKey,
          first_installment: $modal.find("[data-mtuc-first]").val() || "0",
        };
        var payload = $form.serializeArray();
        $.each(payload, function (_, item) {
          var name = item.name;
          if (process === "1" && (name === "egn" || name === "phone2")) {
            return;
          }
          if (name === "consent[]") {
            if (!data.consent) {
              data.consent = [];
            }
            if (!$.isArray(data.consent)) {
              data.consent = [data.consent];
            }
            data.consent.push(item.value);
            return;
          }
          data[name] = item.value;
        });
        if (!data.consent && $form.find("[data-mtuc-consent]").is(":checked")) {
          data.consent = "1";
        }
        if (entryPoint === "product") {
          var form = productFormData();
          data.product_id = $root.attr("data-product-id");
          data.quantity = form.quantity;
          data.option = form.option;
        } else {
          data.cart_fingerprint =
            $root.attr("data-cart-fingerprint") ||
            bootstrap.cart_fingerprint ||
            "";
        }
        // Idempotent recovery (Phase 7/9): server reuses bound local order + cp_created —
        // do not invent a second OC/CP order from the storefront.
        postJson(
          $root.attr("data-route-submit"),
          data,
          function (err, response) {
            if (err || !response) {
              terminalSubmitInFlight = false;
              setProcessing(false);
              closeModal();
              showErrorModal("Заявката не беше успешна.");
              return;
            }
            // Bank / terminal redirect: keep loader locked until navigation leaves the page.
            // Do NOT call setProcessing(false) before window.location.assign.
            if (response.redirect) {
              window.location.assign(String(response.redirect));
              return;
            }
            terminalSubmitInFlight = false;
            setProcessing(false);
            if (response.success) {
              closeModal();
              return;
            }
            // CP-create failure (and similar stay-on-page results): close financing UI, show error dialog.
            if (
              response.terminal_ui === "error_modal" ||
              response.stay_on_page === true
            ) {
              closeModal();
              showErrorModal(response.message || "Заявката не беше успешна.");
              return;
            }
            $modal
              .find("[data-mtuc-submit-error]")
              .text(response.message || "Заявката не беше успешна.");
          },
        );
      },
      scheduleCalculate: scheduleCalculate,
      updateSubmitState: updateSubmitState,
    });

    bindStep2ReadinessListeners();
    updateSubmitState();
    if (entryPoint === "product") {
      $root.data("mtucScheduleCalculate", scheduleCalculate);
    }
  }

  function bindDocumentHandlersOnce($) {
    if ($.data(document, "mtucDocBound") === 1) {
      return;
    }
    $.data(document, "mtucDocBound", 1);

    function apiFromEvent(el) {
      var $modal = $(el).closest(
        "#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal",
      );
      return $modal.length ? $modal.data("mtucApi") : null;
    }

    $(document).on(
      "click.mtuc",
      "#mt-uni-credit-product-modal [data-mtuc-dismiss], #mt-uni-credit-cart-modal [data-mtuc-dismiss]",
      function (e) {
        e.preventDefault();
        var api = apiFromEvent(this);
        if (
          api &&
          typeof api.isTerminalSubmitLocked === "function" &&
          api.isTerminalSubmitLocked()
        ) {
          return;
        }
        if (api) {
          api.closeModal();
        }
      },
    );

    $(document).on(
      "click.mtuc",
      "#mt-uni-credit-product-modal-error [data-mtuc-error-dismiss], #mt-uni-credit-cart-modal-error [data-mtuc-error-dismiss]",
      function (e) {
        e.preventDefault();
        var $err = $(this).closest("[data-mtuc-error-modal]");
        var errId = $err.attr("id") || "";
        var financingId = errId.replace(/-error$/, "");
        var api = financingId ? $("#" + financingId).data("mtucApi") : null;
        if (api && typeof api.closeErrorModal === "function") {
          api.closeErrorModal();
        } else {
          $err.attr("hidden", true).attr("aria-hidden", "true");
        }
      },
    );

    $(document).on(
      "click.mtuc",
      "#mt-uni-credit-product-modal [data-mtuc-apply], #mt-uni-credit-cart-modal [data-mtuc-apply]",
      function (e) {
        e.preventDefault();
        var api = apiFromEvent(this);
        if (
          api &&
          typeof api.isTerminalSubmitLocked === "function" &&
          api.isTerminalSubmitLocked()
        ) {
          return;
        }
        if (api) {
          api.setStep(2);
        }
      },
    );

    $(document).on(
      "click.mtuc",
      "#mt-uni-credit-product-modal [data-mtuc-back], #mt-uni-credit-cart-modal [data-mtuc-back]",
      function (e) {
        e.preventDefault();
        var api = apiFromEvent(this);
        if (
          api &&
          typeof api.isTerminalSubmitLocked === "function" &&
          api.isTerminalSubmitLocked()
        ) {
          return;
        }
        if (api) {
          api.setStep(1);
        }
      },
    );

    $(document).on(
      "change.mtuc",
      "#mt-uni-credit-product-modal [data-mtuc-schemes], #mt-uni-credit-cart-modal [data-mtuc-schemes]",
      function () {
        var api = apiFromEvent(this);
        if (
          api &&
          typeof api.isTerminalSubmitLocked === "function" &&
          api.isTerminalSubmitLocked()
        ) {
          return;
        }
        if (api) {
          api.selectScheme($(this).val());
        }
      },
    );

    $(document).on(
      "input.mtuc",
      "#mt-uni-credit-product-modal [data-mtuc-first], #mt-uni-credit-cart-modal [data-mtuc-first]",
      function () {
        var api = apiFromEvent(this);
        if (
          api &&
          typeof api.isTerminalSubmitLocked === "function" &&
          api.isTerminalSubmitLocked()
        ) {
          return;
        }
        if (api && typeof api.recalculate === "function") {
          api.recalculate();
        }
      },
    );

    $(document).on(
      "click.mtuc",
      "#mt-uni-credit-product-modal [data-mtuc-secondary], #mt-uni-credit-cart-modal [data-mtuc-secondary]",
      function (e) {
        e.preventDefault();
        var api = apiFromEvent(this);
        if (
          api &&
          typeof api.isTerminalSubmitLocked === "function" &&
          api.isTerminalSubmitLocked()
        ) {
          return;
        }
        if (api) {
          api.secondary();
        }
      },
    );

    $(document).on(
      "click.mtuc",
      "#mt-uni-credit-product-modal [data-mtuc-submit], #mt-uni-credit-cart-modal [data-mtuc-submit]",
      function (e) {
        e.preventDefault();
        var api = apiFromEvent(this);
        if (
          api &&
          typeof api.isTerminalSubmitLocked === "function" &&
          api.isTerminalSubmitLocked()
        ) {
          return;
        }
        if (api) {
          api.submit();
        }
      },
    );

    $(document).on("keydown.mtuc", function (e) {
      if (!(e.key === "Escape" || e.keyCode === 27)) {
        return;
      }
      $(
        "#mt-uni-credit-product-modal-error, #mt-uni-credit-cart-modal-error",
      ).each(function () {
        var $err = $(this);
        if ($err.attr("hidden")) {
          return;
        }
        var errId = $err.attr("id") || "";
        var financingId = errId.replace(/-error$/, "");
        var api = financingId ? $("#" + financingId).data("mtucApi") : null;
        if (api && typeof api.closeErrorModal === "function") {
          api.closeErrorModal();
        } else {
          $err.attr("hidden", true).attr("aria-hidden", "true");
        }
      });
      $("#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal").each(
        function () {
          var $m = $(this);
          if (!$m.attr("hidden")) {
            var api = $m.data("mtucApi");
            if (
              api &&
              typeof api.isTerminalSubmitLocked === "function" &&
              api.isTerminalSubmitLocked()
            ) {
              return;
            }
            if (api) {
              api.closeModal();
            }
          }
        },
      );
    });

    // Jet OC3: [name=quantity] + [id^=input-option]. OC3 core uses #product / #input-quantity.
    $(document).on(
      "change.mtucProduct input.mtucProduct",
      '#input-quantity, [name="quantity"], [id^="input-option"], [name^="option["]',
      function (e) {
        var $target = $(e.target);
        if (
          $target.closest(
            "#mt-uni-credit-product-root, #mt-uni-credit-product-modal, #mt-uni-credit-cart-root, #mt-uni-credit-cart-modal",
          ).length
        ) {
          return;
        }
        var $productRoot = $("#mt-uni-credit-product-root");
        if (!$productRoot.length) {
          return;
        }
        var schedule = $productRoot.data("mtucScheduleCalculate");
        if (typeof schedule === "function") {
          schedule();
        }
      },
    );
  }

  waitForJQuery(50, 200, function ($) {
    bindDocumentHandlersOnce($);
    function boot() {
      $("#mt-uni-credit-product-root, #mt-uni-credit-cart-root").each(
        function () {
          initRoot($, $(this));
        },
      );
    }
    $(boot);
    $(document).ajaxComplete(function () {
      boot();
    });
  });
})();
