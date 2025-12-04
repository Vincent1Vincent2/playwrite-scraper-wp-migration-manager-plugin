/**
 * Main Module for Migration Manager
 * Orchestrates all modules and initializes the application
 *
 * @module Main
 */

(function (window, $) {
  "use strict";

  class MigrationManagerApp {
    constructor() {
      this.modules = {};
      this.initialized = false;
      this.config = {
        debug: false,
        modules: {
          core: ["State", "EventBus", "API"],
          ui: ["Renderer", "Messages"],
          features: ["DragDrop", "GroupManager"],
        },
      };
    }

    /**
     * Initialize application
     */
    init() {
      if (this.initialized) {
        console.warn("MigrationManagerApp already initialized");
        return;
      }

      try {
        // Wrap initialization in try-catch
        this.loadConfig();

        if (!this.initCoreModules()) {
          throw new Error("Core modules failed to initialize");
        }

        // Rest of initialization...
      } catch (error) {
        console.error("Initialization failed:", error);
        if (window.Messages) {
          Messages.error("Application failed to initialize: " + error.message);
        }
      }
    }

    /**
     * Load configuration
     */
    loadConfig() {
      // Load from WordPress localized script
      if (window.migrationManager) {
        this.config.isEditor = window.migrationManager.isEditor || false;
        this.config.ajaxUrl = window.migrationManager.ajaxUrl;
        this.config.nonce = window.migrationManager.nonce;
        this.config.strings = window.migrationManager.strings || {};
      }

      // Check debug mode
      if (window.DEBUG_MIGRATION || this.getUrlParam("debug") === "true") {
        this.config.debug = true;
        this.enableDebugMode();
      }
    }

    /**
     * Initialize core modules
     */
    initCoreModules() {
      console.log("📦 Initializing core modules...");

      // State must be first
      if (window.State) {
        State.init();
        this.modules.State = State;
      }

      // EventBus second
      if (window.EventBus) {
        this.modules.EventBus = EventBus;
      }

      // API third
      if (window.API) {
        API.init();
        this.modules.API = API;
      }
    }

    /**
     * Initialize UI modules
     */
    initUIModules() {
      console.log("🎨 Initializing UI modules...");

      // Messages
      if (window.Messages) {
        Messages.init();
        this.modules.Messages = Messages;
      }

      // Renderer
      if (window.Renderer) {
        this.modules.Renderer = Renderer;
      }
    }

    /**
     * Initialize feature modules
     */
    initFeatureModules() {
      console.log("⚡ Initializing feature modules...");

      // DragDrop
      if (window.DragDrop) {
        DragDrop.init();
        this.modules.DragDrop = DragDrop;
      }

      // GroupManager
      if (window.GroupManager) {
        GroupManager.init();
        this.modules.GroupManager = GroupManager;
      }
    }

    /**
     * Setup module communication
     */
    setupCommunication() {
      console.log("🔗 Setting up module communication...");

      // Data flow: API → State → Renderer
      EventBus.on(EventBus.events.DATA_SCRAPED, (data) => {
        State.actions.setScrapedData(
          data,
          data.url || State.get("scraped.url")
        );
        Renderer.render(
          data.data,
          State.get("scraped.url"),
          State.computed.getContext()
        );
      });

      EventBus.on(EventBus.events.DATA_UPDATED, () => {
        Renderer.refresh();
      });

      // Group deletion flow
      EventBus.on(
        EventBus.events.GROUP_DELETED + " " + EventBus.events.GROUP_BULK_DELETE,
        () => {
          Renderer.refresh();
          setTimeout(() => GroupManager.addDeleteButtons(), 100);
        }
      );

      // Drag and drop flow
      EventBus.on(EventBus.events.DRAG_DROP, (data) => {
        Messages.success("Content dropped successfully!");
      });

      // Error handling
      EventBus.on(EventBus.events.ERROR_NETWORK, (error) => {
        Messages.error(error.message || "Network error occurred");
      });

      EventBus.on(EventBus.events.ERROR_SCRAPE, (error) => {
        Messages.error(error.message || "Scraping failed");
      });

      // UI state changes
      EventBus.on(EventBus.events.UI_CONTEXT_CHANGED, (context) => {
        console.log("Context changed to:", context);
      });
    }

    /**
     * Initialize page-specific features
     */
    initPageFeatures() {
      const self = this;

      if (this.config.isEditor) {
        this.initEditorMode();
      } else {
        this.initMainMode();
      }

      // Common initialization
      this.initCommonFeatures();
    }

    /**
     * Initialize main plugin page
     */
    initMainMode() {
      console.log("📄 Initializing main page mode...");

      // Scrape form
      $("#migration-scrape-form").on("submit", (e) => {
        e.preventDefault();
        this.handleScrapeSubmit();
      });

      // Action buttons
      $("#clear-results").on("click", () => this.clearResults());
      $("#export-json").on("click", () => this.exportJSON());
      $("#create-posts").on("click", () => this.createPosts());
      $("#create-pages").on("click", () => this.createPages());
      $("#save-draft").on("click", () => this.saveDraft());

      // Recent scrapes
      $(document).on("click", ".load-scrape", (e) => {
        this.loadScrape($(e.target).data("url"));
      });
    }

    /**
     * Initialize editor mode
     */
    initEditorMode() {
      console.log("✏️ Initializing editor mode...");

      State.set("ui.isEditorMode", true);

      // Editor sidebar
      $("#editor-scrape-btn").on("click", (e) => {
        e.preventDefault();
        const url = $("#editor-scrape-url").val().trim();
        if (this.validateUrl(url)) {
          this.scrapeUrl(url, "editor");
        }
      });

      $("#editor-clear-results").on("click", () => {
        this.clearResults("editor");
      });

      // Sticky sidebar
      $("#toggle-sticky-sidebar").on("click", () => {
        State.actions.toggleStickySidebar();
        this.updateSidebarUI();
      });

      $("#sticky-scrape-btn").on("click", (e) => {
        e.preventDefault();
        const url = $("#sticky-scrape-url").val().trim();
        if (this.validateUrl(url)) {
          this.scrapeUrl(url, "sticky");
        }
      });

      $("#sticky-clear-results").on("click", () => {
        this.clearResults("sticky");
      });

      // Load recent scrapes
      $(document).on("click", ".editor-load-scrape", (e) => {
        const url = $(e.target).data("url");
        const context = State.computed.getContext();
        this.loadScrape(url, context);
      });
    }

    /**
     * Initialize common features
     */
    initCommonFeatures() {
      // Make items draggable after render
      EventBus.on("ui:rendered", () => {
        this.initDraggableItems();
      });

      // Copy buttons
      $(document).on("click", ".copy-btn", (e) => {
        e.preventDefault();
        const text = $(e.target).data("text");
        this.copyToClipboard(text);
      });

      // Download buttons
      $(document).on("click", ".download-btn", (e) => {
        e.preventDefault();
        const url = $(e.target).data("url");
        const filename = $(e.target).data("filename");
        this.downloadFile(url, filename);
      });
    }

    /**
     * Bind global events
     */
    bindGlobalEvents() {
      // Window resize
      $(window).on(
        "resize",
        _.debounce(() => {
          EventBus.emit("window:resize");
        }, 250)
      );

      // Keyboard shortcuts
      $(document).on("keydown", (e) => {
        // Ctrl/Cmd + S to save
        if ((e.ctrlKey || e.metaKey) && e.key === "s") {
          e.preventDefault();
          this.saveDraft();
        }

        // Escape to close messages
        if (e.key === "Escape") {
          Messages.dismissAll();
        }
      });

      // Notice dismiss
      $(document).on("click", ".notice-dismiss", function () {
        $(this).closest(".notice").fadeOut();
      });
    }

    /**
     * Handle scrape submission
     */
    handleScrapeSubmit() {
      const url = $("#scrape-url").val().trim();

      if (!this.validateUrl(url)) {
        Messages.error("Please enter a valid URL");
        return;
      }

      this.scrapeUrl(url, "main");
    }

    /**
     * Scrape URL
     */
    scrapeUrl(url, context = "main") {
      const loadingMsg = Messages.loading("Scraping website...");

      API.scrape(url)
        .then((data) => {
          Messages.update(
            loadingMsg,
            "Scraping completed successfully!",
            "success"
          );

          // Update URL input
          const inputId =
            context === "main"
              ? "#scrape-url"
              : context === "editor"
              ? "#editor-scrape-url"
              : "#sticky-scrape-url";
          $(inputId).val(url);

          // Scroll to results if main page
          if (context === "main") {
            this.scrollToResults();
          }
        })
        .catch((error) => {
          Messages.update(
            loadingMsg,
            error.message || "Scraping failed",
            "error"
          );
        });
    }

    /**
     * Load previous scrape
     */
    loadScrape(url, context = null) {
      context = context || State.computed.getContext();
      const loadingMsg = Messages.loading("Loading previous scrape...");

      API.loadScrape(url)
        .then((data) => {
          Messages.update(
            loadingMsg,
            "Previous scrape loaded successfully!",
            "success"
          );

          if (data.scraped_data) {
            State.actions.setScrapedData(data.scraped_data, url);
            Renderer.render(data.scraped_data.data, url, context);
          }

          // Update URL input
          const inputId =
            context === "main"
              ? "#scrape-url"
              : context === "editor"
              ? "#editor-scrape-url"
              : "#sticky-scrape-url";
          $(inputId).val(url);
        })
        .catch((error) => {
          Messages.update(
            loadingMsg,
            error.message || "Failed to load scrape",
            "error"
          );
        });
    }

    /**
     * Clear results
     */
    clearResults(context = null) {
      context = context || State.computed.getContext();

      // Clear state
      State.reset("scraped");

      // Clear renderer
      Renderer.clear(context);

      // Clear URL input
      const inputId =
        context === "main"
          ? "#scrape-url"
          : context === "editor"
          ? "#editor-scrape-url"
          : "#sticky-scrape-url";
      $(inputId).val("");

      // Hide results container
      const containerId =
        context === "main"
          ? "#migration-results"
          : context === "editor"
          ? "#editor-scraped-content"
          : "#sticky-scraped-content";
      $(containerId).hide();

      Messages.info("Results cleared");
    }

    /**
     * Export JSON
     */
    exportJSON() {
      const data = State.get("scraped.data");

      if (!data) {
        Messages.error("No data to export");
        return;
      }

      const jsonStr = JSON.stringify(data, null, 2);
      const blob = new Blob([jsonStr], { type: "application/json" });
      const url = URL.createObjectURL(blob);

      const a = document.createElement("a");
      a.href = url;
      a.download = `scraped-data-${Date.now()}.json`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);

      Messages.success("JSON exported successfully");
    }

    /**
     * Create posts
     */
    createPosts() {
      Messages.info("Create posts functionality coming soon!");
    }

    /**
     * Create pages
     */
    createPages() {
      Messages.info("Create pages functionality coming soon!");
    }

    /**
     * Save draft
     */
    saveDraft() {
      Messages.info("Save draft functionality coming soon!");
    }

    /**
     * Initialize draggable items
     */
    initDraggableItems() {
      $(".item").each(function () {
        const $item = $(this);
        const content =
          $item.find(".item-text, .rendered-element").text() ||
          $item.find("a[href]").text();

        if (content && DragDrop) {
          DragDrop.makeDraggable(this, content);
        }
      });

      $(".copy-btn").each(function () {
        const text = $(this).data("text");
        if (text && DragDrop) {
          DragDrop.makeDraggable(this, text);
        }
      });
    }

    /**
     * Copy to clipboard
     */
    copyToClipboard(text) {
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard
          .writeText(text)
          .then(() => Messages.success("Copied to clipboard!"))
          .catch(() => this.fallbackCopy(text));
      } else {
        this.fallbackCopy(text);
      }
    }

    /**
     * Fallback copy method
     */
    fallbackCopy(text) {
      const textArea = document.createElement("textarea");
      textArea.value = text;
      textArea.style.position = "fixed";
      textArea.style.left = "-999999px";
      document.body.appendChild(textArea);
      textArea.focus();
      textArea.select();

      try {
        document.execCommand("copy");
        Messages.success("Copied to clipboard!");
      } catch (err) {
        Messages.error("Failed to copy");
      }

      document.body.removeChild(textArea);
    }

    /**
     * Download file
     */
    async downloadFile(url, filename) {
      try {
        Messages.info("Starting download...");

        const response = await fetch(url, { mode: "cors" });
        if (!response.ok) throw new Error("Download failed");

        const blob = await response.blob();
        const downloadUrl = window.URL.createObjectURL(blob);

        const link = document.createElement("a");
        link.href = downloadUrl;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        window.URL.revokeObjectURL(downloadUrl);
        Messages.success("Download completed!");
      } catch (error) {
        // Fallback: open in new tab
        window.open(url, "_blank");
        Messages.info("Opened in new tab - right-click to save");
      }
    }

    /**
     * Update sidebar UI
     */
    updateSidebarUI() {
      const isOpen = State.get("ui.stickySidebarOpen");
      const $sidebar = $("#migration-sticky-sidebar");

      if (isOpen) {
        $sidebar.addClass("open");
      } else {
        $sidebar.removeClass("open");
      }
    }

    /**
     * Scroll to results
     */
    scrollToResults() {
      const $results = $("#migration-results");
      if ($results.length && $results.is(":visible")) {
        $("html, body").animate(
          {
            scrollTop: $results.offset().top - 50,
          },
          500
        );
      }
    }

    /**
     * Validate URL
     */
    validateUrl(url) {
      if (!url) return false;

      try {
        new URL(url);
        return true;
      } catch (_) {
        return false;
      }
    }

    /**
     * Get URL parameter
     */
    getUrlParam(param) {
      const urlParams = new URLSearchParams(window.location.search);
      return urlParams.get(param);
    }

    /**
     * Enable debug mode
     */
    enableDebugMode() {
      console.log("🐛 Debug mode enabled");

      // Enable debug in all modules
      if (State && State.debug) {
        State.debug.watch();
      }

      if (EventBus && EventBus.debug) {
        EventBus.debug.enable();
      }

      // Add debug panel
      this.addDebugPanel();
    }

    /**
     * Add debug panel
     */
    addDebugPanel() {
      const panel = `
        <div id="migration-debug-panel" style="position: fixed; bottom: 20px; right: 20px; background: white; border: 2px solid #333; padding: 10px; z-index: 99999; display: none;">
          <h4>Debug Panel</h4>
          <button onclick="State.debug.log()">Log State</button>
          <button onclick="EventBus.debug.log()">Log Events</button>
          <button onclick="EventBus.debug.history()">Event History</button>
          <button onclick="Renderer.refresh()">Refresh Render</button>
          <button onclick="Messages.dismissAll()">Clear Messages</button>
          <button onclick="$('#migration-debug-panel').hide()">Close</button>
        </div>
      `;

      $("body").append(panel);

      // Toggle with Ctrl+Shift+D
      $(document).on("keydown", (e) => {
        if (e.ctrlKey && e.shiftKey && e.key === "D") {
          $("#migration-debug-panel").toggle();
        }
      });
    }

    /**
     * Get app instance
     */
    static getInstance() {
      if (!window.migrationManagerApp) {
        window.migrationManagerApp = new MigrationManagerApp();
      }
      return window.migrationManagerApp;
    }
  }

  // Export and auto-initialize
  window.MigrationManagerApp = MigrationManagerApp;

  // Initialize when DOM is ready

  const app = MigrationManagerApp.getInstance();
  app.init();
})(window, jQuery);
