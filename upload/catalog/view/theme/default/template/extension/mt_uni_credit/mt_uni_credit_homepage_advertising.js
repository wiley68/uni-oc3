(function () {
  "use strict";

  var ROOT_SELECTOR = "[data-mt-uni-credit-advertising]";
  var PANEL_ID = "mt-uni-credit-advertising-panel";
  var focusableSelector =
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function closest(el, selector) {
    if (!el || !el.closest) {
      while (el && el.nodeType === 1) {
        if (el.matches && el.matches(selector)) {
          return el;
        }
        el = el.parentElement;
      }
      return null;
    }
    return el.closest(selector);
  }

  function init() {
    var root = document.querySelector(ROOT_SELECTOR);
    if (!root || root.getAttribute("data-mtuc-ad-bound") === "1") {
      return;
    }
    root.setAttribute("data-mtuc-ad-bound", "1");

    var imgs = root.querySelectorAll("img");
    for (var i = 0; i < imgs.length; i++) {
      imgs[i].addEventListener("error", function () {
        this.style.display = "none";
      });
    }

    var panel = document.getElementById(PANEL_ID);
    var lastTrigger = null;

    document.addEventListener("click", onDocumentClick);
    if (panel) {
      document.addEventListener("keydown", onDocumentKeydown);
    }

    function onDocumentClick(event) {
      var target = event.target;
      var toggle = closest(target, "[data-mt-uni-credit-advertising-toggle]");
      if (toggle) {
        event.preventDefault();
        if (panel && panel.className.indexOf("is-visible") !== -1) {
          closePanel();
        } else {
          openPanel(toggle);
        }
        return;
      }

      var close = closest(target, "[data-mt-uni-credit-advertising-close]");
      if (close) {
        event.preventDefault();
        closePanel();
        return;
      }

      var open = closest(target, "[data-mt-uni-credit-advertising-open]");
      if (!open) {
        return;
      }
      var url = open.getAttribute("data-mt-uni-credit-advertising-open") || "";
      if (url) {
        window.open(url, "_blank", "noopener,noreferrer");
      }
    }

    function onDocumentKeydown(event) {
      if (!panel || panel.className.indexOf("is-visible") === -1) {
        return;
      }
      trapFocus(event);
    }

    function openPanel(trigger) {
      if (!panel) {
        return;
      }
      lastTrigger = trigger || null;
      panel.hidden = false;
      if (panel.className.indexOf("is-visible") === -1) {
        panel.className += " is-visible";
      }
      panel.setAttribute("aria-hidden", "false");
      if (trigger) {
        trigger.setAttribute("aria-expanded", "true");
      }
      setBackgroundInert(true);
      var focusables = getFocusables(panel);
      if (focusables.length > 0) {
        focusables[0].focus();
      } else if (panel.focus) {
        panel.focus();
      }
    }

    function closePanel() {
      if (!panel) {
        return;
      }
      panel.className = panel.className
        .replace(/\bis-visible\b/g, "")
        .replace(/\s+/g, " ")
        .trim();
      panel.hidden = true;
      panel.setAttribute("aria-hidden", "true");
      setBackgroundInert(false);
      if (lastTrigger) {
        lastTrigger.setAttribute("aria-expanded", "false");
        lastTrigger.focus();
        lastTrigger = null;
      }
    }

    function getFocusables(container) {
      var nodes = container.querySelectorAll(focusableSelector);
      var out = [];
      for (var j = 0; j < nodes.length; j++) {
        if (!nodes[j].hasAttribute("disabled")) {
          out.push(nodes[j]);
        }
      }
      return out;
    }

    function trapFocus(event) {
      var focusables = getFocusables(panel);
      if (focusables.length === 0) {
        if (event.key === "Escape" || event.keyCode === 27) {
          closePanel();
        }
        return;
      }
      var first = focusables[0];
      var last = focusables[focusables.length - 1];
      if (event.key === "Tab" || event.keyCode === 9) {
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
      if (event.key === "Escape" || event.keyCode === 27) {
        event.preventDefault();
        closePanel();
      }
    }

    function setBackgroundInert(inert) {
      var children = document.body.children;
      for (var k = 0; k < children.length; k++) {
        var element = children[k];
        if (element === root || (element.contains && element.contains(root))) {
          continue;
        }
        if (inert) {
          element.setAttribute("aria-hidden", "true");
          element.setAttribute("inert", "");
        } else {
          element.removeAttribute("aria-hidden");
          element.removeAttribute("inert");
        }
      }
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
