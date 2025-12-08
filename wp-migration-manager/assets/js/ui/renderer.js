/**
 * Unified Renderer Module for Migration Manager
 * Handles all rendering contexts (main, editor, sticky) with a single codebase
 *
 * @module Renderer
 */

(function (window, $) {
  "use strict";

  const Renderer = {
    /**
     * Configuration for different rendering contexts
     */
    config: {
      contexts: {
        main: {
          compact: false,
          showFullPreview: true,
          containerClass: "migration-results-full",
          statsContainer: "#migration-stats",
          contentContainer: "#content-preview",
        },
        editor: {
          compact: true,
          showFullPreview: false,
          containerClass: "migration-results-compact",
          statsContainer: "#editor-content-stats",
          contentContainer: "#editor-content-preview",
        },
        sticky: {
          compact: true,
          showFullPreview: false,
          containerClass: "migration-results-compact",
          statsContainer: "#sticky-content-stats",
          contentContainer: "#sticky-content-preview",
        },
      },
    },

    /**
     * Main render function - single entry point for all rendering
     * @param {Array} data - The scraped data to render
     * @param {String} sourceUrl - Source URL of the scraped content
     * @param {String} context - Rendering context (main/editor/sticky)
     */
    render(data, sourceUrl, context = "main") {
      const config = this.config.contexts[context];

      if (!config) {
        console.error("Unknown render context:", context);
        return false;
      }

      // Show container if hidden
      const containerId =
        context === "main"
          ? "migration-results"
          : context === "editor"
          ? "editor-scraped-content"
          : "sticky-scraped-content";
      $(`#${containerId}`).show();

      // Update source URL if in main context
      if (context === "main" && sourceUrl) {
        $("#source-url-link").attr("href", sourceUrl).text(sourceUrl);
      }

      // Render stats
      this.renderStats(data, config.statsContainer);

      // Render content
      this.renderContent(data, config);

      // Post-render tasks
      this.afterRender(context);

      return true;
    },

    /**
     * Render statistics
     */
    renderStats(data, containerSelector) {
      if (!data || !data.length) {
        $(containerSelector).html("<p>No data to display</p>");
        return;
      }

      const stats = this.calculateStats(data);
      const statsHTML = this.buildStatsHTML(stats);
      $(containerSelector).html(statsHTML);
    },

    /**
     * Calculate statistics from data
     */
    calculateStats(data) {
      return {
        total: data.length,
        text: data.filter((item) => item.type === "text").length,
        links: data.filter((item) => item.type === "link").length,
        images: data.filter((item) => item.type === "image").length,
        videos: data.filter((item) => item.type === "video").length,
        groups: data.filter((item) => item.wrapper === true).length,
      };
    },

    /**
     * Build stats HTML
     */
    buildStatsHTML(stats) {
      const isCompact = this.getCurrentContext() !== "main";

      if (isCompact) {
        // Compact stats for sidebar
        return `
          <div class="stats-row">
            <span class="stat-item">
              <strong>${stats.total}</strong> Total
            </span>
            <span class="stat-item">
              <strong>${stats.text}</strong> Text
            </span>
            <span class="stat-item">
              <strong>${stats.links}</strong> Links
            </span>
            <span class="stat-item">
              <strong>${stats.images}</strong> Images
            </span>
          </div>
        `;
      }

      // Full stats for main view
      return `
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
    },

    /**
     * Render main content
     */
    renderContent(data, config) {
      const container = $(config.contentContainer);

      if (!data || data.length === 0) {
        container.html("<p>No content found to display</p>");
        return;
      }

      const html = data
        .map((item, index) => {
          if (
            item.wrapper === true &&
            item.children &&
            item.children.length > 0
          ) {
            return this.renderGroup(item, index, config);
          }
          return this.renderItem(item, index, config);
        })
        .join("");

      container.html(html);
    },

    /**
     * Render a group of items
     */
    renderGroup(group, index, config) {
      const isCompact = config.compact;
      const groupContext = this.buildGroupContext(group, isCompact);
      const childrenHTML = group.children
        .map((child, childIndex) =>
          this.renderItem(child, `${index}-${childIndex}`, config, true)
        )
        .join("");

      if (isCompact) {
        // Compact version for sidebar
        return `
          <div class="grouped-elements" data-group-index="${index}" style="margin-bottom: 15px; background: #fff8e1; border-radius: 6px; border: 1px solid #ffc107; padding: 8px;">
            ${groupContext}
            ${childrenHTML}
          </div>
        `;
      }

      // Full version for main view
      return `
        <div class="grouped-elements" data-group-index="${index}" style="margin-bottom: 25px; background: #fff8e1; border-radius: 12px; border: 2px solid #ffc107; overflow: hidden;">
          ${groupContext}
          <div class="group-items" style="padding: 20px;">
            ${childrenHTML}
          </div>
        </div>
      `;
    },

    /**
     * Build group context header
     */
    buildGroupContext(group, isCompact) {
      const padding = isCompact ? "8px" : "15px 20px";
      const fontSize = isCompact ? "11px" : "12px";

      if (group.type === "link") {
        return `
          <div class="group-context" style="background: #ffc107; color: #333; padding: ${padding}; font-size: ${fontSize}; border-radius: 4px; ${
          !isCompact
            ? "border-bottom: 1px solid rgba(0,0,0,0.1);"
            : "margin-bottom: 8px;"
        }">
            <strong>Link Group:</strong> 
            <a href="${
              group.url
            }" target="_blank" style="color: #333;">${this.escapeHtml(
          group.text
        )}</a>
            ${
              !isCompact
                ? `<button class="button button-small copy-btn" data-text="${this.escapeHtml(
                    group.url
                  )}" style="margin-left: 10px;">Copy Link</button>`
                : ""
            }
          </div>
        `;
      }

      return `
        <div class="group-context" style="background: #ffc107; color: #333; padding: ${padding}; font-size: ${fontSize}; border-radius: 4px; ${
        !isCompact
          ? "border-bottom: 1px solid rgba(0,0,0,0.1);"
          : "margin-bottom: 8px;"
      }">
          <strong>Group:</strong> ${group.element || group.type}
          ${group.text ? `<div>${this.escapeHtml(group.text)}</div>` : ""}
        </div>
      `;
    },

    /**
     * Render individual item
     */
    renderItem(item, index, config, isGroupChild = false) {
      const isCompact = config.compact;
      const itemContent = this.buildItemContent(item, isCompact);
      const itemActions = this.buildItemActions(item, isCompact);
      const itemMeta = this.buildItemMeta(item, isCompact);

      // Determine styling based on context and state
      const itemClass = this.getItemClass(item, isGroupChild);
      const itemStyle = this.getItemStyle(item, isGroupChild, isCompact);
      const typeStyle = this.getTypeStyle(item, isGroupChild, isCompact);

      return `
        <div class="${itemClass}" data-item-index="${index}" style="${itemStyle}">
          <div class="item-type" style="${typeStyle}">${item.type.toUpperCase()}</div>
          ${itemContent}
          ${itemActions}
          ${itemMeta}
        </div>
      `;
    },

    /**
     * Build item content based on type
     */
    buildItemContent(item, isCompact) {
      switch (item.type) {
        case "text":
          return this.buildTextContent(item, isCompact);
        case "link":
          return this.buildLinkContent(item, isCompact);
        case "image":
          return this.buildImageContent(item, isCompact);
        case "video":
          return this.buildVideoContent(item, isCompact);
        default:
          return `<div>Unknown type: ${item.type}</div>`;
      }
    },

    /**
     * Build text content
     */
    buildTextContent(item, isCompact) {
      if (isCompact) {
        return `
          <div class="item-text" style="font-size: 12px; margin-bottom: 6px; padding: 6px; background: white; border-radius: 4px;">
            ${this.escapeHtml(this.truncateText(item.text, 100))}
          </div>
        `;
      }

      const tag = item.element || "div";
      let attributes = "";
      if (item.class) attributes += ` class="${this.escapeHtml(item.class)}"`;
      if (item.id) attributes += ` id="${this.escapeHtml(item.id)}"`;

      return `
        <div class="item-preview" style="margin-bottom: 15px;">
          <div class="preview-label" style="font-size: 12px; color: #666; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">Rendered Element:</div>
          <div class="rendered-element" style="padding: 15px; background: white; border: 1px solid #e1e8ed; border-radius: 6px;">
            <${tag}${attributes}>${this.escapeHtml(item.text)}</${tag}>
          </div>
        </div>
      `;
    },

    /**
     * Build link content
     */
    buildLinkContent(item, isCompact) {
      if (isCompact) {
        return `
          <div class="item-link" style="font-size: 12px; margin-bottom: 6px;">
            <a href="${item.url}" target="_blank" style="color: #667eea;">
              ${this.escapeHtml(this.truncateText(item.text, 50))}
            </a>
          </div>
        `;
      }

      return `
        <div class="item-preview" style="margin-bottom: 15px;">
          <div class="preview-label" style="font-size: 12px; color: #666; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">Rendered Element:</div>
          <div class="rendered-element" style="padding: 15px; background: white; border: 1px solid #e1e8ed; border-radius: 6px;">
            <a href="${
              item.url
            }" target="_blank" style="color: #667eea; text-decoration: none; font-weight: 500;">
              ${this.escapeHtml(item.text)}
            </a>
          </div>
        </div>
      `;
    },

    /**
     * Build image content
     */
    buildImageContent(item, isCompact) {
      if (isCompact) {
        return `
          <div class="item-image" style="font-size: 12px; margin-bottom: 6px;">
            <img src="${item.url}" alt="${item.alt || ""}" 
                 style="max-width: 100%; height: auto; max-height: 60px; border-radius: 4px;" 
                 onerror="this.style.display='none';" />
          </div>
        `;
      }

      let imageHTML;
      if (item.source === "css_background") {
        imageHTML = `
          <div style="width: 300px; height: 200px; background-image: url('${
            item.url
          }'); background-size: cover; background-position: center; border-radius: 4px;">
            ${
              item.alt
                ? `<div style="position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.7); color: white; padding: 8px; font-size: 12px;">${this.escapeHtml(
                    item.alt
                  )}</div>`
                : ""
            }
          </div>
        `;
      } else {
        imageHTML = `
          <img src="${item.url}" alt="${item.alt || ""}" 
               style="max-width: 300px; height: auto;" 
               onerror="this.style.display='none'; this.nextElementSibling.style.display='block';" />
          <div class="image-fallback" style="display:none; padding:20px; background:#f0f0f0; text-align:center; border-radius:6px;">
            <p>Image failed to load</p>
            <small>${item.url}</small>
          </div>
        `;
      }

      return `
        <div class="item-preview" style="margin-bottom: 15px;">
          <div class="preview-label" style="font-size: 12px; color: #666; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">Rendered Element:</div>
          <div class="rendered-element" style="padding: 15px; background: white; border: 1px solid #e1e8ed; border-radius: 6px;">
            ${imageHTML}
          </div>
        </div>
      `;
    },

    /**
     * Build video content
     */
    buildVideoContent(item, isCompact) {
      if (isCompact) {
        return `
          <div class="item-video" style="font-size: 12px; margin-bottom: 6px; padding: 6px; background: #f0f0f0; border-radius: 4px;">
            📹 Video: ${this.truncateText(item.url, 50)}...
          </div>
        `;
      }

      const videoAttrs = item.poster ? `poster="${item.poster}"` : "";
      const sourceAttrs = item.format ? `type="${item.format}"` : "";

      return `
        <div class="item-preview" style="margin-bottom: 15px;">
          <div class="preview-label" style="font-size: 12px; color: #666; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">Rendered Element:</div>
          <div class="rendered-element" style="padding: 15px; background: white; border: 1px solid #e1e8ed; border-radius: 6px;">
            <video controls style="max-width: 400px; height: auto;" ${videoAttrs}>
              <source src="${item.url}" ${sourceAttrs}>
              Your browser does not support the video tag.
            </video>
          </div>
        </div>
      `;
    },

    /**
     * Build item actions
     */
    buildItemActions(item, isCompact) {
      const actions = [];

      switch (item.type) {
        case "text":
          actions.push(
            `<button class="button button-small copy-btn" data-text="${this.escapeHtml(
              item.text
            )}">Copy${isCompact ? "" : " Text"}</button>`
          );
          break;
        case "link":
          actions.push(
            `<button class="button button-small copy-btn" data-text="${this.escapeHtml(
              item.text
            )}">${isCompact ? "Text" : "Copy Text"}</button>`
          );
          actions.push(
            `<button class="button button-small copy-btn" data-text="${this.escapeHtml(
              item.url
            )}">${isCompact ? "URL" : "Copy Link"}</button>`
          );
          break;
        case "image":
          // Always show upload button, with shorter text in compact mode
          actions.push(
            `<button class="button button-small download-btn" data-url="${this.escapeHtml(
              item.url
            )}" data-filename="${this.escapeHtml(
              item.alt || "image"
            )}" data-alt="${this.escapeHtml(
              item.alt || ""
            )}">${isCompact ? "Upload" : "Upload to WordPress"}</button>`
          );
          actions.push(
            `<button class="button button-small copy-btn" data-text="${this.escapeHtml(
              item.url
            )}">${isCompact ? "Copy" : "Copy URL"}</button>`
          );
          break;
        case "video":
          actions.push(
            `<button class="button button-small copy-btn" data-text="${this.escapeHtml(
              item.url
            )}">Copy${isCompact ? "" : " Video"} URL</button>`
          );
          break;
      }

      if (actions.length === 0) return "";

      const margin = isCompact ? "6px" : "10px";
      const gap = isCompact ? "4px" : "8px";

      return `
        <div class="action-buttons" style="margin-top: ${margin}; display: flex; flex-wrap: wrap; gap: ${gap};">
          ${actions.join("")}
        </div>
      `;
    },

    /**
     * Build item metadata
     */
    buildItemMeta(item, isCompact) {
      const meta = [];

      switch (item.type) {
        case "text":
          meta.push(`Element: ${item.element}`);
          if (item.class) meta.push(`Class: ${item.class}`);
          if (item.id) meta.push(`ID: ${item.id}`);
          break;
        case "link":
          if (!isCompact) meta.push(`URL: ${item.url}`);
          break;
        case "image":
          if (!isCompact) {
            meta.push(`URL: ${item.url}`);
            if (item.alt) meta.push(`Alt: ${item.alt}`);
            if (item.source) meta.push(`Source: ${item.source}`);
          }
          break;
        case "video":
          if (!isCompact) {
            meta.push(`URL: ${item.url}`);
            if (item.format) meta.push(`Format: ${item.format}`);
          }
          break;
      }

      if (meta.length === 0) return "";

      const fontSize = isCompact ? "10px" : "12px";
      const margin = isCompact ? "4px" : "8px";

      return `<div class="item-meta" style="font-size: ${fontSize}; color: #888; margin-top: ${margin};">${meta.join(
        " | "
      )}</div>`;
    },

    /**
     * Get item CSS class
     */
    getItemClass(item, isGroupChild) {
      const classes = ["item"];
      if (isGroupChild) classes.push("grouped-child");
      if (item.wrapper) classes.push("wrapper");
      classes.push(`item-type-${item.type}`);
      return classes.join(" ");
    },

    /**
     * Get item inline styles
     */
    getItemStyle(item, isGroupChild, isCompact) {
      const padding = isCompact ? "8px" : "20px";
      const marginBottom = isCompact ? "10px" : "20px";
      const borderRadius = isCompact ? "4px" : "8px";

      let style = `margin-bottom: ${marginBottom}; padding: ${padding}; border-radius: ${borderRadius};`;

      if (isGroupChild) {
        style += isCompact
          ? "background: #f8f9fa; border: 1px solid #e1e8ed;"
          : "background: white; border-left: 3px solid #28a745;";
      } else if (item.wrapper) {
        style += "background: #fff3cd; border-left: 4px solid #ffc107;";
      } else {
        style += isCompact
          ? "background: white; border: 1px solid #e1e8ed;"
          : "background: #f8f9fa; border-left: 4px solid #667eea;";
      }

      return style;
    },

    /**
     * Get type badge styles
     */
    getTypeStyle(item, isGroupChild, isCompact) {
      const fontSize = isCompact ? "10px" : "12px";
      const padding = isCompact ? "2px 8px" : "4px 12px";
      const marginBottom = isCompact ? "4px" : "10px";

      let style = `display: inline-block; padding: ${padding}; border-radius: 20px; font-size: ${fontSize}; font-weight: 600; text-transform: uppercase; margin-bottom: ${marginBottom};`;

      if (isGroupChild) {
        style += "background: #28a745; color: white;";
      } else if (item.wrapper) {
        style += "background: #ffc107; color: #333;";
      } else {
        style += "background: #667eea; color: white;";
      }

      return style;
    },

    /**
     * Post-render tasks
     */
    afterRender(context) {
      // Enable action buttons when data is rendered (main context only)
      if (context === "main") {
        $("#create-posts, #create-pages, #save-draft, #download-images").prop("disabled", false);
      }

      // Trigger event for other modules to hook into
      if (window.EventBus) {
        window.EventBus.emit("contentRendered", { context });
      }

      // Add event listeners for buttons
      this.attachEventListeners();

      // Initialize draggable items
      this.initializeDraggable();

      // Add delete buttons (will be handled by GroupManager)
      setTimeout(() => {
        if (
          window.GroupManager &&
          typeof window.GroupManager.addDeleteButtons === "function"
        ) {
          window.GroupManager.addDeleteButtons();
        }
      }, 100);

      // Scroll to results if main context
      if (context === "main") {
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
    },

    /**
     * Attach event listeners to rendered elements
     */
    attachEventListeners() {
      // Remove existing listeners to prevent duplicates
      $(".copy-btn").off("click.renderer");
      $(".download-btn").off("click.renderer");

      // Copy buttons
      $(".copy-btn").on("click.renderer", function (e) {
        e.preventDefault();
        const text = $(this).data("text");
        if (window.Clipboard) {
          window.Clipboard.copy(text);
        } else if (window.copyText) {
          window.copyText(text);
        }
      });

      // Download buttons
      $(".download-btn").on("click.renderer", function (e) {
        e.preventDefault();
        e.stopPropagation();
        const url = $(this).data("url");
        const filename = $(this).data("filename");
        const alt = $(this).data("alt") || filename || "image";
        
        // Upload to WordPress instead of downloading to computer
        if (window.MigrationManagerApp && window.MigrationManagerApp.getInstance) {
          const app = window.MigrationManagerApp.getInstance();
          if (app.uploadSingleImage) {
            app.uploadSingleImage(url, alt, $(this));
            return false;
          }
        }
        
        // If upload method not available, show error instead of downloading
        if (window.Messages) {
          Messages.error("Upload functionality not available. Please refresh the page.");
        } else {
          alert("Upload functionality not available. Please refresh the page.");
        }
        return false;
      });
    },

    /**
     * Initialize draggable functionality
     */
    initializeDraggable() {
      console.log("init draggable on", $(".item").length);
      const self = this; // Store reference to the Renderer instance

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
            console.log("Making image draggable:", { imageUrl, imageAlt });
          }
        } 
        // Check if it's a link
        else if ($linkElement.length) {
          dragContent = $linkElement.text() + " - " + $linkElement.attr("href");
        } 
        // Check if it's text
        else if ($textElement.length) {
          dragContent = $textElement.text();
        }

        if (dragContent) {
          console.log(
            "init make draggable for",
            "element",
            $item[0],
            "content",
            dragContent.substring(0, 50) + "...",
            "isHTML",
            isHTML
          );
          self.makeDraggable($item[0], dragContent, isHTML); // Use self instead of this
        } else {
          console.log("no dragContent for item");
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
            self.makeDraggable(this, imageHTML, true);
          }
        } else {
          // It's text
          const text = $elem.text() || $elem[0].innerText;
          if (text) {
            self.makeDraggable(this, text, false);
          } else {
            console.log("no text");
          }
        }
      });

      $(".copy-btn").each(function () {
        const text = $(this).data("text");
        if (text) {
          self.makeDraggable(this, text, false);
        }
      });
    },

    /**
     * Make an element draggable with specified content (text or HTML)
     */
    makeDraggable(element, content, isHTML = false) {
      console.log("called makeDraggable", { isHTML, contentPreview: content.substring(0, 50) });
      const $element = $(element);
      let draggedContent = content; // Declare the variable that will be used in drag events

      console.log("Making element draggable:", {
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
        draggedContent = content;
        console.log("Drag start:", {
          element: this.tagName + (this.id ? "#" + this.id : ""),
          contentPreview:
            draggedContent.substring(0, 100) +
            (draggedContent.length > 100 ? "..." : ""),
          isHTML: isHTML
        });

        $(this).addClass("dragging").css({
          cursor: "grabbing",
          opacity: "0.7",
          transform: "rotate(2deg)",
        });

        // Set the drag data
        if (isHTML) {
          // For HTML content (like images), set both HTML and text formats
          e.originalEvent.dataTransfer.setData("text/html", draggedContent);
          e.originalEvent.dataTransfer.setData("text/plain", draggedContent);
        } else {
          // For plain text
          e.originalEvent.dataTransfer.setData("text/plain", draggedContent);
        }
        e.originalEvent.dataTransfer.effectAllowed = "copy";
      });

      $element.on("dragend", function () {
        console.log("Drag end");
        $(this).removeClass("dragging").css({
          cursor: "grab",
          opacity: "",
          transform: "",
        });
      });
    },
    /**
     * Refresh current view
     */
    refresh() {
      const context = this.getCurrentContext();
      const data = this.getCurrentData();
      const url = this.getCurrentUrl();

      if (data) {
        this.render(data, url, context);
      }
    },

    /**
     * Clear results for a specific context
     */
    clear(context) {
      const config = this.config.contexts[context];
      if (config) {
        $(config.statsContainer).empty();
        $(config.contentContainer).empty();
      }
    },

    /**
     * Utility: Get current context
     */
    getCurrentContext() {
      if (window.State && typeof window.State.getContext === "function") {
        return window.State.getContext();
      }
      // Fallback detection
      if (window.isEditorMode) {
        return window.stickySidebarOpen ? "sticky" : "editor";
      }
      return "main";
    },

    /**
     * Utility: Get current data
     */
    getCurrentData() {
      if (window.State && window.State.scraped) {
        return window.State.scraped.data;
      }
      return window.currentScrapedData ? window.currentScrapedData.data : null;
    },

    /**
     * Utility: Get current URL
     */
    getCurrentUrl() {
      if (window.State && window.State.scraped) {
        return window.State.scraped.url;
      }
      return window.currentScrapedUrl || "";
    },

    /**
     * Utility: Escape HTML
     */
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
     * Utility: Truncate text
     */
    truncateText(text, maxLength) {
      if (!text || text.length <= maxLength) return text;
      return text.substring(0, maxLength) + "...";
    },
  };

  // Export for use in other modules
  window.Renderer = Renderer;

  // Also attach to jQuery if needed for backwards compatibility
  if ($) {
    $.migrationRenderer = Renderer;
  }
})(window, jQuery);
