/**
 * API Module for Migration Manager
 * Centralizes all AJAX calls and API interactions
 *
 * @module API
 */

(function (window, $) {
  "use strict";

  const API = {
    /**
     * Configuration
     */
    config: {
      baseUrl: window.migrationManager
        ? window.migrationManager.ajaxUrl
        : ajaxurl,
      nonce: window.migrationManager ? window.migrationManager.nonce : "",
      timeout: 60000,
      retryAttempts: 3,
      retryDelay: 1000,
      cache: true,
      cacheExpiry: 5 * 60 * 1000, // 5 minutes
    },

    /**
     * Request cache
     */
    _cache: new Map(),

    /**
     * Active requests
     */
    _activeRequests: new Map(),

    /**
     * Initialize API module
     */
    init() {
      this.setupInterceptors();
      this.loadConfig();
      console.log("API module initialized");
    },

    /**
     * Core request method
     */
    request(options) {
      const defaults = {
        url: this.config.baseUrl,
        type: "POST",
        timeout: this.config.timeout,
        data: {},
        cache: false,
        dataType: "json",
      };

      const settings = $.extend({}, defaults, options);

      // Add nonce to data
      if (this.config.nonce && !settings.data.nonce) {
        settings.data.nonce = this.config.nonce;
      }

      // Check cache
      const cacheKey = this.getCacheKey(settings);
      if (
        this.config.cache &&
        settings.type === "GET" &&
        this._cache.has(cacheKey)
      ) {
        const cached = this._cache.get(cacheKey);
        if (cached.expires > Date.now()) {
          return Promise.resolve(cached.data);
        }
        this._cache.delete(cacheKey);
      }

      // Check for duplicate request
      if (this._activeRequests.has(cacheKey)) {
        return this._activeRequests.get(cacheKey);
      }

      // Create promise
      const promise = new Promise((resolve, reject) => {
        const attemptRequest = (attemptNumber = 1) => {
          $.ajax(settings)
            .done((response) => {
              // Cache successful GET requests
              if (this.config.cache && settings.type === "GET") {
                this._cache.set(cacheKey, {
                  data: response,
                  expires: Date.now() + this.config.cacheExpiry,
                });
              }

              this._activeRequests.delete(cacheKey);
              resolve(response);
            })
            .fail((xhr, status, error) => {
              // Retry logic
              if (
                attemptNumber < this.config.retryAttempts &&
                this.shouldRetry(xhr, status)
              ) {
                setTimeout(() => {
                  attemptRequest(attemptNumber + 1);
                }, this.config.retryDelay * attemptNumber);
              } else {
                this._activeRequests.delete(cacheKey);
                reject(this.parseError(xhr, status, error));
              }
            });
        };

        attemptRequest();
      });

      this._activeRequests.set(cacheKey, promise);
      return promise;
    },

    /**
     * Scraping endpoints
     */
    scrape(url, options = {}) {
      EventBus.emit(EventBus.events.UI_LOADING_START);

      return this.request({
        data: {
          action: "migration_manager_scrape",
          url: url,
          ...options,
        },
      })
        .then((response) => {
          if (response.success) {
            EventBus.emit(EventBus.events.DATA_SCRAPED, response.data);
            return response.data;
          }
          throw new Error(response.data?.message || "Scraping failed");
        })
        .catch((error) => {
          EventBus.emit(EventBus.events.ERROR_SCRAPE, error);
          throw error;
        })
        .finally(() => {
          EventBus.emit(EventBus.events.UI_LOADING_END);
        });
    },

    loadScrape(url) {
      return this.request({
        data: {
          action: "migration_manager_load_scrape",
          url: url,
        },
      }).then((response) => {
        if (response.success) {
          EventBus.emit(EventBus.events.DATA_LOADED, response.data);
          return response.data;
        }
        throw new Error(response.data?.message || "Failed to load scrape");
      });
    },

    getRecentScrapes() {
      return this.request({
        type: "GET",
        data: {
          action: "migration_manager_get_recent_scrapes",
        },
      });
    },

    /**
     * Group management endpoints
     */
    deleteGroup(groupIndex, url) {
      return this.request({
        data: {
          action: "migration_manager_delete_group",
          url: url || State.get("scraped.url"),
          group_index: groupIndex,
        },
      }).then((response) => {
        if (response.success) {
          EventBus.emit(EventBus.events.GROUP_DELETED, {
            index: groupIndex,
            data: response.data,
          });
          return response.data;
        }
        throw new Error(response.data?.message || "Failed to delete group");
      });
    },

    deleteMultipleGroups(indices, url) {
      return this.request({
        data: {
          action: "migration_manager_delete_multiple_groups",
          url: url || State.get("scraped.url"),
          group_indices: indices,
        },
      }).then((response) => {
        if (response.success) {
          EventBus.emit(EventBus.events.GROUP_BULK_DELETE, {
            indices: indices,
            data: response.data,
          });
          return response.data;
        }
        throw new Error(response.data?.message || "Failed to delete groups");
      });
    },

    /**
     * Content creation endpoints
     */
    createPost(data) {
      return this.request({
        data: {
          action: "migration_manager_create_post",
          content: data,
        },
      }).then((response) => {
        if (response.success) {
          EventBus.emit(EventBus.events.ACTION_CREATE_POST, response.data);
          return response.data;
        }
        throw new Error(response.data?.message || "Failed to create post");
      });
    },

    createPage(data) {
      return this.request({
        data: {
          action: "migration_manager_create_page",
          content: data,
        },
      }).then((response) => {
        if (response.success) {
          EventBus.emit(EventBus.events.ACTION_CREATE_PAGE, response.data);
          return response.data;
        }
        throw new Error(response.data?.message || "Failed to create page");
      });
    },

    saveDraft(data) {
      return this.request({
        data: {
          action: "migration_manager_save_draft",
          content: data,
        },
      });
    },

    /**
     * Image download endpoints
     */
    downloadImages(url) {
      EventBus.emit(EventBus.events.UI_LOADING_START);

      return this.request({
        data: {
          action: "migration_manager_download_images",
          url: url || State.get("scraped.url"),
        },
      })
        .then((response) => {
          if (response.success) {
            EventBus.emit(EventBus.events.IMAGES_DOWNLOADED, response.data);
            return response.data;
          }
          throw new Error(response.data?.message || "Failed to download images");
        })
        .catch((error) => {
          EventBus.emit(EventBus.events.ERROR_IMAGE_DOWNLOAD, error);
          throw error;
        })
        .finally(() => {
          EventBus.emit(EventBus.events.UI_LOADING_END);
        });
    },

    uploadSingleImage(imageUrl, altText, sourceUrl) {
      return this.request({
        data: {
          action: "migration_manager_upload_single_image",
          image_url: imageUrl,
          alt_text: altText || "",
          source_url: sourceUrl || State.get("scraped.url"),
        },
      })
        .then((response) => {
          if (response.success) {
            EventBus.emit(EventBus.events.IMAGES_DOWNLOADED, response.data);
            return response.data;
          }
          throw new Error(response.data?.message || "Failed to upload image");
        })
        .catch((error) => {
          EventBus.emit(EventBus.events.ERROR_IMAGE_DOWNLOAD, error);
          throw error;
        });
    },

    /**
     * Export/Import endpoints
     */
    exportJSON(data) {
      return this.request({
        data: {
          action: "migration_manager_export",
          format: "json",
          content: data,
        },
      }).then((response) => {
        if (response.success) {
          EventBus.emit(EventBus.events.ACTION_EXPORT, response.data);
          return response.data;
        }
        throw new Error(response.data?.message || "Export failed");
      });
    },

    importJSON(file) {
      const formData = new FormData();
      formData.append("action", "migration_manager_import");
      formData.append("file", file);
      formData.append("nonce", this.config.nonce);

      return this.request({
        data: formData,
        processData: false,
        contentType: false,
      });
    },

    /**
     * Utility methods
     */
    shouldRetry(xhr, status) {
      // Retry on network errors or specific status codes
      return (
        status === "timeout" ||
        status === "error" ||
        (xhr.status >= 500 && xhr.status < 600)
      );
    },

    parseError(xhr, status, error) {
      let message = "An error occurred";
      let details = null;

      if (status === "timeout") {
        message = "Request timed out. The server might be busy.";
      } else if (status === "abort") {
        message = "Request was cancelled";
      } else if (xhr.responseJSON?.data?.message) {
        message = xhr.responseJSON.data.message;
        details = xhr.responseJSON.data.details;
      } else if (xhr.responseText) {
        try {
          const response = JSON.parse(xhr.responseText);
          message = response.message || message;
        } catch (e) {
          message = error || message;
        }
      }

      return {
        message: message,
        details: details,
        status: xhr.status,
        statusText: status,
        xhr: xhr,
      };
    },

    getCacheKey(settings) {
      return JSON.stringify({
        url: settings.url,
        type: settings.type,
        data: settings.data,
      });
    },

    clearCache() {
      this._cache.clear();
    },

    abortAll() {
      this._activeRequests.forEach((request) => {
        if (request.abort) request.abort();
      });
      this._activeRequests.clear();
    },

    /**
     * Setup interceptors
     */
    setupInterceptors() {
      // Global error handler
      $(document).ajaxError((event, xhr, settings, error) => {
        if (settings.url === this.config.baseUrl) {
          EventBus.emit(EventBus.events.ERROR_NETWORK, {
            xhr: xhr,
            settings: settings,
            error: error,
          });
        }
      });

      // Show/hide loading indicators
      $(document)
        .ajaxStart(() => {
          if (State) State.set("ui.isLoading", true, true);
        })
        .ajaxStop(() => {
          if (State) State.set("ui.isLoading", false, true);
        });
    },

    /**
     * Configuration
     */
    loadConfig() {
      // Load from WordPress localized script
      if (window.migrationManager) {
        this.config.baseUrl =
          window.migrationManager.ajaxUrl || this.config.baseUrl;
        this.config.nonce = window.migrationManager.nonce || this.config.nonce;
      }
    },

    setConfig(key, value) {
      const keys = key.split(".");
      let target = this.config;

      for (let i = 0; i < keys.length - 1; i++) {
        target = target[keys[i]];
      }

      target[keys[keys.length - 1]] = value;
    },
  };

  /**
   * Convenience methods
   */
  API.get = function (action, data = {}) {
    return this.request({
      type: "GET",
      data: { action, ...data },
    });
  };

  API.post = function (action, data = {}) {
    return this.request({
      type: "POST",
      data: { action, ...data },
    });
  };

  /**
   * Batch operations
   */
  API.batch = function (requests) {
    return Promise.all(requests.map((req) => this.request(req)));
  };

  API.sequence = function (requests) {
    return requests.reduce((promise, req) => {
      return promise.then((results) => {
        return this.request(req).then((result) => [...results, result]);
      });
    }, Promise.resolve([]));
  };

  // Export
  window.API = API;

  if ($) {
    $.migrationAPI = API;
  }

  // Initialize
  $(document).ready(function () {
    API.init();
  });
})(window, jQuery);
