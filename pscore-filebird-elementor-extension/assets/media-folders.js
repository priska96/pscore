/**
 * PSCORE FileBird Elementor Builder Extension
 * Version 1.1.0
 *
 * Adds a read-only FileBird folder tree to the left navigation
 * of the WordPress Media Modal used by Elementor.
 */

(function ($) {
  "use strict";

  if (
    typeof wp === "undefined" ||
    !wp.media ||
    typeof PSCOREFBE === "undefined"
  ) {
    return;
  }

  const PSCOREFileBird = {
    observer: null,

    /**
     * Initialize plugin.
     */
    init() {
      this.watchForMediaModal();
    },

    /**
     * Elementor creates/destroys WordPress media modals dynamically.
     *
     * Instead of depending on a specific Elementor API,
     * observe the admin DOM and initialize any media modal that appears.
     */
    watchForMediaModal() {
      const self = this;

      this.observer = new MutationObserver(function () {
        document.querySelectorAll(".media-modal").forEach(function (modal) {
          self.initializeModal(modal);
        });
      });

      this.observer.observe(document.body, {
        childList: true,
        subtree: true,
      });

      /*
       * Handle a modal that already exists.
       */
      document.querySelectorAll(".media-modal").forEach(function (modal) {
        self.initializeModal(modal);
      });
    },

    /**
     * Initialize one media modal.
     */
    initializeModal(modal) {
      if (!modal) {
        return;
      }

      if (modal.dataset.pscoreFbeTreeInitialized === "1") {
        return;
      }

      /*
       * The standard WordPress media modal contains .media-menu.
       * Elementor uses this same structure.
       */
      const menu = modal.querySelector(".media-menu");

      if (!menu) {
        return;
      }

      modal.dataset.pscoreFbeTreeInitialized = "1";

      this.createTreeContainer(modal, menu);

      this.loadTree(modal);
    },

    /**
     * Add FileBird tree below the existing Actions menu.
     */
    createTreeContainer(modal, menu) {
      const wrapper = document.createElement("div");

      wrapper.className = "pscore-fbe-tree-wrapper";

      wrapper.innerHTML = `
                <div class="pscore-fbe-tree-heading">
                    ${this.escapeHtml(PSCOREFBE.labels.filebird || "FileBird")}
                </div>

                <div class="pscore-fbe-tree">
                    <div class="pscore-fbe-tree-loading">
                        ${this.escapeHtml(PSCOREFBE.labels.loading)}
                    </div>
                </div>
            `;

      /*
       * Put FileBird tree inside the existing left navigation.
       *
       * It appears after the normal WordPress menu items.
       */
      menu.appendChild(wrapper);

      modal.classList.add("pscore-fbe-tree-enabled");
    },

    /**
     * Request complete tree + counts from PHP.
     */
    loadTree(modal) {
      const self = this;

      $.ajax({
        url: PSCOREFBE.ajaxUrl,

        method: "POST",

        data: {
          action: "pscore_fbe_get_tree",

          nonce: PSCOREFBE.nonce,
        },
      })

        .done(function (response) {
          if (!response || !response.success) {
            self.showError(modal);
            return;
          }

          self.renderTree(modal, response.data);
        })

        .fail(function () {
          self.showError(modal);
        });
    },

    /**
     * Render complete FileBird navigation.
     */
    renderTree(modal, data) {
      const container = modal.querySelector(".pscore-fbe-tree");

      if (!container) {
        return;
      }

      container.innerHTML = "";

      const allCount =
        data && data.counts ? parseInt(data.counts.all, 10) || 0 : 0;

      const uncategorizedCount =
        data && data.counts ? parseInt(data.counts.uncategorized, 10) || 0 : 0;

      /*
       * All Files
       */
      container.appendChild(
        this.createFolderRow({
          id: "all",

          name: PSCOREFBE.labels.allMedia,

          count: allCount,

          depth: 0,

          special: true,

          icon: "dashicons-admin-media",
        }),
      );

      /*
       * Uncategorized
       */
      container.appendChild(
        this.createFolderRow({
          id: "uncategorized",

          name: PSCOREFBE.labels.uncategorized,

          count: uncategorizedCount,

          depth: 0,

          special: true,

          icon: "dashicons-category",
        }),
      );

      /*
       * Divider between special folders and actual FileBird folders.
       */
      const divider = document.createElement("div");

      divider.className = "pscore-fbe-tree-divider";

      container.appendChild(divider);

      /*
       * Actual FileBird tree.
       */
      if (!data.tree || !data.tree.length) {
        const empty = document.createElement("div");

        empty.className = "pscore-fbe-tree-empty";

        empty.textContent = PSCOREFBE.labels.empty;

        container.appendChild(empty);
      } else {
        this.renderBranch(container, data.tree, 0);
      }

      /*
       * All Files is active initially.
       */
      const allRow = container.querySelector('[data-folder-id="all"]');

      if (allRow) {
        allRow.classList.add("is-active");
      }

      this.bindTreeEvents(modal);
    },

    /**
     * Recursively render folders.
     */
    renderBranch(container, folders, depth) {
      const self = this;

      folders.forEach(function (folder) {
        const hasChildren =
          Array.isArray(folder.children) && folder.children.length > 0;

        const row = self.createFolderRow({
          id: folder.id,

          name: folder.name,

          count: folder.count || 0,

          depth: depth,

          hasChildren: hasChildren,

          icon: "dashicons-category",
        });

        container.appendChild(row);

        /*
         * Child wrapper.
         */
        if (hasChildren) {
          const children = document.createElement("div");

          children.className = "pscore-fbe-tree-children";

          children.dataset.parentId = String(folder.id);

          self.renderBranch(children, folder.children, depth + 1);

          container.appendChild(children);
        }
      });
    },

    /**
     * Build one tree row.
     */
    createFolderRow(options) {
      const row = document.createElement("div");

      row.className = "pscore-fbe-tree-row";

      if (options.special) {
        row.classList.add("pscore-fbe-tree-special");
      }

      row.dataset.folderId = String(options.id);

      row.dataset.depth = String(options.depth || 0);

      /*
       * Indentation is applied through CSS variable.
       */
      row.style.setProperty("--pscore-fbe-depth", String(options.depth || 0));

      /*
       * Expand arrow.
       */
      let toggleHtml = "";

      if (options.hasChildren) {
        toggleHtml = `
                    <button
                        type="button"
                        class="pscore-fbe-tree-toggle"
                        aria-label="Toggle folder"
                        aria-expanded="true"
                    >
                        <span
                            class="
                                dashicons
                                dashicons-arrow-down-alt2
                            "
                        ></span>
                    </button>
                `;
      } else {
        toggleHtml = `
                    <span
                        class="
                            pscore-fbe-tree-toggle-placeholder
                        "
                    ></span>
                `;
      }

      const icon = options.icon || "dashicons-category";

      row.innerHTML = `
                ${toggleHtml}

                <span
                    class="
                        dashicons
                        ${icon}
                        pscore-fbe-tree-folder-icon
                    "
                ></span>

                <span
                    class="pscore-fbe-tree-name"
                    title="${this.escapeAttribute(options.name)}"
                >
                    ${this.escapeHtml(options.name)}
                </span>

                <span
                    class="pscore-fbe-tree-count"
                >
                    ${parseInt(options.count, 10) || 0}
                </span>
            `;

      return row;
    },

    /**
     * Handle folder and expand/collapse clicks.
     */
    bindTreeEvents(modal) {
      const self = this;

      const tree = modal.querySelector(".pscore-fbe-tree");

      if (!tree) {
        return;
      }

      tree.addEventListener("click", function (event) {
        /*
         * Expand / collapse.
         */
        const toggle = event.target.closest(".pscore-fbe-tree-toggle");

        if (toggle) {
          event.preventDefault();
          event.stopPropagation();

          const row = toggle.closest(".pscore-fbe-tree-row");

          self.toggleFolder(row);

          return;
        }

        /*
         * Folder selection.
         */
        const row = event.target.closest(".pscore-fbe-tree-row");

        if (!row) {
          return;
        }

        const folderId = row.dataset.folderId;

        self.setActiveRow(modal, row);

        if (folderId === "all") {
          self.showAllMedia(modal);

          return;
        }

        if (folderId === "uncategorized") {
          self.loadUncategorized(modal);

          return;
        }

        const numericId = parseInt(folderId, 10);

        if (!numericId) {
          return;
        }

        self.loadFolder(modal, numericId);
      });
    },

    /**
     * Expand/collapse child folders.
     */
    toggleFolder(row) {
      if (!row) {
        return;
      }

      const id = row.dataset.folderId;

      const children = row.nextElementSibling;

      if (
        !children ||
        !children.classList.contains("pscore-fbe-tree-children") ||
        children.dataset.parentId !== id
      ) {
        return;
      }

      const collapsed = children.classList.toggle("is-collapsed");

      const button = row.querySelector(".pscore-fbe-tree-toggle");

      const icon = row.querySelector(".pscore-fbe-tree-toggle .dashicons");

      if (button) {
        button.setAttribute("aria-expanded", collapsed ? "false" : "true");
      }

      if (icon) {
        icon.classList.remove(
          "dashicons-arrow-down-alt2",
          "dashicons-arrow-right-alt2",
        );

        icon.classList.add(
          collapsed
            ? "dashicons-arrow-right-alt2"
            : "dashicons-arrow-down-alt2",
        );
      }
    },

    /**
     * Set active folder.
     */
    setActiveRow(modal, selectedRow) {
      modal.querySelectorAll(".pscore-fbe-tree-row").forEach(function (row) {
        row.classList.remove("is-active");
      });

      selectedRow.classList.add("is-active");
    },

    /**
     * Load one FileBird folder.
     */
    loadFolder(modal, folderId) {
      const self = this;

      this.setLoading(modal, true);

      $.ajax({
        url: PSCOREFBE.ajaxUrl,

        method: "POST",

        data: {
          action: "pscore_fbe_get_attachments",

          nonce: PSCOREFBE.nonce,

          folder_id: folderId,
        },
      })

        .done(function (response) {
          if (!response || !response.success) {
            self.setLoading(modal, false);

            return;
          }

          self.filterAttachments(modal, response.data.attachment_ids || []);

          self.setLoading(modal, false);
        })

        .fail(function () {
          self.setLoading(modal, false);
        });
    },

    /**
     * Load uncategorized attachments.
     */
    loadUncategorized(modal) {
      const self = this;

      this.setLoading(modal, true);

      $.ajax({
        url: PSCOREFBE.ajaxUrl,

        method: "POST",

        data: {
          action: "pscore_fbe_get_uncategorized",

          nonce: PSCOREFBE.nonce,
        },
      })

        .done(function (response) {
          if (!response || !response.success) {
            self.setLoading(modal, false);

            return;
          }

          self.filterAttachments(modal, response.data.attachment_ids || []);

          self.setLoading(modal, false);
        })

        .fail(function () {
          self.setLoading(modal, false);
        });
    },

    /**
     * Filter currently loaded WordPress attachments.
     *
     * IMPORTANT:
     * This version is intentionally read-only and does not replace
     * WordPress/Elementor's Backbone media collection.
     *
     * It hides attachments not belonging to the selected folder.
     */
    filterAttachments(modal, attachmentIds) {
      this.stopAttachmentObserver(modal);

      const allowed = new Set(
        attachmentIds.map(function (id) {
          return String(id);
        }),
      );

      modal._pscoreFbeAllowedIds = allowed;

      this.applyAttachmentFilter(modal, allowed);

      /*
       * WordPress loads media lazily.
       * Reapply filter when new attachment nodes appear.
       */
      const attachmentsContainer = modal.querySelector(".attachments");

      if (!attachmentsContainer) {
        return;
      }

      const self = this;

      const observer = new MutationObserver(function () {
        if (!modal._pscoreFbeAllowedIds) {
          return;
        }

        self.applyAttachmentFilter(modal, modal._pscoreFbeAllowedIds);
      });

      observer.observe(attachmentsContainer, {
        childList: true,
        subtree: true,
      });

      modal._pscoreFbeAttachmentObserver = observer;
    },

    /**
     * Apply current filter.
     */
    applyAttachmentFilter(modal, allowed) {
      modal
        .querySelectorAll(".attachments .attachment")
        .forEach(function (attachment) {
          const id = attachment.dataset.id;

          if (id && allowed.has(String(id))) {
            attachment.classList.remove("pscore-fbe-hidden");
          } else {
            attachment.classList.add("pscore-fbe-hidden");
          }
        });

      /*
       * Show an empty message if none of the currently loaded
       * attachments belongs to this folder.
       */
      this.updateEmptyState(modal);
    },

    /**
     * Restore all media.
     */
    showAllMedia(modal) {
      this.stopAttachmentObserver(modal);

      modal._pscoreFbeAllowedIds = null;

      modal
        .querySelectorAll(".attachments .attachment")
        .forEach(function (attachment) {
          attachment.classList.remove("pscore-fbe-hidden");
        });

      this.removeEmptyState(modal);

      this.setLoading(modal, false);
    },

    /**
     * Stop lazy-load observer.
     */
    stopAttachmentObserver(modal) {
      if (modal._pscoreFbeAttachmentObserver) {
        modal._pscoreFbeAttachmentObserver.disconnect();

        modal._pscoreFbeAttachmentObserver = null;
      }
    },

    /**
     * Empty folder message.
     */
    updateEmptyState(modal) {
      const attachments = Array.from(
        modal.querySelectorAll(".attachments .attachment"),
      );

      const visible = attachments.filter(function (attachment) {
        return !attachment.classList.contains("pscore-fbe-hidden");
      });

      if (attachments.length > 0 && visible.length === 0) {
        this.showEmptyState(modal);
      } else {
        this.removeEmptyState(modal);
      }
    },

    /**
     * Display empty-folder message.
     */
    showEmptyState(modal) {
      if (modal.querySelector(".pscore-fbe-media-empty")) {
        return;
      }

      const browser = modal.querySelector(".attachments-browser");

      if (!browser) {
        return;
      }

      const empty = document.createElement("div");

      empty.className = "pscore-fbe-media-empty";

      empty.textContent = "No media from this folder is currently loaded.";

      browser.appendChild(empty);
    },

    /**
     * Remove empty-folder message.
     */
    removeEmptyState(modal) {
      modal
        .querySelectorAll(".pscore-fbe-media-empty")
        .forEach(function (element) {
          element.remove();
        });
    },

    /**
     * Loading state.
     */
    setLoading(modal, loading) {
      const tree = modal.querySelector(".pscore-fbe-tree");

      if (!tree) {
        return;
      }

      tree.classList.toggle("is-loading", !!loading);
    },

    /**
     * Error while loading FileBird tree.
     */
    showError(modal) {
      const tree = modal.querySelector(".pscore-fbe-tree");

      if (!tree) {
        return;
      }

      tree.innerHTML = "";

      const error = document.createElement("div");

      error.className = "pscore-fbe-tree-error";

      error.textContent = PSCOREFBE.labels.error;

      tree.appendChild(error);
    },

    /**
     * HTML escaping.
     */
    escapeHtml(value) {
      const element = document.createElement("div");

      element.textContent =
        value === null || typeof value === "undefined" ? "" : String(value);

      return element.innerHTML;
    },

    /**
     * HTML attribute escaping.
     */
    escapeAttribute(value) {
      return this.escapeHtml(value)
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    },
  };

  /*
   * Start once wp-admin DOM is ready.
   */
  $(function () {
    PSCOREFileBird.init();
  });
})(jQuery);
