/**
 * EventBus Module for Migration Manager
 * Provides centralized event system for module communication
 *
 * @module EventBus
 */

(function (window, $) {
  "use strict";

  const EventBus = {
    /**
     * Event listeners storage
     */
    _events: {},

    /**
     * Event history for debugging
     */
    _history: [],
    _maxHistory: 100,
    _debugMode: false,

    /**
     * Subscribe to an event
     * @param {string} event - Event name or multiple events separated by space
     * @param {Function} callback - Callback function
     * @param {Object} context - Optional context for callback
     * @returns {Function} Unsubscribe function
     */
    on(event, callback, context = null) {
      const events = event.split(" ");
      const refs = [];

      events.forEach((eventName) => {
        if (!this._events[eventName]) {
          this._events[eventName] = [];
        }

        const handler = {
          callback: callback,
          context: context,
          once: false,
        };

        this._events[eventName].push(handler);
        refs.push({ event: eventName, handler });
      });

      // Return unsubscribe function
      return () => {
        refs.forEach((ref) => {
          this.off(ref.event, ref.handler.callback);
        });
      };
    },

    /**
     * Subscribe to an event once
     */
    once(event, callback, context = null) {
      const events = event.split(" ");

      events.forEach((eventName) => {
        if (!this._events[eventName]) {
          this._events[eventName] = [];
        }

        const handler = {
          callback: callback,
          context: context,
          once: true,
        };

        this._events[eventName].push(handler);
      });
    },

    /**
     * Unsubscribe from an event
     */
    off(event, callback = null) {
      if (!event) {
        // Clear all events
        this._events = {};
        return;
      }

      const events = event.split(" ");

      events.forEach((eventName) => {
        if (!this._events[eventName]) return;

        if (!callback) {
          // Remove all handlers for this event
          delete this._events[eventName];
        } else {
          // Remove specific handler
          this._events[eventName] = this._events[eventName].filter(
            (handler) => handler.callback !== callback
          );

          if (this._events[eventName].length === 0) {
            delete this._events[eventName];
          }
        }
      });
    },

    /**
     * Emit an event
     */
    emit(event, ...args) {
      // Add to history
      if (this._debugMode) {
        this._addToHistory(event, args);
      }

      if (!this._events[event]) {
        if (this._debugMode) {
          console.log(`[EventBus] No listeners for event: ${event}`);
        }
        return;
      }

      const handlers = [...this._events[event]];

      handlers.forEach((handler) => {
        try {
          if (handler.context) {
            handler.callback.apply(handler.context, args);
            console.log("context:", handler.context, "args:", args);
          } else {
            handler.callback(...args);
            console.log("...args:", ...args);
          }

          if (handler.once) {
            this.off(event, handler.callback);
          }
        } catch (error) {
          console.error(`[EventBus] Error in handler for ${event}:`, error);
        }
      });
    },

    /**
     * Emit an event asynchronously
     */
    emitAsync(event, ...args) {
      setTimeout(() => this.emit(event, ...args), 0);
    },

    /**
     * Wait for an event (returns Promise)
     */
    waitFor(event, timeout = 0) {
      return new Promise((resolve, reject) => {
        let timer;

        const unsubscribe = this.once(event, (...args) => {
          if (timer) clearTimeout(timer);
          resolve(args.length === 1 ? args[0] : args);
        });

        if (timeout > 0) {
          timer = setTimeout(() => {
            unsubscribe();
            reject(new Error(`Timeout waiting for event: ${event}`));
          }, timeout);
        }
      });
    },

    /**
     * Check if event has listeners
     */
    hasListeners(event) {
      return this._events[event] && this._events[event].length > 0;
    },

    /**
     * Get listener count for an event
     */
    listenerCount(event) {
      return this._events[event] ? this._events[event].length : 0;
    },

    /**
     * Get all events
     */
    events() {
      return Object.keys(this._events);
    },

    /**
     * Debug helpers
     */
    debug: {
      enable() {
        EventBus._debugMode = true;
        console.log("[EventBus] Debug mode enabled");
      },

      disable() {
        EventBus._debugMode = false;
        console.log("[EventBus] Debug mode disabled");
      },

      log() {
        console.group("[EventBus] Current State");
        console.log("Events:", EventBus.events());
        EventBus.events().forEach((event) => {
          console.log(`  ${event}: ${EventBus.listenerCount(event)} listeners`);
        });
        console.groupEnd();
      },

      history() {
        console.table(EventBus._history);
      },

      clear() {
        EventBus._history = [];
        console.log("[EventBus] History cleared");
      },
    },

    /**
     * Add event to history
     */
    _addToHistory(event, args) {
      this._history.push({
        event: event,
        args: args,
        timestamp: Date.now(),
        time: new Date().toLocaleTimeString(),
      });

      if (this._history.length > this._maxHistory) {
        this._history.shift();
      }
    },

    /**
     * Common event definitions for Migration Manager
     */
    events: {
      // Data events
      DATA_SCRAPED: "data:scraped",
      DATA_LOADED: "data:loaded",
      DATA_UPDATED: "data:updated",
      DATA_DELETED: "data:deleted",
      DATA_CLEARED: "data:cleared",

      // UI events
      UI_RENDERED: "ui:rendered",
      UI_CONTEXT_CHANGED: "ui:context:changed",
      UI_SIDEBAR_TOGGLED: "ui:sidebar:toggled",
      UI_LOADING_START: "ui:loading:start",
      UI_LOADING_END: "ui:loading:end",

      // Drag events
      DRAG_START: "drag:start",
      DRAG_END: "drag:end",
      DRAG_ENTER: "drag:enter",
      DRAG_LEAVE: "drag:leave",
      DRAG_DROP: "drag:drop",

      // Group events
      GROUP_SELECTED: "group:selected",
      GROUP_DESELECTED: "group:deselected",
      GROUP_DELETED: "group:deleted",
      GROUP_BULK_DELETE: "group:bulk:delete",

      // Message events
      MESSAGE_SHOWN: "message:shown",
      MESSAGE_DISMISSED: "message:dismissed",
      MESSAGE_UPDATED: "message:updated",

      // Action events
      ACTION_COPY: "action:copy",
      ACTION_DOWNLOAD: "action:download",
      ACTION_EXPORT: "action:export",
      ACTION_CREATE_POST: "action:create:post",
      ACTION_CREATE_PAGE: "action:create:page",

      // Error events
      ERROR_SCRAPE: "error:scrape",
      ERROR_NETWORK: "error:network",
      ERROR_VALIDATION: "error:validation",
    },
  };

  /**
   * Setup module communication bridges
   */
  EventBus.setupBridges = function () {
    // Bridge State changes to EventBus
    if (window.State) {
      State.subscribe("*", (event) => {
        EventBus.emit("state:changed", event);

        // Emit specific events for important state changes
        if (event.path === "scraped.data") {
          EventBus.emit(EventBus.events.DATA_UPDATED, event.value);
        }
        if (event.path === "ui.activeContext") {
          EventBus.emit(EventBus.events.UI_CONTEXT_CHANGED, event.value);
        }
      });
    }

    // Bridge Messages events to EventBus
    if (window.Messages) {
      Messages.on("displayed", (message) => {
        EventBus.emit(EventBus.events.MESSAGE_SHOWN, message);
      });

      Messages.on("dismissed", (data) => {
        EventBus.emit(EventBus.events.MESSAGE_DISMISSED, data);
      });
    }

    // Setup common event patterns
    this.setupCommonPatterns();
  };

  /**
   * Setup common event patterns
   */
  EventBus.setupCommonPatterns = function () {
    // Auto-render on data change
    this.on(this.events.DATA_UPDATED, (data) => {
      if (window.Renderer) {
        Renderer.refresh();
      }
    });

    // Auto-dismiss loading on success/error
    this.on(`${this.events.DATA_SCRAPED} ${this.events.ERROR_SCRAPE}`, () => {
      if (window.Messages) {
        Messages.clearType("loading");
      }
    });

    // Update state on context change
    this.on(this.events.UI_CONTEXT_CHANGED, (context) => {
      if (window.State) {
        State.set("ui.activeContext", context, true);
      }
    });
  };

  // Export
  window.EventBus = EventBus;

  // jQuery plugin
  if ($) {
    $.eventBus = EventBus;

    // jQuery element events
    $.fn.emitEvent = function (event, ...args) {
      EventBus.emit(event, this, ...args);
      return this;
    };
  }

  // Initialize when ready
  $(document).ready(function () {
    // Delay bridge setup to ensure other modules are loaded
    setTimeout(() => {
      EventBus.setupBridges();
    }, 100);
  });
})(window, jQuery);
