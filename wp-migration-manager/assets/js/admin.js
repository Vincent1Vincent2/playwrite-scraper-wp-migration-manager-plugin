/**
 * Migration Manager - Admin JavaScript
 *
 * @package MigrationManager
 */

(function ($) {
  ("use strict");

  // Global variables
  let currentScrapedData = null;
  let draggedText = "";
  let holdTimer = null;
  let isEditorMode = false;
  let stickySidebarOpen = false;
  let groupsMarkedForDeletion = new Set();
  let currentScrapedUrl = "";
  let bulkDeleteMode = false;

  // Debug configuration
  const DEBUG_MIGRATION = true; // Set to false to disable debug logs

  function debugLog(message, data = null) {
    if (DEBUG_MIGRATION) {
      if (data) {
        console.log("[Migration Manager Debug]", message, data);
      } else {
        console.log("[Migration Manager Debug]", message);
      }
    }
  }

  function debugError(message, error = null) {
    if (DEBUG_MIGRATION) {
      if (error) {
        console.error("[Migration Manager Error]", message, error);
      } else {
        console.error("[Migration Manager Error]", message);
      }
    }
  }

  // Add global debug functions for manual testing
  window.migrationManagerDebug = {
    inspect: function () {
      debugLog("=== MIGRATION MANAGER DEBUG INSPECTION ===");
      debugLog("TinyMCE available:", typeof tinymce !== "undefined");
      if (typeof tinymce !== "undefined") {
        debugLog(
          "TinyMCE editors count:",
          tinymce.editors ? tinymce.editors.length : 0
        );
        if (tinymce.editors) {
          tinymce.editors.forEach((editor, index) => {
            debugLog(`Editor ${index}:`, {
              id: editor.id,
              initialized: editor.initialized,
              removed: editor.removed,
              hasIframe: !!editor.iframeElement,
            });
          });
        }
      }

      const dropTargets = $(
        'textarea, input[type="text"], input[type="url"], iframe[id$="_ifr"], .wp-editor-area'
      );
      debugLog("Drop targets found:", dropTargets.length);

      const draggableItems = $(".draggable-item");
      debugLog("Draggable items found:", draggableItems.length);

      debugLog("Current dragged text:", draggedText);
      debugLog("Editor mode:", isEditorMode);
      debugLog("=== END DEBUG INSPECTION ===");
    },

    testDrag: function (text = "Test content") {
      draggedText = text;
      debugLog("Test drag initiated with text:", text);
    },

    enableDebug: function () {
      window.DEBUG_MIGRATION = true;
      debugLog("Debug logging enabled");
    },

    disableDebug: function () {
      window.DEBUG_MIGRATION = false;
      console.log("[Migration Manager Debug] Debug logging disabled");
    },
  };

  /**
   * Initialize delete group functionality
   */
  function initDeleteGroupFunctionality() {
    // Add event listeners for delete buttons
    $(document).on("click", ".delete-group-btn", handleSingleGroupDelete);
    $(document).on("click", ".bulk-delete-btn", toggleBulkDeleteMode);
    $(document).on("click", ".confirm-bulk-delete", handleBulkDelete);
    $(document).on("click", ".cancel-bulk-delete", cancelBulkDeleteMode);
    $(document).on("change", ".group-checkbox", handleGroupCheckboxChange);
  }

  /**
   * Add delete buttons to rendered results
   * Call this after rendering any results
   */
  function addDeleteButtonsToGroups(context = "main") {
    const contextPrefix =
      context === "sticky" ? "sticky-" : context === "editor" ? "editor-" : "";

    // Add individual delete buttons to each group
    $(`.grouped-elements, .item`).each(function (index) {
      const $group = $(this);

      // Skip if already has delete button
      if ($group.find(".delete-group-btn").length > 0) {
        return;
      }

      // Create delete button
      const deleteBtn = $(`
            <button type="button" class="button button-small delete-group-btn" 
                    data-group-index="${index}" data-context="${context}"
                    style="background: #dc3545; border-color: #dc3545; color: white; margin-left: 5px;">
                <span class="dashicons dashicons-trash" style="font-size: 12px; line-height: 1;"></span>
                Delete
            </button>
        `);

      // Create checkbox for bulk delete
      const checkbox = $(`
            <input type="checkbox" class="group-checkbox" 
                   data-group-index="${index}" 
                   style="display: none; margin-right: 5px;">
        `);

      // Find the best place to insert the buttons
      let $actionContainer = $group.find(".action-buttons").first();
      if ($actionContainer.length === 0) {
        $actionContainer = $group.find(".sidebar-section-header").first();
        if ($actionContainer.length === 0) {
          // Create action container if it doesn't exist
          $actionContainer = $(
            '<div class="action-buttons" style="margin-top: 8px;"></div>'
          );
          $group.append($actionContainer);
        }
      }

      $actionContainer.prepend(checkbox);
      $actionContainer.append(deleteBtn);
    });

    // Add bulk delete controls if not present
    addBulkDeleteControls(context);
  }

  /**
   * Add bulk delete controls to the interface
   */
  function addBulkDeleteControls(context = "main") {
    const contextPrefix =
      context === "sticky" ? "sticky-" : context === "editor" ? "editor-" : "";
    const containerId = `${contextPrefix}content-preview`;
    const $container = $(`#${containerId}`);

    if (
      $container.length === 0 ||
      $container.find(".bulk-delete-controls").length > 0
    ) {
      return;
    }

    const bulkControls = $(`
        <div class="bulk-delete-controls" style="margin-bottom: 15px; padding: 10px; background: #f8f9fa; border-radius: 5px; border-left: 4px solid #ffc107;">
            <div class="bulk-delete-toggle">
                <button type="button" class="button bulk-delete-btn" data-context="${context}">
                    <span class="dashicons dashicons-admin-generic"></span>
                    Enable Bulk Delete
                </button>
                <span class="bulk-help-text" style="font-size: 12px; color: #666; margin-left: 10px;">
                    Select multiple groups to delete at once
                </span>
            </div>
            <div class="bulk-delete-actions" style="display: none; margin-top: 10px;">
                <button type="button" class="button button-primary confirm-bulk-delete" data-context="${context}">
                    <span class="dashicons dashicons-trash"></span>
                    Delete Selected (<span class="selected-count">0</span>)
                </button>
                <button type="button" class="button cancel-bulk-delete" data-context="${context}">
                    Cancel
                </button>
                <div style="margin-top: 5px; font-size: 12px; color: #d63384;">
                    <strong>Warning:</strong> This action cannot be undone!
                </div>
            </div>
        </div>
    `);

    $container.prepend(bulkControls);
  }

  /**
   * Handle single group deletion
   */
  function handleSingleGroupDelete(e) {
    e.preventDefault();

    const $button = $(e.target).closest(".delete-group-btn");
    const groupIndex = parseInt($button.data("group-index"));
    const context = $button.data("context") || "main";

    if (isNaN(groupIndex) || groupIndex < 0) {
      showMessage("error", "Invalid group index", context);
      return;
    }

    // Confirm deletion
    if (
      !confirm(
        "Are you sure you want to delete this group? This action cannot be undone."
      )
    ) {
      return;
    }

    // Show loading state
    $button
      .prop("disabled", true)
      .html(
        '<span class="spinner is-active" style="float: none; margin: 0;"></span> Deleting...'
      );

    // Make AJAX request
    $.ajax({
      url: migrationManager.ajaxUrl,
      type: "POST",
      data: {
        action: "migration_manager_delete_group",
        url: currentScrapedUrl,
        group_index: groupIndex,
        nonce: migrationManager.nonce,
      },
      success: function (response) {
        if (response.success) {
          // Update current scraped data
          currentScrapedData = response.data.updated_data;

          // Refresh the display
          refreshDisplayAfterDeletion(context, response.data.updated_data);

          // Show success message
          showMessage("success", response.data.message, context);

          // Log success
          console.log("Group deleted successfully:", response.data);
        } else {
          showMessage(
            "error",
            response.data.message || "Failed to delete group",
            context
          );

          // Reset button
          $button
            .prop("disabled", false)
            .html('<span class="dashicons dashicons-trash"></span> Delete');
        }
      },
      error: function (xhr, status, error) {
        console.error("Delete group error:", { xhr, status, error });
        showMessage(
          "error",
          "Network error occurred while deleting group",
          context
        );

        // Reset button
        $button
          .prop("disabled", false)
          .html('<span class="dashicons dashicons-trash"></span> Delete');
      },
    });
  }

  /**
   * Toggle bulk delete mode
   */
  function toggleBulkDeleteMode(e) {
    e.preventDefault();

    const context =
      $(e.target).closest(".bulk-delete-btn").data("context") || "main";
    bulkDeleteMode = !bulkDeleteMode;
    groupsMarkedForDeletion.clear();

    if (bulkDeleteMode) {
      // Show checkboxes and bulk actions
      $(".group-checkbox").show();
      $(".bulk-delete-toggle").hide();
      $(".bulk-delete-actions").show();

      // Update UI to show we're in bulk mode
      $(".bulk-delete-controls").css("border-left-color", "#dc3545");
    } else {
      cancelBulkDeleteMode();
    }
  }

  /**
   * Cancel bulk delete mode
   */
  function cancelBulkDeleteMode() {
    bulkDeleteMode = false;
    groupsMarkedForDeletion.clear();

    // Hide checkboxes and bulk actions
    $(".group-checkbox").hide().prop("checked", false);
    $(".bulk-delete-toggle").show();
    $(".bulk-delete-actions").hide();

    // Reset UI
    $(".bulk-delete-controls").css("border-left-color", "#ffc107");
    updateSelectedCount();
  }

  /**
   * Handle checkbox change for group selection
   */
  function handleGroupCheckboxChange(e) {
    const $checkbox = $(e.target);
    const groupIndex = parseInt($checkbox.data("group-index"));

    if ($checkbox.is(":checked")) {
      groupsMarkedForDeletion.add(groupIndex);
    } else {
      groupsMarkedForDeletion.delete(groupIndex);
    }

    updateSelectedCount();
  }

  /**
   * Update selected count display
   */
  function updateSelectedCount() {
    $(".selected-count").text(groupsMarkedForDeletion.size);

    // Enable/disable delete button based on selection
    $(".confirm-bulk-delete").prop(
      "disabled",
      groupsMarkedForDeletion.size === 0
    );
  }

  /**
   * Handle bulk deletion
   */
  function handleBulkDelete(e) {
    e.preventDefault();

    const context =
      $(e.target).closest(".confirm-bulk-delete").data("context") || "main";

    if (groupsMarkedForDeletion.size === 0) {
      showMessage("error", "No groups selected for deletion", context);
      return;
    }

    // Confirm bulk deletion
    const count = groupsMarkedForDeletion.size;
    if (
      !confirm(
        `Are you sure you want to delete ${count} selected groups? This action cannot be undone.`
      )
    ) {
      return;
    }

    const $button = $(e.target);
    $button
      .prop("disabled", true)
      .html(
        '<span class="spinner is-active" style="float: none; margin: 0;"></span> Deleting...'
      );

    // Convert Set to Array for AJAX
    const indicesToDelete = Array.from(groupsMarkedForDeletion);

    // Make AJAX request
    $.ajax({
      url: migrationManager.ajaxUrl,
      type: "POST",
      data: {
        action: "migration_manager_delete_multiple_groups",
        url: currentScrapedUrl,
        group_indices: indicesToDelete,
        nonce: migrationManager.nonce,
      },
      success: function (response) {
        if (response.success) {
          // Update current scraped data
          currentScrapedData = response.data.updated_data;

          // Refresh the display
          refreshDisplayAfterDeletion(context, response.data.updated_data);

          // Show success message
          showMessage("success", response.data.message, context);

          // Exit bulk delete mode
          cancelBulkDeleteMode();

          // Log success
          console.log("Bulk deletion successful:", response.data);
        } else {
          showMessage(
            "error",
            response.data.message || "Failed to delete selected groups",
            context
          );

          // Reset button
          $button
            .prop("disabled", false)
            .html(
              '<span class="dashicons dashicons-trash"></span> Delete Selected (<span class="selected-count">' +
                count +
                "</span>)"
            );
        }
      },
      error: function (xhr, status, error) {
        console.error("Bulk delete error:", { xhr, status, error });
        showMessage(
          "error",
          "Network error occurred while deleting groups",
          context
        );

        // Reset button
        $button
          .prop("disabled", false)
          .html(
            '<span class="dashicons dashicons-trash"></span> Delete Selected (<span class="selected-count">' +
              count +
              "</span>)"
          );
      },
    });
  }

  /**
   * Refresh display after deletion
   */
  function refreshDisplayAfterDeletion(context, updatedData) {
    if (context === "main") {
      displayResults(updatedData, currentScrapedUrl);
    } else {
      displayEditorResults(updatedData, currentScrapedUrl, context);
    }

    // Re-add delete functionality to new elements
    setTimeout(() => {
      addDeleteButtonsToGroups(context);
    }, 100);
  }

  /**
   * Show message with context awareness
   */
  function showMessage(type, message, context = "main") {
    if (context === "main") {
      // Use existing showMessage function
      if (typeof showMessage === "function") {
        showMessage(type, message);
      }
    } else {
      // Use editor message function
      if (typeof showEditorMessage === "function") {
        showEditorMessage(context, type, message);
      }
    }
  }

  /**
   * Set current scraped URL (call this when scraping or loading)
   */
  function setCurrentScrapedUrl(url) {
    currentScrapedUrl = url;
  }

  $(document).ready(function () {
    debugLog("Migration Manager initializing...");

    // Check if we're in editor mode
    isEditorMode = migrationManager.isEditor || false;
    debugLog("Editor mode detected:", isEditorMode);

    initializeMigrationManager();
    initializeDragAndDrop();
    initDeleteGroupFunctionality();

    if (isEditorMode) {
      debugLog("Initializing editor sidebar...");
      initializeEditorSidebar();
      initializeStickySidebar();
    }

    if (
      typeof window.originalDisplayResults === "undefined" &&
      typeof displayResults !== "undefined"
    ) {
      window.originalDisplayResults = displayResults;
      displayResults = function (data, sourceUrl) {
        window.originalDisplayResults(data, sourceUrl);
        setCurrentScrapedUrl(sourceUrl);
        setTimeout(() => addDeleteButtonsToGroups("main"), 100);
      };
    }

    if (
      typeof window.originalDisplayEditorResults === "undefined" &&
      typeof displayEditorResults !== "undefined"
    ) {
      window.originalDisplayEditorResults = displayEditorResults;
      displayEditorResults = function (data, sourceUrl, context) {
        window.originalDisplayEditorResults(data, sourceUrl, context);
        setCurrentScrapedUrl(sourceUrl);
        setTimeout(() => addDeleteButtonsToGroups(context), 100);
      };
    }

    debugLog("Migration Manager initialization complete");
  });

  /**
   * Initialize the Migration Manager
   */
  function initializeMigrationManager() {
    // Main plugin page bindings
    $("#migration-scrape-form").on("submit", handleScrapeSubmission);
    $("#clear-results").on("click", clearResults);
    $("#export-json").on("click", exportJSON);
    $("#create-posts").on("click", createPosts);
    $("#create-pages").on("click", createPages);
    $("#save-draft").on("click", saveDraft);
    $("#preview-mode").on("click", togglePreviewMode);
    $("#download-images").on("click", handleDownloadImages);

    // Recent scrapes load buttons (works for both main page and editor)
    $(document).on(
      "click",
      ".load-scrape, .editor-load-scrape",
      handleLoadScrape
    );

    // Notice dismiss buttons
    $(document).on("click", ".notice-dismiss", handleNoticeDismiss);

    console.log("Migration Manager initialized", { isEditorMode });
  }

  /**
   * Initialize editor sidebar functionality
   */
  function initializeEditorSidebar() {
    // Editor sidebar form submission
    $("#editor-scrape-btn").on("click", function (e) {
      e.preventDefault();
      const url = $("#editor-scrape-url").val().trim();
      if (url && isValidUrl(url)) {
        startEditorScraping(url, "editor");
      } else {
        showEditorMessage(
          "editor",
          "error",
          migrationManager.strings.invalidUrl
        );
      }
    });

    // Clear results button
    $("#editor-clear-results").on("click", function () {
      clearEditorResults("editor");
    });

    console.log("Editor sidebar initialized");
  }

  /**
   * Initialize sticky sidebar functionality
   */
  function initializeStickySidebar() {
    // Toggle sticky sidebar
    $("#toggle-sticky-sidebar").on("click", function () {
      toggleStickySidebar();
    });

    // Close sticky sidebar
    $("#close-sticky-sidebar").on("click", function () {
      closeStickySidebar();
    });

    // Sticky sidebar form submission
    $("#sticky-scrape-btn").on("click", function (e) {
      e.preventDefault();
      const url = $("#sticky-scrape-url").val().trim();
      if (url && isValidUrl(url)) {
        startEditorScraping(url, "sticky");
      } else {
        showEditorMessage(
          "sticky",
          "error",
          migrationManager.strings.invalidUrl
        );
      }
    });

    // Clear results button
    $("#sticky-clear-results").on("click", function () {
      clearEditorResults("sticky");
    });

    // Show sticky sidebar initially if not in meta box
    if (!$("#migration-manager-editor-sidebar").length) {
      $("#migration-sticky-sidebar").show();
    }

    console.log("Sticky sidebar initialized");
  }

  /**
   * Toggle sticky sidebar
   */
  function toggleStickySidebar() {
    const $sidebar = $("#migration-sticky-sidebar");

    if (stickySidebarOpen) {
      $sidebar.removeClass("open");
      stickySidebarOpen = false;
    } else {
      $sidebar.addClass("open");
      stickySidebarOpen = true;
    }
  }

  /**
   * Close sticky sidebar
   */
  function closeStickySidebar() {
    $("#migration-sticky-sidebar").removeClass("open");
    stickySidebarOpen = false;
  }

  /**
   * Start scraping in editor mode
   */
  function startEditorScraping(url, context) {
    // Show loading state
    showEditorLoadingState(context, true);
    showEditorMessage(context, "loading", migrationManager.strings.scraping);

    // Prepare AJAX data
    const ajaxData = {
      action: "migration_manager_scrape",
      url: url,
      nonce: migrationManager.nonce,
    };

    // Make AJAX request
    $.ajax({
      url: migrationManager.ajaxUrl,
      type: "POST",
      data: ajaxData,
      timeout: 60000,
      success: function (response) {
        handleEditorScrapeSuccess(response, url, context);
      },
      error: function (xhr, status, error) {
        handleEditorScrapeError(xhr, status, error, context);
      },
      complete: function () {
        showEditorLoadingState(context, false);
      },
    });
  }

  /**
   * Handle successful scrape in editor
   */
  function handleEditorScrapeSuccess(response, originalUrl, context) {
    if (response.success && response.data) {
      currentScrapedData = response.data;
      displayEditorResults(response.data, originalUrl, context);
      showEditorMessage(context, "success", migrationManager.strings.success);

      // Refresh recent scrapes
      refreshRecentScrapes(context);
    } else {
      const errorMessage =
        response.data && response.data.message
          ? response.data.message
          : migrationManager.strings.error;
      handleEditorScrapeError(null, null, errorMessage, context);
    }
  }

  /**
   * Handle scrape error in editor
   */
  function handleEditorScrapeError(xhr, status, error, context) {
    console.error("Editor scrape error:", { xhr, status, error });

    let errorMessage = migrationManager.strings.error;

    if (status === "timeout") {
      errorMessage =
        "Request timed out. The website might be taking too long to respond.";
    } else if (
      xhr &&
      xhr.responseJSON &&
      xhr.responseJSON.data &&
      xhr.responseJSON.data.message
    ) {
      errorMessage = xhr.responseJSON.data.message;
    } else if (error && typeof error === "string") {
      errorMessage = error;
    }

    showEditorMessage(context, "error", errorMessage);
    hideEditorResults(context);
  }

  /**
   * Display results in editor sidebar
   */
  function displayEditorResults(data, sourceUrl, context) {
    const contentId =
      context === "sticky"
        ? "sticky-scraped-content"
        : "editor-scraped-content";
    const statsId =
      context === "sticky" ? "sticky-content-stats" : "editor-content-stats";
    const previewId =
      context === "sticky"
        ? "sticky-content-preview"
        : "editor-content-preview";

    // Show results section
    $("#" + contentId).show();

    // Render results for sidebar
    renderEditorResults(
      data.data || [],
      sourceUrl,
      context,
      statsId,
      previewId
    );
  }

  /**
   * Render results specifically for editor sidebar
   */
  function renderEditorResults(data, url, context, statsId, previewId) {
    if (data.length === 0) {
      $("#" + previewId).html("<p>" + migrationManager.strings.noData + "</p>");
      return;
    }

    // Calculate stats
    const stats = {
      total: data.length,
      text: data.filter((item) => item.type === "text").length,
      links: data.filter((item) => item.type === "link").length,
      images: data.filter((item) => item.type === "image").length,
      videos: data.filter((item) => item.type === "video").length,
      groups: data.filter((item) => item.type === "group").length,
    };

    // Display compact stats for sidebar
    let statsHtml = `
      <div class="stat-item">
        <div class="stat-number">${stats.total}</div>
        <div class="stat-label">Total</div>
      </div>
      <div class="stat-item">
        <div class="stat-number">${stats.text}</div>
        <div class="stat-label">Text</div>
      </div>
      <div class="stat-item">
        <div class="stat-number">${stats.links}</div>
        <div class="stat-label">Links</div>
      </div>
      <div class="stat-item">
        <div class="stat-number">${stats.images}</div>
        <div class="stat-label">Images</div>
      </div>
      <div class="stat-item">
        <div class="stat-number">${stats.videos}</div>
        <div class="stat-label">Videos</div>
      </div>
      <div class="stat-item">
        <div class="stat-number">${stats.groups}</div>
        <div class="stat-label">Groups</div>
      </div>
    `;

    $("#" + statsId).html(statsHtml);

    // Render items for sidebar (more compact)
    let html = "";
    data.forEach((item, index) => {
      if (item.wrapper === true && item.children && item.children.length > 0) {
        html += renderGroupedElementsForSidebar(item, index);
      } else {
        html += renderItemForSidebar(item, index);
      }
    });

    $("#" + previewId).html(html);
    addSidebarButtonEventListeners();
  }

  /**
   * Render grouped elements for sidebar (compact version)
   */
  function renderGroupedElementsForSidebar(wrapperItem, index) {
    let groupContext = "";

    if (wrapperItem.type === "link") {
      groupContext = `<div class="group-context" style="background: #ffc107; color: #333; padding: 8px; font-size: 11px; border-radius: 4px; margin-bottom: 8px;">
        <strong>Link Group:</strong> <a href="${
          wrapperItem.url
        }" target="_blank" style="color: #333;">${escapeHtml(
        wrapperItem.text
      )}</a>
      </div>`;
    } else {
      groupContext = `<div class="group-context" style="background: #ffc107; color: #333; padding: 8px; font-size: 11px; border-radius: 4px; margin-bottom: 8px;">
        <strong>Group:</strong> ${wrapperItem.element || wrapperItem.type}
      </div>`;
    }

    const childrenHtml = wrapperItem.children
      .map((child, childIndex) => {
        return renderItemForSidebar(child, `${index}-${childIndex}`, true);
      })
      .join("");

    return `
      <div class="grouped-elements" style="margin-bottom: 15px; background: #fff8e1; border-radius: 6px; border: 1px solid #ffc107; padding: 8px;">
        ${groupContext}
        ${childrenHtml}
      </div>
    `;
  }

  /**
   * Render item for sidebar (compact version)
   */
  function renderItemForSidebar(item, index, isGroupedChild = false) {
    let content = "";
    let actionButtons = "";
    let meta = "";

    const itemClass = isGroupedChild ? "item grouped-child" : "item";

    switch (item.type) {
      case "text":
        content = `<div class="item-text" style="font-size: 12px; margin-bottom: 6px; padding: 6px; background: white; border-radius: 4px;">${escapeHtml(
          item.text
        )}</div>`;
        actionButtons = `<button class="button button-small copy-btn" data-text="${escapeHtml(
          item.text
        )}">Copy</button>`;
        meta = `${item.element}`;
        break;

      case "link":
        content = `<div class="item-link" style="font-size: 12px; margin-bottom: 6px;">
          <a href="${
            item.url
          }" target="_blank" style="color: #667eea;">${escapeHtml(
          item.text
        )}</a>
        </div>`;
        actionButtons = `
          <button class="button button-small copy-btn" data-text="${escapeHtml(
            item.text
          )}">Text</button>
          <button class="button button-small copy-btn" data-text="${escapeHtml(
            item.url
          )}">URL</button>
        `;
        break;

      case "image":
        content = `<div class="item-image" style="font-size: 12px; margin-bottom: 6px;">
          <img src="${item.url}" alt="${
          item.alt || ""
        }" style="max-width: 100%; height: auto; max-height: 60px; border-radius: 4px;" />
        </div>`;
        actionButtons = `
          <button class="button button-small download-btn" data-url="${escapeHtml(
            item.url
          )}" data-filename="${escapeHtml(
            item.alt || "image"
          )}" data-alt="${escapeHtml(
            item.alt || ""
          )}">Upload</button>
          <button class="button button-small copy-btn" data-text="${escapeHtml(
            item.url
          )}">Copy</button>
        `;
        break;

      case "video":
        content = `<div class="item-video" style="font-size: 12px; margin-bottom: 6px; padding: 6px; background: #f0f0f0; border-radius: 4px;">
          📹 Video: ${item.url.substring(0, 50)}...
        </div>`;
        actionButtons = `<button class="button button-small copy-btn" data-text="${escapeHtml(
          item.url
        )}">Copy URL</button>`;
        break;

      default:
        content = `<div style="font-size: 12px;">Unknown: ${item.type}</div>`;
    }

    return `
      <div class="${itemClass}" style="margin-bottom: 10px; padding: 8px; background: ${
      isGroupedChild ? "#f8f9fa" : "#white"
    }; border: 1px solid #e1e8ed; border-radius: 4px;">
        <div class="item-type" style="font-size: 10px; color: #667eea; font-weight: bold; margin-bottom: 4px;">${item.type.toUpperCase()}</div>
        ${content}
        <div class="action-buttons" style="margin-top: 6px;">
          ${actionButtons}
        </div>
        ${
          meta
            ? `<div class="item-meta" style="font-size: 10px; color: #888; margin-top: 4px;">${meta}</div>`
            : ""
        }
      </div>
    `;
  }

  /**
   * Add event listeners for sidebar buttons
   */
  function addSidebarButtonEventListeners() {
    // Remove existing listeners to prevent duplicates
    $(".copy-btn").off("click.sidebar");

    // Add new listeners
    $(".copy-btn").on("click.sidebar", function () {
      const text = this.getAttribute("data-text");
      copyText(text);
    });

    // Add drag functionality
    addDragFunctionalityToSidebar();
  }

  /**
   * Add drag functionality to sidebar items
   */
  function addDragFunctionalityToSidebar() {
    // Make all sidebar items draggable
    $(".item").each(function () {
      const $item = $(this);
      const itemType = $item.find(".item-type").text().toLowerCase();
      const $textElement = $item.find(".item-text, .item-link");
      const $linkElement = $item.find("a[href]");
      const $imageElement = $item.find("img");
      const $downloadBtn = $item.find(".download-btn");

      let dragContent = "";
      let isHTML = false;

      // Check if it's an image
      if (itemType === "image" || $imageElement.length > 0) {
        const imageUrl = $imageElement.attr("src") || $downloadBtn.data("url") || "";
        const imageAlt = $imageElement.attr("alt") || $downloadBtn.data("alt") || "image";
        
        if (imageUrl) {
          // Create image HTML for dragging
          dragContent = `<img src="${imageUrl}" alt="${imageAlt}" />`;
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
          dragContent = `<a href="${linkUrl.replace(/"/g, "&quot;")}">${escapedText}</a>`;
          isHTML = true;
        } else {
          // Fallback to plain text if no URL
          dragContent = linkText;
        }
      } 
      // Check if it's text
      else if ($textElement.length) {
        dragContent = $textElement.text();
      }

      if (dragContent) {
        makeDraggable($item[0], dragContent, isHTML);
      }
    });

    // Make copy buttons draggable with their specific content
    $(".copy-btn").each(function () {
      const text = $(this).data("text");
      if (text) {
        makeDraggable(this, text, false);
      }
    });
  }

  /**
   * Show loading state for editor
   */
  function showEditorLoadingState(context, loading) {
    const buttonId =
      context === "sticky" ? "#sticky-scrape-btn" : "#editor-scrape-btn";
    const spinnerId =
      context === "sticky"
        ? "#sticky-scrape-spinner"
        : "#editor-scrape-spinner";

    const $button = $(buttonId);
    const $spinner = $(spinnerId);

    if (loading) {
      $button.prop("disabled", true);
      $spinner.addClass("is-active");
    } else {
      $button.prop("disabled", false);
      $spinner.removeClass("is-active");
    }
  }

  /**
   * Show message in editor sidebar
   */
  function showEditorMessage(context, type, message) {
    const messagesId =
      context === "sticky"
        ? "#sticky-migration-messages"
        : "#editor-migration-messages";
    const $messagesContainer = $(messagesId);

    // Clear existing messages
    $messagesContainer.empty();

    let messageHtml = "";

    switch (type) {
      case "success":
        messageHtml = `<div class="notice notice-success"><p>${escapeHtml(
          message
        )}</p></div>`;
        break;
      case "error":
        messageHtml = `<div class="notice notice-error"><p>${escapeHtml(
          message
        )}</p></div>`;
        break;
      case "loading":
        messageHtml = `<div class="notice notice-info"><p>${escapeHtml(
          message
        )}</p></div>`;
        break;
      default:
        messageHtml = `<div class="notice notice-info"><p>${escapeHtml(
          message
        )}</p></div>`;
    }

    $messagesContainer.html(messageHtml);

    // Auto-dismiss success messages
    if (type === "success") {
      setTimeout(function () {
        $messagesContainer.find(".notice-success").fadeOut();
      }, 3000);
    }
  }

  /**
   * Clear editor results
   */
  function clearEditorResults(context) {
    const contentId =
      context === "sticky"
        ? "#sticky-scraped-content"
        : "#editor-scraped-content";
    const urlId =
      context === "sticky" ? "#sticky-scrape-url" : "#editor-scrape-url";
    const messagesId =
      context === "sticky"
        ? "#sticky-migration-messages"
        : "#editor-migration-messages";

    $(contentId).hide();
    $(urlId).val("");
    $(messagesId).empty();

    if (context === "editor") {
      currentScrapedData = null;
    }
  }

  /**
   * Hide editor results
   */
  function hideEditorResults(context) {
    const contentId =
      context === "sticky"
        ? "#sticky-scraped-content"
        : "#editor-scraped-content";
    $(contentId).hide();
  }

  /**
   * Refresh recent scrapes in sidebar
   */
  function refreshRecentScrapes(context) {
    const listId =
      context === "sticky" ? "#sticky-recent-list" : "#editor-recent-list";

    // Make AJAX call to get updated recent scrapes
    $.ajax({
      url: migrationManager.ajaxUrl,
      type: "POST",
      data: {
        action: "migration_manager_get_recent_scrapes",
        nonce: migrationManager.nonce,
      },
      success: function (response) {
        if (response.success && response.data) {
          $(listId).html(response.data);
        }
      },
    });
  }

  /**
   * Initialize drag and drop functionality
   */
  function initializeDragAndDrop() {
    debugLog("Initializing drag and drop...");

    // Main container selectors - these will handle the drag events
    const containerTargets = [".custom"].join(", ");

    // Specific drop targets for content insertion
    const dropTargets = [
      // Standard form inputs
      "textarea",
      'input[type="text"]',
      'input[type="url"]',
      'input[type="email"]',
      'input[type="search"]',

      // WordPress core editors
      ".wp-editor-area",
      "#content",
      "#title",
      "#excerpt",

      // TinyMCE containers and iframes
      'iframe[id$="_ifr"]',

      // Gutenberg editor
      ".wp-block-post-title",
      ".wp-block-post-content",
      ".wp-block-post-excerpt",
      ".block-editor-rich-text__editable",
      ".block-editor-plain-text",

      // ACF fields

      ".acf-input input",
      ".acf-input textarea",
      ".acf-field-textarea textarea",
      ".acf-field-text input",
      ".acf-field-wysiwyg iframe",
      ".acf-field-wysiwyg .wp-editor-area",
      ".acf-field-wysiwyg .mce-edit-area",

      // Meta boxes
      ".meta-box-sortables input",
      ".meta-box-sortables textarea",

      // Custom fields
      ".custom-field input",
      ".custom-field textarea",

      // Contenteditable elements
      '[contenteditable="true"]',
      '[contenteditable=""]',

      // Code editors
      ".CodeMirror",
      ".ace_editor",

      // Other common selectors
      ".quicktags-toolbar + textarea",
    ];

    debugLog(
      "Container targets:",
      containerTargets.split(", ").length + " selectors"
    );
    debugLog("Drop targets:", dropTargets.length + " selectors");

    // LOG ALL MATCHING ELEMENTS AT STARTUP
    debugLog("=== LOGGING ALL MATCHING ELEMENTS ===");

    const $containerElements = $(containerTargets);
    debugLog("Container elements found:", $containerElements.length);
    $containerElements.each(function (index) {
      debugLog("Container " + (index + 1) + ":", {
        tag: this.tagName,
        id: this.id,
        className: this.className,
        visible: $(this).is(":visible"),
        width: $(this).width(),
        height: $(this).height(),
        position: $(this).offset(),
      });
    });

    const standaloneTargets = dropTargets.join(", ");
    const $standaloneElements = $(standaloneTargets).filter(function () {
      return $(this).closest(containerTargets).length === 0;
    });
    debugLog("Standalone elements found:", $standaloneElements.length);
    $standaloneElements.each(function (index) {
      debugLog("Standalone " + (index + 1) + ":", {
        tag: this.tagName,
        id: this.id,
        className: this.className,
        visible: $(this).is(":visible"),
        width: $(this).width(),
        height: $(this).height(),
        position: $(this).offset(),
      });
    });

    const $allDropElements = $(dropTargets.join(", "));
    debugLog("All drop target elements found:", $allDropElements.length);
    $allDropElements.each(function (index) {
      debugLog("Drop target " + (index + 1) + ":", {
        tag: this.tagName,
        id: this.id,
        className: this.className,
        visible: $(this).is(":visible"),
        width: $(this).width(),
        height: $(this).height(),
        position: $(this).offset(),
        insideContainer:
          $(this).closest(containerTargets).length > 0
            ? $(this).closest(containerTargets)[0].id
            : "none",
      });
    });

    debugLog("=== END ELEMENT LOGGING ===");

    // CONTAINER DRAG EVENTS - Handle drag over large areas
    $(document).on("dragenter", containerTargets, function (e) {
      e.preventDefault();
      const $container = $(this);

      debugLog("Container drag enter detected on:", {
        tag: this.tagName,
        id: this.id,
        className: this.className,
        text:
          draggedText.substring(0, 50) + (draggedText.length > 50 ? "..." : ""),
        elementDimensions: {
          width: $(this).width(),
          height: $(this).height(),
          visible: $(this).is(":visible"),
        },
      });

      // Add visual feedback to the entire container
      $container.addClass("drag-over");
      addFieldTypeClass($container);

      clearTimeout(holdTimer);
      holdTimer = setTimeout(() => {
        debugLog(
          "Container hold timeout reached, adding blinking to:",
          $container[0].id || $container[0].tagName
        );

        // Add blinking to the container and ALL relevant child elements
        $container.addClass("blinking");

        // For TinyMCE containers, add blinking to all TinyMCE elements
        const $childElements = $container.find(
          '.mce-tinymce, .mce-edit-area, .mce-container, iframe[id$="_ifr"], .wp-editor-area, textarea'
        );
        debugLog(
          "Adding blinking to child elements:",
          $childElements.length + " elements"
        );
        $childElements.addClass("blinking");
        $childElements.each(function () {
          debugLog("Blinking added to child:", {
            tag: this.tagName,
            id: this.id,
            className: this.className,
          });
        });

        // For ACF containers
        $container
          .find(".acf-input input, .acf-input textarea, .acf-field-wysiwyg")
          .addClass("blinking");
      }, 1500);
    });

    $(document).on("dragleave", containerTargets, function (e) {
      const $container = $(this);

      debugLog("Container drag leave detected on:", {
        tag: this.tagName,
        id: this.id,
        relatedTarget: e.originalEvent.relatedTarget
          ? {
              tag: e.originalEvent.relatedTarget.tagName,
              id: e.originalEvent.relatedTarget.id,
              className: e.originalEvent.relatedTarget.className,
            }
          : "null",
      });

      // Only remove classes if we're actually leaving the container area
      if (!isRelatedTarget($container[0], e.originalEvent.relatedTarget)) {
        debugLog(
          "Actually leaving container, removing classes from:",
          $container[0].id || $container[0].tagName
        );

        clearTimeout(holdTimer);

        // Remove classes from container and all children
        $container.removeClass(
          "drag-over blinking drag-over-editor drag-over-input drag-over-contenteditable drag-over-iframe"
        );
        $container
          .find("*")
          .removeClass(
            "drag-over blinking drag-over-editor drag-over-input drag-over-contenteditable drag-over-iframe"
          );
      } else {
        debugLog("Still inside container, not removing classes");
      }
    });

    $(document).on("dragover", containerTargets, function (e) {
      e.preventDefault();
      e.originalEvent.dataTransfer.dropEffect = "copy";
      // Log occasionally to avoid spam
      if (Math.random() < 0.01) {
        // 1% chance
        debugLog("Container dragover on:", this.id || this.tagName);
      }
    });

    // CONTAINER DROP EVENTS - Handle drops on containers
    $(document).on("drop", containerTargets, function (e) {
      e.preventDefault();
      e.stopPropagation();

      const $container = $(this);
      debugLog("Container drop detected on:", {
        tag: this.tagName,
        id: this.id,
        className: this.className,
        isBlinking:
          $container.hasClass("blinking") ||
          $container.find(".blinking").length > 0,
        draggedText:
          draggedText.substring(0, 100) +
          (draggedText.length > 100 ? "..." : ""),
      });

      clearTimeout(holdTimer);

      // Check if container or any child is blinking
      const hasBlinking =
        $container.hasClass("blinking") ||
        $container.find(".blinking").length > 0;
      debugLog("Blinking check:", {
        containerBlinking: $container.hasClass("blinking"),
        childrenBlinking: $container.find(".blinking").length,
        hasBlinking: hasBlinking,
      });

      if (hasBlinking) {
        debugLog("Container is blinking, finding best insertion target...");

        // Find the best target for content insertion within this container
        let $insertionTarget = null;

        // Priority order for insertion targets
        const insertionPriority = [
          'iframe[id$="_ifr"]', // TinyMCE iframe (highest priority)
          ".wp-editor-area", // WordPress editor textarea
          "textarea", // Any textarea
          'input[type="text"]', // Text inputs
          '[contenteditable="true"]', // Contenteditable elements
        ];

        for (const selector of insertionPriority) {
          $insertionTarget = $container.find(selector).first();
          if ($insertionTarget.length > 0) {
            debugLog("Found insertion target:", {
              selector: selector,
              tag: $insertionTarget[0].tagName,
              id: $insertionTarget[0].id,
              className: $insertionTarget[0].className,
            });
            break;
          }
        }

        // If no specific target found, try the container itself if it's a valid target
        if (!$insertionTarget || $insertionTarget.length === 0) {
          if ($container.is('textarea, input, [contenteditable="true"]')) {
            $insertionTarget = $container;
            debugLog("Using container itself as insertion target");
          }
        }

        if ($insertionTarget && $insertionTarget.length > 0) {
          debugLog("Attempting content insertion into:", {
            tag: $insertionTarget[0].tagName,
            id: $insertionTarget[0].id,
            className: $insertionTarget[0].className,
          });

          const success = insertIntoField($insertionTarget, draggedText);

          if (success) {
            debugLog("Content insertion successful");
            const messageContext = isEditorMode
              ? stickySidebarOpen
                ? "sticky"
                : "editor"
              : "main";
            if (messageContext === "main") {
              showMessage("success", "Content dropped successfully!");
            } else {
              showEditorMessage(
                messageContext,
                "success",
                "Content dropped successfully!"
              );
            }

            // Trigger change events for form validation
            $insertionTarget.trigger("change").trigger("input");
          } else {
            debugError("Content insertion failed");
          }
        } else {
          debugError("No valid insertion target found in container");
        }
      } else {
        debugLog("Container is not blinking, drop ignored");
      }

      // Clean up all classes
      $container.removeClass(
        "drag-over blinking drag-over-editor drag-over-input drag-over-contenteditable drag-over-iframe"
      );
      $container
        .find("*")
        .removeClass(
          "drag-over blinking drag-over-editor drag-over-input drag-over-contenteditable drag-over-iframe"
        );

      draggedText = "";
    });

    // SPECIFIC ELEMENT DRAG EVENTS - Handle individual elements outside containers
    const standaloneTargetsSelector = dropTargets.join(", ");

    $(document).on("dragenter", standaloneTargetsSelector, function (e) {
      // Only handle if not inside a container that's already handling it
      if ($(this).closest(containerTargets).length === 0) {
        e.preventDefault();
        const $target = $(this);

        debugLog("Standalone element drag enter detected on:", {
          tag: this.tagName,
          id: this.id,
          className: this.className,
          text:
            draggedText.substring(0, 100) +
            (draggedText.length > 50 ? "..." : ""),
        });

        $target.addClass("drag-over");
        addFieldTypeClass($target);

        clearTimeout(holdTimer);
        holdTimer = setTimeout(() => {
          debugLog(
            "Standalone element hold timeout reached, adding blinking to:",
            $target[0].id || $target[0].tagName
          );
          $target.addClass("blinking");
        }, 1500);
      }
    });

    $(document).on("dragleave", standaloneTargetsSelector, function (e) {
      if ($(this).closest(containerTargets).length === 0) {
        const $target = $(this);

        debugLog("Standalone element drag leave detected on:", {
          tag: this.tagName,
          id: this.id,
        });

        if (!isRelatedTarget($target[0], e.originalEvent.relatedTarget)) {
          debugLog(
            "Actually leaving standalone element, removing classes from:",
            $target[0].id || $target[0].tagName
          );
          clearTimeout(holdTimer);
          $target.removeClass(
            "drag-over blinking drag-over-editor drag-over-input drag-over-contenteditable drag-over-iframe"
          );
        }
      }
    });

    $(document).on("dragover", standaloneTargetsSelector, function (e) {
      if ($(this).closest(containerTargets).length === 0) {
        e.preventDefault();
        e.originalEvent.dataTransfer.dropEffect = "copy";
      }
    });

    $(document).on("drop", standaloneTargetsSelector, function (e) {
      if ($(this).closest(containerTargets).length === 0) {
        e.preventDefault();
        e.stopPropagation();

        const $target = $(this);
        debugLog("Standalone element drop detected on:", {
          tag: this.tagName,
          id: this.id,
          className: this.className,
          isBlinking: $target.hasClass("blinking"),
          draggedText:
            draggedText.substring(0, 100) +
            (draggedText.length > 100 ? "..." : ""),
        });

        clearTimeout(holdTimer);

        if ($target.hasClass("blinking")) {
          debugLog(
            "Standalone element is blinking, attempting to insert content..."
          );
          const success = insertIntoField($target, draggedText);

          if (success) {
            debugLog("Content insertion successful");
            const messageContext = isEditorMode
              ? stickySidebarOpen
                ? "sticky"
                : "editor"
              : "main";
            if (messageContext === "main") {
              showMessage("success", "Content dropped successfully!");
            } else {
              showEditorMessage(
                messageContext,
                "success",
                "Content dropped successfully!"
              );
            }

            $target.trigger("change").trigger("input");
          } else {
            debugError("Content insertion failed");
          }
        } else {
          debugLog("Standalone element is not blinking, drop ignored");
        }

        $target.removeClass(
          "drag-over blinking drag-over-editor drag-over-input drag-over-contenteditable drag-over-iframe"
        );
        draggedText = "";
      }
    });

    // Initialize specific TinyMCE iframe handling
    debugLog("Initializing TinyMCE drag and drop...");
    initializeTinyMCEDragDrop();

    debugLog("Drag and drop initialization complete");
  }
  /**
   * Add field type specific CSS class for better visual feedback
   */
  function addFieldTypeClass($target) {
    if (
      $target.hasClass("wp-editor-area") ||
      $target.closest(".wp-editor-container").length
    ) {
      $target.addClass("drag-over-editor");
    } else if (
      $target.hasClass("mce-edit-area") ||
      $target.is('iframe[id$="_ifr"]')
    ) {
      $target.addClass("drag-over-iframe");
    } else if (
      $target.is("[contenteditable]") ||
      $target.hasClass("block-editor-rich-text__editable")
    ) {
      $target.addClass("drag-over-contenteditable");
    } else if ($target.is("input") || $target.is("textarea")) {
      $target.addClass("drag-over-input");
    }
  }

  /**
   * Check if related target is within the drop target (for proper drag leave detection)
   */
  function isRelatedTarget(target, relatedTarget) {
    if (!relatedTarget) return false;
    return target.contains(relatedTarget) || target === relatedTarget;
  }

  /**
   * Initialize specific TinyMCE iframe drag and drop handling
   */
  function initializeTinyMCEDragDrop() {
    debugLog("Initializing TinyMCE drag and drop...");

    // Check if TinyMCE is available
    debugLog(
      "TinyMCE availability:",
      typeof tinymce !== "undefined" ? "Available" : "Not available"
    );

    // Wait for TinyMCE to be ready
    const initTinyMCE = () => {
      if (typeof tinymce !== "undefined") {
        debugLog(
          "TinyMCE found, current editors:",
          tinymce.editors ? tinymce.editors.length : 0
        );

        // Handle all TinyMCE editors
        tinymce.on("AddEditor", function (e) {
          const editor = e.editor;
          debugLog("New TinyMCE editor added:", editor.id);

          // Wait for editor to be fully initialized
          editor.on("init", function () {
            debugLog("TinyMCE editor initialized:", editor.id);
            setupTinyMCEDragDrop(editor);
          });
        });

        // Handle existing editors
        if (tinymce.editors) {
          debugLog(
            "Processing existing TinyMCE editors:",
            tinymce.editors.length
          );
          tinymce.editors.forEach((editor) => {
            debugLog("Checking existing editor:", {
              id: editor.id,
              initialized: editor.initialized,
              removed: editor.removed,
            });

            if (!editor.removed) {
              if (editor.initialized) {
                setupTinyMCEDragDrop(editor);
              } else {
                editor.on("init", function () {
                  debugLog("TinyMCE editor initialized (existing):", editor.id);
                  setupTinyMCEDragDrop(editor);
                });
              }
            }
          });
        }
      } else {
        debugError("TinyMCE not found during initialization");
      }
    };

    // Initialize immediately if TinyMCE is ready, otherwise wait
    if (typeof tinymce !== "undefined" && tinymce.editors) {
      debugLog("TinyMCE ready, initializing immediately");
      initTinyMCE();
    } else {
      debugLog("TinyMCE not ready, waiting...");
      let attempts = 0;
      const maxAttempts = 100; // 10 seconds max

      // Wait for TinyMCE to load
      const checkTinyMCE = setInterval(() => {
        attempts++;
        if (typeof tinymce !== "undefined") {
          debugLog("TinyMCE found after", attempts, "attempts");
          clearInterval(checkTinyMCE);
          initTinyMCE();
        } else if (attempts >= maxAttempts) {
          debugError(
            "TinyMCE not found after",
            maxAttempts,
            "attempts, timing out"
          );
          clearInterval(checkTinyMCE);
        }
      }, 100);
    }
  }

  /**
   * Setup drag and drop for a specific TinyMCE editor
   */
  function setupTinyMCEDragDrop(editor) {
    try {
      debugLog("Setting up drag and drop for TinyMCE editor:", editor.id);

      const iframeElement = editor.iframeElement;
      const $iframe = $(iframeElement);
      console.log("iframe", $iframe);

      if (!iframeElement) {
        debugError("No iframe element found for editor:", editor.id);
        return;
      }

      debugLog("TinyMCE iframe found:", {
        id: iframeElement.id,
        src: iframeElement.src,
        width: iframeElement.offsetWidth,
        height: iframeElement.offsetHeight,
      });

      // Get the iframe's document and body
      const iframeDoc =
        iframeElement.contentDocument || iframeElement.contentWindow.document;
      const iframeBody = iframeDoc.body;

      if (!iframeBody) {
        debugError("No iframe body found for editor:", editor.id);
        return;
      }

      debugLog("TinyMCE iframe body found, setting up events");

      // Add drag and drop event listeners to the iframe body
      $(iframeBody).on("dragenter", function (e) {
        e.preventDefault();
        debugLog("TinyMCE iframe drag enter:", {
          editorId: editor.id,
          draggedText:
            draggedText.substring(0, 50) +
            (draggedText.length > 50 ? "..." : ""),
        });

        $iframe.addClass("drag-over drag-over-iframe");

        clearTimeout(holdTimer);
        holdTimer = setTimeout(() => {
          debugLog("TinyMCE iframe hold timeout, adding blinking");
          $iframe.addClass("blinking");
        }, 1500);
      });

      $(iframeBody).on("dragover", function (e) {
        e.preventDefault();
        e.originalEvent.dataTransfer.dropEffect = "copy";
      });

      $(iframeBody).on("dragleave", function (e) {
        if (!isRelatedTarget(iframeBody, e.originalEvent.relatedTarget)) {
          debugLog("TinyMCE iframe drag leave");
          clearTimeout(holdTimer);
          $iframe.removeClass("drag-over drag-over-iframe blinking");
        }
      });

      $(iframeBody).on("drop", function (e) {
        e.preventDefault();
        e.stopPropagation();

        debugLog("TinyMCE iframe drop detected:", {
          editorId: editor.id,
          isBlinking: $iframe.hasClass("blinking"),
          draggedText:
            draggedText.substring(0, 100) +
            (draggedText.length > 100 ? "..." : ""),
        });

        clearTimeout(holdTimer);

        if ($iframe.hasClass("blinking")) {
          // Insert content using TinyMCE API
          try {
            debugLog("Attempting to insert content into TinyMCE editor");
            editor.focus();
            editor.execCommand("mceInsertContent", false, draggedText);
            debugLog("Content successfully inserted into TinyMCE editor");

            const messageContext = isEditorMode
              ? stickySidebarOpen
                ? "sticky"
                : "editor"
              : "main";
            if (messageContext === "main") {
              showMessage(
                "success",
                "Content dropped into editor successfully!"
              );
            } else {
              showEditorMessage(
                messageContext,
                "success",
                "Content dropped into editor successfully!"
              );
            }
          } catch (error) {
            debugError("Error inserting content into TinyMCE:", error);
          }
        } else {
          debugLog("TinyMCE iframe was not blinking, drop ignored");
        }

        $iframe.removeClass("drag-over drag-over-iframe blinking");
        draggedText = "";
      });

      debugLog("TinyMCE drag and drop setup complete for editor:", editor.id);
    } catch (error) {
      debugError("Error setting up TinyMCE drag and drop:", error);
    }
  }

  /**
   * Initialize drag and drop for iframe-based editors (ACF etc.)
   */
  function initializeIframeDragDrop() {
    // Handle ACF WYSIWYG and other iframe editors (non-TinyMCE)
    $(document).on(
      "dragenter",
      '.acf-field-wysiwyg iframe:not([id$="_ifr"]), .wp-editor-container iframe:not([id$="_ifr"])',
      function (e) {
        e.preventDefault();
        $(this)
          .closest(".acf-field-wysiwyg, .wp-editor-container")
          .addClass("drag-over drag-over-editor");
      }
    );

    $(document).on(
      "dragleave",
      '.acf-field-wysiwyg iframe:not([id$="_ifr"]), .wp-editor-container iframe:not([id$="_ifr"])',
      function (e) {
        const $container = $(this).closest(
          ".acf-field-wysiwyg, .wp-editor-container"
        );
        if (!isRelatedTarget($container[0], e.originalEvent.relatedTarget)) {
          $container.removeClass("drag-over drag-over-editor blinking");
        }
      }
    );

    $(document).on(
      "dragover",
      '.acf-field-wysiwyg iframe:not([id$="_ifr"]), .wp-editor-container iframe:not([id$="_ifr"])',
      function (e) {
        e.preventDefault();
        e.originalEvent.dataTransfer.dropEffect = "copy";
      }
    );

    $(document).on(
      "drop",
      '.acf-field-wysiwyg iframe:not([id$="_ifr"]), .wp-editor-container iframe:not([id$="_ifr"])',
      function (e) {
        e.preventDefault();
        const $iframe = $(this);
        const $container = $iframe.closest(
          ".acf-field-wysiwyg, .wp-editor-container"
        );

        if ($container.hasClass("blinking")) {
          const success = insertIntoIframeEditor($iframe, draggedText);
          if (success) {
            const messageContext = isEditorMode
              ? stickySidebarOpen
                ? "sticky"
                : "editor"
              : "main";
            if (messageContext === "main") {
              showMessage("success", "Content dropped successfully!");
            } else {
              showEditorMessage(
                messageContext,
                "success",
                "Content dropped successfully!"
              );
            }
          }
        }

        $container.removeClass("drag-over drag-over-editor blinking");
        draggedText = "";
      }
    );
  }

  /**
   * Comprehensive field insertion method
   */
  function insertIntoField($target, content) {
    try {
      debugLog("insertIntoField called:", {
        targetTag: $target[0].tagName,
        targetId: $target[0].id,
        targetClass: $target[0].className,
        contentLength: content.length,
        contentPreview:
          content.substring(0, 50) + (content.length > 50 ? "..." : ""),
      });

      // Check if we're dealing with TinyMCE (priority detection)
      const isInTinyMCE =
        $target.closest(".wp-editor-container").length > 0 ||
        $target.hasClass("mce-tinymce") ||
        $target.hasClass("mce-edit-area") ||
        $target.is('iframe[id$="_ifr"]');

      if (isInTinyMCE) {
        debugLog("Detected TinyMCE context, using TinyMCE insertion method");
        // Find the editor container and iframe
        const $editorContainer = $target.closest(".wp-editor-container").length
          ? $target.closest(".wp-editor-container")
          : $target;
        const $iframe = $editorContainer.find('iframe[id$="_ifr"]');

        if ($iframe.length) {
          debugLog("Found TinyMCE iframe:", $iframe[0].id);
          return insertIntoTinyMCEEditor($iframe, content);
        } else {
          debugLog("No TinyMCE iframe found, trying target element");
          return insertIntoTinyMCEEditor($target, content);
        }
      }

      // WordPress editor (TinyMCE/Classic Editor)
      if (
        $target.hasClass("wp-editor-area") ||
        $target.attr("id") === "content"
      ) {
        debugLog("Detected WordPress editor, using WordPress insertion method");
        return insertIntoWordPressEditor($target, content);
      }

      // Gutenberg blocks
      if (
        $target.hasClass("block-editor-rich-text__editable") ||
        $target.closest(".wp-block").length
      ) {
        debugLog("Detected Gutenberg block, using Gutenberg insertion method");
        return insertIntoGutenbergBlock($target, content);
      }

      // Contenteditable elements
      if (
        $target.is("[contenteditable]") ||
        $target.attr("contenteditable") === "true"
      ) {
        debugLog(
          "Detected contenteditable element, using contenteditable insertion method"
        );
        return insertIntoContentEditable($target, content);
      }

      // ACF WYSIWYG fields
      if ($target.closest(".acf-field-wysiwyg").length) {
        debugLog("Detected ACF WYSIWYG field, using ACF insertion method");
        return insertIntoACFWysiwyg($target, content);
      }

      // CodeMirror editors
      if (
        $target.hasClass("CodeMirror") ||
        $target.closest(".CodeMirror").length
      ) {
        debugLog(
          "Detected CodeMirror editor, using CodeMirror insertion method"
        );
        return insertIntoCodeMirror($target, content);
      }

      // Regular inputs and textareas
      if ($target.is("input, textarea")) {
        debugLog(
          "Detected regular input/textarea, using input insertion method"
        );
        return insertIntoInput($target, content);
      }

      // Fallback for any other element
      debugLog("Using generic insertion method as fallback");
      return insertIntoGenericElement($target, content);
    } catch (error) {
      debugError("Error in insertIntoField:", error);
      return false;
    }
  }

  /**
   * Insert content specifically into TinyMCE iframe
   */
  function insertIntoTinyMCEEditor($target, content) {
    try {
      debugLog("insertIntoTinyMCEEditor called");
      let editor = null;

      // Find the TinyMCE editor instance
      if ($target.is('iframe[id$="_ifr"]')) {
        const iframeId = $target.attr("id");
        const editorId = iframeId.replace("_ifr", "");
        debugLog("Looking for TinyMCE editor with ID:", editorId);
        editor = tinymce.get(editorId);
        debugLog("Found editor:", !!editor);
      } else if ($target.hasClass("mce-edit-area")) {
        debugLog("Target is mce-edit-area, looking for iframe...");
        // Find the associated iframe
        const $iframe = $target.find('iframe[id$="_ifr"]');
        if ($iframe.length) {
          const iframeId = $iframe.attr("id");
          const editorId = iframeId.replace("_ifr", "");
          debugLog(
            "Found iframe with ID:",
            iframeId,
            "looking for editor:",
            editorId
          );
          editor = tinymce.get(editorId);
          debugLog("Found editor:", !!editor);
        }
      }

      if (editor && !editor.isHidden()) {
        debugLog("Using TinyMCE API to insert content");
        editor.focus();
        editor.execCommand("mceInsertContent", false, content);
        debugLog("Content inserted successfully via TinyMCE API");
        return true;
      } else {
        debugLog("Editor not found or hidden, trying fallback method");

        // Fallback: try to insert directly into iframe
        const iframeElement = $target.is("iframe")
          ? $target[0]
          : $target.find("iframe")[0];
        if (iframeElement) {
          debugLog("Attempting direct iframe insertion");
          return insertIntoIframeEditor($target, content);
        } else {
          debugError("No iframe found for fallback insertion");
        }
      }

      return false;
    } catch (error) {
      debugError("Error in insertIntoTinyMCEEditor:", error);
      return false;
    }
  }

  /**
   * Insert content into WordPress editor (handles both visual and text modes)
   */
  function insertIntoWordPressEditor($target, content) {
    try {
      // Try to use WordPress editor API if available
      if (typeof tinymce !== "undefined") {
        const editorId = $target.attr("id") || "content";
        const editor = tinymce.get(editorId);

        if (editor && !editor.isHidden()) {
          // Visual mode - insert as HTML
          editor.execCommand("mceInsertContent", false, content);
          editor.focus();
          return true;
        }
      }

      // Fallback to textarea (text mode)
      return insertIntoInput($target, content);
    } catch (error) {
      console.error("Error inserting into WordPress editor:", error);
      return insertIntoInput($target, content);
    }
  }

  /**
   * Insert content into contenteditable elements
   */
  function insertIntoContentEditable($target, content) {
    try {
      const element = $target[0];

      // Save current selection
      let selection, range;
      if (window.getSelection) {
        selection = window.getSelection();
        if (selection.rangeCount > 0) {
          range = selection.getRangeAt(0);
        }
      }

      // Focus the element
      element.focus();

      // If we have a saved selection and it's in our target element
      if (range && element.contains(range.commonAncestorContainer)) {
        selection.removeAllRanges();
        selection.addRange(range);
      } else {
        // Move cursor to end
        const range = document.createRange();
        range.selectNodeContents(element);
        range.collapse(false);
        selection.removeAllRanges();
        selection.addRange(range);
      }

      // Insert content
      if (document.execCommand) {
        document.execCommand("insertHTML", false, content);
      } else {
        // Fallback for browsers that don't support execCommand
        const currentContent = element.innerHTML;
        element.innerHTML = currentContent + content;
      }

      // Trigger events
      $target.trigger("input").trigger("change");

      return true;
    } catch (error) {
      console.error("Error inserting into contenteditable:", error);
      return false;
    }
  }

  /**
   * Insert content into ACF WYSIWYG fields
   */
  function insertIntoACFWysiwyg($target, content) {
    try {
      const $field = $target.closest(".acf-field-wysiwyg");
      const fieldKey = $field.data("key");

      // Try to get the TinyMCE editor instance
      if (typeof tinymce !== "undefined" && fieldKey) {
        const editorId = $field.find(".wp-editor-area").attr("id");
        if (editorId) {
          const editor = tinymce.get(editorId);
          if (editor && !editor.isHidden()) {
            editor.execCommand("mceInsertContent", false, content);
            editor.focus();
            return true;
          }
        }
      }

      // Fallback to textarea
      const $textarea = $field.find("textarea");
      if ($textarea.length) {
        return insertIntoInput($textarea, content);
      }

      return false;
    } catch (error) {
      console.error("Error inserting into ACF WYSIWYG:", error);
      return false;
    }
  }

  /**
   * Insert content into regular input/textarea elements
   */
  function insertIntoInput($target, content) {
    try {
      const element = $target[0];
      const currentValue = $target.val() || "";

      // Check if content is HTML (like an image)
      let insertContent = content;
      if (content.trim().startsWith("<")) {
        // Try to extract image URL from HTML
        const tempDiv = document.createElement("div");
        tempDiv.innerHTML = content;
        const img = tempDiv.querySelector("img");
        if (img) {
          // For images, insert the URL or alt text into text inputs
          const imageUrl = img.getAttribute("src") || "";
          const imageAlt = img.getAttribute("alt") || "";
          insertContent = imageUrl || imageAlt || content;
        }
      }

      // Get cursor position if possible
      let cursorPos = element.selectionStart || currentValue.length;

      // Insert content at cursor position or append
      let newValue;
      if (typeof cursorPos === "number" && cursorPos >= 0) {
        newValue =
          currentValue.slice(0, cursorPos) +
          insertContent +
          currentValue.slice(cursorPos);

        // Set new value
        $target.val(newValue);

        // Move cursor to end of inserted content
        const newCursorPos = cursorPos + insertContent.length;
        if (element.setSelectionRange) {
          element.setSelectionRange(newCursorPos, newCursorPos);
        }
      } else {
        // Fallback: append with newline if there's existing content
        newValue = currentValue ? currentValue + "\n" + insertContent : insertContent;
        $target.val(newValue);
      }

      // Focus and trigger events
      element.focus();
      $target.trigger("input").trigger("change");

      return true;
    } catch (error) {
      console.error("Error inserting into input:", error);
      return false;
    }
  }

  /**
   * Insert content into iframe-based editors
   */
  function insertIntoIframeEditor($iframe, content) {
    try {
      const iframeDoc =
        $iframe[0].contentDocument || $iframe[0].contentWindow.document;
      const body = iframeDoc.body;

      if (body) {
        // Focus the iframe
        $iframe[0].contentWindow.focus();

        // Try to use execCommand
        if (iframeDoc.execCommand) {
          iframeDoc.execCommand("insertHTML", false, content);
          return true;
        } else {
          // Fallback: append to body
          body.innerHTML += content;
          return true;
        }
      }

      return false;
    } catch (error) {
      console.error("Error inserting into iframe editor:", error);
      return false;
    }
  }

  /**
   * Generic insertion method for unknown elements
   */
  function insertIntoGenericElement($target, content) {
    try {
      // Try setting value property
      if (typeof $target[0].value !== "undefined") {
        const currentValue = $target.val() || "";
        const newValue = currentValue ? currentValue + "\n" + content : content;
        $target.val(newValue);
        $target.trigger("input").trigger("change");
        return true;
      }

      // Try setting innerHTML
      if ($target[0].innerHTML !== undefined) {
        $target[0].innerHTML += content;
        $target.trigger("input").trigger("change");
        return true;
      }

      // Try setting textContent
      if ($target[0].textContent !== undefined) {
        $target[0].textContent += content;
        $target.trigger("input").trigger("change");
        return true;
      }

      return false;
    } catch (error) {
      console.error("Error in generic insertion:", error);
      return false;
    }
  }

  /**
   * Make an element draggable with specified text
   */
  function makeDraggable(element, content, isHTML = false) {
    const $element = $(element);

    debugLog("Making element draggable:", {
      tag: element.tagName,
      id: element.id,
      className: element.className,
      contentPreview: content.substring(0, 50) + (content.length > 50 ? "..." : ""),
      isHTML: isHTML
    });

    $element.addClass("draggable-item").attr("draggable", "true").css({
      cursor: "grab",
      "user-select": "none",
      transition: "transform 0.2s ease, box-shadow 0.2s ease",
    });

    // Hover effects
    $element.hover(
      function () {
        $(this).css({
          transform: "translateY(-1px)",
          "box-shadow": "0 2px 8px rgba(0, 0, 0, 0.15)",
        });
      },
      function () {
        if (!$(this).hasClass("dragging")) {
          $(this).css({
            transform: "",
            "box-shadow": "",
          });
        }
      }
    );

    // Drag events
    $element.on("dragstart", function (e) {
      draggedText = content;
      debugLog("Drag start:", {
        element: this.tagName + (this.id ? "#" + this.id : ""),
        draggedContent:
          draggedText.substring(0, 100) +
          (draggedText.length > 100 ? "..." : ""),
        isHTML: isHTML
      });

      $(this).addClass("dragging").css({
        cursor: "grabbing",
        opacity: "0.7",
        transform: "rotate(2deg)",
      });
      
      e.originalEvent.dataTransfer.effectAllowed = "copy";
      
      // Set drag data - support both HTML and plain text
      if (isHTML) {
        e.originalEvent.dataTransfer.setData("text/html", content);
        e.originalEvent.dataTransfer.setData("text/plain", content);
      } else {
        e.originalEvent.dataTransfer.setData("text/plain", content);
      }
    });

    $element.on("dragend", function () {
      debugLog("Drag end");
      $(this).removeClass("dragging").css({
        cursor: "grab",
        opacity: "",
        transform: "",
      });
    });
  }

  /**
   * Add drag functionality to rendered content (main plugin page)
   */
  function addDragFunctionality() {
    $(".item").each(function () {
      const $item = $(this);
      const itemType = $item.find(".item-type").text().toLowerCase();
      const $textElement = $item.find(".item-text, .rendered-element");
      const $linkElement = $item.find("a[href]");
      const $imageElement = $item.find("img");
      const $downloadBtn = $item.find(".download-btn");

      let dragContent = "";
      let isHTML = false;

      // Check if it's an image
      if (itemType === "image" || $imageElement.length > 0) {
        const imageUrl = $imageElement.attr("src") || $downloadBtn.data("url") || "";
        const imageAlt = $imageElement.attr("alt") || $downloadBtn.data("alt") || "image";
        
        if (imageUrl) {
          // Create image HTML for dragging
          dragContent = `<img src="${imageUrl}" alt="${imageAlt}" />`;
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
          dragContent = `<a href="${linkUrl.replace(/"/g, "&quot;")}">${escapedText}</a>`;
          isHTML = true;
        } else {
          // Fallback to plain text if no URL
          dragContent = linkText;
        }
      } 
      // Check if it's text
      else if ($textElement.length) {
        dragContent = $textElement.text();
      }

      if (dragContent) {
        makeDraggable($item[0], dragContent, isHTML);
      }
    });

    $(".rendered-element").each(function () {
      const $elem = $(this);
      const $img = $elem.find("img");
      
      if ($img.length > 0) {
        // It's an image element
        const imageUrl = $img.attr("src") || "";
        const imageAlt = $img.attr("alt") || "image";
        if (imageUrl) {
          const imageHTML = `<img src="${imageUrl}" alt="${imageAlt}" />`;
          makeDraggable(this, imageHTML, true);
        }
      } else {
        // It's text
        const text = $elem.text() || $elem[0].innerText;
        if (text) {
          makeDraggable(this, text, false);
        }
      }
    });

    $(".copy-btn").each(function () {
      const text = $(this).data("text");
      if (text) {
        makeDraggable(this, text);
      }
    });
  }

  function handleScrapeSubmission(e) {
    e.preventDefault();

    const url = $("#scrape-url").val().trim();

    if (!url) {
      showMessage("error", migrationManager.strings.invalidUrl);
      return;
    }

    if (!isValidUrl(url)) {
      showMessage("error", migrationManager.strings.invalidUrl);
      return;
    }

    startScraping(url);
  }

  function startScraping(url) {
    showLoadingState(true);
    showMessage("loading", migrationManager.strings.scraping);

    const ajaxData = {
      action: "migration_manager_scrape",
      url: url,
      nonce: $("#migration_nonce").val(),
    };

    $.ajax({
      url: migrationManager.ajaxUrl,
      type: "POST",
      data: ajaxData,
      timeout: 60000,
      success: function (response) {
        handleScrapeSuccess(response, url);
      },
      error: function (xhr, status, error) {
        handleScrapeError(xhr, status, error);
      },
      complete: function () {
        showLoadingState(false);
      },
    });
  }

  function handleScrapeSuccess(response, originalUrl) {
    if (response.success && response.data) {
      currentScrapedData = response.data;
      displayResults(response.data, originalUrl);
      showMessage("success", migrationManager.strings.success);
    } else {
      const errorMessage =
        response.data && response.data.message
          ? response.data.message
          : migrationManager.strings.error;
      handleScrapeError(null, null, errorMessage);
    }
  }

  function handleScrapeError(xhr, status, error) {
    console.error("Scrape error:", { xhr, status, error });

    let errorMessage = migrationManager.strings.error;

    if (status === "timeout") {
      errorMessage =
        "Request timed out. The website might be taking too long to respond.";
    } else if (
      xhr &&
      xhr.responseJSON &&
      xhr.responseJSON.data &&
      xhr.responseJSON.data.message
    ) {
      errorMessage = xhr.responseJSON.data.message;
    } else if (error && typeof error === "string") {
      errorMessage = error;
    }

    showMessage("error", errorMessage);
    hideResults();
  }

  function displayResults(data, sourceUrl) {
    // Store the current scrape URL for later use
    currentScrapedUrl = sourceUrl || currentScrapedUrl;
    currentScrapedData = data;
    
    $("#migration-results").show();
    $("#source-url-link").attr("href", sourceUrl).text(sourceUrl);
    renderResults(data.data || [], sourceUrl);
    enableActionButtons(true);
    
    // Enable upload images button if there are images
    const hasImages = (data.data || []).some(item => {
      if (item.type === 'image') return true;
      if (item.type === 'group' && item.children) {
        return item.children.some(child => child.type === 'image');
      }
      return false;
    });
    $("#download-images").prop("disabled", !hasImages);

    $("html, body").animate(
      {
        scrollTop: $("#migration-results").offset().top - 50,
      },
      500
    );
  }

  function renderResults(data, url) {
    const resultsContainer = document.getElementById("content-preview");

    if (data.length === 0) {
      resultsContainer.innerHTML =
        "<p>" + migrationManager.strings.noData + "</p>";
      return;
    }

    const stats = {
      total: data.length,
      text: data.filter((item) => item.type === "text").length,
      links: data.filter((item) => item.type === "link").length,
      images: data.filter((item) => item.type === "image").length,
      videos: data.filter((item) => item.type === "video").length,
      groups: data.filter((item) => item.type === "group").length,
    };

    let statsHtml = `
      <div class="migration-stats-grid">
          <div class="stat-item">
              <div class="stat-number">${stats.total}</div>
              <div class="stat-label">Total Items</div>
          </div>
          <div class="stat-item">
              <div class="stat-number">${stats.text}</div>
              <div class="stat-label">Text Elements</div>
          </div>
          <div class="stat-item">
              <div class="stat-number">${stats.links}</div>
              <div class="stat-label">Links</div>
          </div>
          <div class="stat-item">
              <div class="stat-number">${stats.images}</div>
              <div class="stat-label">Images</div>
          </div>
          <div class="stat-item">
              <div class="stat-number">${stats.videos}</div>
              <div class="stat-label">Videos</div>
          </div>
          <div class="stat-item">
              <div class="stat-number">${stats.groups}</div>
              <div class="stat-label">Groups</div>
          </div>
      </div>
    `;

    if (typeof $ !== 'undefined') {
      $("#migration-stats").html(statsHtml);
    } else if (typeof jQuery !== 'undefined') {
      jQuery("#migration-stats").html(statsHtml);
    }

    let html = "";
    data.forEach((item, index) => {
      if (item.wrapper === true && item.children && item.children.length > 0) {
        html += renderGroupedElements(item, index);
      } else {
        html += renderItem(item, index);
      }
    });

    resultsContainer.innerHTML = html;
    addButtonEventListeners();
    // Add delete functionality
    setTimeout(() => {
      addDeleteButtonsToGroups("main");
    }, 100);
  }

  function renderGroupedElements(wrapperItem, index) {
    let groupContext = "";

    if (wrapperItem.type === "link") {
      groupContext = `<div class="group-context" style="background: #ffc107; color: #333; padding: 15px 20px; border-bottom: 1px solid rgba(0,0,0,0.1);">
        <div class="context-label" style="font-size: 12px; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; opacity: 0.8;">Grouped by Link:</div>
        <div class="context-info" style="font-size: 14px; font-weight: 500; display: flex; align-items: center;">
            <a href="${
              wrapperItem.url
            }" target="_blank" style="color: #333; text-decoration: none; font-weight: 500;">${escapeHtml(
        wrapperItem.text
      )}</a>
            <button class="button button-small copy-btn" data-text="${escapeHtml(
              wrapperItem.url
            )}" style="margin-left: 10px;">Copy Link</button>
        </div>
      </div>`;
    } else {
      groupContext = `<div class="group-context" style="background: #ffc107; color: #333; padding: 15px 20px; border-bottom: 1px solid rgba(0,0,0,0.1);">
        <div class="context-label" style="font-size: 12px; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; opacity: 0.8;">Grouped by ${
          wrapperItem.element || wrapperItem.type
        }:</div>
        ${
          wrapperItem.text
            ? `<div class="context-info" style="font-size: 14px; font-weight: 500;">${escapeHtml(
                wrapperItem.text
              )}</div>`
            : ""
        }
      </div>`;
    }

    const childrenHtml = wrapperItem.children
      .map((child, childIndex) => {
        return renderItem(child, `${index}-${childIndex}`, true);
      })
      .join("");

    return `
      <div class="grouped-elements" style="margin-bottom: 25px; background: #fff8e1; border-radius: 12px; border: 2px solid #ffc107; overflow: hidden;">
          ${groupContext}
          <div class="group-items" style="padding: 20px;">
              ${childrenHtml}
          </div>
      </div>
    `;
  }

  function renderItem(item, index, isGroupedChild = false) {
    let content = "";
    let actualElement = "";
    let meta = "";
    let actionButtons = "";

    const isWrapper = item.wrapper === true && !isGroupedChild;
    const itemClass = isGroupedChild
      ? "item grouped-child"
      : isWrapper
      ? "item wrapper"
      : "item";
    const typeClass = isGroupedChild
      ? "item-type grouped"
      : isWrapper
      ? "item-type wrapper"
      : "item-type";

    const itemStyle = isGroupedChild
      ? "background: white; margin-bottom: 15px; border-left: 3px solid #28a745; padding: 20px; border-radius: 8px;"
      : isWrapper
      ? "margin-bottom: 20px; padding: 20px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #ffc107;"
      : "margin-bottom: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #667eea;";

    const typeStyle = isGroupedChild
      ? "display: inline-block; padding: 4px 12px; background: #28a745; color: white; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; margin-bottom: 10px;"
      : isWrapper
      ? "display: inline-block; padding: 4px 12px; background: #ffc107; color: #333; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; margin-bottom: 10px;"
      : "display: inline-block; padding: 4px 12px; background: #667eea; color: white; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; margin-bottom: 10px;";

    switch (item.type) {
      case "text":
        const tag = item.element || "div";
        let attributes = "";
        if (item.class) attributes += ` class="${escapeHtml(item.class)}"`;
        if (item.id) attributes += ` id="${escapeHtml(item.id)}"`;

        actualElement = `<${tag}${attributes}>${escapeHtml(
          item.text
        )}</${tag}>`;
        content = `
          <div class="item-preview" style="margin-bottom: 15px;">
              <div class="preview-label" style="font-size: 12px; color: #666; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">Rendered Element:</div>
              <div class="rendered-element" style="padding: 15px; background: white; border: 1px solid #e1e8ed; border-radius: 6px;">${actualElement}</div>
          </div>
        `;

        actionButtons = `
          <div class="action-buttons" style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px;">
              <button class="button button-small copy-btn" data-text="${escapeHtml(
                item.text
              )}">Copy Text</button>
          </div>
        `;

        meta = `Element: ${item.element}`;
        if (item.class) meta += ` | Class: ${item.class}`;
        if (item.id) meta += ` | ID: ${item.id}`;
        break;

      case "link":
        actualElement = `<a href="${
          item.url
        }" target="_blank" style="color: #667eea; text-decoration: none; font-weight: 500;">${escapeHtml(
          item.text
        )}</a>`;
        content = `
          <div class="item-preview" style="margin-bottom: 15px;">
              <div class="preview-label" style="font-size: 12px; color: #666; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">Rendered Element:</div>
              <div class="rendered-element" style="padding: 15px; background: white; border: 1px solid #e1e8ed; border-radius: 6px;">${actualElement}</div>
          </div>
        `;

        actionButtons = `
          <div class="action-buttons" style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px;">
              <button class="button button-small copy-btn" data-text="${escapeHtml(
                item.text
              )}">Copy Text</button>
              <button class="button button-small copy-btn" data-text="${escapeHtml(
                item.url
              )}">Copy Link</button>
          </div>
        `;

        meta = `URL: ${item.url}`;
        break;

      case "image":
        let imgStyle = "max-width: 300px; height: auto;";
        if (item.source === "css_background") {
          actualElement = `<div style="width: 300px; height: 200px; background-image: url('${
            item.url
          }'); background-size: cover; background-position: center; border-radius: 4px; position: relative;">
            ${
              item.alt
                ? `<div style="position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.7); color: white; padding: 8px; font-size: 12px;">${escapeHtml(
                    item.alt
                  )}</div>`
                : ""
            }
          </div>`;
        } else {
          actualElement = `<img src="${item.url}" alt="${
            item.alt || ""
          }" style="${imgStyle}" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';" />
            <div class="image-fallback" style="display:none; padding:20px; background:#f0f0f0; text-align:center; border-radius:6px;">
                <p>Image failed to load</p>
                <small>${item.url}</small>
            </div>`;
        }

        content = `
          <div class="item-preview" style="margin-bottom: 15px;">
              <div class="preview-label" style="font-size: 12px; color: #666; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">Rendered Element:</div>
              <div class="rendered-element" style="padding: 15px; background: white; border: 1px solid #e1e8ed; border-radius: 6px;">${actualElement}</div>
          </div>
        `;

        actionButtons = `
          <div class="action-buttons" style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px;">
              <button class="button button-small download-btn" data-url="${escapeHtml(
                item.url
              )}" data-filename="${escapeHtml(
          item.alt || "image"
        )}" data-alt="${escapeHtml(
          item.alt || ""
        )}">Upload to WordPress</button>
              <button class="button button-small copy-btn" data-text="${escapeHtml(
                item.url
              )}">Copy URL</button>
          </div>
        `;

        meta = `URL: ${item.url}`;
        if (item.alt) meta += ` | Alt: ${item.alt}`;
        if (item.source) meta += ` | Source: ${item.source}`;
        if (item.element && item.source === "css_background")
          meta += ` | Element: ${item.element}`;
        if (item.class) meta += ` | Class: ${item.class}`;
        if (item.id) meta += ` | ID: ${item.id}`;
        break;

      case "video":
        const videoAttrs = item.poster ? `poster="${item.poster}"` : "";
        const sourceAttrs = item.format ? `type="${item.format}"` : "";
        actualElement = `
          <video controls style="max-width: 400px; height: auto;" ${videoAttrs}>
              <source src="${item.url}" ${sourceAttrs}>
              Your browser does not support the video tag.
          </video>
        `;
        content = `
          <div class="item-preview" style="margin-bottom: 15px;">
              <div class="preview-label" style="font-size: 12px; color: #666; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">Rendered Element:</div>
              <div class="rendered-element" style="padding: 15px; background: white; border: 1px solid #e1e8ed; border-radius: 6px;">${actualElement}</div>
          </div>
        `;

        actionButtons = `
          <div class="action-buttons" style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px;">
              <button class="button button-small copy-btn" data-text="${escapeHtml(
                item.url
              )}">Copy Video URL</button>
          </div>
        `;

        meta = `URL: ${item.url}`;
        if (item.format) meta += ` | Format: ${item.format}`;
        if (item.poster) meta += ` | Poster: ${item.poster}`;
        break;

      default:
        console.log("Unknown item type:", item.type, "Full item:", item);
        content = `<div style="font-size: 16px; margin-bottom: 10px;">Unknown item type: ${item.type}</div>`;
        meta = JSON.stringify(item);
    }

    return `
      <div class="${itemClass}" style="${itemStyle}">
          <div class="${typeClass}" style="${typeStyle}">${item.type}${
      isWrapper
        ? '<span style="display: inline-block; padding: 2px 8px; background: #ffc107; color: #333; border-radius: 12px; font-size: 10px; font-weight: 600; margin-left: 8px; text-transform: uppercase;">Wrapper</span>'
        : ""
    }</div>
          ${content}
          ${actionButtons}
          ${
            meta
              ? `<div class="item-meta" style="font-size: 12px; color: #888; margin-top: 8px;">${meta}</div>`
              : ""
          }
      </div>
    `;
  }

  function showLoadingState(loading) {
    const $button = $("#scrape-btn");
    const $spinner = $("#scrape-spinner");

    if (loading) {
      $button.prop("disabled", true);
      $spinner.addClass("is-active");
    } else {
      $button.prop("disabled", false);
      $spinner.removeClass("is-active");
    }
  }

  function showMessage(type, message) {
    const $messagesContainer = $("#migration-messages");

    $messagesContainer.empty();

    let messageHtml = "";

    switch (type) {
      case "success":
        messageHtml = `<div class="notice notice-success is-dismissible">
          <p><strong>Success!</strong> ${escapeHtml(message)}</p>
          <button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>
        </div>`;
        break;

      case "error":
        messageHtml = `<div class="notice notice-error is-dismissible">
          <p><strong>Error!</strong> ${escapeHtml(message)}</p>
          <button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>
        </div>`;
        break;

      case "loading":
        messageHtml = `<div class="notice notice-info">
          <p><span class="spinner is-active"></span><strong>Processing...</strong> ${escapeHtml(
            message
          )}</p>
        </div>`;
        break;

      default:
        messageHtml = `<div class="notice notice-info is-dismissible">
          <p>${escapeHtml(message)}</p>
          <button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>
        </div>`;
    }

    $messagesContainer.html(messageHtml);

    if (type === "success") {
      setTimeout(function () {
        $messagesContainer.find(".notice-success").fadeOut();
      }, 5000);
    }
  }

  function clearResults() {
    $("#migration-results").hide();
    currentScrapedData = null;
    enableActionButtons(false);
    $("#scrape-url").val("");
    $("#migration-messages").empty();
  }

  function addButtonEventListeners() {
    document.querySelectorAll(".copy-btn").forEach((btn) => {
      btn.addEventListener("click", function () {
        const text = this.getAttribute("data-text");
        copyText(text);
      });
    });

    document.querySelectorAll(".download-btn").forEach((btn) => {
      // Remove any existing listeners to prevent duplicates
      const newBtn = btn.cloneNode(true);
      btn.parentNode.replaceChild(newBtn, btn);
      
      newBtn.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        const url = this.getAttribute("data-url");
        const filename = this.getAttribute("data-filename");
        const alt = this.getAttribute("data-alt") || filename || "image";
        
        console.log("Upload button clicked:", { url, alt, filename });
        
        // Upload to WordPress instead of downloading to computer
        // Try new modular system first
        if (window.MigrationManagerApp && window.MigrationManagerApp.getInstance) {
          try {
            const app = window.MigrationManagerApp.getInstance();
            if (app && app.uploadSingleImage) {
              console.log("Using MigrationManagerApp.uploadSingleImage");
              app.uploadSingleImage(url, alt, this);
              return false;
            }
          } catch (e) {
            console.error("Error accessing MigrationManagerApp:", e);
          }
        }
        
        // Try direct API call if MigrationManagerApp not available
        // uploadImageDirect is defined in this file, so we can call it directly
        console.log("Using uploadImageDirect fallback");
        if (typeof uploadImageDirect === 'function') {
          uploadImageDirect(url, alt, this);
        } else {
          console.error("uploadImageDirect function not found");
          if (typeof showMessage === 'function') {
            showMessage("error", "Upload function not available. Please refresh the page.");
          } else {
            alert("Upload function not available. Please refresh the page.");
          }
        }
        return false;
      });
    });

    addDragFunctionality();
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard
        .writeText(text)
        .then(() => {
          const messageContext = isEditorMode
            ? stickySidebarOpen
              ? "sticky"
              : "editor"
            : "main";
          if (messageContext === "main") {
            showMessage("success", "Copied to clipboard!");
          } else {
            showEditorMessage(
              messageContext,
              "success",
              "Copied to clipboard!"
            );
          }
        })
        .catch(() => {
          fallbackCopyText(text);
        });
    } else {
      fallbackCopyText(text);
    }
  }

  function fallbackCopyText(text) {
    const textArea = document.createElement("textarea");
    textArea.value = text;
    textArea.style.position = "fixed";
    textArea.style.left = "-999999px";
    textArea.style.top = "-999999px";
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
      document.execCommand("copy");
      const messageContext = isEditorMode
        ? stickySidebarOpen
          ? "sticky"
          : "editor"
        : "main";
      if (messageContext === "main") {
        showMessage("success", "Copied to clipboard!");
      } else {
        showEditorMessage(messageContext, "success", "Copied to clipboard!");
      }
    } catch (err) {
      const messageContext = isEditorMode
        ? stickySidebarOpen
          ? "sticky"
          : "editor"
        : "main";
      if (messageContext === "main") {
        showMessage("error", "Failed to copy");
      } else {
        showEditorMessage(messageContext, "error", "Failed to copy");
      }
    }

    document.body.removeChild(textArea);
  }

  function exportJSON() {
    if (!currentScrapedData) {
      showMessage("error", "No data to export");
      return;
    }

    const jsonStr = JSON.stringify(currentScrapedData, null, 2);
    const blob = new Blob([jsonStr], { type: "application/json" });
    const url = URL.createObjectURL(blob);

    const a = document.createElement("a");
    a.href = url;
    a.download = `scraped-data-${Date.now()}.json`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);

    showMessage("success", "JSON exported successfully");
  }

  /**
   * Upload image directly to WordPress (fallback if MigrationManagerApp not available)
   */
  async function uploadImageDirect(imageUrl, altText, button) {
    // Check if migrationManager is available
    if (typeof migrationManager === 'undefined') {
      console.error("migrationManager is not defined");
      if (typeof showMessage === 'function') {
        showMessage("error", "Migration Manager configuration not loaded. Please refresh the page.");
      } else {
        alert("Migration Manager configuration not loaded. Please refresh the page.");
      }
      return;
    }
    
    // Validate required parameters
    if (!imageUrl) {
      console.error("Image URL is required");
      if (typeof showMessage === 'function') {
        showMessage("error", "Image URL is missing");
      }
      return;
    }
    
    const messageContext = isEditorMode
      ? stickySidebarOpen
        ? "sticky"
        : "editor"
      : "main";
    
    // Disable button
    if (button) {
      const originalText = button.innerHTML || button.textContent || "";
      button.disabled = true;
      button.innerHTML = '<span class="spinner is-active" style="float: none; margin: 0; width: 14px; height: 14px;"></span> Uploading...';
      
      // Get source URL from current scrape
      const sourceUrl = currentScrapedUrl || "";
      
      try {
        const response = await fetch(migrationManager.ajaxUrl, {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded",
          },
          body: new URLSearchParams({
            action: "migration_manager_upload_single_image",
            image_url: imageUrl,
            alt_text: altText || "",
            source_url: sourceUrl,
            nonce: migrationManager.nonce,
          }),
        });
        
        const data = await response.json();
        
        if (data.success) {
          if (messageContext === "main") {
            showMessage("success", data.data.message || "Image uploaded successfully!");
          } else {
            showEditorMessage(messageContext, "success", data.data.message || "Image uploaded successfully!");
          }
          
          // Update button
          button.innerHTML = '<span style="color: #46b450;">✓ Uploaded</span>';
          button.classList.add("uploaded");
          
          // Re-enable after delay
          setTimeout(() => {
            button.disabled = false;
            button.innerHTML = originalText;
            button.classList.remove("uploaded");
          }, 3000);
        } else {
          throw new Error(data.data?.message || "Upload failed");
        }
      } catch (error) {
        console.error("Upload error:", error);
        if (messageContext === "main") {
          showMessage("error", error.message || "Failed to upload image");
        } else {
          showEditorMessage(messageContext, "error", error.message || "Failed to upload image");
        }
        
        // Re-enable button
        button.disabled = false;
        button.innerHTML = originalText;
      }
    }
  }

  async function downloadImage(url, filename) {
    try {
      const messageContext = isEditorMode
        ? stickySidebarOpen
          ? "sticky"
          : "editor"
        : "main";
      if (messageContext === "main") {
        showMessage("info", "Attempting download...");
      } else {
        showEditorMessage(messageContext, "info", "Attempting download...");
      }

      const response = await fetch(url, { mode: "cors" });
      if (!response.ok) {
        throw new Error("Failed to fetch image");
      }

      const blob = await response.blob();
      const downloadUrl = window.URL.createObjectURL(blob);

      const link = document.createElement("a");
      link.href = downloadUrl;

      const extension = url.split(".").pop().split("?")[0] || "jpg";
      link.download = `${filename.replace(/[^a-z0-9]/gi, "_")}.${extension}`;

      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);

      window.URL.revokeObjectURL(downloadUrl);

      if (messageContext === "main") {
        showMessage("success", "Image downloaded!");
      } else {
        showEditorMessage(messageContext, "success", "Image downloaded!");
      }
    } catch (error) {
      console.log("Direct download failed, trying fallback method:", error);

      try {
        const link = document.createElement("a");
        link.href = url;
        link.target = "_blank";

        const extension = url.split(".").pop().split("?")[0] || "jpg";
        link.download = `${filename.replace(/[^a-z0-9]/gi, "_")}.${extension}`;

        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        const messageContext = isEditorMode
          ? stickySidebarOpen
            ? "sticky"
            : "editor"
          : "main";
        if (messageContext === "main") {
          showMessage(
            "success",
            'Download link opened! (Right-click and "Save as" if needed)'
          );
        } else {
          showEditorMessage(
            messageContext,
            "success",
            'Download link opened! (Right-click and "Save as" if needed)'
          );
        }
      } catch (fallbackError) {
        console.error("Fallback download failed:", fallbackError);

        window.open(url, "_blank");
        const messageContext = isEditorMode
          ? stickySidebarOpen
            ? "sticky"
            : "editor"
          : "main";
        if (messageContext === "main") {
          showMessage("info", "Image opened in new tab - right-click to save");
        } else {
          showEditorMessage(
            messageContext,
            "info",
            "Image opened in new tab - right-click to save"
          );
        }
      }
    }
  }

  function enableActionButtons(enable) {
    $("#create-posts, #create-pages, #save-draft, #download-images").prop("disabled", !enable);
  }

  function createPosts() {
    showMessage("info", "Create posts functionality will be implemented next");
  }

  function createPages() {
    showMessage("info", "Create pages functionality will be implemented next");
  }

  function saveDraft() {
    showMessage("info", "Save draft functionality will be implemented next");
  }

  function togglePreviewMode() {
    showMessage("info", "Preview mode functionality will be implemented next");
  }

  /**
   * Animate progress bar smoothly
   */
  function animateProgress($progressBar, from, to, callback) {
    if (!$progressBar.length) {
      if (callback) callback();
      return;
    }
    
    const duration = 300; // Animation duration in ms
    const startTime = Date.now();
    const startValue = from;
    const endValue = to;
    
    function update() {
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
    }
    
    requestAnimationFrame(update);
  }

  /**
   * Round number to decimal places
   */
  function round(num, decimals) {
    return Math.round(num * Math.pow(10, decimals)) / Math.pow(10, decimals);
  }

  /**
   * Handle bulk image upload
   */
  function handleDownloadImages() {
    console.log("Upload Images button clicked", { currentScrapedData, currentScrapedUrl });
    
    if (!currentScrapedData || !currentScrapedUrl) {
      console.error("Missing data:", { currentScrapedData: !!currentScrapedData, currentScrapedUrl: !!currentScrapedUrl });
      showMessage("error", "No scraped content found. Please scrape a website first.");
      return;
    }

    // Check if there are images
    const hasImages = (currentScrapedData.data || []).some(item => {
      if (item.type === 'image') return true;
      if (item.type === 'group' && item.children) {
        return item.children.some(child => child.type === 'image');
      }
      return false;
    });

    if (!hasImages) {
      showMessage("error", "No images found in scraped content.");
      return;
    }

    // Show progress UI
    const $progressContainer = $("#image-download-progress");
    const $progressBar = $("#progress-bar");
    const $progressText = $("#progress-text");
    
    if ($progressContainer.length) {
      $progressContainer.show();
      $progressBar.css("width", "0%");
      $progressText.text("Preparing to upload images...");
    }

    // Disable button during upload
    const $downloadBtn = $("#download-images");
    const originalText = $downloadBtn.html();
    $downloadBtn.prop("disabled", true);
    $downloadBtn.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> Uploading...');

    // Process images in batches
    processImageBatchesAdmin(currentScrapedUrl, 0, {}, $progressBar, $progressText, $downloadBtn, originalText, $progressContainer);
  }

  /**
   * Process images in batches (admin.js version)
   */
  function processImageBatchesAdmin(url, batchIndex, urlMapping, $progressBar, $progressText, $downloadBtn, originalText, $progressContainer, accumulatedStats = { downloaded: 0, skipped: 0, failed: 0 }, lastProgress = 0) {
    const batchSize = 5;
    
    $.ajax({
      url: migrationManager.ajaxUrl,
      type: "POST",
      data: {
        action: "migration_manager_download_images",
        url: url,
        batch_index: batchIndex,
        batch_size: batchSize,
        url_mapping: JSON.stringify(urlMapping),
        previous_downloaded: accumulatedStats.downloaded,
        previous_skipped: accumulatedStats.skipped,
        previous_failed: accumulatedStats.failed,
        nonce: migrationManager.nonce
      },
      success: function(response) {
        if (!response.success) {
          showMessage("error", response.data?.message || "Failed to process images");
          $progressBar.css("width", "0%");
          $progressText.html(`<strong>Error:</strong> ${response.data?.message || "Unknown error"}`);
          $downloadBtn.prop("disabled", false);
          $downloadBtn.html(originalText);
          return;
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

        // Calculate smooth progress (animate from last progress to current)
        const targetProgress = total > 0 ? Math.min(Math.round((processed / total) * 100 * 10) / 10, 100) : 0;
        
        console.log("Progress update:", { 
          processed, total, targetProgress, lastProgress, 
          currentImage, batchIndex, downloaded, skipped, failed 
        });
        
        // Update progress text immediately with current image info
        let statusText = '';
        if (currentImage && currentImage.index) {
          const statusMessages = {
            'downloading': `Downloading image ${currentImage.index}/${total}: ${currentImage.filename}...`,
            'uploading': `Uploading image ${currentImage.index}/${total}: ${currentImage.filename}...`,
            'completed': `Completed image ${currentImage.index}/${total}: ${currentImage.filename}`,
            'skipped': `Skipped image ${currentImage.index}/${total}: ${currentImage.filename} (${currentImage.message})`,
            'failed': `Failed image ${currentImage.index}/${total}: ${currentImage.filename}`
          };
          statusText = statusMessages[currentImage.status] || `Processing image ${currentImage.index}/${total}...`;
        } else {
          statusText = `Processing batch ${batchIndex + 1}/${Math.ceil(total / batchSize)}... (${processed}/${total} images)`;
        }
        
        // Update text immediately (before animation)
        if ($progressText.length) {
          $progressText.html(
            `<strong>${statusText}</strong><br>` +
            `<small>Progress: ${processed}/${total} images | ` +
            `Downloaded: ${downloaded} | Skipped: ${skipped} | Failed: ${failed}</small>`
          );
        }
        
        // Animate progress bar smoothly
        animateProgress($progressBar, lastProgress, targetProgress);

        // Merge URL mappings
        const newUrlMapping = data.url_mapping || {};
        const mergedMapping = { ...urlMapping, ...newUrlMapping };

        // Check if complete
        if (data.complete) {
          showMessage("success", data.message || "Images uploaded successfully!");

          // Update progress to 100%
          if ($progressBar.length) {
            $progressBar.css("width", "100%");
            $progressText.html(
              `<strong>Complete!</strong> Downloaded: ${downloaded}, ` +
              `Skipped: ${skipped}, Failed: ${failed}`
            );
          }

          // Update current scraped data if available
          if (data.updated_data && data.updated_data.data) {
            currentScrapedData = data.updated_data;
            // Refresh the display
            displayResults(data.updated_data, url);
          }

          // Re-enable button after a delay
          setTimeout(() => {
            $downloadBtn.prop("disabled", false);
            $downloadBtn.html(originalText);
            if ($progressContainer.length) {
              $progressContainer.fadeOut(3000);
            }
          }, 2000);
        } else {
          // Process next batch
          setTimeout(() => {
            processImageBatchesAdmin(
              url,
              data.batch_index,
              mergedMapping,
              $progressBar,
              $progressText,
              $downloadBtn,
              originalText,
              $progressContainer,
              accumulatedStats
            );
          }, 500);
        }
      },
      error: function(xhr, status, error) {
        showMessage("error", "Failed to upload images: " + error);
        if ($progressBar.length) {
          $progressBar.css("width", "0%");
          $progressText.html(`<strong>Error:</strong> ${error}`);
        }
        $downloadBtn.prop("disabled", false);
        $downloadBtn.html(originalText);
      }
    });
  }

  function handleLoadScrape(e) {
    const url = $(e.target).data("url");
    if (!url) {
      return;
    }

    const $button = $(e.target);
    $button.prop("disabled", true).text("Loading...");

    // Determine context (editor vs main page)
    const isEditorContext = $button.hasClass("editor-load-scrape");
    const context = stickySidebarOpen
      ? "sticky"
      : isEditorContext
      ? "editor"
      : "main";

    $.ajax({
      url: migrationManager.ajaxUrl,
      type: "POST",
      data: {
        action: "migration_manager_load_scrape",
        url: url,
        nonce: migrationManager.nonce,
      },
      success: function (response) {
        if (response.success && response.data) {
          const scrapedData = response.data.scraped_data;
          if (scrapedData && scrapedData.data) {
            currentScrapedData = scrapedData;

            if (isEditorContext) {
              // Set URL in appropriate input
              const urlInputId =
                context === "sticky"
                  ? "#sticky-scrape-url"
                  : "#editor-scrape-url";
              $(urlInputId).val(url);
              displayEditorResults(scrapedData, url, context);
              showEditorMessage(
                context,
                "success",
                "Previous scrape loaded successfully!"
              );
            } else {
              $("#scrape-url").val(url);
              displayResults(scrapedData, url);
              showMessage("success", "Previous scrape loaded successfully!");
            }
          } else {
            const errorMessage = "No valid data found in the saved scrape";
            if (isEditorContext) {
              showEditorMessage(context, "error", errorMessage);
            } else {
              showMessage("error", errorMessage);
            }
          }
        } else {
          const errorMessage =
            response.data && response.data.message
              ? response.data.message
              : "Failed to load previous scrape";

          if (isEditorContext) {
            showEditorMessage(context, "error", errorMessage);
          } else {
            showMessage("error", errorMessage);
          }
        }
      },
      error: function (xhr, status, error) {
        console.error("Load scrape error:", { xhr, status, error });
        const errorMessage = "Failed to load previous scrape: " + error;

        if (isEditorContext) {
          showEditorMessage(context, "error", errorMessage);
        } else {
          showMessage("error", errorMessage);
        }
      },
      complete: function () {
        $button.prop("disabled", false).text("Load");
      },
    });
  }

  function hideResults() {
    $("#migration-results").hide();
    currentScrapedData = null;
    enableActionButtons(false);
  }

  function handleNoticeDismiss(e) {
    $(e.target).closest(".notice").fadeOut();
  }

  function isValidUrl(string) {
    try {
      new URL(string);
      return true;
    } catch (_) {
      return false;
    }
  }

  function escapeHtml(text) {
    if (typeof text !== "string") {
      return text;
    }

    const map = {
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    };

    return text.replace(/[&<>"']/g, function (m) {
      return map[m];
    });
  }
  // CSS styles for delete functionality
  const deleteGroupStyles = `
<style>
.bulk-delete-controls {
    transition: border-left-color 0.3s ease;
}

.group-checkbox {
    margin-right: 8px;
    transform: scale(1.1);
}

.delete-group-btn:hover {
    background-color: #c82333 !important;
    border-color: #bd2130 !important;
}

.bulk-delete-actions .button-primary {
    background: #dc3545;
    border-color: #dc3545;
}

.bulk-delete-actions .button-primary:hover {
    background: #c82333;
    border-color: #bd2130;
}

.bulk-delete-actions .button-primary:disabled {
    background: #e9ecef;
    border-color: #dee2e6;
    color: #6c757d;
}

.grouped-elements.bulk-delete-mode {
    border: 2px dashed #ffc107;
    position: relative;
}

.grouped-elements.selected-for-deletion {
    border: 2px dashed #dc3545;
    background-color: #fff5f5;
}
</style>
`;

  // Inject styles
  if ($("#migration-manager-delete-styles").length === 0) {
    $("head").append(
      '<style id="migration-manager-delete-styles">' +
        deleteGroupStyles +
        "</style>"
    );
  }
})(jQuery);
