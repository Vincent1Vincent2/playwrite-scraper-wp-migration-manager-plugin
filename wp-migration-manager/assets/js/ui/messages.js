/**
 * Unified Messages Module for Migration Manager
 * Handles all message display across different contexts with queuing and animations
 *
 * @module Messages
 */

(function (window, $) {
  "use strict";

  const Messages = {
    /**
     * Configuration
     */
    config: {
      containers: {
        main: "#migration-messages",
        editor: "#editor-migration-messages",
        sticky: "#sticky-migration-messages",
      },

      types: {
        success: {
          class: "notice-success",
          icon: '<span class="dashicons dashicons-yes-alt"></span>',
          autoDismiss: true,
          dismissDelay: 3000,
          prefix: "Success!",
        },
        error: {
          class: "notice-error",
          icon: '<span class="dashicons dashicons-dismiss"></span>',
          autoDismiss: false,
          dismissDelay: 0,
          prefix: "Error!",
        },
        warning: {
          class: "notice-warning",
          icon: '<span class="dashicons dashicons-warning"></span>',
          autoDismiss: false,
          dismissDelay: 0,
          prefix: "Warning!",
        },
        info: {
          class: "notice-info",
          icon: '<span class="dashicons dashicons-info-outline"></span>',
          autoDismiss: false,
          dismissDelay: 0,
          prefix: "",
        },
        loading: {
          class: "notice-info",
          icon: '<span class="spinner is-active"></span>',
          autoDismiss: false,
          dismissDelay: 0,
          prefix: "Processing...",
        },
      },

      animations: {
        fadeIn: 200,
        fadeOut: 300,
        slideDown: 200,
      },

      maxMessages: 3, // Maximum messages to show at once
      stackMessages: true, // Stack multiple messages
      showTimestamp: false, // Show timestamp on messages
      soundEnabled: false, // Play sound on certain messages
    },

    /**
     * Message queue for each context
     */
    queues: {
      main: [],
      editor: [],
      sticky: [],
    },

    /**
     * Active timers for auto-dismiss
     */
    timers: new Map(),

    /**
     * Message history
     */
    history: [],
    maxHistory: 50,

    /**
     * Initialize the Messages module
     */
    init() {
      this.setupContainers();
      this.bindEvents();
      this.loadConfig();

      console.log("Messages module initialized");
    },

    /**
     * Setup message containers
     */
    setupContainers() {
      // Ensure containers exist
      Object.entries(this.config.containers).forEach(([context, selector]) => {
        if ($(selector).length === 0) {
          console.warn(
            `Message container ${selector} not found for context: ${context}`
          );
        }
      });
    },

    /**
     * Bind global events
     */
    bindEvents() {
      // Dismiss button handler
      $(document).on("click.messages", ".notice-dismiss", (e) => {
        e.preventDefault();
        const $notice = $(e.target).closest(".notice");
        const messageId = $notice.data("message-id");
        this.dismiss(messageId, $notice);
      });

      // Close on escape key
      $(document).on("keydown.messages", (e) => {
        if (e.key === "Escape") {
          this.dismissAll();
        }
      });
    },

    /**
     * Show a message
     * @param {string} type - Message type (success, error, warning, info, loading)
     * @param {string} message - Message text
     * @param {Object} options - Additional options
     */
    show(type, message, options = {}) {
      // Determine context
      const context = options.context || this.getContext();

      // Validate type
      if (!this.config.types[type]) {
        console.error("Unknown message type:", type);
        type = "info";
      }

      // Create message object
      const messageObj = {
        id: this.generateId(),
        type: type,
        message: message,
        context: context,
        timestamp: Date.now(),
        options: options,
      };

      // Add to history
      this.addToHistory(messageObj);

      // Update state if available
      if (window.State) {
        window.State.set("ui.lastMessage", messageObj, true);
      }

      // Check if we should queue or display immediately
      if (this.shouldQueue(context)) {
        this.addToQueue(messageObj);
      } else {
        this.display(messageObj);
      }

      return messageObj.id;
    },

    /**
     * Display a message immediately
     */
    display(messageObj) {
      const { type, message, context, id, options } = messageObj;
      const config = this.config.types[type];
      const $container = $(this.config.containers[context]);

      if ($container.length === 0) {
        console.error("Container not found for context:", context);
        return;
      }

      // Build message HTML
      const html = this.buildMessageHTML(messageObj);

      // Clear loading messages if showing success/error
      if (type === "success" || type === "error") {
        this.clearType("loading", context);
      }

      // Add or replace message
      if (options.replace) {
        $container.html(html);
      } else if (this.config.stackMessages) {
        // Check max messages
        const $existing = $container.find(".notice");
        if ($existing.length >= this.config.maxMessages) {
          $existing
            .first()
            .fadeOut(this.config.animations.fadeOut, function () {
              $(this).remove();
            });
        }
        $container.append(html);
      } else {
        $container.html(html);
      }

      // Animate in
      const $message = $container.find(`[data-message-id="${id}"]`);
      $message.hide().slideDown(this.config.animations.slideDown);

      // Set up auto-dismiss
      if (config.autoDismiss) {
        const delay = options.dismissDelay || config.dismissDelay;
        this.setAutoDismiss(id, $message, delay);
      }

      // Play sound if enabled
      if (this.config.soundEnabled && options.sound !== false) {
        this.playSound(type);
      }

      // Trigger event
      this.trigger("displayed", messageObj);
    },

    /**
     * Build message HTML
     */
    buildMessageHTML(messageObj) {
      const { id, type, message, options } = messageObj;
      const config = this.config.types[type];
      const isDismissible = type !== "loading" && options.dismissible !== false;

      let messageText = this.escapeHtml(message);

      // Add prefix if configured
      if (config.prefix && !options.noPrefix) {
        messageText = `<strong>${config.prefix}</strong> ${messageText}`;
      }

      // Add timestamp if configured
      if (this.config.showTimestamp || options.showTimestamp) {
        const time = new Date(messageObj.timestamp).toLocaleTimeString();
        messageText += ` <small style="opacity: 0.7">(${time})</small>`;
      }

      // Add details if provided
      if (options.details) {
        messageText += `<details style="margin-top: 8px;">
          <summary style="cursor: pointer;">More details</summary>
          <pre style="margin-top: 8px; font-size: 11px;">${this.escapeHtml(
            options.details
          )}</pre>
        </details>`;
      }

      // Add action buttons if provided
      let actions = "";
      if (options.actions && Array.isArray(options.actions)) {
        actions = '<div class="notice-actions" style="margin-top: 10px;">';
        options.actions.forEach((action) => {
          actions += `<button type="button" class="button button-small ${
            action.class || ""
          }" 
                              data-action="${
                                action.id
                              }" data-message-id="${id}">
                        ${action.label}
                      </button> `;
        });
        actions += "</div>";
      }

      return `
        <div class="notice ${config.class} ${
        isDismissible ? "is-dismissible" : ""
      } ${options.class || ""}" 
             data-message-id="${id}" 
             data-message-type="${type}"
             style="${options.style || ""}">
          <p>
            ${config.icon} ${messageText}
          </p>
          ${actions}
          ${
            isDismissible
              ? '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>'
              : ""
          }
        </div>
      `;
    },

    /**
     * Quick methods for common message types
     */
    success(message, options = {}) {
      return this.show("success", message, options);
    },

    error(message, options = {}) {
      return this.show("error", message, options);
    },

    warning(message, options = {}) {
      return this.show("warning", message, options);
    },

    info(message, options = {}) {
      return this.show("info", message, options);
    },

    loading(message = "Loading...", options = {}) {
      return this.show("loading", message, options);
    },

    /**
     * Update an existing message
     */
    update(messageId, newMessage, newType = null) {
      const $message = $(`[data-message-id="${messageId}"]`);

      if ($message.length === 0) {
        // Message not found, show new one
        return this.show(newType || "info", newMessage);
      }

      // Update message content
      const $p = $message.find("p").first();
      const oldIcon = $p.find(".dashicons, .spinner").first().prop("outerHTML");

      if (newType && this.config.types[newType]) {
        // Change type
        const oldType = $message.data("message-type");
        const newConfig = this.config.types[newType];

        $message
          .removeClass(this.config.types[oldType].class)
          .addClass(newConfig.class)
          .data("message-type", newType);

        $p.html(`${newConfig.icon} ${this.escapeHtml(newMessage)}`);

        // Update auto-dismiss
        if (newConfig.autoDismiss) {
          this.setAutoDismiss(messageId, $message, newConfig.dismissDelay);
        } else {
          this.clearAutoDismiss(messageId);
        }
      } else {
        // Just update text
        $p.html(`${oldIcon} ${this.escapeHtml(newMessage)}`);
      }

      // Flash to indicate update
      $message.css("opacity", 0.5).animate({ opacity: 1 }, 200);

      return messageId;
    },

    /**
     * Dismiss a specific message
     */
    dismiss(messageId, $element = null) {
      const $message = $element || $(`[data-message-id="${messageId}"]`);

      if ($message.length === 0) return;

      // Clear timer if exists
      this.clearAutoDismiss(messageId);

      // Animate out
      $message.slideUp(this.config.animations.fadeOut, function () {
        $(this).remove();
      });

      // Process queue
      const context = this.getMessageContext($message);
      this.processQueue(context);

      // Trigger event
      this.trigger("dismissed", { id: messageId, context });
    },

    /**
     * Dismiss all messages
     */
    dismissAll(context = null) {
      const contexts = context
        ? [context]
        : Object.keys(this.config.containers);

      contexts.forEach((ctx) => {
        const $container = $(this.config.containers[ctx]);
        $container.find(".notice").each((i, el) => {
          const messageId = $(el).data("message-id");
          this.dismiss(messageId, $(el));
        });
      });
    },

    /**
     * Clear all messages of a specific type
     */
    clearType(type, context = null) {
      const contexts = context
        ? [context]
        : Object.keys(this.config.containers);

      contexts.forEach((ctx) => {
        const $container = $(this.config.containers[ctx]);
        $container.find(`[data-message-type="${type}"]`).each((i, el) => {
          const messageId = $(el).data("message-id");
          this.dismiss(messageId, $(el));
        });
      });
    },

    /**
     * Set auto-dismiss timer
     */
    setAutoDismiss(messageId, $element, delay) {
      // Clear existing timer
      this.clearAutoDismiss(messageId);

      // Set new timer
      const timer = setTimeout(() => {
        this.dismiss(messageId, $element);
      }, delay);

      this.timers.set(messageId, timer);
    },

    /**
     * Clear auto-dismiss timer
     */
    clearAutoDismiss(messageId) {
      if (this.timers.has(messageId)) {
        clearTimeout(this.timers.get(messageId));
        this.timers.delete(messageId);
      }
    },

    /**
     * Queue management
     */
    shouldQueue(context) {
      if (!this.config.stackMessages) return false;

      const $container = $(this.config.containers[context]);
      const currentCount = $container.find(".notice").length;

      return currentCount >= this.config.maxMessages;
    },

    addToQueue(messageObj) {
      const { context } = messageObj;
      if (!this.queues[context]) {
        this.queues[context] = [];
      }
      this.queues[context].push(messageObj);
    },

    processQueue(context) {
      if (!this.queues[context] || this.queues[context].length === 0) {
        return;
      }

      const $container = $(this.config.containers[context]);
      const currentCount = $container.find(".notice").length;

      if (currentCount < this.config.maxMessages) {
        const messageObj = this.queues[context].shift();
        this.display(messageObj);
      }
    },

    /**
     * History management
     */
    addToHistory(messageObj) {
      this.history.unshift(messageObj);

      if (this.history.length > this.maxHistory) {
        this.history.length = this.maxHistory;
      }
    },

    getHistory(filter = {}) {
      let results = [...this.history];

      if (filter.type) {
        results = results.filter((m) => m.type === filter.type);
      }

      if (filter.context) {
        results = results.filter((m) => m.context === filter.context);
      }

      if (filter.since) {
        results = results.filter((m) => m.timestamp >= filter.since);
      }

      return results;
    },

    clearHistory() {
      this.history = [];
    },

    /**
     * Utility methods
     */
    getContext() {
      // Use State module if available
      if (window.State && window.State.computed) {
        return window.State.computed.getContext();
      }

      // Fallback to checking globals
      if (!window.isEditorMode) return "main";
      return window.stickySidebarOpen ? "sticky" : "editor";
    },

    getMessageContext($element) {
      const $container = $element.closest('[id$="-messages"]');
      const id = $container.attr("id");

      for (const [context, selector] of Object.entries(
        this.config.containers
      )) {
        if (selector === "#" + id) {
          return context;
        }
      }

      return "main";
    },

    generateId() {
      return (
        "msg-" + Date.now() + "-" + Math.random().toString(36).substr(2, 9)
      );
    },

    escapeHtml(text) {
      if (typeof text !== "string") return text;

      const map = {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;",
      };

      return text.replace(/[&<>"']/g, (m) => map[m]);
    },

    /**
     * Sound effects (optional)
     */
    playSound(type) {
      // Implement if sound effects are desired
      const sounds = {
        success: "/sounds/success.mp3",
        error: "/sounds/error.mp3",
        warning: "/sounds/warning.mp3",
      };

      if (sounds[type] && window.Audio) {
        try {
          const audio = new Audio(sounds[type]);
          audio.volume = 0.3;
          audio.play().catch(() => {}); // Ignore errors
        } catch (e) {
          // Sound playback failed, ignore
        }
      }
    },

    /**
     * Event system
     */
    events: {},

    on(event, callback) {
      if (!this.events[event]) {
        this.events[event] = [];
      }
      this.events[event].push(callback);

      return () => {
        const index = this.events[event].indexOf(callback);
        if (index > -1) {
          this.events[event].splice(index, 1);
        }
      };
    },

    trigger(event, data) {
      if (this.events[event]) {
        this.events[event].forEach((callback) => callback(data));
      }
    },

    /**
     * Configuration management
     */
    setConfig(key, value) {
      const keys = key.split(".");
      let target = this.config;

      for (let i = 0; i < keys.length - 1; i++) {
        if (!target[keys[i]]) {
          target[keys[i]] = {};
        }
        target = target[keys[i]];
      }

      target[keys[keys.length - 1]] = value;
      this.saveConfig();
    },

    loadConfig() {
      try {
        const saved = localStorage.getItem("migrationMessagesConfig");
        if (saved) {
          const config = JSON.parse(saved);
          $.extend(true, this.config, config);
        }
      } catch (e) {
        // Ignore errors
      }
    },

    saveConfig() {
      try {
        localStorage.setItem(
          "migrationMessagesConfig",
          JSON.stringify(this.config)
        );
      } catch (e) {
        // Ignore errors
      }
    },

    /**
     * Preset message templates
     */
    templates: {
      scraped: (count) => `Successfully scraped ${count} items`,
      deleted: (type = "item") => `${type} deleted successfully`,
      saved: () => "Changes saved successfully",
      copied: () => "Copied to clipboard!",
      dropped: () => "Content dropped successfully!",
      networkError: () => "Network error occurred. Please try again.",
      validationError: (field) => `Please check the ${field} field`,
      processing: () => "Processing your request...",
      loadingData: () => "Loading data...",
      noData: () => "No data found to display",
      confirmDelete: (type = "item") =>
        `Are you sure you want to delete this ${type}?`,
    },

    /**
     * Show template message
     */
    showTemplate(template, type = "info", ...args) {
      if (this.templates[template]) {
        const message = this.templates[template](...args);
        return this.show(type, message);
      }
      return this.show(type, template);
    },
  };

  // Create convenience shortcuts
  const msg = {
    success: (m, o) => Messages.success(m, o),
    error: (m, o) => Messages.error(m, o),
    warning: (m, o) => Messages.warning(m, o),
    info: (m, o) => Messages.info(m, o),
    loading: (m, o) => Messages.loading(m, o),
    dismiss: (id) => Messages.dismiss(id),
    clear: () => Messages.dismissAll(),
    update: (id, m, t) => Messages.update(id, m, t),
  };

  // Export for use in other modules
  window.Messages = Messages;
  window.msg = msg;

  // Also attach to jQuery if needed
  if ($) {
    $.migrationMessages = Messages;
    $.msg = msg;
  }

  // Auto-initialize when DOM is ready
  $(document).ready(function () {
    Messages.init();
  });
})(window, jQuery);
