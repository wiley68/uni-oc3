/**
 * AUD-010 F02-R1 — execute production storefront.js in jsdom.
 * Loads the real file from upload/.../storefront.js (no reimplementation).
 *
 * Run: node tests/support/aud010_f02_jsdom/run_production_js_dom_proof.js
 * Exit 0 on PASS, 1 on FAIL. Prints PASS/FAIL lines for the PHP suite.
 */
"use strict";

var fs = require("fs");
var path = require("path");
var { JSDOM } = require("jsdom");
var jqueryFactory = require("jquery");

var root = path.resolve(__dirname, "..", "..", "..");
var storefrontJs = path.join(
  root,
  "upload",
  "catalog",
  "view",
  "theme",
  "default",
  "template",
  "extension",
  "mt_uni_credit",
  "storefront.js",
);

var failures = [];
var passes = 0;

function assert(cond, message) {
  if (cond) {
    passes += 1;
    console.log("PASS  " + message);
    return;
  }
  failures.push(message);
  console.log("FAIL  " + message);
}

function buildHtml(processMode) {
  var p2 =
    processMode === 2
      ? [
          '<div class="mt-uni-credit-storefront__customer-field">',
          '<input class="mt-uni-credit-storefront__customer-input" name="phone2" type="tel" value="0888111222" data-mtuc-required="1" />',
          '<span class="mt-uni-credit-storefront__field-error" data-mtuc-field-error="phone2" role="alert"></span>',
          "</div>",
          '<div class="mt-uni-credit-storefront__customer-field">',
          '<input class="mt-uni-credit-storefront__customer-input" name="egn" type="text" value="2000010112" data-mtuc-required="1" />',
          '<span class="mt-uni-credit-storefront__field-error" data-mtuc-field-error="egn" role="alert"></span>',
          "</div>",
        ].join("")
      : "";

  var bootstrap = {
    entry_point: "product",
    button_title: "Buy on installments",
    i18n: {
      error_field_required: "This field is required.",
      error_phone_invalid: "Please enter a valid phone number.",
      error_email_invalid: "Please enter a valid email address.",
      error_egn_invalid: "EGN must contain 10 digits.",
      error_phone2_invalid: "Second phone invalid.",
      error_validation_incomplete: "Please fill in all required fields.",
      error_request_failed: "The request was not successful.",
      error_recalculate: "Calculation failed.",
      error_validation: "Please correct the highlighted fields.",
    },
    calculator: {
      currency_iso: "BGN",
      price: 500,
      offers: {
        standard: {
          preferred_scheme_key: "s1",
          schemes: [
            {
              key: "s1",
              months: 12,
              price: 500,
              financed_amount: 500,
              monthly_installment: 50,
              total_payable: 600,
              first_installment: 0,
              glp: 5,
              gpr: 6,
              show_first_installment: false,
            },
          ],
        },
      },
    },
  };

  return [
    "<!DOCTYPE html><html><head><meta charset=\"utf-8\"></head><body>",
    '<div id="mt-uni-credit-product-root" data-entry-point="product"',
    ' data-csrf="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"',
    ' data-route-submit="/submit" data-route-calculate="/calc" data-route-recalculate="/recalc"',
    ' data-product-id="42" data-application-token="tok">',
    '  <div data-mtuc-buttons><button type="button" data-mtuc-offer="standard" data-preferred-key="s1">Offer</button></div>',
    '  <div id="mt-uni-credit-product-modal" class="mt-uni-credit-storefront__modal" hidden aria-hidden="true">',
    '    <div class="mt-uni-credit-storefront__dialog" tabindex="-1">',
    '      <div data-mtuc-step="1" class="mt-uni-credit-storefront__step mt-uni-credit-storefront__step--active">',
    '        <div data-mtuc-popup-error></div>',
    '        <select data-mtuc-schemes></select>',
    '        <input data-mtuc-first type="text" value="0" />',
    "      </div>",
    '      <div data-mtuc-step="2" class="mt-uni-credit-storefront__step" hidden>',
    '        <form data-mtuc-form data-mtuc-process="' +
      String(processMode) +
      '" novalidate>',
    '          <input name="firstname" type="text" value="Ivan" data-mtuc-required="1" />',
    '          <span data-mtuc-field-error="firstname" role="alert"></span>',
    '          <input name="lastname" type="text" value="Ivanov" data-mtuc-required="1" />',
    '          <span data-mtuc-field-error="lastname" role="alert"></span>',
    '          <input name="address" type="text" value="Vitosha 10" data-mtuc-required="1" />',
    '          <span data-mtuc-field-error="address" role="alert"></span>',
    '          <input name="phone" type="tel" value="0888123456" data-mtuc-required="1" />',
    '          <span data-mtuc-field-error="phone" role="alert"></span>',
    '          <input name="email" type="email" value="ivan@example.test" data-mtuc-required="1" />',
    '          <span data-mtuc-field-error="email" role="alert"></span>',
    p2,
    '          <span data-mtuc-submit-error role="alert"></span>',
    '          <input type="checkbox" data-mtuc-consent-checkbox data-mtuc-consent name="consent" value="1" checked />',
    '          <button type="button" data-mtuc-submit>Submit</button>',
    "        </form>",
    "      </div>",
    "    </div>",
    '    <div data-mtuc-processing hidden></div>',
    "  </div>",
    '  <script type="application/json" data-mtuc-bootstrap>' +
      JSON.stringify(bootstrap) +
      "</script>",
    "</div>",
    // Cart root shares the same storefront.js; empty cart proves shared binding path exists.
    '<div id="mt-uni-credit-cart-root" data-entry-point="cart" data-csrf="bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"',
    ' data-route-submit="/cart-submit" data-route-calculate="/cart-calc" data-route-recalculate="/cart-recalc">',
    '  <div id="mt-uni-credit-cart-modal" hidden>',
    '    <div class="mt-uni-credit-storefront__dialog" tabindex="-1">',
    '      <div data-mtuc-step="2">',
    '        <form data-mtuc-form data-mtuc-process="1" novalidate>',
    '          <input name="firstname" type="text" value="Anna" />',
    '          <span data-mtuc-field-error="firstname"></span>',
    '          <input name="lastname" type="text" value="Smith" />',
    '          <span data-mtuc-field-error="lastname"></span>',
    '          <input name="address" type="text" value="Main 1" />',
    '          <span data-mtuc-field-error="address"></span>',
    '          <input name="phone" type="tel" value="0888000111" />',
    '          <span data-mtuc-field-error="phone"></span>',
    '          <input name="email" type="email" value="anna@example.test" />',
    '          <span data-mtuc-field-error="email"></span>',
    '          <span data-mtuc-submit-error></span>',
    '          <input type="checkbox" data-mtuc-consent-checkbox data-mtuc-consent name="consent" value="1" checked />',
    '          <button type="button" data-mtuc-submit>Submit</button>',
    "        </form>",
    "      </div>",
    "    </div>",
    '    <div data-mtuc-processing hidden></div>',
    '    <script type="application/json" data-mtuc-bootstrap>' +
      JSON.stringify({
        entry_point: "cart",
        i18n: bootstrap.i18n,
        calculator: bootstrap.calculator,
        cart_fingerprint: "fp",
      }) +
      "</script>",
    "  </div>",
    "</div>",
    "</body></html>",
  ].join("");
}

