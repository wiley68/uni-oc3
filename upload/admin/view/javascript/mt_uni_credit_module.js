/**
 * UniCredit Module admin helpers.
 *
 * OpenCart 3 admin common.js submits every form whose id contains "form-" whenever
 * any submit button is clicked. This page therefore keeps only #form-module in the
 * DOM and POSTs operational actions through a temporary form created on explicit click.
 */
(function (window, document) {
  "use strict";

  function mtUniCreditPostAction(url) {
    if (!url) {
      return;
    }

    var form = document.createElement("form");
    form.method = "post";
    form.action = url;
    form.style.display = "none";
    document.body.appendChild(form);

    if (typeof form.submit === "function") {
      form.submit();
    }

    if (form.parentNode) {
      form.parentNode.removeChild(form);
    }
  }

  function bindPostButton(buttonId, url) {
    var button = document.getElementById(buttonId);
    if (!button || !url) {
      return;
    }

    button.onclick = function () {
      mtUniCreditPostAction(url);
      return false;
    };
  }

  window.mtUniCreditPostAction = mtUniCreditPostAction;

  document.addEventListener("DOMContentLoaded", function () {
    var root = document.getElementById("content");
    if (!root || !root.getAttribute) {
      return;
    }

    bindPostButton(
      "button-mt-uni-credit-refresh-bank",
      root.getAttribute("data-mt-uni-credit-refresh-bank"),
    );
    bindPostButton(
      "button-mt-uni-credit-download-journal",
      root.getAttribute("data-mt-uni-credit-download-journal"),
    );
  });
})(window, document);
