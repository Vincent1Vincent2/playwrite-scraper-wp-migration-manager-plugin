/**
 * DragDrop Module for Migration Manager
 * Handles all drag and drop functionality with TinyMCE and field detection
 *
 * @module DragDrop
 */

(function (window, $) {
  "use strict";

  const DragDrop = {
    /**
     * Configuration
     */
    config: {
      blinkDelay: 1500,
      blinkClass: "blinking",
      dragOverClass: "drag-over",
      draggableClass: "draggable-item",
      dropEffect: "copy",

      // Selectors for containers and targets
      containers: [
        ".wp-editor-container",
        ".acf-field-wysiwyg",
        ".wp-block",
        ".meta-box-sortables",
        ".custom-field",
      ],

      dropTargets: [
        "textarea",
        'input[type="text"]',
        'input[type="url"]',
        'input[type="email"]',
        ".wp-editor-area",
        "#content",
        "#title",
        "#excerpt",
        ".mce-edit-area",
        'iframe[id$="_ifr"]',
        '[contenteditable="true"]',
        ".acf-input input",
        ".acf-input textarea",
      ],
    },

    /**
     * State
     */
    zones: new Map(),
    timers: new Map(),
    initialized: false,

    /**
     * Initialize module
     */
    init() {
      if (this.initialized) return;

      this.detectDropZones();
      this.bindEvents();
      this.observeDOM();
      this.initTinyMCE();

      this.initialized = true;
      console.log("DragDrop module initialized");
    },

    /**
     * Detect all drop zones
     */
    detectDropZones() {
      this.zones.clear();

      // Detect containers
      $(this.config.containers.join(",")).each((i, el) => {
        this.registerZone(el, "container");
      });

      // Detect standalone targets
      const containerSelector = this.config.containers.join(",");
      $(this.config.dropTargets.join(",")).each((i, el) => {
        if ($(el).closest(containerSelector).length === 0) {
          this.registerZone(el, "target");
        }
      });

      EventBus.emit("dragdrop:zones:detected", this.zones.size);
    },

    /**
     * Register a drop zone
     */
    registerZone(element, type) {
      const $el = $(element);
      const id = element.id || `zone-${type}-${this.zones.size}`;

      this.zones.set(id, {
        element: element,
        $element: $el,
        type: type,
        priority: this.getZonePriority(element),
        isContainer: type === "container",
      });
    },

    /**
     * Get zone priority
     */
    getZonePriority(element) {
      const $el = $(element);

      if ($el.is('iframe[id$="_ifr"]')) return 1;
      if ($el.hasClass("wp-editor-area")) return 2;
      if ($el.is("textarea")) return 3;
      if ($el.is("[contenteditable]")) return 4;
      if ($el.is("input")) return 5;

      return 10;
    },

    /**
     * Bind events
     */
    bindEvents() {
      const self = this;

      // Container events
      $(document)
        .off(".dragdrop")
        .on(
          "dragenter.dragdrop",
          this.config.containers.join(","),
          function (e) {
            self.handleContainerDragEnter(e, this);
          }
        )
        .on(
          "dragover.dragdrop",
          this.config.containers.join(","),
          function (e) {
            self.handleContainerDragOver(e, this);
          }
        )
        .on(
          "dragleave.dragdrop",
          this.config.containers.join(","),
          function (e) {
            self.handleContainerDragLeave(e, this);
          }
        )
        .on("drop.dragdrop", this.config.containers.join(","), function (e) {
          self.handleContainerDrop(e, this);
        });

      // Target events
      $(document)
        .on(
          "dragenter.dragdrop",
          this.config.dropTargets.join(","),
          function (e) {
            if (
              $(this).closest(self.config.containers.join(",")).length === 0
            ) {
              self.handleTargetDragEnter(e, this);
            }
          }
        )
        .on(
          "dragover.dragdrop",
          this.config.dropTargets.join(","),
          function (e) {
            if (
              $(this).closest(self.config.containers.join(",")).length === 0
            ) {
              e.preventDefault();
              e.originalEvent.dataTransfer.dropEffect = self.config.dropEffect;
            }
          }
        )
        .on(
          "dragleave.dragdrop",
          this.config.dropTargets.join(","),
          function (e) {
            if (
              $(this).closest(self.config.containers.join(",")).length === 0
            ) {
              self.handleTargetDragLeave(e, this);
            }
          }
        )
        .on("drop.dragdrop", this.config.dropTargets.join(","), function (e) {
          if ($(this).closest(self.config.containers.join(",")).length === 0) {
            self.handleTargetDrop(e, this);
          }
        });

      // Draggable items
      $(document)
        .on("dragstart.dragdrop", ".draggable-item", function (e) {
          self.handleDragStart(e, this);
        })
        .on("dragend.dragdrop", ".draggable-item", function (e) {
          self.handleDragEnd(e, this);
        });
    },

    /**
     * Container handlers
     */
    handleContainerDragEnter(e, element) {
      e.preventDefault();
      const $container = $(element);

      $container.addClass(this.config.dragOverClass);

      // Clear existing timer
      const timerId = $container.data("timer-id");
      if (timerId) {
        clearTimeout(timerId);
      }

      // Set new timer for blink effect
      const timer = setTimeout(() => {
        $container.addClass(this.config.blinkClass);
        $container
          .find(this.config.dropTargets.join(","))
          .addClass(this.config.blinkClass);

        EventBus.emit("dragdrop:blink:start", element);
      }, this.config.blinkDelay);

      $container.data("timer-id", timer);
    },

    handleContainerDragOver(e, element) {
      e.preventDefault();
      e.originalEvent.dataTransfer.dropEffect = this.config.dropEffect;
    },

    handleContainerDragLeave(e, element) {
      const $container = $(element);

      // Check if we're really leaving the container
      if (!this.isRelatedTarget(element, e.originalEvent.relatedTarget)) {
        this.clearContainerState($container);
      }
    },

    handleContainerDrop(e, element) {
      e.preventDefault();
      e.stopPropagation();

      const $container = $(element);

      if ($container.hasClass(this.config.blinkClass)) {
        const target = this.findBestTarget($container);
        if (target) {
          this.insertContent(target);
        }
      }

      this.clearContainerState($container);
      State.actions.endDrag();
    },

    /**
     * Target handlers
     */
    handleTargetDragEnter(e, element) {
      e.preventDefault();
      const $target = $(element);

      $target.addClass(this.config.dragOverClass);

      const timer = setTimeout(() => {
        $target.addClass(this.config.blinkClass);
        EventBus.emit("dragdrop:blink:start", element);
      }, this.config.blinkDelay);

      $target.data("timer-id", timer);
    },

    handleTargetDragLeave(e, element) {
      const $target = $(element);

      if (!this.isRelatedTarget(element, e.originalEvent.relatedTarget)) {
        this.clearTargetState($target);
      }
    },

    handleTargetDrop(e, element) {
      e.preventDefault();
      e.stopPropagation();

      const $target = $(element);

      if ($target.hasClass(this.config.blinkClass)) {
        this.insertContent($target);
      }

      this.clearTargetState($target);
      State.actions.endDrag();
    },

    /**
     * Drag start/end handlers
     */
    handleDragStart(e, element) {
      const $element = $(element);
      const content = $element.find(".item-text").text();
      State.actions.startDrag(content, element);

      $element.addClass("dragging");
      e.originalEvent.dataTransfer.effectAllowed = "copy";
      e.originalEvent.dataTransfer.setData("text/plain", content);

      EventBus.emit(EventBus.events.DRAG_START, { element, content });
    },

    handleDragEnd(e, element) {
      $(element).removeClass("dragging");
      State.actions.endDrag();
      EventBus.emit(EventBus.events.DRAG_END, element);
    },

    /**
     * Content insertion
     */
    insertContent($target) {
      const content = State.get("drag.content");
      if (!content) return false;

      const element = $target[0];
      let success = false;

      // Try TinyMCE first
      if (this.isTinyMCE($target)) {
        success = this.insertIntoTinyMCE($target, content);
      }
      // Contenteditable
      else if ($target.is("[contenteditable]")) {
        success = this.insertIntoContentEditable($target, content);
      }
      // Regular input/textarea
      else if ($target.is("input, textarea")) {
        success = this.insertIntoInput($target, content);
      }

      if (success) {
        EventBus.emit(EventBus.events.DRAG_DROP, { target: element, content });
        msg.success("Content dropped successfully!");
      }

      return success;
    },

    insertIntoTinyMCE($target, content) {
      try {
        let editor = null;

        if ($target.is('iframe[id$="_ifr"]')) {
          const editorId = $target.attr("id").replace("_ifr", "");
          editor = tinymce.get(editorId);
        }

        if (editor && !editor.isHidden()) {
          editor.focus();
          editor.execCommand("mceInsertContent", false, content);
          return true;
        }

        // Fallback to iframe document
        const iframeDoc =
          $target[0].contentDocument || $target[0].contentWindow.document;
        if (iframeDoc && iframeDoc.body) {
          iframeDoc.body.focus();
          iframeDoc.execCommand("insertHTML", false, content);
          return true;
        }
      } catch (e) {
        console.error("TinyMCE insertion error:", e);
      }

      return false;
    },

    insertIntoContentEditable($target, content) {
      try {
        const element = $target[0];
        element.focus();

        if (document.execCommand) {
          document.execCommand("insertHTML", false, content);
        } else {
          element.innerHTML += content;
        }

        $target.trigger("input").trigger("change");
        return true;
      } catch (e) {
        console.error("ContentEditable insertion error:", e);
        return false;
      }
    },

    insertIntoInput($target, content) {
      try {
        const element = $target[0];
        const currentValue = $target.val() || "";
        const cursorPos = element.selectionStart || currentValue.length;

        const newValue =
          currentValue.slice(0, cursorPos) +
          content +
          currentValue.slice(cursorPos);

        $target.val(newValue);

        // Set cursor position
        const newPos = cursorPos + content.length;
        if (element.setSelectionRange) {
          element.setSelectionRange(newPos, newPos);
        }

        element.focus();
        $target.trigger("input").trigger("change");

        return true;
      } catch (e) {
        console.error("Input insertion error:", e);
        return false;
      }
    },

    /**
     * Find best target in container
     */
    findBestTarget($container) {
      const targets = $container.find(this.config.dropTargets.join(","));

      if (targets.length === 0) {
        return $container.is(this.config.dropTargets.join(","))
          ? $container
          : null;
      }

      // Sort by priority
      const sorted = targets.toArray().sort((a, b) => {
        return this.getZonePriority(a) - this.getZonePriority(b);
      });

      return $(sorted[0]);
    },

    /**
     * TinyMCE specific initialization
     */
    initTinyMCE() {
      if (typeof tinymce === "undefined") return;

      const self = this;

      // Handle new editors
      tinymce.on("AddEditor", function (e) {
        e.editor.on("init", function () {
          self.setupTinyMCEEditor(this);
        });
      });

      // Handle existing editors
      if (tinymce.editors) {
        tinymce.editors.forEach((editor) => {
          if (editor.initialized && !editor.removed) {
            self.setupTinyMCEEditor(editor);
          } else if (!editor.removed) {
            editor.on("init", function () {
              self.setupTinyMCEEditor(this);
            });
          }
        });
      }
    },

    setupTinyMCEEditor(editor) {
      const self = this;
      const iframe = editor.iframeElement;
      if (!iframe) return;

      const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
      const $body = $(iframeDoc.body);

      $body
        .on("dragenter", function (e) {
          e.preventDefault();
          $(iframe).addClass(self.config.dragOverClass);

          const timer = setTimeout(() => {
            $(iframe).addClass(self.config.blinkClass);
          }, self.config.blinkDelay);

          $(iframe).data("timer-id", timer);
        })
        .on("dragover", function (e) {
          e.preventDefault();
        })
        .on("dragleave", function (e) {
          if (!self.isRelatedTarget(iframeDoc.body, e.relatedTarget)) {
            self.clearTargetState($(iframe));
          }
        })
        .on("drop", function (e) {
          e.preventDefault();

          if ($(iframe).hasClass(self.config.blinkClass)) {
            const content = State.get("drag.content");
            if (content) {
              editor.execCommand("mceInsertContent", false, content);
              msg.success("Content dropped into editor!");
            }
          }

          self.clearTargetState($(iframe));
          State.actions.endDrag();
        });
    },

    /**
     * Make element draggable
     */
    makeDraggable(element, content) {
      const $el = $(element);

      $el
        .addClass(this.config.draggableClass)
        .attr("draggable", "true")
        .data("drag-content", content)
        .css({
          cursor: "grab",
          "user-select": "none",
        });

      return $el;
    },

    /**
     * Utilities
     */
    isRelatedTarget(target, related) {
      if (!related) return false;
      return target.contains(related) || target === related;
    },

    isTinyMCE($element) {
      return (
        $element.is('iframe[id$="_ifr"]') ||
        $element.closest(".mce-tinymce").length > 0
      );
    },

    clearContainerState($container) {
      const timerId = $container.data("timer-id");
      if (timerId) {
        clearTimeout(timerId);
        $container.removeData("timer-id");
      }

      $container.removeClass(
        `${this.config.dragOverClass} ${this.config.blinkClass}`
      );
      $container
        .find("*")
        .removeClass(`${this.config.dragOverClass} ${this.config.blinkClass}`);
    },

    clearTargetState($target) {
      const timerId = $target.data("timer-id");
      if (timerId) {
        clearTimeout(timerId);
        $target.removeData("timer-id");
      }

      $target.removeClass(
        `${this.config.dragOverClass} ${this.config.blinkClass}`
      );
    },

    /**
     * DOM observation
     */
    observeDOM() {
      if (!window.MutationObserver) return;

      const self = this;
      const observer = new MutationObserver(function (mutations) {
        let shouldUpdate = false;

        mutations.forEach((mutation) => {
          if (mutation.addedNodes.length) {
            mutation.addedNodes.forEach((node) => {
              if (node.nodeType === 1) {
                const $node = $(node);
                if (
                  $node.is(self.config.containers.join(",")) ||
                  $node.find(self.config.containers.join(",")).length
                ) {
                  shouldUpdate = true;
                }
              }
            });
          }
        });

        if (shouldUpdate) {
          self.detectDropZones();
        }
      });

      observer.observe(document.body, {
        childList: true,
        subtree: true,
      });
    },
  };

  // Export
  window.DragDrop = DragDrop;

  if ($) {
    $.migrationDragDrop = DragDrop;
  }

  // Initialize
  $(document).ready(function () {
    DragDrop.init();
  });
})(window, jQuery);