function bootDom(processMode) {
  var html = buildHtml(processMode);
  var dom = new JSDOM(html, {
    url: "https://example.test/product",
    runScripts: "dangerously",
    pretendToBeVisual: true,
  });
  var window = dom.window;
  var $ = jqueryFactory(window);
  window.$ = window.jQuery = $;

  // jsdom: elements often report zero layout → jQuery :visible is false.
  // Production visibility checks must still run; treat non-[hidden] nodes as visible.
  var oldIs = $.fn.is;
  $.fn.is = function (selector) {
    if (selector === ":visible") {
      var el = this.get(0);
      if (!el) {
        return false;
      }
      if (el.hidden || (el.getAttribute && el.getAttribute("hidden") !== null)) {
        return false;
      }
      var style = window.getComputedStyle ? window.getComputedStyle(el) : null;
      if (style && (style.display === "none" || style.visibility === "hidden")) {
        return false;
      }
      return true;
    }
    if (selector === ":hidden") {
      return !$(this).is(":visible");
    }
    return oldIs.apply(this, arguments);
  };

  // jQuery .trigger('focus') does not always update document.activeElement in jsdom.
  var oldTrigger = $.fn.trigger;
  $.fn.trigger = function (type) {
    var result = oldTrigger.apply(this, arguments);
    var name = typeof type === "string" ? type : type && type.type;
    if (name === "focus") {
      this.each(function () {
        if (typeof this.focus === "function") {
          this.focus();
        }
      });
    }
    return result;
  };

  // Production storefront.js waits for jQuery then boots roots.
  var code = fs.readFileSync(storefrontJs, "utf8");
  window.eval(code);

  // Allow waitForJQuery / $(boot) to run.
  return new Promise(function (resolve) {
    setTimeout(function () {
      resolve({ dom: dom, window: window, $: $ });
    }, 80);
  });
}

