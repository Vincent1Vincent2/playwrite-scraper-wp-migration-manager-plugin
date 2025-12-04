/**
 * State Management Module for Migration Manager
 * Centralizes all application state and provides a single source of truth
 *
 * @module State
 */

(function (window, $) {
  "use strict";

  const State = {
    /**
     * Private state storage
     */
    _state: {
      // Scraped data management
      scraped: {
        data: null, // The full scraped data object
        url: "", // URL that was scraped
        timestamp: null, // When the data was scraped
        isDirty: false, // Has data been modified since scraping
      },

      // Drag and drop state
      drag: {
        content: "", // Content being dragged
        isActive: false, // Is drag operation in progress
        source: null, // Source element of drag
        holdTimer: null, // Timer for hold-to-blink functionality
        targetZones: [], // Active drop zones
      },

      // UI state
      ui: {
        isEditorMode: false, // Are we in post/page editor
        stickySidebarOpen: false, // Is sticky sidebar visible
        activeContext: "main", // Current context: main/editor/sticky
        isLoading: false, // Global loading state
        lastMessage: null, // Last message shown
      },

      // Group management state
      groups: {
        bulkDeleteMode: false, // Is bulk delete mode active
        markedForDeletion: new Set(), // Groups selected for deletion
        lastDeleted: null, // Last deleted group info for undo
        totalGroups: 0, // Total number of groups
      },

      // Selection state
      selection: {
        items: new Set(), // Selected individual items
        groups: new Set(), // Selected groups
        lastSelected: null, // Last selected item/group
        mode: "single", // Selection mode: single/multiple
      },

      // Recent scrapes cache
      recentScrapes: {
        list: [], // List of recent scrapes
        maxItems: 10, // Maximum items to keep
        lastUpdated: null, // When list was last updated
      },

      // Settings/preferences
      settings: {
        autoScroll: true, // Auto-scroll to results
        compactView: false, // Use compact view in main area
        confirmDelete: true, // Show confirmation for delete
        dragDelay: 1500, // Delay before showing blink effect (ms)
        debugMode: false, // Enable debug logging
      },
    },

    /**
     * State change listeners
     */
    _listeners: {
      "*": [], // Global listeners (listen to all changes)
      scraped: [], // Specific path listeners
      drag: [],
      ui: [],
      groups: [],
      selection: [],
      settings: [],
    },

    /**
     * Initialize state module
     */
    init() {
      // Load saved settings from localStorage if available
      this.loadSettings();

      // Set initial values from global scope if they exist
      this.migrateGlobalState();

      // Set up state persistence
      this.setupPersistence();

      // Initialize from DOM/URL parameters
      this.initFromContext();

      console.log("State module initialized", this.getAll());
    },

    /**
     * Migrate existing global variables to state
     */
    migrateGlobalState() {
      // Migrate existing globals if they exist
      if (
        typeof window.currentScrapedData !== "undefined" &&
        window.currentScrapedData
      ) {
        this.set("scraped.data", window.currentScrapedData);
      }

      if (typeof window.currentScrapedUrl !== "undefined") {
        this.set("scraped.url", window.currentScrapedUrl);
      }

      if (typeof window.draggedText !== "undefined") {
        this.set("drag.content", window.draggedText);
      }

      if (typeof window.holdTimer !== "undefined") {
        this.set("drag.holdTimer", window.holdTimer);
      }

      if (typeof window.isEditorMode !== "undefined") {
        this.set("ui.isEditorMode", window.isEditorMode);
      }

      if (typeof window.stickySidebarOpen !== "undefined") {
        this.set("ui.stickySidebarOpen", window.stickySidebarOpen);
      }

      if (typeof window.bulkDeleteMode !== "undefined") {
        this.set("groups.bulkDeleteMode", window.bulkDeleteMode);
      }

      if (typeof window.groupsMarkedForDeletion !== "undefined") {
        this.set("groups.markedForDeletion", window.groupsMarkedForDeletion);
      }
    },

    /**
     * Initialize state from current context
     */
    initFromContext() {
      // Check if we're in editor mode
      if (window.migrationManager) {
        this.set("ui.isEditorMode", window.migrationManager.isEditor || false);
      }

      // Check for debug mode
      if (window.DEBUG_MIGRATION) {
        this.set("settings.debugMode", true);
      }
    },

    /**
     * Get a value from state using dot notation
     * @param {string} path - Path to value (e.g., 'scraped.data')
     * @param {*} defaultValue - Default value if path doesn't exist
     */
    get(path, defaultValue = undefined) {
      if (!path) return this._state;

      const keys = path.split(".");
      let value = this._state;

      for (const key of keys) {
        if (value && typeof value === "object" && key in value) {
          value = value[key];
        } else {
          return defaultValue;
        }
      }

      return value;
    },

    /**
     * Set a value in state using dot notation
     * @param {string} path - Path to value (e.g., 'scraped.data')
     * @param {*} value - Value to set
     * @param {boolean} silent - If true, don't emit change events
     */
    set(path, value, silent = false) {
      const keys = path.split(".");
      const lastKey = keys.pop();
      let target = this._state;

      // Navigate to the parent object
      for (const key of keys) {
        if (!(key in target) || typeof target[key] !== "object") {
          target[key] = {};
        }
        target = target[key];
      }

      // Store old value for change event
      const oldValue = target[lastKey];

      // Set the new value
      target[lastKey] = value;

      // Emit change events unless silent
      if (!silent) {
        this.emitChange(path, value, oldValue);
      }

      // Update any bound global variables for backwards compatibility
      this.updateGlobals(path, value);

      return value;
    },

    /**
     * Update multiple values at once
     * @param {Object} updates - Object with paths as keys and values
     */
    setBatch(updates, silent = false) {
      const changes = [];

      for (const [path, value] of Object.entries(updates)) {
        const keys = path.split(".");
        const lastKey = keys.pop();
        let target = this._state;

        for (const key of keys) {
          if (!(key in target) || typeof target[key] !== "object") {
            target[key] = {};
          }
          target = target[key];
        }

        const oldValue = target[lastKey];
        target[lastKey] = value;

        changes.push({ path, value, oldValue });
      }

      // Emit batch change event
      if (!silent) {
        this.emitChange("*", changes, null);

        // Also emit individual changes
        changes.forEach((change) => {
          this.emitChange(change.path, change.value, change.oldValue);
        });
      }

      // Update globals
      changes.forEach((change) => {
        this.updateGlobals(change.path, change.value);
      });
    },

    /**
     * Get all state
     */
    getAll() {
      return JSON.parse(JSON.stringify(this._state));
    },

    /**
     * Reset state to initial values
     */
    reset(section = null) {
      if (section && this._state[section]) {
        // Reset specific section
        const initialState = this.getInitialState()[section];
        this.set(section, initialState);
      } else {
        // Reset everything
        this._state = this.getInitialState();
        this.emitChange("*", this._state, null);
      }
    },

    /**
     * Get initial state structure
     */
    getInitialState() {
      return {
        scraped: {
          data: null,
          url: "",
          timestamp: null,
          isDirty: false,
        },
        drag: {
          content: "",
          isActive: false,
          source: null,
          holdTimer: null,
          targetZones: [],
        },
        ui: {
          isEditorMode: false,
          stickySidebarOpen: false,
          activeContext: "main",
          isLoading: false,
          lastMessage: null,
        },
        groups: {
          bulkDeleteMode: false,
          markedForDeletion: new Set(),
          lastDeleted: null,
          totalGroups: 0,
        },
        selection: {
          items: new Set(),
          groups: new Set(),
          lastSelected: null,
          mode: "single",
        },
        recentScrapes: {
          list: [],
          maxItems: 10,
          lastUpdated: null,
        },
        settings: {
          autoScroll: true,
          compactView: false,
          confirmDelete: true,
          dragDelay: 1500,
          debugMode: false,
        },
      };
    },

    /**
     * Subscribe to state changes
     * @param {string} path - Path to watch (use '*' for all changes)
     * @param {Function} callback - Callback function
     * @returns {Function} Unsubscribe function
     */
    subscribe(path, callback) {
      const listenerPath = path || "*";

      if (!this._listeners[listenerPath]) {
        this._listeners[listenerPath] = [];
      }

      this._listeners[listenerPath].push(callback);

      // Return unsubscribe function
      return () => {
        const index = this._listeners[listenerPath].indexOf(callback);
        if (index > -1) {
          this._listeners[listenerPath].splice(index, 1);
        }
      };
    },

    /**
     * Emit change events
     */
    emitChange(path, newValue, oldValue) {
      const event = {
        path,
        value: newValue,
        oldValue,
        timestamp: Date.now(),
      };

      // Notify specific path listeners
      if (this._listeners[path]) {
        this._listeners[path].forEach((callback) => callback(event));
      }

      // Notify parent path listeners (e.g., 'scraped' for 'scraped.data')
      const parentPath = path.split(".")[0];
      if (parentPath !== path && this._listeners[parentPath]) {
        this._listeners[parentPath].forEach((callback) => callback(event));
      }

      // Notify global listeners
      if (this._listeners["*"]) {
        this._listeners["*"].forEach((callback) => callback(event));
      }

      // Log if debug mode
      if (this.get("settings.debugMode")) {
        console.log("[State Change]", event);
      }
    },

    /**
     * Update global variables for backwards compatibility
     */
    updateGlobals(path, value) {
      // Map state paths to global variables
      const globalMappings = {
        "scraped.data": "currentScrapedData",
        "scraped.url": "currentScrapedUrl",
        "drag.content": "draggedText",
        "drag.holdTimer": "holdTimer",
        "ui.isEditorMode": "isEditorMode",
        "ui.stickySidebarOpen": "stickySidebarOpen",
        "groups.bulkDeleteMode": "bulkDeleteMode",
        "groups.markedForDeletion": "groupsMarkedForDeletion",
      };

      if (globalMappings[path]) {
        window[globalMappings[path]] = value;
      }
    },

    /**
     * Computed properties
     */
    computed: {
      /**
       * Get current context based on UI state
       */
      getContext() {
        if (!State.get("ui.isEditorMode")) return "main";
        return State.get("ui.stickySidebarOpen") ? "sticky" : "editor";
      },

      /**
       * Check if we have scraped data
       */
      hasData() {
        const data = State.get("scraped.data");
        return data && data.data && data.data.length > 0;
      },

      /**
       * Check if drag operation is active
       */
      isDragging() {
        return State.get("drag.isActive") && State.get("drag.content");
      },

      /**
       * Get selected count
       */
      getSelectedCount() {
        const groups = State.get("groups.markedForDeletion");
        const items = State.get("selection.items");
        return (groups ? groups.size : 0) + (items ? items.size : 0);
      },

      /**
       * Check if in bulk operation mode
       */
      isBulkMode() {
        return (
          State.get("groups.bulkDeleteMode") ||
          State.get("selection.mode") === "multiple"
        );
      },
    },

    /**
     * Action methods - these perform common state operations
     */
    actions: {
      /**
       * Set scraped data
       */
      setScrapedData(data, url) {
        State.setBatch({
          "scraped.data": data,
          "scraped.url": url,
          "scraped.timestamp": Date.now(),
          "scraped.isDirty": false,
        });
      },

      /**
       * Mark data as dirty (modified)
       */
      markDataDirty() {
        State.set("scraped.isDirty", true);
      },

      /**
       * Start drag operation
       */
      startDrag(content, source) {
        State.setBatch({
          "drag.content": content,
          "drag.source": source,
          "drag.isActive": true,
        });
      },

      /**
       * End drag operation
       */
      endDrag() {
        // Clear hold timer if exists
        const timer = State.get("drag.holdTimer");
        if (timer) {
          clearTimeout(timer);
        }

        State.setBatch({
          "drag.content": "",
          "drag.source": null,
          "drag.isActive": false,
          "drag.holdTimer": null,
          "drag.targetZones": [],
        });
      },

      /**
       * Toggle sticky sidebar
       */
      toggleStickySidebar() {
        const isOpen = State.get("ui.stickySidebarOpen");
        State.set("ui.stickySidebarOpen", !isOpen);
        State.set("ui.activeContext", !isOpen ? "sticky" : "editor");
      },

      /**
       * Enter bulk delete mode
       */
      enterBulkDeleteMode() {
        State.setBatch({
          "groups.bulkDeleteMode": true,
          "groups.markedForDeletion": new Set(),
        });
      },

      /**
       * Exit bulk delete mode
       */
      exitBulkDeleteMode() {
        State.setBatch({
          "groups.bulkDeleteMode": false,
          "groups.markedForDeletion": new Set(),
        });
      },

      /**
       * Toggle group selection
       */
      toggleGroupSelection(groupIndex) {
        const marked = State.get("groups.markedForDeletion");
        const newSet = new Set(marked);

        if (newSet.has(groupIndex)) {
          newSet.delete(groupIndex);
        } else {
          newSet.add(groupIndex);
        }

        State.set("groups.markedForDeletion", newSet);
      },

      /**
       * Add to recent scrapes
       */
      addRecentScrape(url, data) {
        const list = State.get("recentScrapes.list") || [];
        const maxItems = State.get("recentScrapes.maxItems");

        // Remove if already exists
        const filtered = list.filter((item) => item.url !== url);

        // Add to beginning
        filtered.unshift({ url, timestamp: Date.now(), data });

        // Limit size
        if (filtered.length > maxItems) {
          filtered.length = maxItems;
        }

        State.setBatch({
          "recentScrapes.list": filtered,
          "recentScrapes.lastUpdated": Date.now(),
        });
      },
    },

    /**
     * Settings persistence
     */
    loadSettings() {
      try {
        const saved = localStorage.getItem("migrationManagerSettings");
        if (saved) {
          const settings = JSON.parse(saved);
          this.set("settings", { ...this.get("settings"), ...settings }, true);
        }
      } catch (e) {
        console.error("Failed to load settings:", e);
      }
    },

    saveSettings() {
      try {
        const settings = this.get("settings");
        localStorage.setItem(
          "migrationManagerSettings",
          JSON.stringify(settings)
        );
      } catch (e) {
        console.error("Failed to save settings:", e);
      }
    },

    setupPersistence() {
      // Auto-save settings when they change
      this.subscribe("settings", () => {
        this.saveSettings();
      });
    },
  };

  // Create debug helper
  State.debug = {
    /**
     * Log current state
     */
    log(section = null) {
      if (section) {
        console.log(`[State.${section}]`, State.get(section));
      } else {
        console.log("[State]", State.getAll());
      }
    },

    /**
     * Watch state changes
     */
    watch(path = "*") {
      return State.subscribe(path, (event) => {
        console.log(`[State Change: ${event.path}]`, {
          old: event.oldValue,
          new: event.value,
        });
      });
    },

    /**
     * Get state snapshot
     */
    snapshot() {
      return JSON.stringify(State.getAll(), null, 2);
    },

    /**
     * Restore from snapshot
     */
    restore(snapshot) {
      try {
        const data = JSON.parse(snapshot);
        State._state = data;
        State.emitChange("*", data, null);
        console.log("State restored from snapshot");
      } catch (e) {
        console.error("Failed to restore snapshot:", e);
      }
    },
  };

  // Export for use in other modules
  window.State = State;

  // Also attach to jQuery if needed
  if ($) {
    $.migrationState = State;
  }

  // Auto-initialize when DOM is ready
  $(document).ready(function () {
    State.init();
  });
})(window, jQuery);
