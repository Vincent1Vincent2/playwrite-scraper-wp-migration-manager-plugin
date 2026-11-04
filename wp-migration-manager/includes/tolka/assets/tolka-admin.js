/**
 * Migrate to Tolka — wizard behavior.
 *
 * Uses the localized wmmTolka object and writes measure, preview, and run
 * results into the wizard containers. Every request posts action, nonce,
 * _ajax_nonce, and _wpnonce. Measure also posts url.
 *
 * A speed partial can keep its own markup. Value slots are updated in place
 * when the partial marks them with data-wmm-tolka or the classes below.
 * Otherwise the measurement replaces the contents of #wmm-tolka-speed.
 *
 * Slot attributes: current_ms, tolka_ms, faster, basis, provenance_note.
 * Slot classes: wmm-tolka-speed-current, wmm-tolka-speed-tolka,
 * wmm-tolka-speed-faster, wmm-tolka-speed-basis, wmm-tolka-provenance.
 *
 * @package MigrationManager
 */

(function () {
  "use strict";

  var PLACEHOLDER_NOTE =
    "Placeholder coefficients, not lab measurements. The page timing is measured live. Replace this file with Tolka lab data before using the percent in a pitch.";
  var BASIS_FALLBACK =
    "Measured page load plus coefficients from benchmarks.json";
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

  var SPEED_SLOT_SELECTORS = {
    current:
      '[data-wmm-tolka="current_ms"], [data-wmm-tolka="current"], .wmm-tolka-speed-current',
    tolka:
      '[data-wmm-tolka="tolka_ms"], [data-wmm-tolka="tolka"], .wmm-tolka-speed-tolka',
    faster:
      '[data-wmm-tolka="faster"], [data-wmm-tolka="faster_percent"], .wmm-tolka-speed-faster',
    basis: '[data-wmm-tolka="basis"], .wmm-tolka-speed-basis',
    provenance:
      '[data-wmm-tolka="provenance_note"], [data-wmm-tolka="provenance"], .wmm-tolka-provenance',
  };

  var cfg = window.wmmTolka || null;

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
      var invalid = validateUrl(url);
      if (invalid) {
        if (els.url) {
          els.url.setAttribute("aria-invalid", "true");
        }
        showError(els.speed, invalid);
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
        "Measuring…",
        els.speed,
        "Measuring the public page. This can take a few seconds.",
        function () {
          return post(
            cfg.actionMeasure,
            { url: url },
            40000,
            "The speed check took too long and was stopped. Try the public URL again."
          ).then(function (result) {
            if (!result.ok) {
              showError(els.speed, result.error);
              return;
            }
            renderSpeed(els.speed, result.data, url);
          });
        }
      );
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
        "Previewing…",
        els.previewResult,
        "Building the preview of languages, posts, terms, strings, menus, and media.",
        function () {
          return post(
            cfg.actionPreview,
            {},
            120000,
            "The preview took too long and was stopped. Nothing was written."
          ).then(function (result) {
            if (!result.ok) {
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
        "Migrating…",
        els.report,
        "Running the migration. Polylang data stays in place.",
        function () {
          return post(
            cfg.actionRun,
            {},
            300000,
            "The migration did not answer in time. Reload the page before running it again. Polylang data was not removed."
          ).then(function (result) {
            if (!result.ok) {
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
    return !button || button.getAttribute("data-wmm-tolka-busy") === "1";
  }

  function withBusy(button, busyLabel, container, statusText, work) {
    if (!button) {
      return;
    }
    setBusy(button, true, busyLabel);
    if (container) {
      container.setAttribute("aria-busy", "true");
      showStatus(container, statusText);
    }

    Promise.resolve()
      .then(work)
      .then(
        function () {},
        function (err) {
          showError(
            container,
            err && err.message
              ? err.message
              : "The request failed. Try again."
          );
        }
      )
      .then(function () {
        setBusy(button, false);
        if (container) {
          container.removeAttribute("aria-busy");
        }
      });
  }

  function setBusy(button, busy, busyLabel) {
    if (!button) {
      return;
    }
    if (busy) {
      if (!button.hasAttribute("data-wmm-tolka-label")) {
        button.setAttribute(
          "data-wmm-tolka-label",
          button.textContent == null ? "" : String(button.textContent)
        );
      }
      button.setAttribute("data-wmm-tolka-busy", "1");
      button.disabled = true;
      button.classList.add("wmm-tolka-is-busy");
      button.setAttribute("aria-busy", "true");
      button.textContent = busyLabel;
      return;
    }

    button.removeAttribute("data-wmm-tolka-busy");
    button.disabled = false;
    button.classList.remove("wmm-tolka-is-busy");
    button.removeAttribute("aria-busy");
    if (button.hasAttribute("data-wmm-tolka-label")) {
      button.textContent = button.getAttribute("data-wmm-tolka-label");
    }
  }

  function post(action, fields, timeoutMs, timeoutMessage) {
    var body = encodeForm(formFields(action, fields));
    return send(body, timeoutMs, timeoutMessage).then(
      function (response) {
        return parseAjax(response.status, response.text);
      },
      function (err) {
        return {
          ok: false,
          error:
            err && err.message
              ? err.message
              : "The request could not be completed. Check your connection and try again.",
        };
      }
    );
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

  function send(body, timeoutMs, timeoutMessage) {
    if (typeof window.fetch === "function") {
      return sendFetch(body, timeoutMs, timeoutMessage);
    }
    return sendXhr(body, timeoutMs, timeoutMessage);
  }

  function timeoutError(timeoutMessage) {
    return new Error(
      timeoutMessage || "The request took too long and was stopped. Try again."
    );
  }

  function networkError() {
    return new Error(
      "The request could not be completed. Check your connection and try again."
    );
  }

  function sendFetch(body, timeoutMs, timeoutMessage) {
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
          return response.text().then(function (text) {
            return { status: response.status, text: text };
          });
        },
        function (err) {
          if (err && err.name === "AbortError") {
            throw timeoutError(timeoutMessage);
          }
          throw networkError();
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

  function sendXhr(body, timeoutMs, timeoutMessage) {
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
        reject(networkError());
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
        error: "The security check failed. Reload the page and try again.",
      };
    }
    if (trimmed === "0") {
      return {
        ok: false,
        error:
          "The migration request was not recognized. Reload the page and try again.",
      };
    }
    if (trimmed === "") {
      return {
        ok: false,
        error:
          "The server returned an empty response. Reload the page and try again.",
      };
    }

    var json;
    try {
      json = JSON.parse(trimmed);
    } catch (e) {
      if (status >= 400) {
        return {
          ok: false,
          error: "The request failed (HTTP " + status + ").",
        };
      }
      return {
        ok: false,
        error:
          "The server returned a response that could not be read. Reload the page and try again.",
      };
    }

    if (!json || json.success !== true) {
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
    if (/\s/.test(url)) {
      return "Enter the public site URL without spaces.";
    }
    var match = url.match(/^([a-z][a-z0-9+.-]*):/i);
    if (!match) {
      return "Enter a public http or https URL.";
    }
    var scheme = match[1].toLowerCase();
    if (scheme !== "http" && scheme !== "https") {
      return "Enter a public http or https URL. The speed check does not measure other kinds of addresses.";
    }
    return "";
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
    if (estimate.ok !== true) {
      var message = estimate.error ? String(estimate.error).trim() : "";
      showError(
        container,
        message ||
          "The speed estimate could not be calculated from this page. No percent is shown."
      );
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

    if (preserveSpeedPartial(container)) {
      setSlot(findSlot(container, SPEED_SLOT_SELECTORS.current), currentText);
      setSlot(findSlot(container, SPEED_SLOT_SELECTORS.tolka), tolkaText);
      setSlot(
        findSlot(container, SPEED_SLOT_SELECTORS.faster),
        faster || "A percent faster was not included in this estimate."
      );
      setSlot(findSlot(container, SPEED_SLOT_SELECTORS.basis), finishSentence(basis));
      upsertProvenance(container, note, estimate.provenance || "");
      upsertTruncation(container, metrics);
      markResult(container);
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

  function upsertProvenance(container, note, provenance) {
    var slot = findSlot(container, SPEED_SLOT_SELECTORS.provenance);
    if (slot) {
      var target = valueTarget(slot);
      target.textContent = note || "";
      slot.setAttribute("data-provenance", provenance || "");
      if (note) {
        slot.removeAttribute("hidden");
        if (provenance === "placeholder") {
          slot.classList.add("wmm-tolka-provenance-placeholder");
        } else {
          slot.classList.remove("wmm-tolka-provenance-placeholder");
        }
      } else {
        slot.setAttribute("hidden", "hidden");
        slot.classList.remove("wmm-tolka-provenance-placeholder");
      }
      return;
    }

    var existing = container.querySelector(
      ".wmm-tolka-provenance[data-wmm-tolka-generated]"
    );
    if (!note) {
      if (existing && existing.parentNode) {
        existing.parentNode.removeChild(existing);
      }
      return;
    }
    if (!existing) {
      existing = markGenerated(el("p", "wmm-tolka-provenance"));
      container.appendChild(existing);
    }
    existing.textContent = note;
    existing.setAttribute("data-provenance", provenance || "");
    if (provenance === "placeholder") {
      existing.classList.add("wmm-tolka-provenance-placeholder");
    } else {
      existing.classList.remove("wmm-tolka-provenance-placeholder");
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

      SECTIONS.forEach(function (section) {
        total += countOf(counts, sample, section.key);
      });

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
    var langs = mapEntries(item.translations).map(function (entry) {
      return entry.lang;
    });
    if (langs.length) {
      bits.push(langs.join(", "));
    }
    return bits.join(" · ");
  }

  function menuLine(item) {
    var location = text(item.location) || "Menu";
    var parts = mapEntries(item.translations).map(function (entry) {
      var name = field(entry.value, "name") || entry.lang || "Menu";
      var label =
        entry.lang && name !== entry.lang ? entry.lang + ": " + name : String(name);
      var items =
        entry.value && Array.isArray(entry.value.items) ? entry.value.items.length : null;
      if (items != null) {
        label += " (" + items + (items === 1 ? " item" : " items") + ")";
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
      var title = text(report.title);
      if (!title) {
        title = report.ok === true ? "Migration finished." : "Migration did not finish.";
      }
      title = finishSentence(title);

      clear(container);
      var root = markGenerated(el("div", "wmm-tolka-report-body"));
      root.setAttribute("data-ok", report.ok === true ? "true" : "false");
      if (report.ok !== true) {
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

      var lines = Array.isArray(report.lines) ? report.lines : [];
      if (lines.length) {
        var list = el("dl", "wmm-tolka-report-lines");
        lines.forEach(function (line) {
          var pair = reportPair(line);
          if (!pair) {
            return;
          }
          var row = el("div", "wmm-tolka-report-line");
          row.appendChild(el("dt", "wmm-tolka-report-label", pair.label));
          row.appendChild(el("dd", "wmm-tolka-report-value", pair.value));
          list.appendChild(row);
        });
        if (list.childNodes.length) {
          root.appendChild(list);
        }
      } else if (!errors.length) {
        root.appendChild(
          el("p", "wmm-tolka-empty", "There are no details to list.")
        );
      }

      var closing = reportClosing(report.ok === true, summary);
      if (closing) {
        root.appendChild(el("p", "wmm-tolka-report-note", closing));
      }

      container.appendChild(root);
      container.classList.toggle("wmm-tolka-has-error", report.ok !== true || errors.length > 0);
      container.classList.add("wmm-tolka-has-result");
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
    var ok = run.ok === true && (!run.write || run.write.ok !== false);
    return {
      ok: ok,
      title: ok ? "Migration finished." : "Migration did not finish.",
      summary: "",
      lines: lines,
      errors: uniqueStrings(errors),
      missing: run.missing,
    };
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
    if (typeof value === "number" || typeof value === "boolean") {
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
    var blob = String(summary || "").toLowerCase();
    if (ok) {
      if (blob.indexOf("polylang") !== -1) {
        return "";
      }
      return "Polylang data stays in place.";
    }
    if (
      blob.indexOf("not removed") !== -1 ||
      blob.indexOf("stays in place") !== -1
    ) {
      return "";
    }
    return "Polylang data was not removed.";
  }

  function failureWithKeptData(message) {
    var messages = Array.isArray(message) ? message.slice() : [message];
    var joined = messages
      .map(function (item) {
        return item == null ? "" : String(item);
      })
      .join(" ")
      .toLowerCase();
    if (
      joined.indexOf("not removed") === -1 &&
      joined.indexOf("stays in place") === -1
    ) {
      messages.push("Polylang data was not removed.");
    }
    return messages;
  }

  function showStatus(container, text) {
    if (!container) {
      return;
    }
    var status = markGenerated(el("p", "wmm-tolka-status", text));
    if (preserveSpeedPartial(container)) {
      removeGenerated(container);
      clearSpeedSlotValues(container);
      if (container.firstChild) {
        container.insertBefore(status, container.firstChild);
      } else {
        container.appendChild(status);
      }
    } else {
      clear(container);
      container.appendChild(status);
    }
    container.classList.remove("wmm-tolka-has-error");
    container.classList.remove("wmm-tolka-has-result");
  }

  function showError(container, message) {
    if (!container) {
      return;
    }
    var messages = uniqueStrings(
      (Array.isArray(message) ? message : [message]).reduce(function (list, item) {
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

    var nodes = messages.map(errorParagraph);
    if (preserveSpeedPartial(container)) {
      removeGenerated(container);
      clearSpeedSlotValues(container);
      for (var i = nodes.length - 1; i >= 0; i -= 1) {
        if (container.firstChild) {
          container.insertBefore(nodes[i], container.firstChild);
        } else {
          container.appendChild(nodes[i]);
        }
      }
    } else {
      clear(container);
      nodes.forEach(function (node) {
        container.appendChild(node);
      });
    }
    container.classList.add("wmm-tolka-has-error");
    container.classList.remove("wmm-tolka-has-result");
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

  function preserveSpeedPartial(container) {
    return (
      container &&
      container.id === "wmm-tolka-speed" &&
      !!findSlot(container, SPEED_SLOT_SELECTORS.current) &&
      !!findSlot(container, SPEED_SLOT_SELECTORS.tolka) &&
      !!findSlot(container, SPEED_SLOT_SELECTORS.faster) &&
      !!findSlot(container, SPEED_SLOT_SELECTORS.basis)
    );
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
      if (
        current.getAttribute &&
        current.getAttribute("data-wmm-tolka-generated") === "1"
      ) {
        return true;
      }
      current = current.parentNode;
    }
    return false;
  }

  function clearSpeedSlotValues(container) {
    [
      SPEED_SLOT_SELECTORS.current,
      SPEED_SLOT_SELECTORS.tolka,
      SPEED_SLOT_SELECTORS.faster,
      SPEED_SLOT_SELECTORS.basis,
    ].forEach(function (selector) {
      var slot = findSlot(container, selector);
      if (slot) {
        valueTarget(slot).textContent = "";
      }
    });
    var provenance = findSlot(container, SPEED_SLOT_SELECTORS.provenance);
    if (provenance) {
      valueTarget(provenance).textContent = "";
      provenance.setAttribute("hidden", "hidden");
    }
  }

  function setSlot(node, text) {
    if (!node) {
      return;
    }
    valueTarget(node).textContent = text == null ? "" : String(text);
  }

  function valueTarget(node) {
    var nested = node.querySelector
      ? node.querySelector(".wmm-tolka-value")
      : null;
    if (nested && !insideGeneratedResult(nested)) {
      return nested;
    }
    if (!node.children || node.children.length === 0) {
      return node;
    }
    var created = document.createElement("span");
    created.className = "wmm-tolka-value";
    node.appendChild(created);
    return created;
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
      if (node.parentNode) {
        node.parentNode.removeChild(node);
      }
    });
  }

  function markGenerated(node) {
    node.setAttribute("data-wmm-tolka-generated", "1");
    return node;
  }

  function clear(node) {
    while (node.firstChild) {
      node.removeChild(node.firstChild);
    }
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text != null) {
      node.textContent = String(text);
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
    return digits + " ms";
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
