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

    function currentOffer() {
      return (state.offers && state.offers[selectedOfferType]) || null;
    }

    function setProcessing(active) {
      var $panel = $modal.find("[data-mtuc-processing]");
      var $dialog = $modal.find(".mt-uni-credit-storefront__dialog");
      if (active) {
        $panel.removeAttr("hidden");
        $dialog.css({ opacity: "0.45", pointerEvents: "none" });
      } else {
        $panel.attr("hidden", true);
        $dialog.css({ opacity: "", pointerEvents: "" });
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
      }, 0);
    }

    /**
     * OC4 contract: toggle [hidden] + __step--active, then focus Step 2.
     * Visual transition uses short opacity fade (Jet/UniCredit family hide/show('slow')
     * rhythm) while keeping OC4 class/hidden sequencing — no hard flash.
     */
    function setStep(step, options) {
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

      $current
        .addClass("is-transitioning-out")
        .css("opacity", "0");
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
            focusStep2Field();
          }
        }, 350);
      }, 350);
    }

    function openModal(offerType, preferredKey) {
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
      if (firstInstallmentTimer) {
        window.clearTimeout(firstInstallmentTimer);
        firstInstallmentTimer = null;
      }
      setProcessing(false);
      $modal.attr("hidden", true).attr("aria-hidden", "true");
      setStep(1, { animate: false });
      restoreModal();
    }

    function productFormData() {
      var $form = $("#form-product");
      var quantity = 1;
      var option = {};
      if ($form.length) {
        quantity = parseInt($form.find('[name="quantity"]').val(), 10) || 1;
        $form.find('[name^="option"]').each(function () {
          var $el = $(this);
          var name = $el.attr("name") || "";
          var match = name.match(/^option\[(\d+)\](\[\])?$/);
          if (!match) {
            return;
          }
          var id = match[1];
          if ($el.is(":checkbox") || $el.is(":radio")) {
            if (!$el.is(":checked")) {
              return;
            }
          }
          if (match[2]) {
            if (!option[id]) {
              option[id] = [];
            }
            option[id].push($el.val());
          } else {
            option[id] = $el.val();
          }
        });
      }
      return { quantity: quantity, option: option };
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
            err ||
            !response ||
            !response.success ||
            response.sequence !== localSeq
          ) {
            if (response && response.unavailable) {
              $root
                .find("[data-mtuc-entry-error]")
                .text("")
                .attr("hidden", true);
              $root.hide();
            }
            return;
          }
          state = response.calculator || state;
          $root.find("[data-mtuc-preferred-price]").each(function () {
            var type = $(this)
              .closest("[data-mtuc-offer]")
              .attr("data-mtuc-offer");
            if (state.offers && state.offers[type]) {
              $(this).text(state.offers[type].installment_label);
            }
          });
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
      var $btn = $(this);
      openModal(
        $btn.attr("data-mtuc-offer"),
        $btn.attr("data-preferred-key") || "",
      );
    });

    $modal.data("mtucApi", {
      closeModal: closeModal,
      setStep: setStep,
      setProcessing: setProcessing,
      selectScheme: function (key) {
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
        scheduleRecalculate(false);
      },
      secondary: function () {
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
        var $form = $modal.find("[data-mtuc-form]");
        if (!$form.length) {
          return;
        }
        var consent = $form.find("[data-mtuc-consent]").is(":checked");
        if (!consent) {
          $modal
            .find("[data-mtuc-submit-error]")
            .text("Моля, приемете условията.");
          return;
        }
        setProcessing(true);
        var payload = $form.serializeArray();
        var data = {
          csrf: $root.attr("data-csrf"),
          scheme_key: selectedSchemeKey,
          consent: "1",
        };
        $.each(payload, function (_, item) {
          data[item.name] = item.value;
        });
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
        postJson(
          $root.attr("data-route-submit"),
          data,
          function (err, response) {
            setProcessing(false);
            if (err || !response) {
              $modal
                .find("[data-mtuc-submit-error]")
                .text("Заявката не беше успешна.");
              return;
            }
            if (response.redirect) {
              window.location = response.redirect;
              return;
            }
            if (response.success) {
              closeModal();
              return;
            }
            $modal
              .find("[data-mtuc-submit-error]")
              .text(response.message || "Заявката не беше успешна.");
          },
        );
      },
      scheduleCalculate: scheduleCalculate,
    });

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
        if (api) {
          api.closeModal();
        }
      },
    );

    $(document).on(
      "click.mtuc",
      "#mt-uni-credit-product-modal [data-mtuc-apply], #mt-uni-credit-cart-modal [data-mtuc-apply]",
      function (e) {
        e.preventDefault();
        var api = apiFromEvent(this);
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
        if (api) {
          api.submit();
        }
      },
    );

    $(document).on("keydown.mtuc", function (e) {
      if (!(e.key === "Escape" || e.keyCode === 27)) {
        return;
      }
      $("#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal").each(
        function () {
          var $m = $(this);
          if (!$m.attr("hidden")) {
            var api = $m.data("mtucApi");
            if (api) {
              api.closeModal();
            }
          }
        },
      );
    });

    $(document).on(
      "change.mtuc input.mtuc",
      "#form-product [name='quantity'], #form-product [name^='option']",
      function () {
        var $root = $("#mt-uni-credit-product-root");
        var schedule = $root.data("mtucScheduleCalculate");
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
