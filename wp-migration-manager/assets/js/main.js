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
        // Enable action buttons after rendering
        if (State.computed.getContext() === "main") {
          $("#create-posts, #create-pages, #save-draft").prop("disabled", false);
          
          // Enable upload images button only if there are images
          const hasImages = (data.data || []).some(item => {
            if (item.type === 'image') return true;
            if (item.type === 'group' && item.children) {
              return item.children.some(child => child.type === 'image');
            }
            return false;
          });
          $("#download-images").prop("disabled", !hasImages);
        }
      });

      EventBus.on(EventBus.events.DATA_LOADED, (data) => {
        // Enable action buttons when data is loaded
        if (State.computed.getContext() === "main") {
          $("#create-posts, #create-pages, #save-draft").prop("disabled", false);
          
          // Enable upload images button only if there are images
          const hasImages = (data.data || []).some(item => {
            if (item.type === 'image') return true;
            if (item.type === 'group' && item.children) {
              return item.children.some(child => child.type === 'image');
            }
            return false;
          });
          $("#download-images").prop("disabled", !hasImages);
        }
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
      $("#download-images").on("click", () => this.downloadImages());

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

      // Download/Upload buttons - handled by renderer now
      // Keeping this for backwards compatibility but renderer takes precedence
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

      // Disable action buttons
      if (context === "main") {
        $("#create-posts, #create-pages, #save-draft, #download-images").prop("disabled", true);
        $("#image-download-progress").hide();
      }

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
     * Download images from scraped content (with progress bar)
     */
    downloadImages() {
      const url = State.get("scraped.url");
      const data = State.get("scraped.data");

      if (!url || !data) {
        Messages.error("No scraped content found. Please scrape a website first.");
        return;
      }

      // Show progress UI
      const $progressContainer = $("#image-download-progress");
      const $progressBar = $("#progress-bar");
      const $progressText = $("#progress-text");
      
      $progressContainer.show();
      $progressBar.css("width", "0%");
      $progressText.text("Preparing to upload images...");

      // Disable button during download
      const $downloadBtn = $("#download-images");
      const originalText = $downloadBtn.html();
      $downloadBtn.prop("disabled", true);
      $downloadBtn.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> Uploading...');

      const loadingMsg = Messages.loading("Uploading images...");

      // Process images in batches with progress updates
      this.processImageBatches(url, 0, {}, loadingMsg, $progressBar, $progressText, $downloadBtn, originalText, $progressContainer);
    }

    /**
     * Process images in batches with progress updates
     */
    processImageBatches(url, batchIndex, urlMapping, loadingMsg, $progressBar, $progressText, $downloadBtn, originalText, $progressContainer, accumulatedStats = { downloaded: 0, skipped: 0, failed: 0 }, lastProgress = 0) {
      const batchSize = 5; // Process 5 images at a time
      
      API.request({
        data: {
          action: "migration_manager_download_images",
          url: url,
          batch_index: batchIndex,
          batch_size: batchSize,
          url_mapping: JSON.stringify(urlMapping),
          previous_downloaded: accumulatedStats.downloaded,
          previous_skipped: accumulatedStats.skipped,
          previous_failed: accumulatedStats.failed
        },
      })
        .then((response) => {
          if (!response.success) {
            throw new Error(response.data?.message || "Failed to process images");
          }

          const data = response.data;
          const total = data.total || 0;
          const processed = data.processed || 0;
          const downloaded = data.downloaded || 0;
          const skipped = data.skipped || 0;
          const failed = data.failed || 0;
          const currentImage = data.current_image || null;

          // Update accumulated stats
          accumulatedStats.downloaded = downloaded;
          accumulatedStats.skipped = skipped;
          accumulatedStats.failed = failed;

          // Calculate smooth progress
          const targetProgress = Math.min(Math.round((processed / total) * 100 * 10) / 10, 100);
          
          // Animate progress bar smoothly
          this.animateProgress($progressBar, lastProgress, targetProgress, () => {
            // Update progress text with current image info
            let statusText = '';
            if (currentImage) {
              const statusMessages = {
                'downloading': `Downloading image ${currentImage.index}/${total}: ${currentImage.filename}...`,
                'uploading': `Uploading image ${currentImage.index}/${total}: ${currentImage.filename}...`,
                'completed': `Completed image ${currentImage.index}/${total}: ${currentImage.filename}`,
                'skipped': `Skipped image ${currentImage.index}/${total}: ${currentImage.filename} (${currentImage.message})`,
                'failed': `Failed image ${currentImage.index}/${total}: ${currentImage.filename}`
              };
              statusText = statusMessages[currentImage.status] || `Processing image ${currentImage.index}/${total}...`;
            } else {
              statusText = `Processing batch ${batchIndex + 1}/${Math.ceil(total / batchSize)}...`;
            }
            
            $progressText.html(
              `<strong>${statusText}</strong><br>` +
              `<small>Progress: ${processed}/${total} images | ` +
              `Downloaded: ${downloaded} | Skipped: ${skipped} | Failed: ${failed}</small>`
            );
          });

          // Merge URL mappings
          const newUrlMapping = data.url_mapping || {};
          const mergedMapping = { ...urlMapping, ...newUrlMapping };

          // Check if complete
          if (data.complete) {
            Messages.update(
              loadingMsg,
              data.message || "Images uploaded successfully!",
              "success"
            );

            // Animate to 100%
            this.animateProgress($progressBar, targetProgress, 100, () => {
              $progressText.html(
                `<strong>Complete!</strong> Downloaded: ${downloaded}, ` +
                `Skipped: ${skipped}, Failed: ${failed}`
              );
            });

            // Update state with new data if available
            if (data.updated_data && data.updated_data.data) {
              State.actions.setScrapedData(data.updated_data, url);
              
              // Refresh renderer to show updated URLs
              if (window.Renderer) {
                Renderer.refresh();
              }
            }

            // Re-enable button after a delay
            setTimeout(() => {
              $downloadBtn.prop("disabled", false);
              $downloadBtn.html(originalText);
              $progressContainer.fadeOut(3000);
            }, 2000);
          } else {
            // Process next batch with smooth progress continuation
            setTimeout(() => {
              this.processImageBatches(
                url,
                data.batch_index,
                mergedMapping,
                loadingMsg,
                $progressBar,
                $progressText,
                $downloadBtn,
                originalText,
                $progressContainer,
                accumulatedStats,
                targetProgress
              );
            }, 300); // Small delay between batches
          }
        })
        .catch((error) => {
          Messages.update(
            loadingMsg,
            error.message || "Failed to upload images",
            "error"
          );

          $progressBar.css("width", "0%");
          $progressText.html(`<strong>Error:</strong> ${error.message || "Unknown error"}`);

          // Re-enable button
          $downloadBtn.prop("disabled", false);
          $downloadBtn.html(originalText);
        });
    }

    /**
     * Animate progress bar smoothly
     */
    animateProgress($progressBar, from, to, callback) {
      if (!$progressBar.length) {
        if (callback) callback();
        return;
      }
      
      const duration = 300; // Animation duration in ms
      const startTime = Date.now();
      const startValue = from;
      const endValue = to;
      
      const update = () => {
        const elapsed = Date.now() - startTime;
        const progress = Math.min(elapsed / duration, 1);
        
        // Easing function for smooth animation
        const easeOut = 1 - Math.pow(1 - progress, 3);
        const currentValue = startValue + (endValue - startValue) * easeOut;
        
        $progressBar.css("width", currentValue + "%");
        
        if (progress < 1) {
          requestAnimationFrame(update);
        } else {
          $progressBar.css("width", endValue + "%");
          if (callback) callback();
        }
      };
      
      requestAnimationFrame(update);
    }

    /**
     * Upload a single image to WordPress
     */
    uploadSingleImage(imageUrl, altText, $button) {
      const sourceUrl = State.get("scraped.url");
      
      if (!sourceUrl) {
        Messages.error("No scraped content found. Please scrape a website first.");
        return;
      }

      // Handle both jQuery object and DOM element
      const $btn = typeof jQuery !== 'undefined' && $button instanceof jQuery ? $button : jQuery($button);
      const isDOMElement = $button && !($button instanceof jQuery) && $button.nodeType;
      
      // Disable button and show loading
      const originalText = $btn.length ? $btn.html() : (isDOMElement ? $button.innerHTML : '');
      if ($btn.length) {
        $btn.prop("disabled", true);
        $btn.html('<span class="spinner is-active" style="float: none; margin: 0; width: 14px; height: 14px;"></span> Uploading...');
      } else if (isDOMElement) {
        $button.disabled = true;
        $button.innerHTML = '<span class="spinner is-active" style="float: none; margin: 0; width: 14px; height: 14px;"></span> Uploading...';
      }

      const loadingMsg = Messages.loading(`Uploading image: ${altText || "image"}...`);

      API.uploadSingleImage(imageUrl, altText, sourceUrl)
        .then((response) => {
          Messages.update(
            loadingMsg,
            response.message || "Image uploaded successfully!",
            "success"
          );

          // Update button text to show it's uploaded
          if ($btn.length) {
            $btn.html('<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> Uploaded');
            $btn.addClass("uploaded");
          } else if (isDOMElement) {
            $button.innerHTML = '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> Uploaded';
            $button.classList.add("uploaded");
          }

          // Update the image URL in the scraped data if available
          if (response.wp_url && response.original_url) {
            // Update state and refresh renderer
            const data = State.get("scraped.data");
            if (data && Array.isArray(data)) {
              const updated = this.replaceImageUrlInData(data, response.original_url, response.wp_url);
              State.actions.setScrapedData({ data: updated, url: sourceUrl }, sourceUrl);
              
              if (window.Renderer) {
                Renderer.refresh();
              }
            }
          }

          // Re-enable button after delay
          setTimeout(() => {
            if ($btn.length) {
              $btn.prop("disabled", false);
              $btn.html(originalText);
              $btn.removeClass("uploaded");
            } else if (isDOMElement) {
              $button.disabled = false;
              $button.innerHTML = originalText;
              $button.classList.remove("uploaded");
            }
          }, 3000);
        })
        .catch((error) => {
          Messages.update(
            loadingMsg,
            error.message || "Failed to upload image",
            "error"
          );

          // Re-enable button
          if ($btn.length) {
            $btn.prop("disabled", false);
            $btn.html(originalText);
          } else if (isDOMElement) {
            $button.disabled = false;
            $button.innerHTML = originalText;
          }
        });
    }

    /**
     * Replace image URL in scraped data
     */
    replaceImageUrlInData(data, originalUrl, newUrl) {
      return data.map(item => {
        if (item.type === 'image' && item.url === originalUrl) {
          return { ...item, url: newUrl, wp_uploaded: true };
        }
        if (item.type === 'group' && item.children) {
          return {
            ...item,
            children: item.children.map(child => {
              if (child.type === 'image' && child.url === originalUrl) {
                return { ...child, url: newUrl, wp_uploaded: true };
              }
              return child;
            })
          };
        }
        return item;
      });
    }

    /**
     * Initialize draggable items
     */
    initDraggableItems() {
      $(".item").each(function () {
        const $item = $(this);
        const itemType = $item.find(".item-type").text().toLowerCase();
        const $textElement = $item.find(".item-text, .rendered-element");
        const $linkElement = $item.find("a[href]");
        const $imageElement = $item.find("img");
        const $downloadBtn = $item.find(".download-btn");

        let content = "";
        let isHTML = false;

        // Check if it's an image
        if (itemType === "image" || $imageElement.length > 0) {
          const imageUrl = $imageElement.attr("src") || $downloadBtn.data("url") || "";
          const imageAlt = $imageElement.attr("alt") || $downloadBtn.data("alt") || "image";
          
          if (imageUrl) {
            // Create image HTML for dragging
            content = `<img src="${imageUrl}" alt="${imageAlt}" />`;
            isHTML = true;
          }
        } 
        // Check if it's a link
        else if (itemType === "link" || $linkElement.length) {
          const linkUrl = $linkElement.attr("href") || "";
          const linkText = $linkElement.text() || linkUrl;
          
          if (linkUrl) {
            // Escape HTML in link text to prevent XSS
            const escapedText = linkText.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
            // Create link HTML for dragging
            content = `<a href="${linkUrl.replace(/"/g, "&quot;")}">${escapedText}</a>`;
            isHTML = true;
          } else {
            // Fallback to plain text if no URL
            content = linkText;
          }
        } 
        // Check if it's text
        else if ($textElement.length) {
          content = $textElement.text();
        }

        if (content && DragDrop) {
          DragDrop.makeDraggable(this, content, isHTML);
        }
      });

      $(".copy-btn").each(function () {
        const text = $(this).data("text");
        if (text && DragDrop) {
          DragDrop.makeDraggable(this, text, false);
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
