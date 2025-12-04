/**
 * GroupManager Module for Migration Manager
 * Handles group selection, deletion, and bulk operations
 *
 * @module GroupManager
 */

(function (window, $) {
  "use strict";

  const GroupManager = {
    /**
     * Configuration
     */
    config: {
      deleteButtonClass: "delete-group-btn",
      checkboxClass: "group-checkbox",
      bulkControlsClass: "bulk-delete-controls",
      confirmDelete: true,
      animationDuration: 300,
    },

    /**
     * State
     */
    initialized: false,

    /**
     * Initialize module
     */
    init() {
      if (this.initialized) return;

      this.bindEvents();
      this.setupEventListeners();

      this.initialized = true;
      console.log("GroupManager module initialized");
    },

    /**
     * Bind DOM events
     */
    bindEvents() {
      const self = this;

      $(document)
        .off(".groupmanager")
        .on("click.groupmanager", ".delete-group-btn", function (e) {
          e.preventDefault();
          self.handleSingleDelete(this);
        })
        .on("click.groupmanager", ".bulk-delete-btn", function (e) {
          e.preventDefault();
          self.toggleBulkMode();
        })
        .on("click.groupmanager", ".confirm-bulk-delete", function (e) {
          e.preventDefault();
          self.handleBulkDelete();
        })
        .on("click.groupmanager", ".cancel-bulk-delete", function (e) {
          e.preventDefault();
          self.exitBulkMode();
        })
        .on("change.groupmanager", ".group-checkbox", function (e) {
          self.handleCheckboxChange(this);
        });
    },

    /**
     * Setup event listeners
     */
    setupEventListeners() {
      // Listen for render events to add delete buttons
      EventBus.on("ui:rendered", () => {
        this.addDeleteButtons();
      });

      EventBus.on(EventBus.events.DATA_UPDATED, () => {
        this.addDeleteButtons();
      });

      // Listen for successful deletions
      EventBus.on(EventBus.events.GROUP_DELETED, (data) => {
        this.onDeleteSuccess(data);
      });

      EventBus.on(EventBus.events.GROUP_BULK_DELETE, (data) => {
        this.onBulkDeleteSuccess(data);
      });
    },

    /**
     * Add delete buttons to groups
     */
    addDeleteButtons() {
      const self = this;
      const context = State.computed.getContext();

      // Add bulk controls if not present
      this.addBulkControls(context);

      // Add delete buttons to each group
      $(".grouped-elements, .item").each(function (index) {
        const $group = $(this);

        // Skip if already has delete button
        if ($group.find(".delete-group-btn").length > 0) return;

        // Create delete button
        const deleteBtn = self.createDeleteButton(index, context);
        const checkbox = self.createCheckbox(index);

        // Find or create action container
        let $actionContainer = $group.find(".action-buttons").first();
        if ($actionContainer.length === 0) {
          $actionContainer = $(
            '<div class="action-buttons" style="margin-top: 8px;"></div>'
          );
          $group.append($actionContainer);
        }

        $actionContainer.prepend(checkbox);
        $actionContainer.append(deleteBtn);
      });

      // Update bulk mode visibility
      if (State.get("groups.bulkDeleteMode")) {
        this.showBulkMode();
      }
    },

    /**
     * Add bulk delete controls
     */
    addBulkControls(context) {
      const containerId =
        context === "main"
          ? "content-preview"
          : context === "editor"
          ? "editor-content-preview"
          : "sticky-content-preview";

      const $container = $(`#${containerId}`);

      if (
        $container.length === 0 ||
        $container.find(".bulk-delete-controls").length > 0
      ) {
        return;
      }

      const bulkControls = `
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
      `;

      $container.prepend(bulkControls);
    },

    /**
     * Create delete button
     */
    createDeleteButton(index, context) {
      return $(`
        <button type="button" class="button button-small delete-group-btn" 
                data-group-index="${index}" 
                data-context="${context}"
                style="background: #dc3545; border-color: #dc3545; color: white; margin-left: 5px;">
          <span class="dashicons dashicons-trash" style="font-size: 12px; line-height: 1;"></span>
          Delete
        </button>
      `);
    },

    /**
     * Create checkbox
     */
    createCheckbox(index) {
      return $(`
        <input type="checkbox" class="group-checkbox" 
               data-group-index="${index}" 
               style="display: none; margin-right: 5px;">
      `);
    },

    /**
     * Handle single delete
     */
    handleSingleDelete(button) {
      const $button = $(button);
      const groupIndex = parseInt($button.data("group-index"));
      const context = $button.data("context") || "main";

      if (isNaN(groupIndex) || groupIndex < 0) {
        msg.error("Invalid group index");
        return;
      }

      // Confirm deletion
      if (this.config.confirmDelete) {
        if (
          !confirm(
            "Are you sure you want to delete this group? This action cannot be undone."
          )
        ) {
          return;
        }
      }

      // Show loading state
      $button
        .prop("disabled", true)
        .html(
          '<span class="spinner is-active" style="float: none; margin: 0;"></span> Deleting...'
        );

      // Make API call
      API.deleteGroup(groupIndex, State.get("scraped.url"))
        .then((response) => {
          State.set("scraped.data", response.updated_data);
          msg.success("Group deleted successfully");
        })
        .catch((error) => {
          msg.error(error.message || "Failed to delete group");

          // Reset button
          $button
            .prop("disabled", false)
            .html('<span class="dashicons dashicons-trash"></span> Delete');
        });
    },

    /**
     * Handle bulk delete
     */
    handleBulkDelete() {
      const markedGroups = State.get("groups.markedForDeletion");

      if (!markedGroups || markedGroups.size === 0) {
        msg.error("No groups selected for deletion");
        return;
      }

      const count = markedGroups.size;

      // Confirm deletion
      if (this.config.confirmDelete) {
        if (
          !confirm(
            `Are you sure you want to delete ${count} selected groups? This action cannot be undone.`
          )
        ) {
          return;
        }
      }

      // Show loading state
      const $button = $(".confirm-bulk-delete");
      $button
        .prop("disabled", true)
        .html(
          '<span class="spinner is-active" style="float: none; margin: 0;"></span> Deleting...'
        );

      // Convert Set to Array
      const indicesToDelete = Array.from(markedGroups);

      // Make API call
      API.deleteMultipleGroups(indicesToDelete, State.get("scraped.url"))
        .then((response) => {
          State.set("scraped.data", response.updated_data);
          msg.success(`Successfully deleted ${count} groups`);
          this.exitBulkMode();
        })
        .catch((error) => {
          msg.error(error.message || "Failed to delete selected groups");

          // Reset button
          $button
            .prop("disabled", false)
            .html(
              `<span class="dashicons dashicons-trash"></span> Delete Selected (<span class="selected-count">${count}</span>)`
            );
        });
    },

    /**
     * Toggle bulk mode
     */
    toggleBulkMode() {
      if (State.get("groups.bulkDeleteMode")) {
        this.exitBulkMode();
      } else {
        this.enterBulkMode();
      }
    },

    /**
     * Enter bulk mode
     */
    enterBulkMode() {
      State.actions.enterBulkDeleteMode();

      // Show checkboxes and bulk actions
      $(".group-checkbox").show();
      $(".bulk-delete-toggle").hide();
      $(".bulk-delete-actions").show();

      // Update UI
      $(".bulk-delete-controls").css("border-left-color", "#dc3545");

      EventBus.emit("groupmanager:bulkmode:enter");
    },

    /**
     * Exit bulk mode
     */
    exitBulkMode() {
      State.actions.exitBulkDeleteMode();

      // Hide checkboxes and bulk actions
      $(".group-checkbox").hide().prop("checked", false);
      $(".bulk-delete-toggle").show();
      $(".bulk-delete-actions").hide();

      // Reset UI
      $(".bulk-delete-controls").css("border-left-color", "#ffc107");
      this.updateSelectedCount();

      EventBus.emit("groupmanager:bulkmode:exit");
    },

    /**
     * Show bulk mode (for persistence)
     */
    showBulkMode() {
      $(".group-checkbox").show();
      $(".bulk-delete-toggle").hide();
      $(".bulk-delete-actions").show();
      $(".bulk-delete-controls").css("border-left-color", "#dc3545");

      // Restore checked state
      const marked = State.get("groups.markedForDeletion");
      if (marked) {
        marked.forEach((index) => {
          $(`.group-checkbox[data-group-index="${index}"]`).prop(
            "checked",
            true
          );
        });
      }

      this.updateSelectedCount();
    },

    /**
     * Handle checkbox change
     */
    handleCheckboxChange(checkbox) {
      const $checkbox = $(checkbox);
      const groupIndex = parseInt($checkbox.data("group-index"));

      State.actions.toggleGroupSelection(groupIndex);
      this.updateSelectedCount();

      EventBus.emit("groupmanager:selection:changed", {
        index: groupIndex,
        selected: $checkbox.is(":checked"),
      });
    },

    /**
     * Update selected count display
     */
    updateSelectedCount() {
      const marked = State.get("groups.markedForDeletion");
      const count = marked ? marked.size : 0;

      $(".selected-count").text(count);
      $(".confirm-bulk-delete").prop("disabled", count === 0);
    },

    /**
     * Handle delete success
     */
    onDeleteSuccess(data) {
      // Refresh display
      const context = State.computed.getContext();
      Renderer.render(data.data.data, State.get("scraped.url"), context);

      // Re-add delete buttons
      setTimeout(() => {
        this.addDeleteButtons();
      }, 100);
    },

    /**
     * Handle bulk delete success
     */
    onBulkDeleteSuccess(data) {
      // Refresh display
      const context = State.computed.getContext();
      Renderer.render(data.data.data, State.get("scraped.url"), context);

      // Re-add delete buttons
      setTimeout(() => {
        this.addDeleteButtons();
      }, 100);
    },

    /**
     * Select all groups
     */
    selectAll() {
      $(".group-checkbox").each(function () {
        const $checkbox = $(this);
        if (!$checkbox.is(":checked")) {
          $checkbox.prop("checked", true).trigger("change");
        }
      });
    },

    /**
     * Deselect all groups
     */
    deselectAll() {
      $(".group-checkbox").each(function () {
        const $checkbox = $(this);
        if ($checkbox.is(":checked")) {
          $checkbox.prop("checked", false).trigger("change");
        }
      });
    },

    /**
     * Get group count
     */
    getGroupCount() {
      return $(".grouped-elements, .item").length;
    },

    /**
     * Get selected groups
     */
    getSelectedGroups() {
      const marked = State.get("groups.markedForDeletion");
      return marked ? Array.from(marked) : [];
    },

    /**
     * Delete group by index (programmatic)
     */
    deleteGroupByIndex(index) {
      return API.deleteGroup(index, State.get("scraped.url")).then(
        (response) => {
          State.set("scraped.data", response.updated_data);
          EventBus.emit(EventBus.events.GROUP_DELETED, {
            index: index,
            data: response,
          });
          return response;
        }
      );
    },

    /**
     * Set configuration
     */
    setConfig(key, value) {
      this.config[key] = value;
    },
  };

  // Export
  window.GroupManager = GroupManager;

  if ($) {
    $.migrationGroupManager = GroupManager;
  }

  // Initialize
  $(document).ready(function () {
    GroupManager.init();
  });
})(window, jQuery);
