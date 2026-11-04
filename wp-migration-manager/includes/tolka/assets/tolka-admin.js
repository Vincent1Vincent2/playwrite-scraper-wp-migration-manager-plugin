/**
 * Migrate to Tolka — wizard behavior.
 *
 * Uses the localized wmmTolka object and writes measure, preview, and run
 * results into the wizard containers. Every request posts action, nonce,
 * _ajax_nonce, and _wpnonce. Measure also posts url.
 *
 * The speed panel keeps its markup. Slots are filled in place when present:
 * [data-field="summary"], [data-field="current_ms"], [data-field="tolka_ms"],
 * [data-field="faster_label"], [data-field="basis"],
 * [data-field="provenance_note"], and [data-field="error"].
 * The provenance note is shown when provenance is placeholder.
 * Without those slots, the measurement is written into #wmm-tolka-speed.
 *
 * A button's data-wmm-tolka-busy attribute is its busy label. The icon stays.
 *
 * @package MigrationManager
 */

(function () {
  "use strict";

  var PLACEHOLDER_NOTE =
    "Placeholder coefficients, not lab measurements. The page timing is measured live. Replace this file with Tolka lab data before using the percent in a pitch.";
  var BASIS_FALLBACK =
    "Measured page load plus coefficients from benchmarks.json";
  var SPEED_SCOPE =
    "The speed percent is an estimate for the single page you load. It is not a result for every page on the site.";
  var SAMPLE_LIMIT = 20;

  var SECTIONS = [
    {
      key: "languages",
      title: "Languages",
      singular: "language",
      plural: "languages",
      empty: "No languages were found.",
    },
    {
      key: "posts",
      title: "Posts",
      singular: "post",
      plural: "posts",
      empty: "No posts were found.",
    },
    {
      key: "terms",
      title: "Terms",
      singular: "term",
      plural: "terms",
      empty: "No terms were found.",
    },
    {
      key: "strings",
      title: "Strings",
      singular: "string",
      plural: "strings",
      empty: "No strings were found.",
    },
    {
      key: "menus",
      title: "Menus",
      singular: "menu",
      plural: "menus",
      empty: "No menus were found.",
    },
    {
      key: "media",
      title: "Media",
      singular: "media item",
      plural: "media items",
      empty: "No media items were found.",
    },
  ];

  var cfg = window.wmmTolka || null;
  var requestInFlight = false;
  var SPEED_ANCHOR_IDS = [
    "wmm-tolka-speed-panel",
    "wmm-tolka-speed-summary",
    "wmm-tolka-current-load",
    "wmm-tolka-tolka-load",
    "wmm-tolka-faster",
    "wmm-tolka-basis",
    "wmm-tolka-provenance-note",
    "wmm-tolka-speed-error",
    "wmm-tolka-speed-savings",
    "wmm-tolka-speed-scope",
    "wmm-tolka-speed-heading",
  ];

  ready(function () {
    var wizard = document.getElementById("wmm-tolka-wizard");
    if (!wizard || wizard.getAttribute("data-wmm-tolka-bound") === "1") {
      return;
    }
    wizard.setAttribute("data-wmm-tolka-bound", "1");

    var els = {
      url: document.getElementById("wmm-tolka-url"),
      measure: document.getElementById("wmm-tolka-measure"),
      preview: document.getElementById("wmm-tolka-preview"),
      run: document.getElementById("wmm-tolka-run"),
      speed: document.getElementById("wmm-tolka-speed"),
      previewResult: document.getElementById("wmm-tolka-preview-result"),
      report: document.getElementById("wmm-tolka-report"),
    };

    if (!cfg || !cfg.ajaxUrl) {
      showError(
        els.speed,
        "The migration screen could not start. Reload the page. If this continues, the Tolka settings were not loaded."
      );
      showError(
        els.previewResult,
        "The migration screen could not start. Reload the page. If this continues, the Tolka settings were not loaded."
      );
      showError(
        els.report,
        "The migration screen could not start. Reload the page. If this continues, the Tolka settings were not loaded."
      );
      return;
    }

    ensureLive(els.speed);
    ensureLive(els.previewResult);
    ensureLive(els.report);
    paintInitialEmpty(els.speed, speedEmptyCopy());
    paintInitialEmpty(els.previewResult, previewEmptyCopy());
    paintInitialEmpty(els.report, reportEmptyCopy());

    bindClick(els.measure, onMeasure);
    bindClick(els.preview, onPreview);
    bindClick(els.run, onRun);

    if (els.url) {
      els.url.addEventListener("keydown", function (event) {
        if (event.key === "Enter") {
          event.preventDefault();
          onMeasure(event);
        }
      });
    }

    function onMeasure(event) {
      if (event && typeof event.preventDefault === "function") {
        event.preventDefault();
      }
      if (isBusy(els.measure)) {
        return;
      }

      var url = els.url ? String(els.url.value || "").trim() : "";
      if (!url) {
        rejectMeasure("Enter a public site URL, then measure again.");
        return;
      }
      var invalid = validateUrl(url);
      if (invalid) {
        rejectMeasure(invalid);
        return;
      }
      if (els.url) {
        els.url.removeAttribute("aria-invalid");
      }
      if (!cfg.actionMeasure) {
        showError(
          els.speed,
          "The speed check is not available. Reload the page and try again."
        );
        return;
      }

      withBusy(
        els.measure,
        "Measuring the public site...",
        els.speed,
        "Measuring the public page. This can take a few seconds.",
        function () {
          if (!String(url || "").trim()) {
            rejectMeasure("Enter a public site URL, then measure again.");
            return;
          }
          return post(
            cfg.actionMeasure,
            { url: url },
            40000,
            "The speed check took too long, so try the public URL again.",
            "The speed check could not be completed, so try again."
          ).then(function (result) {
            if (!result.ok) {
              if (presentBadResponse(els.speed, result, "The speed check returned a response that could not be read, so try again.")) {
                return;
              }
              showError(els.speed, result.error);
              return;
            }
            renderSpeed(els.speed, result.data, url);
          });
        }
      );
    }

    function presentBadResponse(container, result, fallback) {
      if (!result || (!result.transport && !result.malformed)) {
        return false;
      }
      showTransportFailure(container, result.error || fallback);
      dropBusyClass();
      return true;
    }

    function rejectMeasure(message) {
      if (els.url) {
        els.url.setAttribute("aria-invalid", "true");
      }
      showMeasureRejection(els.speed, message);
    }

    function onPreview(event) {
      if (event && typeof event.preventDefault === "function") {
        event.preventDefault();
      }
      if (isBusy(els.preview)) {
        return;
      }
      if (!cfg.actionPreview) {
        showError(
          els.previewResult,
          "The preview is not available. Reload the page and try again."
        );
        return;
      }

      withBusy(
        els.preview,
        "Loading the preview...",
        els.previewResult,
        "Building the preview of languages, posts, terms, strings, menus, and media.",
        function () {
          return post(
            cfg.actionPreview,
            {},
            120000,
            "The preview took too long, so nothing was written.",
            "The preview could not be completed, so nothing was written."
          ).then(function (result) {
            if (!result.ok) {
              if (presentBadResponse(els.previewResult, result, "The preview returned a response that could not be read, so try again.")) {
                return;
              }
              showError(els.previewResult, result.error);
              return;
            }
            renderPreview(els.previewResult, result.data);
          });
        }
      );
    }

    function onRun(event) {
      if (event && typeof event.preventDefault === "function") {
        event.preventDefault();
      }
      if (isBusy(els.run)) {
        return;
      }
      if (!cfg.actionRun) {
        showError(
          els.report,
          "The migration is not available. Reload the page and try again."
        );
        return;
      }

      withBusy(
        els.run,
        "Running the migration...",
        els.report,
        "Running the migration. Polylang data stays in place.",
        function () {
          return post(
            cfg.actionRun,
            {},
            300000,
            "The migration did not answer in time, so try again before reloading.",
            "The migration could not be completed, and Polylang data was not removed."
          ).then(function (result) {
            if (!result.ok) {
              if (presentBadResponse(els.report, result, "The migration returned a response that could not be read, so try again.")) {
                return;
              }
              showError(els.report, failureWithKeptData(result.error));
              return;
            }
            renderReport(els.report, result.data);
          });
        }
      );
    }
  });

  function ready(fn) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", fn);
    } else {
      fn();
    }
  }

  function bindClick(button, handler) {
    if (!button) {
      return;
    }
    button.addEventListener("click", handler);
  }

  function isBusy(button) {
    if (requestInFlight) {
      return true;
    }
    if (hasBusyClass(button)) {
      return true;
    }
    if (anotherButtonIsBusy(button)) {
      return true;
    }
    return !button || button.getAttribute("data-wmm-tolka-pending") === "1";
  }

  function hasBusyClass(node) {
    if (!node) {
      return false;
    }
    if (node.classList) {
      return node.classList.contains("wmm-tolka-is-busy");
    }
    return (" " + (node.className || "") + " ").indexOf(" wmm-tolka-is-busy ") !== -1;
  }

  function anotherButtonIsBusy(button) {
    if (typeof document === "undefined" || !document.querySelectorAll) {
      return false;
    }
    var nodes = document.querySelectorAll(".wmm-tolka-is-busy");
    for (var i = 0; i < nodes.length; i += 1) {
      if (nodes[i] !== button) {
        return true;
      }
    }
    return false;
  }

  function withBusy(button, busyLabel, container, statusText, work) {
    if (!button || requestInFlight || hasBusyClass(button) || anotherButtonIsBusy(button)) {
      return;
    }
    requestInFlight = true;
    holdBusyClass(button);
    try {
      setBusy(button, true, busyLabel);
      if (container) {
        container.setAttribute("aria-busy", "true");
        showStatus(container, statusText);
      }
    } catch (ignore) {
      /* The busy class stays until release, even if the panel cannot update. */
    }

    Promise.resolve()
      .then(work)
      .then(
        function () {},
        function (err) {
          try {
            showTransportFailure(
              container,
              "The request could not be completed, so try again."
            );
          } catch (ignore) {
            /* The buttons still have to accept another click. */
          }
        }
      )
      .then(release, release);

    function release() {
      try {
        requestInFlight = false;
        dropBusyClass(button);
        enableActions();
        if (container) {
          container.removeAttribute("aria-busy");
        }
      } catch (ignore) {
        requestInFlight = false;
        dropBusyClass(button);
        enableActions();
      }
    }
  }

  function holdBusyClass(button) {
    if (typeof document !== "undefined" && document.querySelectorAll) {
      Array.prototype.forEach.call(document.querySelectorAll(".wmm-tolka-is-busy"), function (node) {
        if (node !== button) {
          removeBusyClass(node);
        }
      });
    }
    if (!button) {
      return;
    }
    if (button.classList) {
      button.classList.add("wmm-tolka-is-busy");
    } else {
      button.className = ((button.className || "") + " wmm-tolka-is-busy").replace(/\s+/g, " ").trim();
    }
  }

  function dropBusyClass(button) {
    removeBusyClass(button);
    actionButtons().forEach(removeBusyClass);
    if (typeof document !== "undefined" && document.querySelectorAll) {
      Array.prototype.forEach.call(document.querySelectorAll(".wmm-tolka-is-busy"), removeBusyClass);
    }
  }

  function removeBusyClass(node) {
    if (!node) {
      return;
    }
    if (node.classList) {
      node.classList.remove("wmm-tolka-is-busy");
      return;
    }
    var name = " " + (node.className || "") + " ";
    node.className = name.replace(/ wmm-tolka-is-busy /g, " ").replace(/\s+/g, " ").trim();
  }

  function enableActions() {
    requestInFlight = false;
    actionButtons().forEach(function (button) {
      if (!button) {
        return;
      }
      button.disabled = false;
      button.removeAttribute("disabled");
      button.removeAttribute("data-wmm-tolka-pending");
      button.removeAttribute("data-wmm-tolka-peer-disabled");
      button.classList.remove("wmm-tolka-is-busy");
      button.setAttribute("aria-busy", "false");
      restoreButtonLabel(button);
    });
  }

  function setBusy(button, busy, busyLabel) {
    if (!button) {
      return;
    }
    if (busy) {
      rememberButtonLabel(button);
      button.setAttribute("data-wmm-tolka-pending", "1");
      button.disabled = true;
      button.classList.add("wmm-tolka-is-busy");
      button.setAttribute("aria-busy", "true");
      writeBusyLabel(button, busyLabelFor(button, busyLabel));
      setPeersDisabled(button, true);
      return;
    }

    button.removeAttribute("data-wmm-tolka-pending");
    button.disabled = false;
    button.classList.remove("wmm-tolka-is-busy");
    button.setAttribute("aria-busy", "false");
    restoreButtonLabel(button);
    setPeersDisabled(button, false);
  }

  function busyLabelFor(button, fallback) {
    var fromMarkup = button.getAttribute("data-wmm-tolka-busy");
    if (fromMarkup && fromMarkup !== "1") {
      return fromMarkup;
    }
    return fallback;
  }

  function rememberButtonLabel(button) {
    if (button.hasAttribute("data-wmm-tolka-label")) {
      return;
    }
    var textNode = buttonTextNode(button);
    button.setAttribute("data-wmm-tolka-label-mode", textNode ? "node" : "content");
    button.setAttribute(
      "data-wmm-tolka-label",
      textNode ? textNode.nodeValue : String(button.textContent || "")
    );
  }

  function writeBusyLabel(button, label) {
    var textNode = buttonTextNode(button);
    if (!textNode) {
      button.textContent = label;
      return;
    }
    textNode.nodeValue = textNode.nodeValue.replace(/\S(?:.*\S)?/, label);
  }

  function restoreButtonLabel(button) {
    if (!button.hasAttribute("data-wmm-tolka-label")) {
      return;
    }
    var saved = button.getAttribute("data-wmm-tolka-label");
    if (button.getAttribute("data-wmm-tolka-label-mode") === "node") {
      var textNode = buttonTextNode(button);
      if (textNode) {
        textNode.nodeValue = saved;
        return;
      }
    }
    button.textContent = saved;
  }

  function buttonTextNode(button) {
    var nodes = button.childNodes;
    var found = null;
    for (var i = 0; i < nodes.length; i += 1) {
      if (nodes[i].nodeType === 3 && /\S/.test(nodes[i].nodeValue || "")) {
        found = nodes[i];
      }
    }
    return found;
  }

  function actionButtons() {
    return [
      document.getElementById("wmm-tolka-measure"),
      document.getElementById("wmm-tolka-preview"),
      document.getElementById("wmm-tolka-run"),
    ];
  }

  function setPeersDisabled(active, disabled) {
    actionButtons().forEach(function (button) {
      if (!button || button === active) {
        return;
      }
      if (disabled) {
        if (!button.hasAttribute("data-wmm-tolka-peer-disabled")) {
          button.setAttribute(
            "data-wmm-tolka-peer-disabled",
            button.disabled ? "1" : "0"
          );
        }
        button.disabled = true;
        return;
      }
      if (button.getAttribute("data-wmm-tolka-peer-disabled") === "0") {
        button.disabled = false;
      }
      button.removeAttribute("data-wmm-tolka-peer-disabled");
    });
  }

  function post(action, fields, timeoutMs, timeoutMessage, networkMessage) {
    var body = encodeForm(formFields(action, fields));
    var sent;
    try {
      sent = send(body, timeoutMs, timeoutMessage, networkMessage);
    } catch (err) {
      return Promise.resolve(transportResult(err, networkMessage));
    }
    return sent.then(
      function (response) {
        return parseAjax(response.status, response.text);
      },
      function (err) {
        return transportResult(err, networkMessage);
      }
    );
  }

  function transportResult(err, fallback) {
    var sentence = err && err.wmmTolkaSentence ? err.wmmTolkaSentence : fallback;
    return {
      ok: false,
      transport: true,
      error: finishSentence(
        sentence || "The request could not be completed, so try again."
      ),
    };
  }

  function formFields(action, fields) {
    var nonce = cfg && cfg.nonce != null ? String(cfg.nonce) : "";
    var payload = {
      action: action == null ? "" : String(action),
      nonce: nonce,
      _ajax_nonce: nonce,
      _wpnonce: nonce,
    };
    var extra = fields || {};
    Object.keys(extra).forEach(function (key) {
      payload[key] = extra[key] == null ? "" : String(extra[key]);
    });
    return payload;
  }

  function encodeForm(fields) {
    var keys = Object.keys(fields);
    if (typeof URLSearchParams !== "undefined") {
      var params = new URLSearchParams();
      keys.forEach(function (key) {
        params.append(key, fields[key]);
      });
      return params.toString();
    }
    return keys
      .map(function (key) {
        return (
          encodeURIComponent(key) + "=" + encodeURIComponent(fields[key])
        );
      })
      .join("&");
  }

  function send(body, timeoutMs, timeoutMessage, networkMessage) {
    if (typeof window.fetch === "function") {
      return sendFetch(body, timeoutMs, timeoutMessage, networkMessage);
    }
    return sendXhr(body, timeoutMs, timeoutMessage, networkMessage);
  }

  function timeoutError(timeoutMessage) {
    var sentence = finishSentence(
      timeoutMessage || "The request took too long, so try again."
    );
    var err = new Error(sentence);
    err.wmmTolkaSentence = sentence;
    err.wmmTolkaTransport = true;
    return err;
  }

  function networkError(networkMessage) {
    var sentence = finishSentence(
      networkMessage || "The request could not be completed, so try again."
    );
    var err = new Error(sentence);
    err.wmmTolkaSentence = sentence;
    err.wmmTolkaTransport = true;
    return err;
  }

  function sendFetch(body, timeoutMs, timeoutMessage, networkMessage) {
    var controller =
      typeof AbortController === "function" ? new AbortController() : null;
    var timer = null;
    if (controller && timeoutMs) {
      timer = window.setTimeout(function () {
        controller.abort();
      }, timeoutMs);
    }

    return window
      .fetch(cfg.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: body,
        signal: controller ? controller.signal : undefined,
      })
      .then(
        function (response) {
          return response.text().then(
            function (text) {
              return { status: response.status, text: text };
            },
            function () {
              throw networkError(networkMessage);
            }
          );
        },
        function (err) {
          if (err && err.name === "AbortError") {
            throw timeoutError(timeoutMessage);
          }
          throw networkError(networkMessage);
        }
      )
      .then(
        function (result) {
          if (timer) {
            window.clearTimeout(timer);
          }
          return result;
        },
        function (err) {
          if (timer) {
            window.clearTimeout(timer);
          }
          throw err;
        }
      );
  }

  function sendXhr(body, timeoutMs, timeoutMessage, networkMessage) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      xhr.open("POST", cfg.ajaxUrl, true);
      xhr.withCredentials = true;
      xhr.setRequestHeader(
        "Content-Type",
        "application/x-www-form-urlencoded; charset=UTF-8"
      );
      if (timeoutMs) {
        xhr.timeout = timeoutMs;
      }
      xhr.onload = function () {
        resolve({ status: xhr.status, text: xhr.responseText });
      };
      xhr.onerror = function () {
        reject(networkError(networkMessage));
      };
      xhr.ontimeout = function () {
        reject(timeoutError(timeoutMessage));
      };
      xhr.send(body);
    });
  }

  function parseAjax(status, text) {
    var trimmed = String(text == null ? "" : text)
      .replace(/^\uFEFF/, "")
      .trim();

    if (trimmed === "-1") {
      return {
        ok: false,
        malformed: true,
        error: "The security check failed, so reload the page and try again.",
      };
    }
    if (trimmed === "0") {
      return {
        ok: false,
        malformed: true,
        error: "The migration request was not recognized, so reload the page and try again.",
      };
    }

    var json;
    try {
      json = trimmed === "" ? null : JSON.parse(trimmed);
    } catch (e) {
      return { ok: false, malformed: true };
    }
    if (!isJsonObject(json)) {
      return { ok: false, malformed: true };
    }

    if (json.success !== true) {
      var data = json && Object.prototype.hasOwnProperty.call(json, "data")
        ? json.data
        : json;
      var messages = collectMessages(data);
      if (!messages.length && status >= 400) {
        messages.push("The request failed (HTTP " + status + ").");
      }
      if (!messages.length) {
        messages.push("The request failed.");
      }
      return { ok: false, error: messages };
    }

    return { ok: true, data: json.data == null ? {} : json.data };
  }

  function isJsonObject(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
  }

  function collectMessages(data) {
    var messages = [];

    function push(value) {
      var text = value == null ? "" : String(value).trim();
      if (!text || messages.indexOf(text) !== -1) {
        return;
      }
      messages.push(text);
    }

    function walk(value) {
      if (value == null) {
        return;
      }
      if (typeof value === "string" || typeof value === "number") {
        var text = String(value).trim();
        if (text && text !== "0" && text !== "-1") {
          push(text);
        }
        return;
      }
      if (Array.isArray(value)) {
        value.forEach(walk);
        return;
      }
      if (typeof value !== "object") {
        return;
      }
      if (typeof value.message === "string") {
        push(value.message);
      }
      if (typeof value.error === "string") {
        push(value.error);
      }
      if (Array.isArray(value.errors)) {
        value.errors.forEach(function (item) {
          if (typeof item === "string" || typeof item === "number") {
            push(item);
          } else if (item && typeof item.message === "string") {
            push(item.message);
          }
        });
      }
      var names = missingNames(value.missing);
      if (names.length) {
        push(
          "This step could not run because part of the migration is missing: " +
            names.join(", ") +
            "."
        );
      }
      if (value.estimate && typeof value.estimate.error === "string") {
        push(value.estimate.error);
      }
    }

    walk(data);
    return messages;
  }

  function missingNames(missing) {
    if (missing == null || missing === "") {
      return [];
    }
    var list = Array.isArray(missing) ? missing : [missing];
    var names = [];
    list.forEach(function (item) {
      var text = item == null ? "" : String(item).trim();
      if (text && names.indexOf(text) === -1) {
        names.push(text);
      }
    });
    return names;
  }

  function validateUrl(value) {
    var url = String(value || "").trim();
    if (!url) {
      return "Enter a public site URL, then measure again.";
    }
    if (url.length > 2000) {
      return "That address is longer than 2000 characters, so it was not measured. Nothing was changed.";
    }
    if (/\s/.test(url)) {
      return "Enter the public site URL without spaces.";
    }
    var scheme = urlScheme(url);
    if (scheme === "javascript" || scheme === "data") {
      return "javascript: and data: addresses are not measured. Nothing was changed.";
    }
    if (scheme !== "http" && scheme !== "https") {
      return "Enter a public http or https URL. The speed check does not measure other kinds of addresses.";
    }
    if (urlHasUserinfo(url)) {
      return "That address includes a username or password, so it was not measured. Nothing was changed.";
    }
    if (urlHasEmptyHost(url)) {
      return "That address has no host, so it was not measured.";
    }
    if (urlIsLocal(url)) {
      return "That address is on a local or private network, so it was not measured.";
    }
    return "";
  }

  function urlScheme(url) {
    var match = String(url || "").match(/^([a-z][a-z0-9+.-]*):/i);
    return match ? match[1].toLowerCase() : "";
  }

  function urlHasUserinfo(url) {
    var match = String(url || "").match(/^[a-z][a-z0-9+.-]*:\/\/([^/?#]*)/i);
    if (match && match[1].indexOf("@") !== -1) {
      return true;
    }
    try {
      var parsed = new URL(url);
      return parsed.username !== "" || parsed.password !== "";
    } catch (e) {
      return false;
    }
  }

  function urlHasEmptyHost(url) {
    var match = String(url || "").match(/^https?:\/\/([^/?#]*)/i);
    if (!match) {
      return false;
    }
    var authority = match[1];
    var at = authority.lastIndexOf("@");
    if (at !== -1) {
      authority = authority.slice(at + 1);
    }
    if (authority.charAt(0) === "[" && authority.charAt(authority.length - 1) === "]") {
      authority = authority.slice(1, -1);
    }
    authority = authority.replace(/\.+$/g, "").replace(/^\s+|\s+$/g, "");
    return authority === "";
  }

  function measureHost(url) {
    try {
      var parsed = new URL(url);
      var host = String(parsed.hostname || "").toLowerCase();
      if (host.charAt(0) === "[" && host.charAt(host.length - 1) === "]") {
        host = host.slice(1, -1);
      }
      return host.replace(/\.+$/g, "");
    } catch (e) {
      return "";
    }
  }

  function urlIsLocal(url) {
    var host = measureHost(url);
    if (!host) {
      return false;
    }
    if (host === "localhost" || host.slice(-10) === ".localhost") {
      return true;
    }
    var ipv4 = ipv4Long(host);
    if (ipv4 != null && ipv4IsLocal(ipv4)) {
      return true;
    }
    return ipv6IsLocal(host);
  }

  function ipv4Long(host) {
    var quad = host.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/);
    if (quad) {
      return ipv4Pack([Number(quad[1]), Number(quad[2]), Number(quad[3]), Number(quad[4])], [255, 255, 255, 255]);
    }
    var triple = host.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,10})$/);
    if (triple) {
      return ipv4Pack([Number(triple[1]), Number(triple[2]), Number(triple[3])], [255, 255, 65535]);
    }
    var pair = host.match(/^(\d{1,3})\.(\d{1,10})$/);
    if (pair) {
      return ipv4Pack([Number(pair[1]), Number(pair[2])], [255, 16777215]);
    }
    if (/^\d+$/.test(host)) {
      var number = Number(host);
      if (String(number) === host && number >= 0 && number <= 4294967295) {
        return number;
      }
    }
    return null;
  }

  function ipv4Pack(parts, limits) {
    var weights = parts.length === 4
      ? [16777216, 65536, 256, 1]
      : parts.length === 3
        ? [16777216, 65536, 1]
        : [16777216, 1];
    var total = 0;
    for (var i = 0; i < parts.length; i += 1) {
      if (parts[i] < 0 || parts[i] > limits[i]) {
        return null;
      }
      total += parts[i] * weights[i];
    }
    return total;
  }

  function ipv4IsLocal(number) {
    var first = Math.floor(number / 16777216);
    var second = Math.floor((number % 16777216) / 65536);
    if (first === 0 || first === 10 || first === 127) {
      return true;
    }
    if (first === 192 && second === 168) {
      return true;
    }
    if (first === 172 && second >= 16 && second <= 31) {
      return true;
    }
    return first === 169 && second === 254;
  }

  function ipv6IsLocal(host) {
    var bytes = ipv6Bytes(host);
    if (!bytes) {
      return false;
    }
    var loopback = true;
    var i;
    for (i = 0; i < 15; i += 1) {
      if (bytes[i] !== 0) {
        loopback = false;
        break;
      }
    }
    if (loopback && bytes[15] === 1) {
      return true;
    }
    var mapped = true;
    for (i = 0; i < 10; i += 1) {
      if (bytes[i] !== 0) {
        mapped = false;
        break;
      }
    }
    if (mapped && bytes[10] === 255 && bytes[11] === 255) {
      return ipv4IsLocal(
        bytes[12] * 16777216 + bytes[13] * 65536 + bytes[14] * 256 + bytes[15]
      );
    }
    if ((bytes[0] & 254) === 252) {
      return true;
    }
    return bytes[0] === 254 && (bytes[1] & 192) === 128;
  }

  function ipv6Bytes(host) {
    var value = String(host || "").split("%")[0];
    if (value.indexOf(":") === -1) {
      return null;
    }
    var halves = value.split("::");
    if (halves.length > 2) {
      return null;
    }
    var head = ipv6Side(halves[0]);
    if (!head) {
      return null;
    }
    if (halves.length === 1) {
      return head.length === 16 ? head : null;
    }
    var tail = ipv6Side(halves[1]);
    if (!tail || head.length + tail.length > 16) {
      return null;
    }
    var bytes = head.slice();
    while (bytes.length + tail.length < 16) {
      bytes.push(0);
    }
    return bytes.concat(tail);
  }

  function ipv6Side(side) {
    if (side === "") {
      return [];
    }
    var parts = side.split(":");
    var bytes = [];
    for (var i = 0; i < parts.length; i += 1) {
      if (parts[i].indexOf(".") !== -1) {
        var long = ipv4Long(parts[i]);
        if (long == null || i !== parts.length - 1) {
          return null;
        }
        bytes.push(
          Math.floor(long / 16777216) & 255,
          Math.floor(long / 65536) & 255,
          Math.floor(long / 256) & 255,
          long & 255
        );
        continue;
      }
      if (!/^[0-9a-f]{1,4}$/i.test(parts[i])) {
        return null;
      }
      var piece = parseInt(parts[i], 16);
      bytes.push((piece >> 8) & 255, piece & 255);
    }
    return bytes;
  }

  function renderSpeed(container, data, url) {
    if (!container) {
      return;
    }
    var payload = data && typeof data === "object" && !Array.isArray(data) ? data : {};
    var estimate =
      payload.estimate && typeof payload.estimate === "object"
        ? payload.estimate
        : null;
    if (
      !estimate &&
      (payload.current_ms != null ||
        payload.provenance != null ||
        payload.faster_percent != null ||
        payload.ok != null)
    ) {
      estimate = payload;
    }
    if (!estimate) {
      showError(
        container,
        "The speed estimate was not returned. No percent is shown."
      );
      return;
    }
    if (!isAffirmative(estimate.ok)) {
      var message = speedFailureMessage(estimate.error);
      if (!paintSpeedError(container, message, estimate)) {
        showError(container, message);
      }
      return;
    }

    try {
      paintSpeed(container, estimate, payload.metrics || {}, url);
    } catch (err) {
      showError(
        container,
        "The speed result could not be displayed. Reload the page and try again."
      );
    }
  }

  function paintSpeed(container, estimate, metrics, url) {
    var note = provenanceText(estimate);
    var faster = fasterText(estimate);
    var basis =
      estimate.basis && String(estimate.basis).trim()
        ? String(estimate.basis).trim()
        : BASIS_FALLBACK;
    var currentText = formatMs(estimate.current_ms);
    var tolkaText = formatMs(estimate.tolka_ms);

    removeGenerated(container);

    var wroteIds = writeSpeedIds(container, {
      current: currentText,
      currentMs: estimate.current_ms,
      tolka: tolkaText,
      tolkaMs: estimate.tolka_ms,
      faster: faster,
      percent: estimate.faster_percent,
      basis: basis,
      note: note,
      provenance: estimate.provenance || "",
    });
    if (
      fillSpeedPanel(
        container,
        estimate,
        metrics,
        currentText,
        tolkaText,
        faster,
        basis,
        note
      ) ||
      wroteIds
    ) {
      writeSpeedIds(container, {
        current: currentText,
        currentMs: estimate.current_ms,
        tolka: tolkaText,
        tolkaMs: estimate.tolka_ms,
        faster: faster,
        percent: estimate.faster_percent,
        basis: basis,
        note: note,
        provenance: estimate.provenance || "",
      });
      return;
    }

    clear(container);
    var result = markGenerated(el("div", "wmm-tolka-speed-result"));
    result.setAttribute("data-current-ms", String(rounded(estimate.current_ms)));
    result.setAttribute("data-tolka-ms", String(rounded(estimate.tolka_ms)));
    if (estimate.faster_percent != null && estimate.faster_percent !== "") {
      result.setAttribute("data-faster-percent", String(estimate.faster_percent));
    }
    result.setAttribute("data-provenance", estimate.provenance || "");

    if (url) {
      result.appendChild(
        el(
          "p",
          "wmm-tolka-speed-url",
          "The public page measured was " + url + "."
        )
      );
    }
    result.appendChild(
      el(
        "p",
        "wmm-tolka-speed-current",
        "The current load is " + currentText + "."
      )
    );
    result.appendChild(
      el(
        "p",
        "wmm-tolka-speed-tolka",
        "The estimated Tolka load is " + tolkaText + "."
      )
    );
    result.appendChild(el("p", "wmm-tolka-speed-faster", fasterSentence(faster)));
    result.appendChild(el("p", "wmm-tolka-speed-basis", finishSentence(basis)));
    if (metrics && metrics.truncated === true) {
      result.appendChild(
        el(
          "p",
          "wmm-tolka-speed-truncated",
          "Only the first part of the page HTML was measured."
        )
      );
    }
    if (note) {
      var provenance = el("p", "wmm-tolka-provenance", note);
      provenance.setAttribute("data-provenance", estimate.provenance || "");
      if (estimate.provenance === "placeholder") {
        provenance.classList.add("wmm-tolka-provenance-placeholder");
      }
      result.appendChild(provenance);
    }
    container.appendChild(result);
    markResult(container);
    focusResult(container);
  }

  function provenanceText(estimate) {
    var note = estimate.provenance_note
      ? String(estimate.provenance_note).trim()
      : "";
    if (estimate.provenance === "placeholder") {
      return note || PLACEHOLDER_NOTE;
    }
    return note;
  }

  function fasterText(estimate) {
    if (estimate.faster_label && String(estimate.faster_label).trim()) {
      return String(estimate.faster_label).trim();
    }
    if (estimate.faster_percent == null || estimate.faster_percent === "") {
      return "";
    }
    var number = Number(estimate.faster_percent);
    if (!isFinite(number)) {
      return "";
    }
    return number.toFixed(1) + "% faster";
  }

  function fasterSentence(faster) {
    if (!faster) {
      return "A percent faster was not included in this estimate.";
    }
    if (/^tolka is estimated/i.test(faster)) {
      return finishSentence(faster);
    }
    return finishSentence("Tolka is estimated to be " + faster);
  }

  function speedFailureMessage(detail) {
    var text = detail ? String(detail).trim() : "";
    if (!text) {
      return "The speed estimate could not be completed. Check the public site URL and measure again. No percent is shown.";
    }
    if (/the speed estimate could not be completed/i.test(text)) {
      return finishSentence(text);
    }
    return "The speed estimate could not be completed. " + finishSentence(text);
  }

  function speedSummary(faster, currentText, tolkaText, percent) {
    if (!faster || !currentText || !tolkaText) {
      return "The speed estimate completed, but one or more figures were missing. No percent is shown.";
    }
    var number = Number(percent);
    if (isFinite(number) && number < 0) {
      return (
        "Tolka is not estimated to be faster on this page. The current load is " +
        currentText +
        ", and the estimated Tolka load is " +
        tolkaText +
        "."
      );
    }
    if (isFinite(number) && number < 0.05) {
      return (
        "Tolka is estimated to take about the same time on this page. The current load is " +
        currentText +
        ", and the estimated Tolka load is " +
        tolkaText +
        "."
      );
    }
    return (
      "Tolka is estimated to be " +
      faster +
      " on this page. The current load is " +
      currentText +
      ", and the estimated Tolka load is " +
      tolkaText +
      "."
    );
  }

  function applySavings(slots, estimate) {
    if (!slots.savings) {
      return;
    }
    var saved = 0;
    var percent = NaN;
    if (estimate) {
      saved = rounded(estimate.current_ms) - rounded(estimate.tolka_ms);
      percent = Number(estimate.faster_percent);
    }
    if (estimate && isFinite(percent) && percent > 0 && saved > 0) {
      setText(
        slots.savings,
        "The estimated Tolka load is " +
          formatMs(saved) +
          " shorter than the measured load."
      );
      slots.savings.setAttribute("data-saved-ms", String(saved));
      reveal(slots.savings);
      return;
    }
    setText(slots.savings, "");
    slots.savings.removeAttribute("data-saved-ms");
    conceal(slots.savings);
  }

  function describeFaster(slots) {
    if (!slots.faster) {
      return;
    }
    var ids = [];
    if (slots.scope && slots.scope.id && !slots.scope.hasAttribute("hidden")) {
      ids.push(slots.scope.id);
    }
    var note = slots.provenance ? noteWrapper(slots.provenance) : null;
    var noteId = slots.provenance && slots.provenance.id ? slots.provenance.id : "";
    if (note && noteId && !note.hasAttribute("hidden")) {
      ids.push(noteId);
    }
    if (ids.length) {
      slots.faster.setAttribute("aria-describedby", ids.join(" "));
      return;
    }
    slots.faster.removeAttribute("aria-describedby");
  }

  function applyScope(slots, show) {
    if (!slots.scope) {
      return;
    }
    if (!show) {
      conceal(slots.scope);
      return;
    }
    setText(slots.scope, SPEED_SCOPE);
    reveal(slots.scope);
  }

  function writeSpeedIds(container, values) {
    var any = false;
    function put(id, text, after) {
      var node = speedNode(container, id);
      if (!node) {
        return;
      }
      any = true;
      setText(node, text == null ? "" : text);
      if (after) {
        after(node);
      }
    }
    put("wmm-tolka-current-load", values.current, function (node) {
      setMs(node, values.currentMs);
      reveal(node);
    });
    put("wmm-tolka-tolka-load", values.tolka, function (node) {
      setMs(node, values.tolkaMs);
      reveal(node);
    });
    put("wmm-tolka-faster", values.faster || "", function (node) {
      if (values.percent != null && values.percent !== "" && isFinite(Number(values.percent))) {
        node.setAttribute("data-faster-percent", Number(values.percent).toFixed(1));
      }
      reveal(node);
    });
    put("wmm-tolka-basis", values.basis ? finishSentence(values.basis) : "", function (node) {
      if (values.basis) {
        reveal(node);
      }
    });
    put("wmm-tolka-provenance-note", values.note || "", function (node) {
      if (values.provenance) {
        node.setAttribute("data-provenance", values.provenance);
      }
      if (values.note) {
        reveal(node);
        var parent = node.parentNode;
        if (
          parent &&
          parent.classList &&
          (parent.classList.contains("wmm-tolka-provenance") ||
            parent.classList.contains("wmm-tolka-provenance-note"))
        ) {
          reveal(parent);
        }
      }
    });
    return any;
  }

  function speedNode(container, id) {
    if (!container || typeof document === "undefined" || !document.getElementById) {
      return null;
    }
    var node = document.getElementById(id);
    if (!node) {
      return null;
    }
    if (node === container || (container.contains && container.contains(node))) {
      return node;
    }
    return null;
  }

  function fillSpeedPanel(container, estimate, metrics, currentText, tolkaText, faster, basis, note) {
    var slots = collectSpeedSlots(container);
    if (!slots.current || !slots.tolka || !slots.faster || !slots.basis) {
      return false;
    }

    setText(slots.current, currentText);
    setMs(slots.current, estimate.current_ms);
    setText(slots.tolka, tolkaText);
    setMs(slots.tolka, estimate.tolka_ms);
    setText(
      slots.faster,
      faster || "A percent faster was not included in this estimate."
    );
    if (
      estimate.faster_percent != null &&
      estimate.faster_percent !== "" &&
      isFinite(Number(estimate.faster_percent))
    ) {
      slots.faster.setAttribute(
        "data-faster-percent",
        Number(estimate.faster_percent).toFixed(1)
      );
    } else {
      slots.faster.removeAttribute("data-faster-percent");
    }
    setText(slots.basis, finishSentence(basis));
    reveal(slots.basis);
    if (slots.basisLabel) {
      reveal(slots.basisLabel);
    }
    if (slots.summary) {
      setText(
        slots.summary,
        speedSummary(faster, currentText, tolkaText, estimate.faster_percent)
      );
      reveal(slots.summary);
      slots.summary.classList.remove("description");
    }
    applySavings(slots, estimate);
    applyScope(slots, true);
    applyProvenance(slots, note, estimate.provenance || "");
    describeFaster(slots);
    hideSpeedError(slots);
    upsertTruncation(container, metrics);
    setPanelState(
      slots.panel,
      "ready",
      estimate.provenance === "placeholder" ? "placeholder" : estimate.provenance || ""
    );
    if (slots.panel) {
      slots.panel.classList.remove("wmm-tolka-is-busy");
    }
    markResult(container);
    focusResult(container);
    return true;
  }

  function paintSpeedError(container, message, estimate) {
    if (!container || container.id !== "wmm-tolka-speed") {
      return false;
    }
    var slots = collectSpeedSlots(container);
    if (!hasSpeedAnchor(container) && !(slots.current && slots.tolka && slots.faster && slots.basis)) {
      return false;
    }

    var messages = normalizeMessages(message);
    removeGenerated(container);
    if (slots.current) {
      setText(slots.current, "Unavailable");
      slots.current.removeAttribute("data-ms");
    }
    if (slots.tolka) {
      setText(slots.tolka, "Unavailable");
      slots.tolka.removeAttribute("data-ms");
    }
    if (slots.faster) {
      setText(slots.faster, "Unavailable");
      slots.faster.removeAttribute("data-faster-percent");
    }
    setText(slots.basis, "");
    conceal(slots.basis);
    if (slots.basisLabel) {
      conceal(slots.basisLabel);
    }
    applySavings(slots, null);
    applyScope(slots, false);
    if (slots.summary) {
      setText(slots.summary, "");
      conceal(slots.summary);
    }
    if (slots.error) {
      setText(slots.error, messages.join(" "));
      var errorBox = errorWrapper(slots.error);
      reveal(errorBox);
      errorBox.setAttribute("role", "alert");
      errorBox.classList.add("notice", "notice-error");
    } else {
      for (var i = messages.length - 1; i >= 0; i -= 1) {
        container.insertBefore(errorParagraph(messages[i]), container.firstChild);
      }
    }

    var provenance = estimate && estimate.provenance ? String(estimate.provenance) : "";
    var note = provenance === "placeholder" && estimate ? provenanceText(estimate) : "";
    applyProvenance(slots, note, provenance);
    describeFaster(slots);
    setPanelState(slots.panel, "error", note ? provenance : "");
    if (slots.panel) {
      slots.panel.classList.remove("wmm-tolka-is-busy");
    }
    container.classList.add("wmm-tolka-has-error");
    container.classList.remove("wmm-tolka-has-result");
    focusResult(container);
    return true;
  }

  function paintSpeedStatus(container, text) {
    var slots = collectSpeedSlots(container);
    if (!slots.current || !slots.tolka || !slots.faster || !slots.basis) {
      return false;
    }
    removeGenerated(container);
    hideSpeedError(slots);
    var status = markGenerated(el("p", "wmm-tolka-status", text));
    status.setAttribute("role", "status");
    if (container.firstChild) {
      container.insertBefore(status, container.firstChild);
    } else {
      container.appendChild(status);
    }
    return true;
  }

  function applyProvenance(slots, note, provenance) {
    if (!slots.provenance) {
      return;
    }
    setText(slots.provenance, note || "");
    var wrapper = noteWrapper(slots.provenance);
    slots.provenance.setAttribute("data-provenance", provenance || "");
    if (note) {
      reveal(wrapper);
      reveal(slots.provenance);
      if (provenance === "placeholder") {
        wrapper.classList.add("wmm-tolka-provenance-placeholder");
        if (wrapper !== slots.provenance) {
          wrapper.classList.add("notice", "notice-warning");
        }
      } else {
        wrapper.classList.remove(
          "wmm-tolka-provenance-placeholder",
          "notice",
          "notice-warning"
        );
      }
      return;
    }
    conceal(wrapper);
    if (wrapper !== slots.provenance) {
      conceal(slots.provenance);
    }
    wrapper.classList.remove(
      "wmm-tolka-provenance-placeholder",
      "notice",
      "notice-warning"
    );
  }

  function hideSpeedError(slots) {
    if (!slots.error) {
      return;
    }
    setText(slots.error, "");
    var wrapper = errorWrapper(slots.error);
    conceal(wrapper);
    wrapper.removeAttribute("role");
    wrapper.classList.remove("notice", "notice-error");
  }

  function noteWrapper(slot) {
    var parent = slot.parentNode;
    if (
      parent &&
      parent.classList &&
      parent.classList.contains("wmm-tolka-provenance-note")
    ) {
      return parent;
    }
    return slot;
  }

  function errorWrapper(slot) {
    var parent = slot.parentNode;
    if (
      parent &&
      parent.classList &&
      parent.classList.contains("wmm-tolka-speed-error")
    ) {
      return parent;
    }
    return slot;
  }

  function setPanelState(panel, state, provenance) {
    if (!panel) {
      return;
    }
    panel.setAttribute("data-state", state);
    panel.setAttribute("data-provenance", provenance || "");
    panel.classList.remove(
      "wmm-tolka-is-ready",
      "wmm-tolka-is-error",
      "wmm-tolka-is-empty",
      "wmm-tolka-is-placeholder",
      "wmm-tolka-is-busy"
    );
    if (state === "ready") {
      panel.classList.add("wmm-tolka-is-ready");
    } else if (state === "error") {
      panel.classList.add("wmm-tolka-is-error");
    } else {
      panel.classList.add("wmm-tolka-is-empty");
    }
    if (provenance === "placeholder") {
      panel.classList.add("wmm-tolka-is-placeholder");
    }
  }

  function upsertTruncation(container, metrics) {
    var existing = container.querySelector(".wmm-tolka-speed-truncated");
    if (!metrics || metrics.truncated !== true) {
      if (existing && existing.getAttribute("data-wmm-tolka-generated") === "1" && existing.parentNode) {
        existing.parentNode.removeChild(existing);
      }
      return;
    }
    if (existing) {
      return;
    }
    container.appendChild(
      markGenerated(
        el(
          "p",
          "wmm-tolka-speed-truncated",
          "Only the first part of the page HTML was measured."
        )
      )
    );
  }

  function summaryTable(container) {
    return previewTable(container);
  }

  function previewTable(container) {
    if (!container || !container.querySelector) {
      return null;
    }
    return container.querySelector("table.wmm-tolka-summary") || container.querySelector("table");
  }

  function normalizeLabel(value) {
    return String(value || "")
      .replace(/\s+/g, " ")
      .trim()
      .toLowerCase();
  }

  function findLabeledRow(rows, label) {
    var wanted = normalizeLabel(label);
    for (var i = 0; i < rows.length; i += 1) {
      var head = rows[i].querySelector("th, td");
      if (head && normalizeLabel(head.textContent) === wanted) {
        return rows[i];
      }
    }
    return null;
  }

  function rowValueCell(row) {
    if (!row) {
      return null;
    }
    var cells = row.querySelectorAll("td");
    return cells.length ? cells[cells.length - 1] : null;
  }

  function setRowValue(row, value) {
    var cell = rowValueCell(row);
    if (cell) {
      setPlainText(cell, value);
    }
  }

  function previewRowMap(table) {
    var rows = table.querySelectorAll("tbody tr");
    var found = {};
    var complete = true;
    SECTIONS.forEach(function (section) {
      found[section.key] = findLabeledRow(rows, section.title);
      if (!found[section.key]) {
        complete = false;
      }
    });
    return complete ? found : null;
  }

  function orderedPreviewRows(table) {
    var rows = table.querySelectorAll("tbody tr");
    if (!rows.length) {
      rows = table.querySelectorAll("tr");
    }
    var bodyRows = [];
    Array.prototype.forEach.call(rows, function (row) {
      if (row.parentNode && row.parentNode.tagName && row.parentNode.tagName.toLowerCase() === "thead") {
        return;
      }
      bodyRows.push(row);
    });
    if (bodyRows.length !== SECTIONS.length) {
      return null;
    }
    var found = {};
    SECTIONS.forEach(function (section, index) {
      found[section.key] = bodyRows[index];
    });
    return found;
  }

  function sectionCountText(section, counts, sample) {
    var count = countOf(counts, sample, section.key);
    if (count === 0) {
      return section.empty;
    }
    return countLabel(count, section.singular, section.plural) + ".";
  }

  function appendPreviewCounts(container, counts, sample) {
    var list = el("ul", "wmm-tolka-preview-counts");
    markGenerated(list);
    SECTIONS.forEach(function (section) {
      var item = el("li", "wmm-tolka-count");
      item.setAttribute("data-section", section.key);
      setPlainText(item, section.title + ": " + sectionCountText(section, counts, sample));
      list.appendChild(item);
    });
    container.appendChild(list);
  }

  function fillPreviewTable(container, counts, sample, errors, missing, total) {
    var table = previewTable(container);
    if (!table) {
      return false;
    }

    removeGenerated(container);
    var rowMap = previewRowMap(table) || orderedPreviewRows(table);
    if (rowMap) {
      SECTIONS.forEach(function (section) {
        setRowValue(rowMap[section.key], sectionCountText(section, counts, sample));
        rowMap[section.key].setAttribute("data-section", section.key);
      });
    } else {
      appendPreviewCounts(container, counts, sample);
    }

    var lead = container.querySelector(".wmm-tolka-empty-lead");
    if (lead) {
      setPlainText(
        lead,
        total === 0 && !errors.length
          ? "Nothing was found to preview. Nothing was written, and Polylang data stays in place."
          : "Preview only. Nothing was written, and Polylang data stays in place."
      );
    }

    if (errors.length || missing.length) {
      var notes = markGenerated(el("div", "wmm-tolka-preview-notes"));
      errors.forEach(function (message) {
        notes.appendChild(errorParagraph(message));
      });
      if (missing.length) {
        notes.appendChild(
          el(
            "p",
            "wmm-tolka-missing",
            "These parts are not available yet: " + missing.join(", ") + "."
          )
        );
      }
      container.insertBefore(notes, container.firstChild);
    }

    var samples = markGenerated(el("div", "wmm-tolka-preview-samples"));
    SECTIONS.forEach(function (section) {
      samples.appendChild(renderSection(section, counts, sample));
    });
    container.appendChild(samples);
    return true;
  }

  function reportRowMap(table) {
    var rows = table.querySelectorAll("tbody tr");
    var found = {
      title: findLabeledRow(rows, "Title"),
      summary: findLabeledRow(rows, "Summary"),
    };
    if (!found.title || !found.summary) {
      return null;
    }
    SECTIONS.forEach(function (section) {
      found[section.key] = findLabeledRow(rows, section.title);
    });
    var hasSection = SECTIONS.some(function (section) {
      return !!found[section.key];
    });
    return hasSection ? found : null;
  }

  function lineValueForLabel(lines, label) {
    var wanted = normalizeLabel(label);
    var match = "";
    lines.forEach(function (line) {
      var pair = reportPair(line);
      if (!match && pair && normalizeLabel(pair.label) === wanted) {
        match = pair.value;
      }
    });
    return match;
  }

  function fillReportTable(container, lines, title, summary, errors, missing, succeeded) {
    var table = summaryTable(container);
    var rowMap = table ? reportRowMap(table) : null;
    if (!rowMap) {
      return false;
    }

    removeGenerated(container);
    setRowValue(rowMap.title, title);
    setRowValue(rowMap.summary, summary || (succeeded ? "Migration finished." : "Migration did not finish."));
    var used = { title: true, summary: true };
    SECTIONS.forEach(function (section) {
      if (!rowMap[section.key]) {
        return;
      }
      var value = lineValueForLabel(lines, section.title);
      if (!value) {
        value = succeeded ? "None reported." : "Not available.";
      }
      setRowValue(rowMap[section.key], value);
      rowMap[section.key].setAttribute("data-section", section.key);
      used[normalizeLabel(section.title)] = true;
    });

    var note = container.querySelector(".wmm-tolka-notice p");
    if (note) {
      setPlainText(
        note,
        summary ? finishSentence(summary) : reportClosing(succeeded, summary) || title
      );
    }

    var extras = [];
    lines.forEach(function (line) {
      var pair = reportPair(line);
      if (!pair || used[normalizeLabel(pair.label)]) {
        return;
      }
      extras.push(pair);
    });

    if (errors.length || missing.length || extras.length) {
      var extra = markGenerated(el("div", "wmm-tolka-report-extra"));
      errors.forEach(function (message) {
        extra.appendChild(errorParagraph(message));
      });
      if (missing.length) {
        extra.appendChild(
          el(
            "p",
            "wmm-tolka-missing",
            "These parts are not available yet: " + missing.join(", ") + "."
          )
        );
      }
      if (extras.length) {
        extra.appendChild(reportLinesList(extras));
      }
      container.appendChild(extra);
    }
    return true;
  }

  function paintTableStatus(container, text) {
    var table = summaryTable(container);
    if (!table || !table.querySelector("tbody tr")) {
      return false;
    }
    removeGenerated(container);
    var status = markGenerated(el("p", "wmm-tolka-status", text));
    status.setAttribute("role", "status");
    container.insertBefore(status, container.firstChild);
    return true;
  }

  function paintTableError(container, messages) {
    var table = summaryTable(container);
    if (!table || !table.querySelector("tbody tr")) {
      return false;
    }
    removeGenerated(container);
    var notes = markGenerated(el("div", "wmm-tolka-preview-notes"));
    messages.forEach(function (message) {
      notes.appendChild(errorParagraph(message));
    });
    container.insertBefore(notes, container.firstChild);
    var phrase = container.id === "wmm-tolka-report" ? "Could not be run." : "Could not be loaded.";
    Array.prototype.forEach.call(table.querySelectorAll("tbody tr"), function (row) {
      setRowValue(row, phrase);
    });
    return true;
  }

  function renderPreview(container, data) {
    if (!container) {
      return;
    }
    if (!data || typeof data !== "object" || Array.isArray(data)) {
      showError(
        container,
        "The preview was not returned. Nothing was written."
      );
      return;
    }

    try {
      var counts = data.counts && typeof data.counts === "object" ? data.counts : {};
      var sample = data.sample && typeof data.sample === "object" ? data.sample : {};
      var errors = stringList(data.errors);
      var missing = missingNames(data.missing);
      var total = 0;
      SECTIONS.forEach(function (section) {
        total += countOf(counts, sample, section.key);
      });

      if (fillPreviewTable(container, counts, sample, errors, missing, total)) {
        container.classList.remove("wmm-tolka-has-error");
        container.classList.add("wmm-tolka-has-result");
        if (errors.length || missing.length) {
          container.classList.add("wmm-tolka-has-error");
        }
        focusResult(container);
        return;
      }

      clear(container);
      var root = markGenerated(el("div", "wmm-tolka-preview"));

      errors.forEach(function (message) {
        root.appendChild(errorParagraph(message));
      });
      if (missing.length) {
        root.appendChild(
          el(
            "p",
            "wmm-tolka-missing",
            "These parts are not available yet: " + missing.join(", ") + "."
          )
        );
      }

      root.appendChild(
        el(
          "p",
          "wmm-tolka-preview-lead",
          total === 0 && !errors.length
            ? "Nothing was found to preview. Nothing was written, and Polylang data stays in place."
            : "Preview only. Nothing was written, and Polylang data stays in place."
        )
      );

      SECTIONS.forEach(function (section) {
        root.appendChild(renderSection(section, counts, sample));
      });

      container.appendChild(root);
      container.classList.remove("wmm-tolka-has-error");
      container.classList.add("wmm-tolka-has-result");
      if (errors.length || missing.length) {
        container.classList.add("wmm-tolka-has-error");
      }
      focusResult(container);
    } catch (err) {
      showError(
        container,
        "The preview could not be displayed. Nothing was written."
      );
    }
  }

  function renderSection(section, counts, sample) {
    var count = countOf(counts, sample, section.key);
    var items = sampleItems(sample, section.key);
    var shown = items.slice();
    if (section.key === "languages") {
      shown.sort(function (a, b) {
        return (b && truthyFlag(b.is_default) ? 1 : 0) - (a && truthyFlag(a.is_default) ? 1 : 0);
      });
    }
    shown = shown.slice(0, SAMPLE_LIMIT);

    var block = el("section", "wmm-tolka-section");
    block.setAttribute("data-section", section.key);
    block.appendChild(el("h3", "wmm-tolka-section-title", section.title));

    if (count === 0) {
      block.appendChild(el("p", "wmm-tolka-empty", section.empty));
      return block;
    }

    block.appendChild(
      el(
        "p",
        "wmm-tolka-section-count",
        countLabel(count, section.singular, section.plural) + "."
      )
    );
    if (shown.length < count) {
      block.appendChild(
        el(
          "p",
          "wmm-tolka-section-note",
          "Showing the first " + shown.length + " of " + count + "."
        )
      );
    }

    var list = el("ul", "wmm-tolka-sample");
    shown.forEach(function (item) {
      list.appendChild(el("li", "wmm-tolka-sample-item", sampleLine(section.key, item)));
    });
    block.appendChild(list);
    return block;
  }

  function sampleItems(sample, key) {
    if (!sample || !Array.isArray(sample[key])) {
      return [];
    }
    return sample[key];
  }

  function sampleLine(section, item) {
    if (item == null) {
      return "Untitled item";
    }
    if (typeof item !== "object") {
      return clip(item, 160) || "Untitled item";
    }
    if (section === "languages") {
      return languageLine(item);
    }
    if (section === "posts") {
      return postLine(item);
    }
    if (section === "terms") {
      return termLine(item);
    }
    if (section === "strings") {
      return stringLine(item);
    }
    if (section === "menus") {
      return menuLine(item);
    }
    if (section === "media") {
      return mediaLine(item);
    }
    return clip(JSON.stringify(item), 160);
  }

  function languageLine(item) {
    var name = text(item.name);
    var slug = text(item.slug);
    var head = name || slug || "Untitled language";
    if (name && slug) {
      head += " (" + slug + ")";
    }
    var extra = [];
    if (text(item.locale)) {
      extra.push(text(item.locale));
    }
    if (truthyFlag(item.is_default)) {
      extra.push("default");
    }
    var flag = shortFlag(item.flag);
    if (flag) {
      extra.push(flag);
    }
    return extra.length ? head + " · " + extra.join(" · ") : head;
  }

  function postLine(item) {
    var entries = mapEntries(item.translations);
    var title = firstField(entries, "title");
    var head = clip(title || text(item.group) || "Untitled post", 80);
    var bits = [head];
    if (text(item.type)) {
      bits.push(text(item.type));
    }
    var langs = entries.map(function (entry) {
      return entry.lang;
    });
    if (langs.length) {
      bits.push(langs.join(", "));
    }
    var status = firstField(entries, "status");
    if (status) {
      bits.push(status);
    }
    if (text(item.group) && text(item.group) !== head) {
      bits.push(text(item.group));
    }
    return bits.join(" · ");
  }

  function termLine(item) {
    var entries = mapEntries(item.translations);
    var name = firstField(entries, "name") || text(item.name);
    var bits = [];
    if (text(item.taxonomy)) {
      bits.push(text(item.taxonomy));
    }
    bits.push(clip(name || text(item.group) || "Untitled term", 80));
    var langs = entries.map(function (entry) {
      return entry.lang;
    });
    if (langs.length) {
      bits.push(langs.join(", "));
    }
    return bits.join(" · ");
  }

  function stringLine(item) {
    var bits = [clip(text(item.name) || "Untitled string", 60)];
    if (text(item.context)) {
      bits.push(clip(text(item.context), 40));
    }
    if (text(item.string)) {
      bits.push(clip(text(item.string), 80));
    }
    var translations = mapEntries(item.translations).map(function (entry) {
      var translated = field(entry.value, "string");
      if (!entry.lang) {
        return clip(translated, 40);
      }
      if (!translated || translated === entry.lang) {
        return entry.lang;
      }
      return entry.lang + ": " + clip(translated, 40);
    });
    if (translations.length) {
      bits.push(translations.join(", "));
    }
    return bits.join(" · ");
  }

  function menuLine(item) {
    var location = text(item.location) || "Menu";
    var parts = mapEntries(item.translations).map(function (entry) {
      var name = field(entry.value, "name") || entry.lang || "Menu";
      var label =
        entry.lang && name !== entry.lang ? entry.lang + ": " + name : String(name);
      var menuItems =
        entry.value && Array.isArray(entry.value.items) ? entry.value.items : null;
      if (menuItems) {
        label += " (" + menuItems.length + (menuItems.length === 1 ? " item" : " items") + ")";
        var titles = [];
        menuItems.forEach(function (menuItem) {
          if (titles.length >= 3 || !menuItem) {
            return;
          }
          var title = text(menuItem.title) || text(menuItem.url);
          if (title) {
            titles.push(clip(title, 40));
          }
        });
        if (titles.length) {
          label += ": " + titles.join(", ");
        }
      }
      return label;
    });
    return parts.length ? location + " · " + parts.join(", ") : location;
  }

  function mediaLine(item) {
    var file = text(item.file);
    var head = file
      ? clip(file, 80)
      : item.attachment_id != null && item.attachment_id !== ""
        ? "Attachment " + item.attachment_id
        : "Media";
    var langs = [];
    mapEntries(item.alt).concat(mapEntries(item.title)).forEach(function (entry) {
      if (entry.lang && langs.indexOf(entry.lang) === -1) {
        langs.push(entry.lang);
      }
    });
    return langs.length ? head + " · " + langs.join(", ") : head;
  }

  function renderReport(container, data) {
    if (!container) {
      return;
    }
    var parts = unwrapReport(data);
    if (!parts) {
      showError(
        container,
        failureWithKeptData(
          "The migration finished without a report. Reload the page before running it again."
        )
      );
      return;
    }

    try {
      var report = parts.report;
      var errors = stringList(report.errors).concat(stringList(parts.errors));
      if (parts.run) {
        errors = errors.concat(stringList(parts.run.errors));
        if (parts.run.write) {
          errors = errors.concat(stringList(parts.run.write.errors));
        }
      }
      errors = uniqueStrings(errors);
      var missing = missingNames(report.missing).concat(
        missingNames(parts.missing)
      );
      if (parts.run) {
        missing = missing.concat(missingNames(parts.run.missing));
      }
      missing = uniqueStrings(missing);

      var summary = text(report.summary);
      if (summary && summary === errors.join(" ")) {
        summary = "";
      }
      var succeeded = isAffirmative(report.ok);
      var title = text(report.title);
      if (!title) {
        title = succeeded ? "Migration finished." : "Migration did not finish.";
      }
      title = finishSentence(title);
      var lines = Array.isArray(report.lines) ? report.lines : [];

      if (fillReportTable(container, lines, title, summary, errors, missing, succeeded)) {
        writeReportLines(container, lines);
        container.classList.toggle("wmm-tolka-has-error", !succeeded || errors.length > 0);
        container.classList.add("wmm-tolka-has-result");
        focusResult(container);
        return;
      }

      clear(container);
      var root = markGenerated(el("div", "wmm-tolka-report-body"));
      root.setAttribute("data-ok", succeeded ? "true" : "false");
      if (!succeeded) {
        root.classList.add("wmm-tolka-report-failed");
      }
      root.appendChild(el("h3", "wmm-tolka-report-title", title));
      if (summary) {
        root.appendChild(el("p", "wmm-tolka-report-summary", finishSentence(summary)));
      }
      errors.forEach(function (message) {
        root.appendChild(errorParagraph(message));
      });
      if (missing.length) {
        root.appendChild(
          el(
            "p",
            "wmm-tolka-missing",
            "These parts are not available yet: " + missing.join(", ") + "."
          )
        );
      }

      if (lines.length) {
        root.appendChild(reportLinesList(lines));
      } else if (!errors.length) {
        root.appendChild(
          el("p", "wmm-tolka-empty", "There are no details to list.")
        );
      }

      var closing = reportClosing(succeeded, summary);
      if (closing) {
        root.appendChild(el("p", "wmm-tolka-report-note", closing));
      }

      container.appendChild(root);
      container.classList.toggle("wmm-tolka-has-error", !succeeded || errors.length > 0);
      container.classList.add("wmm-tolka-has-result");
      focusResult(container);
    } catch (err) {
      showError(
        container,
        failureWithKeptData(
          "The migration report could not be displayed. Reload the page before running it again."
        )
      );
    }
  }

  function unwrapReport(data) {
    if (!data || typeof data !== "object" || Array.isArray(data)) {
      return null;
    }
    var report = null;
    var run = null;
    if (data.report && typeof data.report === "object" && !Array.isArray(data.report)) {
      report = data.report;
    }
    if (data.run && typeof data.run === "object" && !Array.isArray(data.run)) {
      run = data.run;
    }
    if (
      !report &&
      (data.title != null || data.summary != null || Array.isArray(data.lines))
    ) {
      report = data;
    }
    if (!report && (data.counts || data.write || data.ok != null)) {
      run = data;
      report = reportFromRun(data);
    }
    if (!report) {
      return null;
    }
    return {
      report: report,
      run: run,
      errors: report === data ? [] : data.errors,
      missing: report === data ? [] : data.missing,
    };
  }

  function reportFromRun(run) {
    var lines = [];
    var counts = run.counts && typeof run.counts === "object" ? run.counts : {};
    SECTIONS.forEach(function (section) {
      if (Object.prototype.hasOwnProperty.call(counts, section.key)) {
        lines.push({ label: section.title, value: counts[section.key] });
      }
    });
    var errors = stringList(run.errors);
    if (run.write && typeof run.write === "object") {
      errors = errors.concat(stringList(run.write.errors));
    }
    var ok = isAffirmative(run.ok) && (!run.write || run.write.ok !== false);
    return {
      ok: ok,
      title: ok ? "Migration finished." : "Migration did not finish.",
      summary: "",
      lines: lines,
      errors: uniqueStrings(errors),
      missing: run.missing,
    };
  }

  function writeReportLines(container, lines) {
    if (!container) {
      return;
    }
    var existing = container.querySelector(".wmm-tolka-report-lines");
    if (existing && existing.parentNode) {
      existing.parentNode.removeChild(existing);
    }
    var list = reportLinesList(lines);
    if (!list.childNodes.length) {
      return;
    }
    container.appendChild(list);
  }

  function reportLinesList(lines) {
    var list = el("dl", "wmm-tolka-report-lines");
    (lines || []).forEach(function (line) {
      var pair = reportPair(line);
      if (!pair) {
        return;
      }
      var row = el("div", "wmm-tolka-report-line");
      var label = el("dt", "wmm-tolka-report-label");
      var value = el("dd", "wmm-tolka-report-value");
      setPlainText(label, pair.label);
      setPlainText(value, pair.value);
      row.appendChild(label);
      row.appendChild(value);
      list.appendChild(row);
    });
    return list;
  }

  function reportPair(line) {
    var label = "";
    var value = "";
    if (Array.isArray(line)) {
      label = text(line[0]);
      value = formatReportValue(line[1]);
    } else if (line && typeof line === "object") {
      label = text(line.label);
      value = formatReportValue(line.value);
    }
    if (!label) {
      return null;
    }
    return { label: label, value: value };
  }

  function formatReportValue(value) {
    if (value == null) {
      return "";
    }
    if (typeof value === "string") {
      return value;
    }
    if (typeof value === "boolean") {
      return value ? "Yes" : "No";
    }
    if (typeof value === "number") {
      return String(value);
    }
    if (Array.isArray(value)) {
      return value
        .map(formatReportValue)
        .filter(function (item) {
          return item !== "";
        })
        .join(", ");
    }
    try {
      return JSON.stringify(value);
    } catch (e) {
      return "";
    }
  }

  function reportClosing(ok, summary) {
    if (mentionsKeptData(summary)) {
      return "";
    }
    if (ok) {
      return "Polylang data stays in place.";
    }
    return "Polylang data was not removed.";
  }

  function mentionsKeptData(value) {
    var blob = String(value || "").toLowerCase();
    return (
      blob.indexOf("not removed") !== -1 ||
      blob.indexOf("not deleted") !== -1 ||
      blob.indexOf("stays in place") !== -1 ||
      blob.indexOf("left in place") !== -1
    );
  }

  function failureWithKeptData(message) {
    var messages = Array.isArray(message) ? message.slice() : [message];
    var joined = messages
      .map(function (item) {
        return item == null ? "" : String(item);
      })
      .join(" ");
    if (!mentionsKeptData(joined)) {
      messages.push("Polylang data was not removed.");
    }
    return messages;
  }

  function showStatus(container, text) {
    if (!container) {
      return;
    }
    if (paintSpeedStatus(container, text)) {
      container.classList.remove("wmm-tolka-has-error");
      container.classList.remove("wmm-tolka-has-result");
      return;
    }
    if (paintTableStatus(container, text)) {
      container.classList.remove("wmm-tolka-has-error");
      container.classList.remove("wmm-tolka-has-result");
      return;
    }
    clear(container);
    var status = markGenerated(el("p", "wmm-tolka-status", text));
    status.setAttribute("role", "status");
    container.appendChild(status);
    container.classList.remove("wmm-tolka-has-error");
    container.classList.remove("wmm-tolka-has-result");
  }

  function showTransportFailure(container, sentence) {
    var text = finishSentence(
      sentence || "The request could not be completed, so try again."
    );
    if (!container) {
      return;
    }
    removeGenerated(container);
    if (container.id === "wmm-tolka-speed") {
      var slots = collectSpeedSlots(container);
      if (slots.panel) {
        slots.panel.classList.remove("wmm-tolka-is-busy");
      }
      if (slots.error) {
        setPlainText(slots.error, text);
        var errorBox = errorWrapper(slots.error);
        reveal(errorBox);
        errorBox.setAttribute("role", "alert");
        errorBox.classList.add("notice", "notice-error");
        container.classList.add("wmm-tolka-has-error");
        return;
      }
    }
    var previous = container.querySelector(".wmm-tolka-transport-error");
    if (previous && previous.parentNode) {
      previous.parentNode.removeChild(previous);
    }
    var paragraph = el("p", "wmm-tolka-error wmm-tolka-transport-error", text);
    paragraph.setAttribute("role", "alert");
    if (container.firstChild) {
      container.insertBefore(paragraph, container.firstChild);
    } else {
      container.appendChild(paragraph);
    }
    container.classList.add("wmm-tolka-has-error");
  }

  function showMeasureRejection(container, message) {
    if (container && hasSpeedAnchor(container)) {
      var slots = collectSpeedSlots(container);
      var text = normalizeMessages(message).join(" ");
      if (slots.error) {
        setText(slots.error, text);
        var errorBox = errorWrapper(slots.error);
        reveal(errorBox);
        errorBox.setAttribute("role", "alert");
        errorBox.classList.add("notice", "notice-error");
        return;
      }
      var alert = markGenerated(el("p", "wmm-tolka-error", text));
      alert.setAttribute("role", "alert");
      container.insertBefore(alert, container.firstChild);
      return;
    }
    showError(container, message);
  }

  function showError(container, message) {
    if (!container) {
      return;
    }
    var messages = normalizeMessages(message);
    if (container.id === "wmm-tolka-speed" && hasSpeedAnchor(container)) {
      paintSpeedError(container, messages, null);
      return;
    }
    if (paintSpeedError(container, messages, null)) {
      return;
    }
    if (paintTableError(container, messages)) {
      container.classList.add("wmm-tolka-has-error");
      container.classList.remove("wmm-tolka-has-result");
      focusResult(container);
      return;
    }

    clear(container);
    messages.forEach(function (item) {
      container.appendChild(errorParagraph(item));
    });
    container.classList.add("wmm-tolka-has-error");
    container.classList.remove("wmm-tolka-has-result");
    focusResult(container);
  }

  function focusResult(container) {
    if (!container || typeof container.focus !== "function") {
      return;
    }
    if (!container.hasAttribute("tabindex")) {
      return;
    }
    try {
      container.focus({ preventScroll: false });
    } catch (err) {
      container.focus();
    }
  }

  function normalizeMessages(message) {
    var messages = uniqueStrings(
      (Array.isArray(message) ? message : [message])
        .reduce(function (list, item) {
          if (Array.isArray(item)) {
            return list.concat(item);
          }
          list.push(item);
          return list;
        }, [])
        .map(function (item) {
          return item == null ? "" : String(item).trim();
        })
        .filter(Boolean)
    );
    if (!messages.length) {
      messages = ["The request failed."];
    }
    return messages;
  }

  function errorParagraph(message) {
    var paragraph = markGenerated(el("p", "wmm-tolka-error", message));
    paragraph.setAttribute("role", "alert");
    return paragraph;
  }

  function ensureLive(container) {
    if (!container || container.hasAttribute("aria-live")) {
      return;
    }
    container.setAttribute("aria-live", "polite");
  }

  function paintInitialEmpty(container, copy) {
    if (!container || !isVisuallyEmpty(container)) {
      return;
    }
    clear(container);
    container.appendChild(markGenerated(el("p", "wmm-tolka-empty", copy)));
  }

  function isVisuallyEmpty(container) {
    return !String(container.textContent || "").trim();
  }

  function speedEmptyCopy() {
    return "No measurement yet. Enter the public site URL and choose Measure speed to see the current load, the estimated Tolka load, the percent faster, and the basis of that estimate.";
  }

  function previewEmptyCopy() {
    return "No preview yet. Choose Preview migration to see languages, posts, terms, strings, menus, and media. Nothing is written, and Polylang data stays in place.";
  }

  function reportEmptyCopy() {
    return "No migration yet. Choose Run migration when the preview looks right. Polylang data stays in place. A Tolka package is stored and Tolka meta is written.";
  }

  function collectSpeedSlots(container) {
    if (!container || container.id !== "wmm-tolka-speed") {
      return {};
    }
    var slots = {
      panel: findSlot(container, ".wmm-tolka-speed-panel"),
      summary: speedSlot(container, [
        "[data-field='summary']",
        "#wmm-tolka-speed-summary",
      ]),
      current: speedValue(
        container,
        "current_ms",
        "wmm-tolka-current-load",
        "wmm-tolka-speed-current",
        ["[data-wmm-tolka='current_ms']", "[data-wmm-tolka='current']"]
      ),
      tolka: speedValue(
        container,
        "tolka_ms",
        "wmm-tolka-tolka-load",
        "wmm-tolka-speed-tolka",
        ["[data-wmm-tolka='tolka_ms']", "[data-wmm-tolka='tolka']"]
      ),
      faster: speedValue(
        container,
        "faster_label",
        "wmm-tolka-faster",
        "wmm-tolka-speed-faster",
        ["[data-wmm-tolka='faster']", "[data-wmm-tolka='faster_percent']"]
      ),
      basis: speedValue(container, "basis", "wmm-tolka-basis", "wmm-tolka-speed-basis", [
        "[data-wmm-tolka='basis']",
      ]),
      provenance: speedValue(
        container,
        "provenance_note",
        "wmm-tolka-provenance-note",
        "wmm-tolka-provenance",
        ["[data-wmm-tolka='provenance_note']", "[data-wmm-tolka='provenance']"]
      ),
      error: speedSlot(container, ["[data-field='error']", "#wmm-tolka-speed-error"]),
      savings: speedSlot(container, ["[data-field='savings']", "#wmm-tolka-speed-savings"]),
      scope: speedSlot(container, ["[data-field='scope']", "#wmm-tolka-speed-scope"]),
      basisLabel: findSlot(container, ".wmm-tolka-speed-basis-label"),
    };
    var current = speedNode(container, "wmm-tolka-current-load");
    var tolka = speedNode(container, "wmm-tolka-tolka-load");
    var faster = speedNode(container, "wmm-tolka-faster");
    var basis = speedNode(container, "wmm-tolka-basis");
    var note = speedNode(container, "wmm-tolka-provenance-note");
    if (current) {
      slots.current = current;
    }
    if (tolka) {
      slots.tolka = tolka;
    }
    if (faster) {
      slots.faster = faster;
    }
    if (basis) {
      slots.basis = basis;
    }
    if (note) {
      slots.provenance = note;
    }
    return slots;
  }

  function speedValue(container, field, id, className, extras) {
    var named = speedNode(container, id);
    if (named) {
      return named;
    }
    var selectors = ["[data-field='" + field + "']", "#" + id].concat(extras || []);
    var direct = speedSlot(container, selectors);
    if (direct) {
      return preferValue(direct);
    }
    var classNode = findSlot(container, "." + className);
    return classNode ? preferValue(classNode) : null;
  }

  function speedSlot(container, selectors) {
    for (var i = 0; i < selectors.length; i += 1) {
      var node = findSlot(container, selectors[i]);
      if (node) {
        return node;
      }
    }
    return null;
  }

  function preferValue(node) {
    if (!node || !node.querySelector) {
      return node;
    }
    if (
      node.getAttribute("data-field") ||
      node.classList.contains("wmm-tolka-speed-value") ||
      node.classList.contains("wmm-tolka-value")
    ) {
      return node;
    }
    var candidates = node.querySelectorAll(
      ".wmm-tolka-value, .wmm-tolka-speed-value, [data-field]"
    );
    for (var i = 0; i < candidates.length; i += 1) {
      if (!insideGeneratedResult(candidates[i])) {
        return candidates[i];
      }
    }
    return node;
  }

  function setPlainText(node, value) {
    if (!node) {
      return;
    }
    var text = value == null ? "" : String(value);
    while (node.firstChild) {
      node.removeChild(node.firstChild);
    }
    node.appendChild(document.createTextNode(text));
  }

  function setText(node, text) {
    setPlainText(node, text);
  }

  function setMs(node, value) {
    if (!node) {
      return;
    }
    var number = Number(value);
    if (!isFinite(number)) {
      node.removeAttribute("data-ms");
      return;
    }
    node.setAttribute("data-ms", String(rounded(number)));
  }

  function reveal(node) {
    if (node) {
      node.removeAttribute("hidden");
    }
  }

  function conceal(node) {
    if (node) {
      node.setAttribute("hidden", "hidden");
    }
  }

  function findSlot(container, selector) {
    if (!container || !container.querySelectorAll) {
      return null;
    }
    var nodes = container.querySelectorAll(selector);
    for (var i = 0; i < nodes.length; i += 1) {
      if (!insideGeneratedResult(nodes[i])) {
        return nodes[i];
      }
    }
    return null;
  }

  function insideGeneratedResult(node) {
    var current = node;
    while (current) {
      if (isOwnGenerated(current)) {
        return true;
      }
      current = current.parentNode;
    }
    return false;
  }

  function hasSpeedAnchor(container) {
    if (!container || !container.querySelector) {
      return false;
    }
    for (var i = 0; i < SPEED_ANCHOR_IDS.length; i += 1) {
      if (container.querySelector("#" + SPEED_ANCHOR_IDS[i])) {
        return true;
      }
    }
    return false;
  }

  function isProtectedSpeedNode(node) {
    if (!node) {
      return false;
    }
    if (node.id && SPEED_ANCHOR_IDS.indexOf(node.id) !== -1) {
      return true;
    }
    if (!node.querySelector) {
      return false;
    }
    for (var i = 0; i < SPEED_ANCHOR_IDS.length; i += 1) {
      if (node.querySelector("#" + SPEED_ANCHOR_IDS[i])) {
        return true;
      }
    }
    return false;
  }

  function isOwnGenerated(node) {
    if (!node || !node.getAttribute) {
      return false;
    }
    if (node.getAttribute("data-field")) {
      return false;
    }
    return node.getAttribute("data-wmm-tolka-generated") === "1";
  }

  function markResult(container) {
    container.classList.remove("wmm-tolka-has-error");
    container.classList.add("wmm-tolka-has-result");
  }

  function removeGenerated(container) {
    if (!container || !container.querySelectorAll) {
      return;
    }
    var nodes = container.querySelectorAll("[data-wmm-tolka-generated]");
    Array.prototype.forEach.call(nodes, function (node) {
      if (!isOwnGenerated(node) || !node.parentNode || isProtectedSpeedNode(node)) {
        return;
      }
      var preview = previewPanel();
      if (preview && (node === preview || (node.contains && node.contains(preview)))) {
        return;
      }
      node.parentNode.removeChild(node);
    });
  }

  function markGenerated(node) {
    node.setAttribute("data-wmm-tolka-generated", "1");
    return node;
  }

  function clear(node) {
    if (!node) {
      return;
    }
    var preview = previewPanel();
    var restore = false;
    if (preview && node !== preview && node.contains && node.contains(preview)) {
      restore = true;
      if (preview.parentNode) {
        preview.parentNode.removeChild(preview);
      }
    }
    while (node.firstChild) {
      node.removeChild(node.firstChild);
    }
    if (restore && preview) {
      node.appendChild(preview);
    }
  }

  function previewPanel() {
    if (typeof document === "undefined" || !document.getElementById) {
      return null;
    }
    return document.getElementById("wmm-tolka-preview-result");
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text != null) {
      setPlainText(node, text);
    }
    return node;
  }

  function countOf(counts, sample, key) {
    if (
      counts &&
      Object.prototype.hasOwnProperty.call(counts, key) &&
      counts[key] != null &&
      counts[key] !== ""
    ) {
      var number = Number(counts[key]);
      if (isFinite(number) && number >= 0) {
        return Math.round(number);
      }
    }
    return sampleItems(sample, key).length;
  }

  function countLabel(count, singular, plural) {
    return String(count) + " " + (count === 1 ? singular : plural);
  }

  function mapEntries(map) {
    if (!map || typeof map !== "object" || Array.isArray(map)) {
      return [];
    }
    return Object.keys(map).map(function (lang) {
      return { lang: lang, value: map[lang] };
    });
  }

  function firstField(entries, key) {
    var found = "";
    entries.forEach(function (entry) {
      if (!found) {
        found = field(entry.value, key);
      }
    });
    return found;
  }

  function field(value, key) {
    if (value == null) {
      return "";
    }
    if (typeof value === "string" || typeof value === "number") {
      return String(value);
    }
    if (typeof value === "object" && value[key] != null && typeof value[key] !== "object") {
      return String(value[key]);
    }
    return "";
  }

  function text(value) {
    if (value == null) {
      return "";
    }
    if (typeof value === "string" || typeof value === "number") {
      return String(value).trim();
    }
    return "";
  }

  function stringList(value) {
    if (value == null) {
      return [];
    }
    var list = Array.isArray(value) ? value : [value];
    var out = [];
    list.forEach(function (item) {
      if (typeof item === "string" && item.trim()) {
        out.push(item.trim());
      }
    });
    return out;
  }

  function uniqueStrings(list) {
    var out = [];
    (list || []).forEach(function (item) {
      var textValue = item == null ? "" : String(item).trim();
      if (textValue && out.indexOf(textValue) === -1) {
        out.push(textValue);
      }
    });
    return out;
  }

  function truthyFlag(value) {
    return value === true || value === 1 || value === "1";
  }

  function isAffirmative(value) {
    return value === true || value === 1;
  }

  function shortFlag(flag) {
    var value = text(flag);
    if (!value || /^https?:/i.test(value) || value.length > 16) {
      return "";
    }
    return value;
  }

  function clip(value, max) {
    var normalized = String(value == null ? "" : value).replace(/\s+/g, " ").trim();
    if (normalized.length <= max) {
      return normalized;
    }
    return normalized.slice(0, max - 1).trim() + "…";
  }

  function rounded(value) {
    var number = Number(value);
    if (!isFinite(number)) {
      return 0;
    }
    return Math.round(number);
  }

  function formatMs(value) {
    var number = rounded(value);
    var digits = String(Math.abs(number)).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    if (number < 0) {
      digits = "-" + digits;
    }
    if (number < 1000) {
      return digits + " ms";
    }

    var seconds = number / 1000;
    var hundredths = Math.round(seconds * 100);
    var decimals = 2;
    if (hundredths % 100 === 0) {
      decimals = 0;
    } else if (hundredths % 10 === 0) {
      decimals = 1;
    }
    if (decimals === 0 && Math.round(seconds) === 1) {
      return digits + " ms (1 second)";
    }
    return digits + " ms (" + seconds.toFixed(decimals) + " seconds)";
  }

  function finishSentence(value) {
    var sentence = String(value == null ? "" : value).trim();
    if (!sentence) {
      return "";
    }
    if (/[.!?]$/.test(sentence)) {
      return sentence;
    }
    return sentence + ".";
  }
})();