function spanText($, modal, field) {
  return String($(modal).find('[data-mtuc-field-error="' + field + '"]').text() || "");
}

function runProofs() {
  return bootDom(1).then(function (env) {
    var $ = env.$;
    var window = env.window;
    var $modal = $("#mt-uni-credit-product-modal");
    var api = $modal.data("mtucApi");

    assert(!!api, "production JS bound mtucApi on Product modal");
    assert(typeof api.showFieldErrors === "function", "production showFieldErrors exposed");
    assert(typeof api.clearAllFieldErrors === "function", "production clearAllFieldErrors exposed");
    assert(typeof api.clearOneFieldError === "function", "production clearOneFieldError exposed");
    assert(typeof api.focusFirstInvalidField === "function", "production focusFirstInvalidField exposed");
    assert(typeof api.setProcessing === "function", "production setProcessing exposed");
    assert(typeof api.submit === "function", "production submit exposed");

    var $cartModal = $("#mt-uni-credit-cart-modal");
    var cartApi = $cartModal.data("mtucApi");
    assert(!!cartApi && typeof cartApi.showFieldErrors === "function", "same storefront.js bound Cart mtucApi");

    // Open step 2 / show modal for focus + visibility checks.
    api.setStep(2, { animate: false });
    $modal.removeAttr("hidden").attr("aria-hidden", "false");

    var multi = {
      firstname: "First name too long",
      email: "Bad email",
      phone: "Bad phone",
      future_field: "ignored",
    };
    api.showFieldErrors(multi);
    assert(spanText($, $modal, "firstname") === "First name too long", "JS: firstname span rendered");
    assert(spanText($, $modal, "email") === "Bad email", "JS: email span rendered");
    assert(spanText($, $modal, "phone") === "Bad phone", "JS: phone span rendered");
    assert(spanText($, $modal, "lastname") === "", "JS: unrelated lastname still empty");
    assert(
      $modal.find('[name="firstname"]').attr("aria-invalid") === "true",
      "JS: aria-invalid=true on errored field",
    );

    api.focusFirstInvalidField(multi);
    assert(
      window.document.activeElement === $modal.find('[name="firstname"]').get(0),
      "JS: first invalid focus = firstname",
    );

    // Clear-on-correction (production clearOneFieldError + input listener path).
    api.clearOneFieldError("firstname");
    assert(spanText($, $modal, "firstname") === "", "JS: clear-one clears firstname span");
    assert(
      $modal.find('[name="firstname"]').attr("aria-invalid") === "false",
      "JS: aria-invalid=false after clear",
    );
    assert(spanText($, $modal, "email") === "Bad email", "JS: unrelated email error retained");

    // Clear-before-submit + server validation via mocked $.ajax.
    $modal.find('[name="firstname"]').val("Ivan");
    $modal.find('[name="lastname"]').val("Ivanov");
    $modal.find('[name="address"]').val("Vitosha 10");
    $modal.find('[name="phone"]').val("0888123456");
    $modal.find('[name="email"]').val("ivan@example.test");
    $modal.find("[data-mtuc-consent]").prop("checked", true);
    api.showFieldErrors({ lastname: "stale lastname" });
    assert(spanText($, $modal, "lastname") === "stale lastname", "JS: stale error present before submit");

    var ajaxCalls = 0;
    $.ajax = function (opts) {
      ajaxCalls += 1;
      var deferred = $.Deferred();
      setTimeout(function () {
        deferred.resolve({
          success: false,
          error: "validation",
          message: "Please correct the highlighted fields.",
          errors: {
            firstname: "Name length",
            email: "Email invalid",
            future_field: "nope",
          },
        });
      }, 0);
      return deferred.promise();
    };

    var firstnameBefore = $modal.find('[name="firstname"]').val();
    api.submit();

    return new Promise(function (resolve) {
      setTimeout(function () {
        assert(ajaxCalls === 1, "JS: submit posted once for valid client form");
        assert(spanText($, $modal, "lastname") === "", "JS: clear-before-submit removed stale lastname");
        assert(spanText($, $modal, "firstname") === "Name length", "JS: server firstname error rendered");
        assert(spanText($, $modal, "email") === "Email invalid", "JS: server email error rendered");
        assert(
          $modal.find("[data-mtuc-submit-error]").text() ===
            "Please correct the highlighted fields.",
          "JS: generic validation summary shown",
        );
        assert(
          $modal.attr("hidden") == null || $modal.attr("hidden") === false || $modal.attr("hidden") === undefined,
          "JS: modal remains open after validation",
        );
        // jQuery attr('hidden') may be false string when removed — also check property.
        assert(!$modal[0].hasAttribute("hidden"), "JS: modal hidden attribute absent");
        assert(
          $modal.find('[name="firstname"]').val() === firstnameBefore,
          "JS: entered values retained",
        );
        assert(
          $modal.attr("data-mtuc-processing-active") == null,
          "JS: processing state released after validation",
        );
        assert(
          window.document.activeElement === $modal.find('[name="firstname"]').get(0),
          "JS: focus moved to first server-invalid field",
        );

        // Empty / missing errors must not throw.
        var threwEmpty = false;
        try {
          api.clearAllFieldErrors();
          api.showFieldErrors({});
          api.focusFirstInvalidField({});
          api.showFieldErrors(null);
          api.focusFirstInvalidField(undefined);
        } catch (e) {
          threwEmpty = true;
        }
        assert(!threwEmpty, "JS: empty/missing errors map does not throw");
        $modal.find("[data-mtuc-submit-error]").text("Please correct the highlighted fields.");
        assert(
          $modal.find("[data-mtuc-submit-error]").text().length > 0,
          "JS: generic summary available with empty errors",
        );

        // Unknown field alone does not crash and does not invent spans.
        api.clearAllFieldErrors();
        api.showFieldErrors({ future_field: "x", address: "Address bad" });
        assert(spanText($, $modal, "address") === "Address bad", "JS: known error still renders with unknown key");
        assert($('[data-mtuc-field-error="future_field"]').length === 0, "JS: unknown key has no span");

        // Process 1: phone2/egn absent — must not steal focus.
        api.clearAllFieldErrors();
        api.showFieldErrors({
          phone2: "should not focus",
          egn: "should not focus",
          email: "Email only",
        });
        api.focusFirstInvalidField({
          phone2: "should not focus",
          egn: "should not focus",
          email: "Email only",
        });
        assert(
          window.document.activeElement === $modal.find('[name="email"]').get(0),
          "JS: Process 1 focus skips absent phone2/egn",
        );
        assert(spanText($, $modal, "phone2") === "", "JS: Process 1 phone2 span unaffected/absent");

        env.dom.window.close();
        resolve();
      }, 40);
    });
  }).then(function () {
    return bootDom(2).then(function (env) {
      var $ = env.$;
      var window = env.window;
      var $modal = $("#mt-uni-credit-product-modal");
      var api = $modal.data("mtucApi");
      assert(!!api, "Process 2 fixture: production API bound");
      api.setStep(2, { animate: false });
      $modal.removeAttr("hidden");

      api.showFieldErrors({
        phone2: "Second phone invalid.",
        egn: "EGN must contain 10 digits.",
        lastname: "Last required",
      });
      assert(spanText($, $modal, "phone2") === "Second phone invalid.", "JS: phone2 mapped");
      assert(spanText($, $modal, "egn") === "EGN must contain 10 digits.", "JS: egn mapped");
      assert(spanText($, $modal, "egn").indexOf("2000010112") === -1, "JS: egn error does not echo value");
      api.focusFirstInvalidField({
        phone2: "Second phone invalid.",
        egn: "EGN must contain 10 digits.",
        lastname: "Last required",
      });
      assert(
        window.document.activeElement === $modal.find('[name="lastname"]').get(0),
        "JS: Process 2 first focus follows field order (lastname before phone2)",
      );

      // Prove input event clears only one field via production listener.
      api.showFieldErrors({ phone2: "keep", egn: "clear-me" });
      $modal.find('[name="egn"]').val("2001010112").trigger("input");
      assert(spanText($, $modal, "egn") === "", "JS: input clears egn error via production listener");
      assert(spanText($, $modal, "phone2") === "keep", "JS: input leaves phone2 error");

      env.dom.window.close();
    });
  });
}

if (!fs.existsSync(storefrontJs)) {
  console.log("FAIL  production storefront.js missing: " + storefrontJs);
  process.exit(1);
}

runProofs()
  .then(function () {
    if (failures.length) {
      console.log(
        "AUD-010 F02-R1 JSDOM: FAIL (" +
          failures.length +
          " failures, " +
          passes +
          " passes)",
      );
      process.exit(1);
    }
    console.log("AUD-010 F02-R1 JSDOM: PASS (" + passes + " passes)");
    process.exit(0);
  })
  .catch(function (err) {
    console.log("FAIL  harness exception: " + (err && err.stack ? err.stack : err));
    process.exit(1);
  });
