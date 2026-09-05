(function ($) {
  "use strict";

  var ROOT_ID = "mt-uni-credit-checkout-root";
  var BOOTSTRAP_ID = "mt-uni-credit-checkout-bootstrap";

  function formatMoney(value) {
    var n = parseFloat(String(value).replace(",", "."));
    if (isNaN(n)) {
      n = 0;
    }
    return n.toFixed(2);
  }

  function currencyLabel(iso) {
    return String(iso || "").toUpperCase() === "EUR" ? "евро" : "лв.";
  }

  function formatMoneyWithCurrency(value, iso) {
    return formatMoney(value) + " " + currencyLabel(iso);
  }

  function formatPercent(value) {
    var n = parseFloat(String(value).replace(",", "."));
    if (isNaN(n)) {
      return "";
    }
    return n.toFixed(2) + "%";
  }

  function initRoot(root) {
    if (!root || root.getAttribute("data-mtuc-bound") === "1") {
      return;
    }

    var bootstrapEl =
      root.querySelector("#" + BOOTSTRAP_ID) ||
      document.getElementById(BOOTSTRAP_ID);
    if (!bootstrapEl) {
      return;
    }

    var state;
    try {
      state = JSON.parse(bootstrapEl.textContent || "{}");
    } catch (e) {
      return;
    }

    root.setAttribute("data-mtuc-bound", "1");

    var $root = $(root);
    var selectedSchemeKey = "";
    var lastCalculation = null;
    var calcBusy = false;
    var confirmBusy = false;
    var firstTimer = null;
    var sequence = 0;

    function schemeSelect() {
      return $root.find("[data-mtuc-schemes]").get(0);
    }

    function firstInput() {
      return $root.find("[data-mtuc-first]").get(0);
    }

    function submitBtn() {
      return $root.find("#button-confirm, [data-mtuc-submit]").first();
    }

    function currencyIso() {
      return (state.calculator && state.calculator.currency_iso) || "";
    }

    function unifiedSchemes() {
      var offers = (state.calculator && state.calculator.offers) || {};
      if (
        offers.standard &&
        $.isArray(offers.standard.schemes) &&
        offers.standard.schemes.length
      ) {
        return offers.standard.schemes;
      }
      var merged = [];
      var seen = {};
      $.each(["standard", "promo"], function (_, type) {
        var list = (offers[type] && offers[type].schemes) || [];
        $.each(list, function (__, scheme) {
          if (!scheme || !scheme.key || seen[scheme.key]) {
            return;
          }
          seen[scheme.key] = true;
          merged.push(scheme);
        });
      });
      return merged;
    }

    function selectedScheme() {
      var key = selectedSchemeKey;
      var schemes = unifiedSchemes();
      for (var i = 0; i < schemes.length; i++) {
        if (schemes[i] && schemes[i].key === key) {
          return schemes[i];
        }
      }
      return null;
    }

    function consentsRequired() {
      var consents =
        (state.modal && state.modal.consents) ||
        (state.calculator && state.calculator.consents) ||
        [];
      if (!$.isArray(consents)) {
        return [];
      }
      return $.grep(consents, function (c) {
        return c && c.has_checkbox;
      });
    }

    function consentsAccepted() {
      var required = consentsRequired();
      if (!required.length) {
        return true;
      }
      var ok = true;
      $.each(required, function (_, consent) {
        var $cb = $root.find(
          "#mtuc-checkout-consent-" +
            consent.id +
            ', [data-mtuc-consent-checkbox][value="' +
            consent.id +
            '"]',
        );
        if (!$cb.length || !$cb.is(":checked")) {
          ok = false;
        }
      });
      return ok;
    }

    function process2Ok() {
      if (!(state.modal && state.modal.process2)) {
        return true;
      }
      var egn = String($root.find('[name="egn"]').val() || "").trim();
      var phone2 = String($root.find('[name="phone2"]').val() || "").trim();
      return egn.length === 10 && phone2.length > 0;
    }

    function updateConfirmState() {
      var $btn = submitBtn();
      var enabled =
        !!selectedScheme() &&
        !calcBusy &&
        !confirmBusy &&
        consentsAccepted() &&
        process2Ok();
      $btn.prop("disabled", !enabled);
      $btn.attr("aria-disabled", enabled ? "false" : "true");
    }

    function renderCalculation(calculation) {
      if (!calculation) {
        return;
      }
      lastCalculation = calculation;
      var iso = currencyIso();
      var map = {
        price: formatMoneyWithCurrency(
          calculation.price != null
            ? calculation.price
            : state.calculator && state.calculator.price,
          iso,
        ),
        financed_amount: formatMoneyWithCurrency(
          calculation.financed_amount != null
            ? calculation.financed_amount
            : calculation.financed,
          iso,
        ),
        monthly_installment: formatMoneyWithCurrency(
          calculation.monthly_installment != null
            ? calculation.monthly_installment
            : calculation.monthly,
          iso,
        ),
        total_payable: formatMoneyWithCurrency(
          calculation.total_payable != null
            ? calculation.total_payable
            : calculation.total,
          iso,
        ),
        glp: formatPercent(calculation.glp),
        gpr: formatPercent(calculation.gpr),
      };
      $.each(map, function (key, value) {
        $root.find('[data-mtuc-display="' + key + '"]').text(value);
      });

      var $first = $(firstInput());
      if ($first.length) {
        $first.val(formatMoney(calculation.first_installment || 0));
        if (calculation.first_installment_locked) {
          $first.attr("readonly", "readonly");
        } else {
          $first.removeAttr("readonly");
        }
      }
      var $firstRow = $root.find("[data-mtuc-first-row]");
      if ($firstRow.length) {
        $firstRow.prop("hidden", calculation.show_first_installment === false);
      }
      updateConfirmState();
    }

    function applySchemeFromSelect() {
      var select = schemeSelect();
      selectedSchemeKey = select ? String(select.value || "") : "";
      var scheme = selectedScheme();
      if (!scheme) {
        updateConfirmState();
        return;
      }
      recalculate(scheme);
    }

    function recalculate(scheme) {
      if (!scheme || !state.recalculate_url) {
        return;
      }
      calcBusy = true;
      updateConfirmState();
      var seq = ++sequence;
      var first = firstInput();
      var firstVal = first
        ? parseFloat(String(first.value).replace(",", ".")) || 0
        : 0;
      $.ajax({
        url: state.recalculate_url,
        type: "POST",
        dataType: "json",
        data: {
          csrf_token: state.csrf_token,
          scheme_key: scheme.key,
          first_installment: firstVal,
          sequence: seq,
        },
        success: function (json) {
          if (seq !== sequence) {
            return;
          }
          if (json && json.success && json.calculation) {
            $root.find("[data-mtuc-popup-error]").text("");
            renderCalculation(json.calculation);
          } else {
            $root
              .find("[data-mtuc-popup-error]")
              .text(
                (json && json.message) ||
                  (state.i18n && state.i18n.unavailable) ||
                  "",
              );
          }
        },
        error: function () {
          if (seq !== sequence) {
            return;
          }
          $root
            .find("[data-mtuc-popup-error]")
            .text((state.i18n && state.i18n.unavailable) || "");
        },
        complete: function () {
          if (seq !== sequence) {
            return;
          }
          calcBusy = false;
          updateConfirmState();
        },
      });
    }

    function setProcessing(active) {
      var $panel = $root.find("[data-mtuc-processing]");
      if (!$panel.length) {
        return;
      }
      $panel.prop("hidden", !active);
      $panel
        .find("[role='status']")
        .attr("aria-busy", active ? "true" : "false");
    }

    function collectConsentIds() {
      var ids = [];
      $root.find("[data-mtuc-consent-checkbox]:checked").each(function () {
        ids.push($(this).val());
      });
      return ids;
    }

    function confirmOrder() {
      if (confirmBusy || !selectedScheme()) {
        return;
      }
      if (!consentsAccepted()) {
        $root
          .find("[data-mtuc-submit-error]")
          .text((state.i18n && state.i18n.consent) || "");
        return;
      }
      if (!process2Ok()) {
        return;
      }

      confirmBusy = true;
      updateConfirmState();
      setProcessing(true);
      $root.find("[data-mtuc-submit-error]").text("");

      var scheme = selectedScheme();
      var first = firstInput();
      var firstVal =
        lastCalculation && lastCalculation.first_installment_locked
          ? lastCalculation.first_installment
          : first
            ? parseFloat(String(first.value).replace(",", ".")) || 0
            : 0;

      var data = {
        csrf_token: state.csrf_token,
        scheme_key: scheme.key,
        first_installment: firstVal,
        egn: String($root.find('[name="egn"]').val() || ""),
        phone2: String($root.find('[name="phone2"]').val() || ""),
      };
      $.each(collectConsentIds(), function (_, id) {
        if (!data["consent"]) {
          data["consent"] = [];
        }
        data["consent"].push(id);
      });

      $.ajax({
        url: state.confirm_url,
        type: "POST",
        dataType: "json",
        data: data,
        success: function (json) {
          if (json && json.redirect) {
            window.location = json.redirect;
            return;
          }
          $root
            .find("[data-mtuc-submit-error]")
            .text(
              (json && json.error) ||
                (state.i18n && state.i18n.unavailable) ||
                "",
            );
        },
        error: function () {
          $root
            .find("[data-mtuc-submit-error]")
            .text((state.i18n && state.i18n.unavailable) || "");
        },
        complete: function () {
          confirmBusy = false;
          setProcessing(false);
          updateConfirmState();
        },
      });
    }

    $root.on("change", "[data-mtuc-schemes]", function () {
      applySchemeFromSelect();
    });
    $root.on("input change", "[data-mtuc-first]", function () {
      if (firstTimer) {
        clearTimeout(firstTimer);
      }
      firstTimer = setTimeout(function () {
        var scheme = selectedScheme();
        if (scheme) {
          recalculate(scheme);
        }
      }, 350);
    });
    $root.on(
      "change input",
      "[data-mtuc-consent-checkbox], [name='egn'], [name='phone2']",
      function () {
        updateConfirmState();
      },
    );
    $root.on("click", "#button-confirm, [data-mtuc-submit]", function (e) {
      e.preventDefault();
      confirmOrder();
    });

    var select = schemeSelect();
    if (select && select.value) {
      selectedSchemeKey = String(select.value);
    } else {
      var schemes = unifiedSchemes();
      if (schemes.length) {
        selectedSchemeKey = String(schemes[0].key || "");
        if (select) {
          select.value = selectedSchemeKey;
        }
      }
    }

    var initial = selectedScheme();
    if (initial) {
      renderCalculation({
        price: state.calculator && state.calculator.price,
        financed_amount: initial.financed_amount,
        monthly_installment: initial.monthly_installment,
        total_payable: initial.total_payable,
        glp: initial.glp,
        gpr: initial.gpr,
        first_installment: initial.first_installment,
        first_installment_locked: initial.first_installment_locked,
        show_first_installment:
          state.calculator && state.calculator.show_first_installment,
      });
      recalculate(initial);
    } else {
      updateConfirmState();
    }
  }

  function scan() {
    var root = document.getElementById(ROOT_ID);
    if (root) {
      initRoot(root);
    }
  }

  $(scan);
  $(document).ajaxComplete(function () {
    setTimeout(scan, 0);
  });
})(window.jQuery);
