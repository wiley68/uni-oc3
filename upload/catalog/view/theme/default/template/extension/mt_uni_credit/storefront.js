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

    function fillDisplays(scheme) {
      if (!scheme) {
        return;
      }
      var currencyIso = state.currency_iso || "";
      $modal
        .find('[data-mtuc-display="price"]')
        .text(formatMoneyWithCurrency(state.price, currencyIso));
      $modal
        .find('[data-mtuc-display="monthly"]')
        .text(formatMoneyWithCurrency(scheme.monthly, currencyIso));
      $modal
        .find('[data-mtuc-display="total"]')
        .text(formatMoneyWithCurrency(scheme.total, currencyIso));
      $modal
        .find("[data-mtuc-first]")
        .val(formatMoney(scheme.first_installment || 0));
      if (scheme.first_installment_locked) {
        $modal.find("[data-mtuc-first]").attr("readonly", "readonly");
      }
    }

    function fillSchemes() {
      var offer = currentOffer();
      var $select = $modal.find("[data-mtuc-schemes]");
      var currencyIso = state.currency_iso || "";
      $select.empty();
      if (!offer || !offer.schemes) {
        return;
      }
      $.each(offer.schemes, function (_, scheme) {
        var selected = scheme.key === selectedSchemeKey ? " selected" : "";
        $select.append(
          '<option value="' +
            scheme.key +
            '"' +
            selected +
            ">" +
            scheme.months +
            " × " +
            formatMoneyWithCurrency(scheme.monthly, currencyIso) +
            "</option>",
        );
      });
      if (!selectedSchemeKey && offer.preferred_scheme_key) {
        selectedSchemeKey = offer.preferred_scheme_key;
        $select.val(selectedSchemeKey);
      }
      fillDisplays(findScheme(offer.schemes, selectedSchemeKey));
    }

    function setStep(step) {
      $modal
        .find("[data-mtuc-step]")
        .attr("hidden", true)
        .removeClass("mt-uni-credit-storefront__step--active");
      $modal
        .find('[data-mtuc-step="' + step + '"]')
        .removeAttr("hidden")
        .addClass("mt-uni-credit-storefront__step--active");
    }

    function openModal(offerType) {
      selectedOfferType = offerType || selectedOfferType;
      var offer = currentOffer();
      if (offer && offer.preferred_scheme_key) {
        selectedSchemeKey = offer.preferred_scheme_key;
      }
      moveModalToBody();
      fillSchemes();
      setStep(1);
      $modal.removeAttr("hidden").attr("aria-hidden", "false");
      $modal.find(".mt-uni-credit-storefront__dialog").trigger("focus");
    }

    function closeModal() {
      $modal.attr("hidden", true).attr("aria-hidden", "true");
      $modal.find("[data-mtuc-processing]").attr("hidden", true);
      setStep(1);
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
        .fail(function () {
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
          if ($modal.is(":visible") || !$modal.attr("hidden")) {
            fillSchemes();
          }
        },
      );
    }

    $root.off("click.mtuc").on("click.mtuc", "[data-mtuc-offer]", function (e) {
      e.preventDefault();
      openModal($(this).attr("data-mtuc-offer"));
    });

    // Instance API on modal — document handlers are bound once and look up $.data.
    $modal.data("mtucApi", {
      closeModal: closeModal,
      setStep: setStep,
      selectScheme: function (key) {
        selectedSchemeKey = key;
        var offer = currentOffer();
        if (offer) {
          fillDisplays(findScheme(offer.schemes || [], selectedSchemeKey));
        }
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
        $modal.find("[data-mtuc-processing]").removeAttr("hidden");
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
            $modal.find("[data-mtuc-processing]").attr("hidden", true);
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
    // Journal/AJAX fragment rebuilds: re-init new roots only (bound roots skipped).
    $(document).ajaxComplete(function () {
      boot();
    });
  });
})();
