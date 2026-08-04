(() => {
  "use strict";

  const tg = window.Telegram?.WebApp;
  const appMain = document.getElementById("appMain");
  const bottomNav = document.getElementById("bottomNav");
  const authScreen = document.getElementById("screenAuth");

  const screens = {
    home: document.getElementById("screenHome"),
    channels: document.getElementById("screenChannels"),
    ads: document.getElementById("screenAds"),
    server: document.getElementById("screenServer"),
  };

  const state = {
    initData: "",
    cache: null,
    charts: {},
    dashboardCharts: {},
    activeChannelId: null,
    channelTab: "overview",
    activeBotId: null,
    botTab: "overview",
    botProfilePendingPhoto: null,
    botProfileDeletePhoto: false,
    openFolderId: null,
    explorerDragPayload: null,
    selectedFolderIcon: "folder",
    folderModalMode: "create",
    editingFolderId: null,
    folderOpenSuppressUntil: 0,
    activeContextFolderId: null,
    folderContextBlockClick: false,
    channelOpenSuppressUntil: 0,
    activeContextChannelId: null,
    channelContextBlockClick: false,
    contentGroupFolderOpenSuppressUntil: 0,
    activeContentGroupContextFolderId: null,
    contentGroupFolderContextBlockClick: false,
    contentGroupOpenSuppressUntil: 0,
    activeContextContentGroupId: null,
    contentGroupContextBlockClick: false,
    adsFolders: [],
    adsFolderNames: {},
    adsPromoFolderId: null,
    channelsMetaRefreshGen: 0,
    channelsMetaRefreshAttempts: 0,
    dashboardBuilt: false,
    dashboardDataSig: "",
    dashboardMembersFolderId: "all",
    dashboardFolderPickerBound: false,
    dashboardFolders: [],
    dashboardMembersTrendLoading: false,
    openBotFolderId: null,
    botExplorerDragPayload: null,
    openContentGroupFolderId: null,
    selectedBotFolderIcon: "folder",
    selectedContentGroupFolderIcon: "folder",
    contentGroupFolderModalMode: "create",
    editingContentGroupFolderId: null,
    botFolderModalMode: "create",
    editingBotFolderId: null,
    botFolderOpenSuppressUntil: 0,
    activeBotContextFolderId: null,
    botFolderContextBlockClick: false,
    activeBotContextBotId: null,
    botContextBlockClick: false,
    botOpenSuppressUntil: 0,
    editingRenameBotId: null,
    botFolderJoinFolderId: null,
    botFolderJoinManageFolderId: null,
    openAutoPostFolderId: null,
    selectedAutoPostFolderIcon: "folder",
    autoPostFolderModalMode: "create",
    editingAutoPostFolderId: null,
    autoPostFolderOpenSuppressUntil: 0,
    activeAutoPostContextFolderId: null,
    autoPostFolderContextBlockClick: false,
    postSessionOpenSuppressUntil: 0,
    activePostSessionContextId: null,
    postSessionContextBlockClick: false,
    activePostSessionId: null,
    postSessionTab: "overview",
    editingPostSessionId: null,
    apWizardStep: 1,
    apWizardGroupChatId: null,
    apWizardGroupTitle: "",
    apWizardMediaType: "photo",
    apWizardSelectedMedia: [],
    apMediaPickerSelectedKeys: [],
    apWizardEditScheduleId: null,
    openHashtagToolsFolderId: null,
    activeHashtagSetId: null,
    hashtagSetTab: "overview",
    hashtagWizardEditSetId: null,
    hashtagWizardTags: [],
    hashtagSettingsTags: [],
    bannerTab: "overview",
    channelProfilePendingPhoto: null,
    channelProfileDeletePhoto: false,
    folderTreePicker: {
      mode: null,
      targetId: null,
      excludeFolderIds: [],
      expanded: {},
    },
  };

  let selectedAddBotType = "uploader";
  let BOT_TYPE_LABEL = "مستراپلودر v1.0";
  const BOT_TYPE_LABELS = {
    uploader: "مستراپلودر v1.0",
    guardian: "مسترمحافظ v1.0",
  };

  function syncDefaultUploaderVersionLabel(version) {
    if (!version?.name) return;
    BOT_TYPE_LABELS.uploader = version.name;
    const uploaderTab = document.querySelector('#addBotTypeTabs [data-bot-type="uploader"]');
    if (uploaderTab) uploaderTab.textContent = version.name;
  }

  function syncDefaultGuardianVersionLabel(version) {
    if (!version?.name) return;
    BOT_TYPE_LABELS.guardian = version.name;
    const guardianTab = document.querySelector('#addBotTypeTabs [data-bot-type="guardian"]');
    if (guardianTab) guardianTab.textContent = version.name;
  }

  function getBotTypeLabel(bot) {
    if (!bot) return BOT_TYPE_LABELS.uploader;
    return bot.uploader_version_name || BOT_TYPE_LABELS[bot.bot_type] || BOT_TYPE_LABELS.uploader;
  }

  function setAddBotType(type) {
    selectedAddBotType = type === "guardian" ? "guardian" : "uploader";
    document.querySelectorAll("#addBotTypeTabs [data-bot-type]").forEach((tab) => {
      const active = tab.dataset.botType === selectedAddBotType;
      tab.classList.toggle("is-active", active);
      tab.setAttribute("aria-selected", active ? "true" : "false");
    });
  }

  function isBotBanned(bot) {
    if (!bot) return false;
    if (bot.is_banned === true || bot.is_banned === 1 || bot.is_banned === "1") return true;
    if (bot.status && bot.status !== "active") return true;
    const health = String(bot.health_status || "ok").trim();
    return health !== "" && health !== "ok";
  }

  function isChannelBanned(channel) {
    if (!channel) return false;
    if (channel.is_banned === true || channel.is_banned === 1 || channel.is_banned === "1") return true;
    if (channel.is_active === false || channel.is_active === 0 || channel.is_active === "0") return true;
    const health = String(channel.health_status || "ok").trim();
    return health !== "" && health !== "ok";
  }

  function buildBotIconHtml({ initial, photoUrl, banned = false, size = "tile" }) {
    const rootClass = size === "tile" ? "explorer-tile__icon explorer-tile__icon--bot" : "bot-icon-wrap";
    const bannedClass = banned ? " bot-icon-wrap--banned" : "";
    const tileBannedClass = banned ? " explorer-tile__icon--bot-banned" : "";
    const banBadge = banned
      ? `<span class="bot-ban-badge" aria-label="مسدود">BAN</span>`
      : "";
    const letterClass = size === "tile" ? "explorer-tile__letter" : "bot-icon-wrap__letter";
    const showPhoto = Boolean(photoUrl) && !banned;
    const inner = showPhoto
      ? `<img src="${photoUrl}" alt="" loading="lazy" decoding="async" class="bot-icon-photo"><span class="${letterClass} bot-icon-fallback" aria-hidden="true">${escapeHtml(initial)}</span>`
      : `<span class="${letterClass}" aria-hidden="true">${escapeHtml(initial)}</span>`;

    if (size === "tile") {
      return `
        <span class="${rootClass}${tileBannedClass}${showPhoto ? " explorer-tile__icon--bot-photo" : ""}">
          ${inner}
          ${banBadge}
        </span>`;
    }

    return `
      <span class="${rootClass}${bannedClass}${showPhoto ? " bot-icon-wrap--photo" : ""}">
        ${inner}
        ${banBadge}
      </span>`;
  }

  function bindBotIconPhotoFallbacks(root = document) {
    root.querySelectorAll(".bot-icon-photo, .context-menu__preview-photo").forEach((img) => {
      if (img.dataset.fallbackBound === "1") return;
      img.dataset.fallbackBound = "1";
      img.addEventListener("error", () => {
        img.hidden = true;
        img.parentElement?.classList.remove(
          "explorer-tile__icon--bot-photo",
          "bot-icon-wrap--photo",
          "context-menu__preview-icon--photo"
        );
        const fallback = img.parentElement?.querySelector(".bot-icon-fallback");
        if (fallback) fallback.hidden = false;
      }, { once: true });
    });
  }

  function buildChannelIconHtml({ initial, photoUrl, banned = false }) {
    const iconBannedClass = banned ? " explorer-tile__icon--channel-banned" : "";
    const banBadge = banned
      ? `<span class="bot-ban-badge" aria-label="مسدود">BAN</span>`
      : "";
    const inner = photoUrl
      ? `<img src="${photoUrl}" alt="" loading="lazy" decoding="async">`
      : `<span class="explorer-tile__letter" aria-hidden="true">${escapeHtml(initial)}</span>`;

    return `<span class="explorer-tile__icon explorer-tile__icon--channel${iconBannedClass}">${inner}${banBadge}</span>`;
  }

  const FOLDER_ICONS = [
    "folder",
    "folder-open",
    "star",
    "heart",
    "bookmark",
    "tag",
    "bolt",
    "bullhorn",
    "users",
    "globe",
    "lock",
    "fire",
    "gem",
  ];

  function escapeHtml(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;");
  }

  const TOAST_ICONS = {
    success: "fa-circle-check",
    error: "fa-circle-xmark",
    warning: "fa-triangle-exclamation",
    info: "fa-circle-info",
  };

  function showToast(message, options = {}) {
    const text = String(message ?? "").trim();
    if (!text) return;

    const type = options.type || "info";
    const duration = Number(options.duration) > 0 ? Number(options.duration) : 3000;
    const root = document.getElementById("toastRoot");
    if (!root) return;

    const el = document.createElement("div");
    el.className = `toast toast--${type}`;
    el.setAttribute("role", "status");
    el.innerHTML = `
      <span class="toast__icon" aria-hidden="true"><i class="fa-solid ${TOAST_ICONS[type] || TOAST_ICONS.info}"></i></span>
      <p class="toast__text">${escapeHtml(text)}</p>
      <span class="toast__progress" style="animation-duration:${duration}ms"></span>`;

    root.appendChild(el);
    requestAnimationFrame(() => el.classList.add("is-visible"));

    const remove = () => {
      el.classList.remove("is-visible");
      el.classList.add("is-leaving");
      window.setTimeout(() => el.remove(), 280);
    };

    const timer = window.setTimeout(remove, duration);
    el.addEventListener("click", () => {
      window.clearTimeout(timer);
      remove();
    });

    tg?.HapticFeedback?.notificationOccurred(type === "error" ? "error" : "success");
  }

  function openActionSheet(title, items, onPick) {
    const sheet = document.getElementById("actionSheet");
    const list = document.getElementById("actionSheetList");
    const titleEl = document.getElementById("actionSheetTitle");
    if (!sheet || !list || !titleEl) {
      return;
    }

    titleEl.textContent = title;
    list.innerHTML = items
      .map(
        (item) => `
        <button type="button" class="action-sheet__item" data-pick-id="${escapeHtml(String(item.id))}">
          ${item.icon ? `<i class="fa-solid fa-${escapeHtml(item.icon)}"></i>` : ""}
          <span>${escapeHtml(item.label)}</span>
        </button>`
      )
      .join("");

    const close = () => {
      sheet.hidden = true;
    };

    list.querySelectorAll("[data-pick-id]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = btn.getAttribute("data-pick-id");
        close();
        onPick?.(id);
      });
    });

    sheet.querySelectorAll("[data-action-sheet-close]").forEach((btn) => {
      btn.onclick = close;
    });

    sheet.hidden = false;
  }

  function getFolderParentId(folder) {
    const pid = folder?.parent_id;
    if (pid == null || pid === "" || Number(pid) === 0) return null;
    return Number(pid);
  }

  function getChildFolders(folders, parentId) {
    const wantParent = parentId == null ? null : Number(parentId);
    return sortExplorerPinned(
      (folders || []).filter((folder) => getFolderParentId(folder) === wantParent)
    );
  }

  function sortExplorerPinned(items) {
    return [...(items || [])].sort((a, b) => {
      const ap = a?.is_pinned ? 1 : 0;
      const bp = b?.is_pinned ? 1 : 0;
      if (ap !== bp) return bp - ap;
      return 0;
    });
  }

  function explorerPinnedClass(isPinned) {
    return isPinned ? " explorer-tile--pinned" : "";
  }

  function explorerPinBadge(isPinned) {
    if (!isPinned) return "";
    return `<span class="explorer-tile__pin" aria-hidden="true"><i class="fa-solid fa-thumbtack"></i></span>`;
  }

  function pinMenuLabel(isPinned) {
    return isPinned ? "برداشتن پین" : "پین کردن";
  }

  function buildFolderPath(folders, folderId) {
    const path = [];
    let current = folderId == null ? null : Number(folderId);
    const seen = new Set();
    while (current != null && !seen.has(current)) {
      seen.add(current);
      const folder = (folders || []).find((f) => Number(f.id) === current);
      if (!folder) break;
      path.unshift(folder);
      current = getFolderParentId(folder);
    }
    return path;
  }

  function getFolderDescendantIds(folders, folderId) {
    const ids = [];
    const queue = [Number(folderId)];
    const seen = new Set();
    while (queue.length) {
      const current = queue.shift();
      if (seen.has(current)) continue;
      seen.add(current);
      for (const child of getChildFolders(folders, current)) {
        const childId = Number(child.id);
        ids.push(childId);
        queue.push(childId);
      }
    }
    return ids;
  }

  function isInvalidFolderMove(folders, draggedFolderId, targetParentId) {
    const dragged = Number(draggedFolderId);
    const target = Number(targetParentId);
    if (!dragged || !target) return false;
    if (dragged === target) return true;
    return getFolderDescendantIds(folders, dragged).includes(target);
  }

  function parseExplorerDragPayload(raw) {
    const value = String(raw || "").trim();
    if (!value) return null;
    if (value.startsWith("channel:")) {
      const chatId = Number(value.slice(8));
      return chatId ? { type: "channel", id: chatId } : null;
    }
    if (value.startsWith("folder:")) {
      const folderId = Number(value.slice(7));
      return folderId ? { type: "folder", id: folderId } : null;
    }
    const legacyChannelId = Number(value);
    return legacyChannelId ? { type: "channel", id: legacyChannelId } : null;
  }

  function formatFolderMeta(folder) {
    const channelCount = Number(folder?.channel_count ?? 0);
    const subfolderCount = Number(folder?.subfolder_count ?? 0);
    const parts = [];
    if (subfolderCount > 0) parts.push(`${formatNumber(subfolderCount)} زیرپوشه`);
    parts.push(`${formatNumber(channelCount)} کانال`);
    return parts.join(" · ");
  }

  function formatBotFolderMeta(folder) {
    const botCount = Number(folder?.bot_count ?? 0);
    const subfolderCount = Number(folder?.subfolder_count ?? 0);
    const parts = [];
    if (subfolderCount > 0) parts.push(`${formatNumber(subfolderCount)} زیرپوشه`);
    parts.push(`${formatNumber(botCount)} ربات`);
    return parts.join(" · ");
  }

  function persistBotFolderNav(folderId) {
    try {
      if (folderId == null || folderId === "") {
        sessionStorage.removeItem("gpro_bot_folder_id");
      } else {
        sessionStorage.setItem("gpro_bot_folder_id", String(folderId));
      }
    } catch (e) {
      /* ignore */
    }
  }

  function restoreBotFolderNav(folders) {
    const list = folders || [];
    const isValid = (id) => list.some((f) => Number(f.id) === Number(id));

    try {
      const saved = sessionStorage.getItem("gpro_bot_folder_id");
      if (saved && isValid(Number(saved))) {
        state.openBotFolderId = Number(saved);
        return;
      }
    } catch (e) {
      /* ignore */
    }

    if (state.openBotFolderId != null && !isValid(state.openBotFolderId)) {
      state.openBotFolderId = null;
      persistBotFolderNav(null);
    }
  }

  function autoOpenBotFolderIfNeeded(folders, bots) {
    if (state.openBotFolderId != null) return;
    const list = bots || [];
    if (!list.length) return;
    const folderIds = [
      ...new Set(
        list
          .map((b) => b.folder_id)
          .filter((id) => id != null && id !== "")
          .map((id) => Number(id))
      ),
    ];
    if (folderIds.length !== 1) return;
    const targetId = folderIds[0];
    if (!(folders || []).some((f) => Number(f.id) === targetId)) return;
    state.openBotFolderId = targetId;
    persistBotFolderNav(targetId);
  }

  function foldersWithAssignedBots(folders, bots) {
    const ids = new Set(
      (bots || [])
        .map((b) => b.folder_id)
        .filter((id) => id != null && id !== "")
        .map((id) => Number(id))
    );
    return (folders || []).filter((f) => ids.has(Number(f.id)));
  }

  function explorerFolderMeta(folder, folderKind = "channel") {
    return folderKind === "bot" ? formatBotFolderMeta(folder) : formatFolderMeta(folder);
  }

  function renderChannelsBreadcrumb(folders, openFolderId) {
    const breadcrumb = document.getElementById("channelsBreadcrumb");
    if (!breadcrumb) return;
    if (openFolderId == null) {
      breadcrumb.textContent = "صفحه اصلی";
      return;
    }
    const path = buildFolderPath(folders, openFolderId);
    const crumbs = [
      `<button type="button" class="explorer-bar__crumb" data-breadcrumb-folder="">صفحه اصلی</button>`,
      ...path.map((folder, index) => {
        const isLast = index === path.length - 1;
        if (isLast) {
          return `<span class="explorer-bar__crumb explorer-bar__crumb--current">${escapeHtml(folder.name || "پوشه")}</span>`;
        }
        return `<button type="button" class="explorer-bar__crumb" data-breadcrumb-folder="${folder.id}">${escapeHtml(folder.name || "پوشه")}</button>`;
      }),
    ];
    breadcrumb.innerHTML = crumbs.join('<span class="explorer-bar__sep">/</span>');

    breadcrumb.querySelectorAll("[data-breadcrumb-folder]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const raw = btn.getAttribute("data-breadcrumb-folder");
        state.openFolderId = raw ? Number(raw) : null;
        renderChannels(state.cache?.channels || { channels: [], folders: [] });
        tg?.HapticFeedback?.selectionChanged();
      });
    });
  }

  function renderBotsBreadcrumb(folders, openFolderId) {
    const breadcrumb = document.getElementById("botsBreadcrumb");
    if (!breadcrumb) return;
    if (openFolderId == null) {
      breadcrumb.textContent = "صفحه اصلی";
      return;
    }
    const path = buildFolderPath(folders, openFolderId);
    const crumbs = [
      `<button type="button" class="explorer-bar__crumb" data-bot-breadcrumb-folder="">صفحه اصلی</button>`,
      ...path.map((folder, index) => {
        const isLast = index === path.length - 1;
        if (isLast) {
          return `<span class="explorer-bar__crumb explorer-bar__crumb--current">${escapeHtml(folder.name || "پوشه")}</span>`;
        }
        return `<button type="button" class="explorer-bar__crumb" data-bot-breadcrumb-folder="${folder.id}">${escapeHtml(folder.name || "پوشه")}</button>`;
      }),
    ];
    breadcrumb.innerHTML = crumbs.join('<span class="explorer-bar__sep">/</span>');

    breadcrumb.querySelectorAll("[data-bot-breadcrumb-folder]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const raw = btn.getAttribute("data-bot-breadcrumb-folder");
        state.openBotFolderId = raw ? Number(raw) : null;
        persistBotFolderNav(state.openBotFolderId);
        renderBots(state.cache?.bots || { bots: [], folders: [] });
        tg?.HapticFeedback?.selectionChanged();
      });
    });
  }

  function openFolderTreePicker(options = {}) {
    const modal = document.getElementById("folderTreePickerModal");
    const list = document.getElementById("folderTreePickerList");
    const titleEl = document.getElementById("folderTreePickerTitle");
    const hintEl = document.getElementById("folderTreePickerHint");
    const searchWrap = document.getElementById("folderTreePickerSearchWrap");
    const searchInput = document.getElementById("folderTreePickerSearch");
    if (!modal || !list) return;

    const folders = options.folders || state.cache?.channels?.folders || [];
    const mode = options.mode || "channel";
    const folderKind =
      options.folderKind ||
      (mode === "post_session_bot" || mode.startsWith("bot") || mode === "move_bot" || mode === "move_bot_folder"
        ? "bot"
        : "channel");
    const targetId = options.targetId;
    const excludeFolderIds = new Set(
      (options.excludeFolderIds || []).map((id) => Number(id)).filter((id) => id > 0)
    );
    const allowRoot = options.allowRoot !== false;

    state.folderTreePicker = {
      mode,
      folderKind,
      targetId,
      allowRoot,
      excludeFolderIds: [...excludeFolderIds],
      searchQuery: "",
      expanded: { ...(state.folderTreePicker?.expanded || {}) },
    };

    for (const folder of folders) {
      const id = Number(folder.id);
      if (excludeFolderIds.has(id)) continue;
      if (getChildFolders(folders, id).length > 0) {
        state.folderTreePicker.expanded[id] = true;
      }
    }
    state.folderTreePicker.expanded.root = true;

    if (titleEl) {
      titleEl.textContent =
        mode === "post_session_channel"
          ? "انتخاب پوشه کانال"
          : mode === "hashtag_channel" || mode === "hashtag_set_settings_channel" || mode === "hashtag_add_channel"
            ? "انتخاب پوشه کانال"
          : mode === "glass_add_folder" || mode === "glass_settings_folder"
            ? "انتخاب پوشه کانال"
          : mode === "banner_add_folder" || mode === "zapas_add_folder"
            ? "انتخاب پوشه کانال"
          : mode === "post_session_bot"
            ? "انتخاب پوشه ربات"
            : mode === "add_bot_folder"
          ? "انتخاب پوشه کانال"
          : mode === "move_bot"
            ? "انتقال ربات به پوشه"
            : mode === "move_bot_folder"
              ? "انتقال پوشه ربات"
              : mode === "folder" || mode === "move_bot_folder"
                ? "انتقال پوشه"
                : "انتقال به پوشه";
    }
    if (hintEl) {
      hintEl.textContent =
        mode === "post_session_channel"
          ? "فقط پوشه‌های اخلاقی / غیراخلاقی · با + زیرپوشه‌ها را باز کنید"
          : mode === "hashtag_channel" || mode === "hashtag_set_settings_channel" || mode === "hashtag_add_channel"
            ? "پوشه کانال مرتبط با هشتگ‌ها · با + زیرپوشه‌ها را باز کنید"
          : mode === "glass_add_folder" || mode === "glass_settings_folder"
            ? "پوشه کانال برای دکمه شیشه‌ای · با + زیرپوشه‌ها را باز کنید"
          : mode === "banner_add_folder"
            ? "پوشه کانال برای عکس بنر · با + زیرپوشه‌ها را باز کنید"
          : mode === "zapas_add_folder"
            ? "پوشه کانال برای زاپاس · با + زیرپوشه‌ها را باز کنید"
          : mode === "post_session_bot"
            ? "پوشه‌های بخش ربات‌های من · با + زیرپوشه‌ها را باز کنید"
            : mode === "add_bot_folder"
          ? "پوشه کانال را انتخاب کنید · با + زیرپوشه‌ها را باز و بسته کنید"
          : mode === "move_bot" || mode === "move_bot_folder"
            ? "پوشه مقصد را انتخاب کنید · با + زیرپوشه‌ها را باز و بسته کنید"
            : mode === "folder"
              ? "پوشه مقصد را انتخاب کنید · با + زیرپوشه‌ها را باز و بسته کنید"
              : "پوشه مقصد را انتخاب کنید · با + زیرپوشه‌ها را باز و بسته کنید";
    }

    if (searchWrap) {
      searchWrap.hidden = ![
        "add_bot_folder",
        "move_bot",
        "move_bot_folder",
        "post_session_channel",
        "hashtag_channel",
        "hashtag_set_settings_channel",
        "hashtag_add_channel",
        "glass_add_folder",
        "glass_settings_folder",
        "banner_add_folder",
        "zapas_add_folder",
        "post_session_bot",
      ].includes(mode);
    }
    if (searchInput) {
      searchInput.value = "";
      if (!searchWrap?.hidden) {
        setTimeout(() => searchInput.focus(), 50);
      }
    }

    renderFolderTreePickerList(folders);
    modal.hidden = false;
  }

  function closeFolderTreePicker() {
    const modal = document.getElementById("folderTreePickerModal");
    const searchInput = document.getElementById("folderTreePickerSearch");
    if (modal) modal.hidden = true;
    if (searchInput) searchInput.value = "";
    state.folderTreePicker.mode = null;
    state.folderTreePicker.targetId = null;
    state.folderTreePicker.excludeFolderIds = [];
    state.folderTreePicker.searchQuery = "";
  }

  function folderTreeSearchMatches(folders, folderId, query) {
    const q = String(query || "").trim().toLowerCase();
    if (!q) return true;
    const path = buildFolderPath(folders, folderId)
      .map((folder) => folder.name || "")
      .join(" / ")
      .toLowerCase();
    return path.includes(q);
  }

  function getFolderTreeSearchVisibleIds(folders, query) {
    const q = String(query || "").trim().toLowerCase();
    if (!q) return null;

    const visible = new Set();
    for (const folder of folders) {
      const folderId = Number(folder.id);
      if (!folderTreeSearchMatches(folders, folderId, q)) continue;
      for (const node of buildFolderPath(folders, folderId)) {
        visible.add(Number(node.id));
      }
    }
    return visible;
  }

  function renderFolderTreePickerList(folders) {
    const list = document.getElementById("folderTreePickerList");
    if (!list) return;

    const picker = state.folderTreePicker || {};
    const expanded = picker.expanded || {};
    const exclude = new Set((picker.excludeFolderIds || []).map((id) => Number(id)));
    const searchQuery = picker.searchQuery || "";
    const visibleIds = getFolderTreeSearchVisibleIds(folders, searchQuery);
    const allowRoot = picker.allowRoot !== false;
    const mode = picker.mode || "channel";
    const folderKind = picker.folderKind || "channel";

    if (visibleIds) {
      for (const id of visibleIds) {
        expanded[id] = true;
      }
      expanded.root = true;
    }

    function renderNodes(parentId, depth) {
      const nodes = getChildFolders(folders, parentId);
      return nodes
        .map((folder) => {
          const folderId = Number(folder.id);
          if (exclude.has(folderId)) return "";
          if (visibleIds && !visibleIds.has(folderId)) return "";
          const children = getChildFolders(folders, folderId).filter(
            (child) => !exclude.has(Number(child.id)) && (!visibleIds || visibleIds.has(Number(child.id)))
          );
          const hasChildren = children.length > 0;
          const isExpanded = !!expanded[folderId];
          const icon = folder.icon || "folder";
          const toggleBtn = hasChildren
            ? `<button type="button" class="folder-tree__toggle" data-tree-toggle="${folderId}" aria-label="${isExpanded ? "بستن" : "باز کردن"}">${isExpanded ? "−" : "+"}</button>`
            : `<span class="folder-tree__toggle folder-tree__toggle--spacer" aria-hidden="true"></span>`;
          const childHtml = hasChildren && isExpanded ? `<div class="folder-tree__children">${renderNodes(folderId, depth + 1)}</div>` : "";
          return `
            <div class="folder-tree__node" style="--tree-depth:${depth}">
              ${toggleBtn}
              <button type="button" class="folder-tree__pick" data-tree-pick="${folderId}">
                <i class="fa-solid fa-${escapeHtml(icon)}"></i>
                <span class="folder-tree__label">${escapeHtml(folder.name || "پوشه")}</span>
                <span class="folder-tree__meta">${escapeHtml(explorerFolderMeta(folder, folderKind))}</span>
              </button>
            </div>
            ${childHtml}`;
        })
        .join("");
    }

    const rootExpanded = expanded.root !== false;
    const rootHtml =
      allowRoot && mode !== "add_bot_folder" && mode !== "post_session_channel" && mode !== "post_session_bot" && mode !== "hashtag_channel" && mode !== "hashtag_set_settings_channel" && mode !== "hashtag_add_channel"
        ? `
        <div class="folder-tree__node folder-tree__node--root" style="--tree-depth:0">
          <button type="button" class="folder-tree__toggle" data-tree-toggle="root" aria-label="${rootExpanded ? "بستن" : "باز کردن"}">${rootExpanded ? "−" : "+"}</button>
          <button type="button" class="folder-tree__pick folder-tree__pick--root" data-tree-pick="root">
            <i class="fa-solid fa-house"></i>
            <span class="folder-tree__label">صفحه اصلی</span>
            <span class="folder-tree__meta">بدون پوشه / ریشه</span>
          </button>
        </div>
        ${rootExpanded ? `<div class="folder-tree__children">${renderNodes(null, 1)}</div>` : ""}`
        : `<div class="folder-tree__children folder-tree__children--flat">${renderNodes(null, 0)}</div>`;

    list.innerHTML = `<div class="folder-tree">${rootHtml}</div>`;

    if (allowRoot && mode !== "add_bot_folder" && mode !== "post_session_channel" && mode !== "post_session_bot" && visibleIds && visibleIds.size === 0) {
      list.innerHTML = `<div class="folder-tree"><p class="folder-tree__empty">پوشه‌ای با این نام پیدا نشد</p></div>`;
    } else if (!allowRoot && visibleIds && visibleIds.size === 0) {
      list.innerHTML = `<div class="folder-tree"><p class="folder-tree__empty">پوشه‌ای با این نام پیدا نشد</p></div>`;
    }

    list.querySelectorAll("[data-tree-toggle]").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        const key = btn.getAttribute("data-tree-toggle");
        if (key === "root") {
          state.folderTreePicker.expanded.root = !state.folderTreePicker.expanded.root;
        } else {
          const id = Number(key);
          state.folderTreePicker.expanded[id] = !state.folderTreePicker.expanded[id];
        }
        renderFolderTreePickerList(folders);
      });
    });

    list.querySelectorAll("[data-tree-pick]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const raw = btn.getAttribute("data-tree-pick");
        const folderId = raw === "root" ? null : Number(raw);
        await applyFolderTreePickerSelection(folderId);
      });
    });
  }

  async function applyFolderTreePickerSelection(folderId) {
    const mode = state.folderTreePicker.mode;
    const targetId = state.folderTreePicker.targetId;
    closeFolderTreePicker();

    if (!mode) return;

    if (mode === "add_bot_folder") {
      if (!folderId || folderId <= 0) {
        showToast("یک پوشه کانال انتخاب کنید", { type: "warning" });
        return;
      }
      setAddBotChannelFolderSelection(folderId);
      tg?.HapticFeedback?.selectionChanged();
      return;
    }

    if (mode === "post_session_channel") {
      if (!folderId || folderId <= 0) {
        showToast("پوشه کانال را انتخاب کنید", { type: "warning" });
        return;
      }
      setPostSessionChannelFolderSelection(folderId);
      tg?.HapticFeedback?.selectionChanged();
      return;
    }

    if (mode === "hashtag_channel" || mode === "hashtag_set_settings_channel" || mode === "hashtag_add_channel") {
      if (!folderId || folderId <= 0) {
        showToast("پوشه کانال را انتخاب کنید", { type: "warning" });
        return;
      }
      if (mode === "hashtag_set_settings_channel") {
        setHashtagSetSettingsChannelFolderSelection(folderId);
      } else if (mode === "hashtag_add_channel") {
        createHashtagConfigForChannelFolder(folderId);
      } else {
        setHashtagChannelFolderSelection(folderId);
      }
      tg?.HapticFeedback?.selectionChanged();
      return;
    }

    if (mode === "banner_add_folder") {
      if (!folderId || folderId <= 0) {
        showToast("پوشه کانال را انتخاب کنید", { type: "warning" });
        return;
      }
      createBannerBindingForFolder(folderId);
      tg?.HapticFeedback?.selectionChanged();
      return;
    }

    if (mode === "zapas_add_folder") {
      if (!folderId || folderId <= 0) {
        showToast("پوشه کانال را انتخاب کنید", { type: "warning" });
        return;
      }
      createZapasBindingForFolder(folderId);
      tg?.HapticFeedback?.selectionChanged();
      return;
    }

    if (mode === "glass_add_folder" || mode === "glass_settings_folder") {
      if (!folderId || folderId <= 0) {
        showToast("پوشه کانال را انتخاب کنید", { type: "warning" });
        return;
      }
      if (mode === "glass_settings_folder") {
        const existing = getGlassButtonSettingByFolder(folderId);
        state.glassButtonSelectedFolderId = folderId;
        loadGlassButtonSettingsEditor(existing, folderId);
        renderGlassButtonConfigsList();
      } else {
        const existing = getGlassButtonSettingByFolder(folderId);
        if (existing) {
          selectGlassButtonConfig(folderId, { openSettings: true });
          showToast("این پوشه قبلاً تنظیم شده — در حال ویرایش", { type: "info", duration: 2200 });
        } else {
          selectGlassButtonConfig(folderId, { openSettings: true, createIfMissing: true });
        }
      }
      tg?.HapticFeedback?.selectionChanged();
      return;
    }

    if (mode === "post_session_bot") {
      if (!folderId || folderId <= 0) {
        showToast("پوشه ربات را انتخاب کنید", { type: "warning" });
        return;
      }
      setPostSessionBotFolderSelection(folderId);
      tg?.HapticFeedback?.selectionChanged();
      return;
    }

    if (targetId == null) return;

    try {
      if (mode === "channel") {
        await folderApi({ action: "assign", chat_id: Number(targetId), folder_id: folderId });
        await reloadChannels();
        showToast("کانال منتقل شد", { type: "success" });
      } else if (mode === "folder") {
        await folderApi({ action: "move_folder", folder_id: Number(targetId), parent_id: folderId });
        if (Number(state.openFolderId) === Number(targetId)) {
          state.openFolderId = folderId;
        }
        await reloadChannels();
        showToast("پوشه منتقل شد", { type: "success" });
      } else if (mode === "move_bot") {
        await botFolderApi({ action: "assign", bot_id: Number(targetId), folder_id: folderId });
        await reloadBots();
        showToast("ربات منتقل شد", { type: "success" });
      } else if (mode === "move_bot_folder") {
        await botFolderApi({ action: "move_folder", folder_id: Number(targetId), parent_id: folderId });
        if (Number(state.openBotFolderId) === Number(targetId)) {
          state.openBotFolderId = folderId;
          persistBotFolderNav(folderId);
        }
        await reloadBots();
        showToast("پوشه منتقل شد", { type: "success" });
      }
      tg?.HapticFeedback?.notificationOccurred("success");
    } catch (e) {
      showToast(mode === "folder" ? "انتقال پوشه ناموفق بود" : "انتقال ناموفق بود", { type: "error" });
    }
  }

  function showMoveToFolderPicker(chatId, folders) {
    if (!folders.length) {
      showToast("اول یک پوشه بسازید", { type: "warning" });
      return;
    }
    openFolderTreePicker({ mode: "channel", targetId: chatId, folders });
  }

  function showMoveFolderPicker(folderId, folders) {
    const descendants = getFolderDescendantIds(folders, folderId);
    openFolderTreePicker({
      mode: "folder",
      targetId: folderId,
      folders,
      excludeFolderIds: [Number(folderId), ...descendants],
    });
  }

  function computeFolderDetails(folderId) {
    const data = state.cache?.channels || {};
    const folder = (data.folders || []).find((f) => Number(f.id) === Number(folderId));
    const channels = (data.channels || []).filter((c) => Number(c.folder_id) === Number(folderId));
    let totalMembers = 0;
    for (const ch of channels) {
      const n = Number(ch.member_count);
      if (!Number.isNaN(n)) totalMembers += n;
    }
    return {
      folder,
      channel_count: channels.length,
      total_members: totalMembers,
      created_at: folder?.created_at ?? null,
    };
  }

  function closeFolderContextMenu() {
    const menu = document.getElementById("folderContextMenu");
    const panel = document.getElementById("folderContextPanel");
    if (menu) menu.hidden = true;
    if (panel) {
      panel.innerHTML = "";
      panel.removeAttribute("style");
    }
    document.querySelectorAll(".explorer-tile--folder.is-context-active").forEach((el) => {
      el.classList.remove("is-context-active");
    });
    state.activeContextFolderId = null;
  }

  function ensureContextMenuRoot(menuEl) {
    if (menuEl && menuEl.parentElement !== document.body) {
      document.body.appendChild(menuEl);
    }
    return menuEl;
  }

  function ensureFolderContextMenuRoot() {
    return ensureContextMenuRoot(document.getElementById("folderContextMenu"));
  }

  function ensureBotFolderContextMenuRoot() {
    return ensureContextMenuRoot(document.getElementById("botFolderContextMenu"));
  }

  function ensureBotContextMenuRoot() {
    return ensureContextMenuRoot(document.getElementById("botContextMenu"));
  }

  function ensureChannelContextMenuRoot() {
    return ensureContextMenuRoot(document.getElementById("channelContextMenu"));
  }

  function ensureContentGroupFolderContextMenuRoot() {
    return ensureContextMenuRoot(document.getElementById("contentGroupFolderContextMenu"));
  }

  function ensureContentGroupContextMenuRoot() {
    return ensureContextMenuRoot(document.getElementById("contentGroupContextMenu"));
  }

  function ensureAutoPostFolderContextMenuRoot() {
    return ensureContextMenuRoot(document.getElementById("autoPostFolderContextMenu"));
  }

  function ensurePostSessionContextMenuRoot() {
    return ensureContextMenuRoot(document.getElementById("postSessionContextMenu"));
  }

  function buildContextMenuPreview({ icon, iconLetter, iconTone = "folder", name, meta, banned = false, photoUrl = null }) {
    let iconHtml;
    if (photoUrl && !banned) {
      iconHtml = `<img src="${photoUrl}" alt="" loading="lazy" decoding="async" class="context-menu__preview-photo"><span class="context-menu__preview-letter bot-icon-fallback" hidden aria-hidden="true">${escapeHtml(iconLetter || "?")}</span>`;
    } else if (iconLetter) {
      iconHtml = `<span class="context-menu__preview-letter">${escapeHtml(iconLetter)}</span>${banned ? `<span class="bot-ban-badge bot-ban-badge--preview" aria-label="مسدود">BAN</span>` : ""}`;
    } else {
      iconHtml = `<i class="fa-solid fa-${escapeHtml(icon || "folder")}"></i>`;
    }
    return `
      <div class="context-menu__preview context-menu__preview--${escapeHtml(iconTone)}${banned ? " context-menu__preview--banned" : ""}">
        <span class="context-menu__preview-icon${banned ? " context-menu__preview-icon--banned" : ""}${photoUrl && !banned ? " context-menu__preview-icon--photo" : ""}" aria-hidden="true">${iconHtml}</span>
        <div class="context-menu__preview-body">
          <p class="context-menu__preview-name">${escapeHtml(name || "—")}</p>
          <p class="context-menu__preview-meta">${escapeHtml(meta || "")}</p>
        </div>
      </div>
      <div class="context-menu__sep context-menu__sep--preview" role="separator"></div>`;
  }

  function placeFolderContextMenu(panel, tile, clientX, clientY) {
    const pad = 10;
    const gap = 6;
    panel.style.visibility = "hidden";
    panel.style.left = "0px";
    panel.style.top = "0px";

    const place = () => {
      const panelRect = panel.getBoundingClientRect();
      const tileRect = tile?.getBoundingClientRect();
      let left = clientX;
      let top = clientY;

      if (tileRect) {
        const docDir = document.documentElement.getAttribute("dir") === "rtl" ? "rtl" : "ltr";
        top = tileRect.bottom + gap;
        if (docDir === "rtl") {
          left = tileRect.right - panelRect.width;
        } else {
          left = tileRect.left;
        }
        if (top + panelRect.height > window.innerHeight - pad) {
          top = tileRect.top - panelRect.height - gap;
        }
        if (left < pad) left = pad;
        if (left + panelRect.width > window.innerWidth - pad) {
          left = window.innerWidth - panelRect.width - pad;
        }
        if (top < pad) top = pad;
      } else {
        left = Math.min(Math.max(pad, left), Math.max(pad, window.innerWidth - panelRect.width - pad));
        top = Math.min(Math.max(pad, top), Math.max(pad, window.innerHeight - panelRect.height - pad));
      }

      panel.style.left = `${left}px`;
      panel.style.top = `${top}px`;
      panel.style.visibility = "visible";
    };

    requestAnimationFrame(() => requestAnimationFrame(place));
  }

  function openFolderContextMenuFallback(folderId, folders) {
    const folder = folders.find((f) => Number(f.id) === Number(folderId));
    if (!folder) return;
    openActionSheet(
      folder.name || "پوشه",
      [
        { id: "rename", label: "تغییر نام پوشه", icon: "pen" },
        { id: "details", label: "جزئیات", icon: "circle-info" },
        { id: "move", label: "انتقال به پوشه", icon: "folder-tree" },
        { id: "delete", label: "حذف پوشه", icon: "trash-can" },
      ],
      async (action) => {
        if (!action) return;
        if (action === "rename") openFolderModal({ mode: "edit", folder });
        else if (action === "details") openFolderDetailsModal(folderId);
        else if (action === "move") showMoveFolderPicker(folderId, folders);
        else if (action === "delete") await deleteFolderById(folderId);
      }
    );
  }

  function showFolderContextMenu(folderId, clientX, clientY, folders, tileEl) {
    const folder = folders.find((f) => Number(f.id) === Number(folderId));
    if (!folder) return;

    const menu = ensureFolderContextMenuRoot();
    const panel = document.getElementById("folderContextPanel");
    if (!menu || !panel) {
      openFolderContextMenuFallback(folderId, folders);
      return;
    }

    closeFolderContextMenu();
    state.activeContextFolderId = folderId;
    state.folderOpenSuppressUntil = Date.now() + 700;
    state.folderContextBlockClick = true;
    window.setTimeout(() => {
      state.folderContextBlockClick = false;
    }, 700);

    const tile = tileEl || document.querySelector(`[data-folder-id="${folderId}"]`);
    tile?.classList.add("is-context-active");

    const details = computeFolderDetails(folderId);
    const icon = folder.icon || "folder";
    const preview = buildContextMenuPreview({
      icon,
      iconTone: "folder",
      name: folder.name || "پوشه",
      meta: formatFolderMeta(folder) + ` · ${formatNumber(details.total_members)} عضو`,
    });

    panel.innerHTML = `${preview}
      <button type="button" class="context-menu__item" data-folder-ctx="rename">
        <i class="fa-solid fa-pen"></i>
        <span>تغییر نام پوشه</span>
      </button>
      <button type="button" class="context-menu__item" data-folder-ctx="details">
        <i class="fa-solid fa-circle-info"></i>
        <span>جزئیات</span>
      </button>
      <button type="button" class="context-menu__item" data-folder-ctx="pin">
        <i class="fa-solid fa-thumbtack"></i>
        <span>${pinMenuLabel(folder.is_pinned)}</span>
      </button>
      <button type="button" class="context-menu__item" data-folder-ctx="move">
        <i class="fa-solid fa-folder-tree"></i>
        <span>انتقال به پوشه</span>
      </button>
      <div class="context-menu__sep" role="separator"></div>
      <button type="button" class="context-menu__item context-menu__item--danger" data-folder-ctx="delete">
        <i class="fa-solid fa-trash-can"></i>
        <span>حذف پوشه</span>
      </button>`;

    menu.hidden = false;
    placeFolderContextMenu(panel, tile, clientX, clientY);

    const onBackdrop = (e) => {
      if (e.target.matches("[data-folder-context-close]")) {
        closeFolderContextMenu();
      }
    };
    menu.addEventListener("click", onBackdrop, { once: true });

    panel.querySelectorAll("[data-folder-ctx]").forEach((btn) => {
      btn.addEventListener("click", async (e) => {
        e.stopPropagation();
        const action = btn.getAttribute("data-folder-ctx");
        closeFolderContextMenu();
        if (action === "rename") {
          openFolderModal({ mode: "edit", folder });
        } else if (action === "details") {
          openFolderDetailsModal(folderId);
        } else if (action === "pin") {
          await folderApi({ action: "pin", folder_id: folderId, pinned: !folder.is_pinned });
          await reloadChannels();
          showToast(folder.is_pinned ? "پین برداشته شد" : "پین شد", { type: "success" });
        } else if (action === "move") {
          showMoveFolderPicker(folderId, folders);
        } else if (action === "delete") {
          await deleteFolderById(folderId);
        }
      });
    });

    tg?.HapticFeedback?.impactOccurred("medium");
  }

  function closeChannelContextMenu() {
    const menu = document.getElementById("channelContextMenu");
    const panel = document.getElementById("channelContextPanel");
    if (menu) menu.hidden = true;
    if (panel) {
      panel.innerHTML = "";
      panel.removeAttribute("style");
    }
    state.activeContextChannelId = null;
    document.querySelectorAll(".explorer-tile--channel.is-context-active").forEach((el) => {
      el.classList.remove("is-context-active");
    });
  }

  function showChannelContextMenu(chatId, clientX, clientY, channels, tileEl) {
    const channel = (channels || []).find((c) => Number(c.chat_id) === Number(chatId));
    if (!channel) return;

    const menu = ensureChannelContextMenuRoot();
    const panel = document.getElementById("channelContextPanel");
    if (!menu || !panel) return;

    closeChannelContextMenu();
    state.activeContextChannelId = chatId;
    state.channelOpenSuppressUntil = Date.now() + 700;
    state.channelContextBlockClick = true;
    window.setTimeout(() => {
      state.channelContextBlockClick = false;
    }, 700);

    const tile = tileEl || document.querySelector(`[data-channel-id="${chatId}"]`);
    tile?.classList.add("is-context-active");

    const title = channel.title || "کانال";
    const initial = title.charAt(0).toUpperCase();
    const banned = isChannelBanned(channel);
    const members = channel.member_count != null ? `${formatNumber(channel.member_count)} عضو` : "—";
    const preview = buildContextMenuPreview({
      iconLetter: initial,
      iconTone: "folder",
      name: title,
      meta: members,
      banned,
      photoUrl: channel.photo_url,
    });

    panel.innerHTML = `${preview}
      <button type="button" class="context-menu__item" data-channel-ctx="details">
        <i class="fa-solid fa-circle-info"></i>
        <span>جزئیات</span>
      </button>
      <button type="button" class="context-menu__item" data-channel-ctx="pin">
        <i class="fa-solid fa-thumbtack"></i>
        <span>${pinMenuLabel(channel.is_pinned)}</span>
      </button>
      <button type="button" class="context-menu__item" data-channel-ctx="move">
        <i class="fa-solid fa-folder-tree"></i>
        <span>انتقال به پوشه</span>
      </button>`;

    menu.hidden = false;
    placeFolderContextMenu(panel, tile, clientX, clientY);
    bindBotIconPhotoFallbacks(panel);

    menu.addEventListener(
      "click",
      (e) => {
        if (e.target.matches("[data-channel-context-close]")) closeChannelContextMenu();
      },
      { once: true }
    );

    panel.querySelectorAll("[data-channel-ctx]").forEach((btn) => {
      btn.addEventListener("click", async (e) => {
        e.stopPropagation();
        const action = btn.getAttribute("data-channel-ctx");
        closeChannelContextMenu();
        const folders = state.cache?.channels?.folders || [];
        if (action === "details") openChannelDetail(chatId);
        else if (action === "pin") {
          await folderApi({ action: "pin", entity: "channel", chat_id: chatId, pinned: !channel.is_pinned });
          await reloadChannels();
          showToast(channel.is_pinned ? "پین برداشته شد" : "پین شد", { type: "success" });
        } else if (action === "move") showMoveToFolderPicker(chatId, folders);
      });
    });

    tg?.HapticFeedback?.impactOccurred("medium");
  }

  function bindChannelLongPress(el, chatId, channels) {
    if (el.dataset.channelMenuBound === "1") return;
    el.dataset.channelMenuBound = "1";

    const LONG_MS = 480;
    const MOVE_PX = 14;
    let timer = null;
    let startX = 0;
    let startY = 0;
    let longFired = false;

    const clear = () => {
      if (timer) {
        window.clearTimeout(timer);
        timer = null;
      }
    };

    const openAt = (x, y) => {
      longFired = true;
      showChannelContextMenu(chatId, x, y, channels, el);
    };

    el.addEventListener("contextmenu", (e) => {
      e.preventDefault();
      e.stopPropagation();
      clear();
      openAt(e.clientX, e.clientY);
    });

    el.addEventListener(
      "pointerdown",
      (e) => {
        if (e.pointerType === "mouse" && e.button === 2) {
          e.preventDefault();
          clear();
          openAt(e.clientX, e.clientY);
          return;
        }
        if (e.pointerType === "mouse" && e.button !== 0) return;

        longFired = false;
        startX = e.clientX;
        startY = e.clientY;
        clear();
        timer = window.setTimeout(() => openAt(e.clientX, e.clientY), LONG_MS);
      },
      { passive: false }
    );

    el.addEventListener(
      "pointermove",
      (e) => {
        if (!timer) return;
        const dx = Math.abs(e.clientX - startX);
        const dy = Math.abs(e.clientY - startY);
        if (dx > MOVE_PX || dy > MOVE_PX) clear();
      },
      { passive: true }
    );

    el.addEventListener("pointerup", clear);
    el.addEventListener("pointercancel", clear);
    el.addEventListener("pointerleave", (e) => {
      if (e.pointerType === "mouse") clear();
    });

    el.addEventListener(
      "click",
      (e) => {
        if (longFired || state.channelContextBlockClick || Date.now() < state.channelOpenSuppressUntil) {
          e.preventDefault();
          e.stopImmediatePropagation();
          longFired = false;
        }
      },
      true
    );
  }

  function bindFolderLongPress(el, folderId, folders) {
    if (el.dataset.folderMenuBound === "1") return;
    el.dataset.folderMenuBound = "1";

    const LONG_MS = 480;
    const MOVE_PX = 14;
    let timer = null;
    let startX = 0;
    let startY = 0;
    let longFired = false;

    const clear = () => {
      if (timer) {
        window.clearTimeout(timer);
        timer = null;
      }
    };

    const openAt = (x, y) => {
      longFired = true;
      showFolderContextMenu(folderId, x, y, folders, el);
    };

    el.addEventListener("contextmenu", (e) => {
      e.preventDefault();
      e.stopPropagation();
      clear();
      openAt(e.clientX, e.clientY);
    });

    el.addEventListener(
      "pointerdown",
      (e) => {
        if (e.pointerType === "mouse" && e.button === 2) {
          e.preventDefault();
          clear();
          openAt(e.clientX, e.clientY);
          return;
        }
        if (e.pointerType === "mouse" && e.button !== 0) return;

        longFired = false;
        startX = e.clientX;
        startY = e.clientY;
        clear();
        timer = window.setTimeout(() => openAt(e.clientX, e.clientY), LONG_MS);
      },
      { passive: false }
    );

    el.addEventListener(
      "pointermove",
      (e) => {
        if (!timer) return;
        const dx = Math.abs(e.clientX - startX);
        const dy = Math.abs(e.clientY - startY);
        if (dx > MOVE_PX || dy > MOVE_PX) clear();
      },
      { passive: true }
    );

    el.addEventListener("pointerup", clear);
    el.addEventListener("pointercancel", clear);
    el.addEventListener("pointerleave", (e) => {
      if (e.pointerType === "mouse") clear();
    });

    el.addEventListener(
      "click",
      (e) => {
        if (longFired || state.folderContextBlockClick || Date.now() < state.folderOpenSuppressUntil) {
          e.preventDefault();
          e.stopImmediatePropagation();
          longFired = false;
        }
      },
      true
    );
  }

  async function deleteFolderById(folderId) {
    if (!folderId) return;
    try {
      await folderApi({ action: "delete", folder_id: folderId });
      if (state.openFolderId === folderId) state.openFolderId = null;
      await reloadChannels();
      showToast("پوشه حذف شد", { type: "success" });
      tg?.HapticFeedback?.notificationOccurred("success");
    } catch (err) {
      showToast("حذف پوشه ناموفق بود", { type: "error" });
    }
  }

  function openFolderDetailsModal(folderId) {
    const details = computeFolderDetails(folderId);
    const folder = details.folder;
    if (!folder) {
      showToast("پوشه یافت نشد", { type: "error" });
      return;
    }

    const modal = document.getElementById("folderDetailsModal");
    const iconEl = document.getElementById("folderDetailsIcon");
    const icon = folder.icon || "folder";

    setText("folderDetailsName", folder.name || "پوشه");
    setText("folderDetailsChannels", formatNumber(details.channel_count));
    setText("folderDetailsMembers", formatNumber(details.total_members));
    setText("folderDetailsCreated", details.created_at ? formatDate(details.created_at) : "—");

    if (iconEl) {
      iconEl.innerHTML = `<i class="fa-solid fa-${escapeHtml(icon)}"></i>`;
    }
    if (modal) modal.hidden = false;
    tg?.HapticFeedback?.selectionChanged();
  }

  function closeFolderDetailsModal() {
    const modal = document.getElementById("folderDetailsModal");
    if (modal) modal.hidden = true;
  }

  function formatNumber(value) {
    if (value == null || Number.isNaN(Number(value))) return "—";
    return new Intl.NumberFormat("fa-IR").format(Number(value));
  }

  function api(path, options = {}) {
    const timeoutMs = Number(options.timeout) > 0 ? Number(options.timeout) : 20000;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    const method = options.method || "GET";
    let url = `api/${path}`;
    if (method === "GET") {
      url += url.includes("?") ? "&" : "?";
      url += `_t=${Date.now()}`;
    }

    return fetch(url, {
      method,
      headers: {
        "Content-Type": "application/json",
        "X-Telegram-Init-Data": state.initData,
      },
      body: options.body ? JSON.stringify(options.body) : undefined,
      signal: controller.signal,
    })
      .then(async (res) => {
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
          throw new Error(data.error || "request_failed");
        }
        return data;
      })
      .finally(() => clearTimeout(timer));
  }

  function formatDate(value) {
    if (!value) return "—";
    const date = new Date(String(value).replace(" ", "T"));
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat("fa-IR", {
      dateStyle: "medium",
      timeStyle: "short",
    }).format(date);
  }

  function formatDateDashboard(value) {
    if (!value) return "—";
    const date = new Date(String(value).replace(" ", "T"));
    if (Number.isNaN(date.getTime())) return value;
    const day = new Intl.DateTimeFormat("fa-IR", {
      day: "numeric",
      month: "short",
    }).format(date);
    const time = new Intl.DateTimeFormat("fa-IR", {
      hour: "2-digit",
      minute: "2-digit",
    }).format(date);
    return `${day} · ${time}`;
  }

  function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  }

  function showScreen(name) {
    authScreen?.classList.remove("is-active");
    appMain?.removeAttribute("hidden");
    bottomNav?.removeAttribute("hidden");

    Object.entries(screens).forEach(([key, el]) => {
      el?.classList.toggle("is-active", key === name);
    });

    document.querySelectorAll(".bottom-nav [data-nav]").forEach((btn) => {
      btn.classList.toggle("is-active", btn.dataset.nav === name);
    });

    window.scrollTo(0, 0);

    if (name === "server") {
      if (state.cache?.server) {
        renderServer(state.cache.server);
      }
      loadServerTab({ deep: false });
    }

    if (name === "channels" && state.cache?.channels) {
      state.channelsMetaRefreshGen += 1;
      state.channelsMetaRefreshAttempts = 0;
      renderChannels(state.cache.channels, { refreshDashboard: false });
      scheduleChannelsMetaRefresh(state.cache.channels);
    }

    if (name === "channels" && state.cache?.bots) {
      renderBots(state.cache.bots);
    }

    if (name === "ads") {
      loadAdsTab();
    }
  }

  function renderProfile(auth) {
    // Home quick stats are filled from bot/channel aggregates in renderHomeQuickStats().
    void auth;
  }

  function getDashboardPayload(data) {
    return data?.dashboard || null;
  }

  function getDashboardEligibleFolders(folders, promoFolderId) {
    const promoId = promoFolderId != null ? Number(promoFolderId) : null;
    return (folders || []).filter((folder) => promoId == null || Number(folder.id) !== promoId);
  }

  function renderHomeQuickStats() {
    const dash = getDashboardPayload(state.cache?.channels);
    const bots = state.cache?.bots;

    const channelMembers = dash?.totals?.member_count ?? 0;
    const botMembers = bots?.total_bot_users ?? 0;

    setText("homeBotMembers", formatNumber(botMembers));
    setText("homeChannelMembers", formatNumber(channelMembers));
  }

  function channelNeedsMeta(channel) {
    return channel.member_count == null || !channel.photo_url;
  }

  function scheduleChannelsMetaRefresh(data) {
    const channels = data?.channels || [];
    if (!channels.some(channelNeedsMeta)) {
      state.channelsMetaRefreshAttempts = 0;
      return;
    }
    if (!screens.channels?.classList.contains("is-active")) {
      return;
    }
    const maxAttempts = 8;
    if (state.channelsMetaRefreshAttempts >= maxAttempts) {
      return;
    }
    const gen = (state.channelsMetaRefreshGen += 1);
    const delayMs = Math.min(12000, 1200 + state.channelsMetaRefreshAttempts * 1500);
    window.setTimeout(async () => {
      if (gen !== state.channelsMetaRefreshGen) return;
      if (!screens.channels?.classList.contains("is-active")) return;
      try {
        const fresh = await api("channels.php");
        if (gen !== state.channelsMetaRefreshGen) return;
        state.channelsMetaRefreshAttempts += 1;
        renderChannels(fresh, { refreshDashboard: false });
      } catch (e) {
        console.warn("channels meta refresh failed", e);
      }
    }, delayMs);
  }

  function renderChannels(data, options = {}) {
    const refreshDashboard = options.refreshDashboard !== false;
    const channels = data.channels || [];
    const folders = data.folders || [];
    const total = data.total ?? channels.length;

    state.cache = state.cache || {};
    state.cache.channels = data;

    const inFolder = state.openFolderId != null;
    const sub = inFolder
      ? folders.find((f) => f.id === state.openFolderId)?.name || "پوشه"
      : `${total} کانال`;
    setText(
      "channelsCount",
      inFolder
        ? sub
        : total > 0
          ? `${total} کانال`
          : channels.length > 0
            ? `${channels.length} کانال`
            : "هنوز کانالی ثبت نشده"
    );
    if (refreshDashboard) {
      renderDashboardChannelsOverview(data);
    } else {
      refreshDashboardKpisIfPresent(data);
    }

    const list = document.getElementById("channelsList");
    const btnUp = document.getElementById("btnChannelsUp");
    renderChannelsBreadcrumb(folders, state.openFolderId);
    if (btnUp) btnUp.hidden = !inFolder;

    if (!list) return;

    const visibleChannels = sortExplorerPinned(
      channels.filter((c) => {
      const fid = c.folder_id ?? null;
      if (inFolder) return Number(fid) === Number(state.openFolderId);
      if (fid == null || fid === "") return true;
      const folderExists = folders.some((f) => Number(f.id) === Number(fid));
      return !folderExists;
    })
    );

    const visibleFolders = getChildFolders(folders, inFolder ? state.openFolderId : null);

    if (!channels.length) {
      list.innerHTML = `
        <div class="explorer-empty">
          <i class="fa-solid fa-folder-open"></i>
          <p>${inFolder ? "این پوشه خالی است" : "هنوز کانالی ثبت نشده"}</p>
          <span>${inFolder ? "کانال‌ها را بکشید و اینجا رها کنید" : "ربات را در کانال ادمین کنید"}</span>
        </div>`;
      return;
    }

    if (!visibleChannels.length && !visibleFolders.length && !inFolder) {
      list.innerHTML = `
        <div class="explorer-empty">
          <i class="fa-solid fa-bullhorn"></i>
          <p>کانال‌ها در پوشه‌ها هستند</p>
          <span>پوشه‌ها را در صفحه اصلی باز کنید</span>
        </div>`;
      return;
    }

    const folderTiles = visibleFolders
      .map((folder) => {
        const icon = folder.icon || "folder";
        return `
        <div class="explorer-tile explorer-tile--folder${explorerPinnedClass(folder.is_pinned)}" draggable="true" data-folder-id="${folder.id}" data-drag-folder="${folder.id}" data-drop-folder="${folder.id}" tabindex="0">
          ${explorerPinBadge(folder.is_pinned)}
          <button type="button" class="explorer-tile__move" data-move-folder="${folder.id}" aria-label="انتقال پوشه">
            <i class="fa-solid fa-folder-tree"></i>
          </button>
          <span class="explorer-tile__icon explorer-tile__icon--folder"><i class="fa-solid fa-${escapeHtml(icon)}"></i></span>
          <span class="explorer-tile__name">${escapeHtml(folder.name)}</span>
          <span class="explorer-tile__meta">${escapeHtml(formatFolderMeta(folder))}</span>
        </div>`;
      })
      .join("");

    const channelTiles = visibleChannels
      .map((channel) => {
        const title = channel.title || "کانال";
        const initial = title.charAt(0).toUpperCase();
        const pending = channelNeedsMeta(channel);
        const banned = isChannelBanned(channel);
        const members = channel.member_count != null ? formatNumber(channel.member_count) : pending ? "…" : "—";
        const isGroup = channel.type === "group" || channel.type === "supergroup";
        const kindLabel = isGroup ? "گروه" : "کانال";

        return `
        <div class="explorer-tile explorer-tile--channel${isGroup ? " explorer-tile--group-in-channels" : ""}${pending ? " is-meta-pending" : ""}${banned ? " explorer-tile--channel-banned" : ""}${explorerPinnedClass(channel.is_pinned)}" draggable="true" data-channel-id="${channel.chat_id}" data-drag-channel="${channel.chat_id}">
          ${explorerPinBadge(channel.is_pinned)}
          <button type="button" class="explorer-tile__move" data-move-channel="${channel.chat_id}" aria-label="انتقال به پوشه">
            <i class="fa-solid fa-folder-plus"></i>
          </button>
          ${buildChannelIconHtml({ initial, photoUrl: channel.photo_url, banned }).replaceAll("explorer-tile__icon--channel", isGroup ? "explorer-tile__icon--group" : "explorer-tile__icon--channel")}
          <span class="explorer-tile__name">${escapeHtml(channel.title)}</span>
          <span class="explorer-tile__meta">${kindLabel} · ${members} عضو</span>
        </div>`;
      })
      .join("");

    list.innerHTML = folderTiles + channelTiles;
    bindExplorerInteractions(list, folders);
    scheduleChannelsMetaRefresh(data);
  }

  async function folderApi(body) {
    return api("channel_folders.php", { method: "POST", body });
  }

  async function reloadChannels() {
    const data = await api("channels.php");
    renderChannels(data);
    return data;
  }

  function bindExplorerInteractions(list, folders) {
    list.querySelectorAll("[data-folder-id]").forEach((el) => {
      const id = Number(el.dataset.folderId);
      bindFolderLongPress(el, id, folders);
      el.addEventListener("click", (e) => {
        if (e.target.closest("[data-move-folder]")) return;
        if (el.classList.contains("is-dragging")) return;
        if (
          state.folderContextBlockClick ||
          state.activeContextFolderId != null ||
          Date.now() < state.folderOpenSuppressUntil
        ) {
          return;
        }
        state.openFolderId = id;
        renderChannels(state.cache.channels);
        tg?.HapticFeedback?.selectionChanged();
      });
    });

    list.querySelectorAll("[data-move-folder]").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        showMoveFolderPicker(Number(btn.dataset.moveFolder), folders);
      });
    });

    list.querySelectorAll("[data-channel-id]").forEach((el) => {
      const id = Number(el.dataset.channelId);
      bindChannelLongPress(el, id, state.cache?.channels?.channels || []);
      el.addEventListener("click", (e) => {
        if (e.target.closest("[data-move-channel]")) return;
        if (el.classList.contains("is-dragging")) return;
        if (
          state.channelContextBlockClick ||
          state.activeContextChannelId != null ||
          Date.now() < state.channelOpenSuppressUntil
        ) {
          return;
        }
        openChannelDetail(id);
      });
    });

    list.querySelectorAll("[data-move-channel]").forEach((btn) => {
      btn.addEventListener("click", async (e) => {
        e.stopPropagation();
        const chatId = Number(btn.dataset.moveChannel);
        showMoveToFolderPicker(chatId, folders);
      });
    });

    list.querySelectorAll("[data-drag-channel]").forEach((tile) => {
      tile.addEventListener("dragstart", (e) => {
        tile.classList.add("is-dragging");
        const chatId = tile.dataset.dragChannel;
        state.explorerDragPayload = { type: "channel", id: Number(chatId) };
        e.dataTransfer?.setData("text/plain", `channel:${chatId}`);
        e.dataTransfer.effectAllowed = "move";
      });
      tile.addEventListener("dragend", () => {
        tile.classList.remove("is-dragging");
        state.explorerDragPayload = null;
      });
    });

    list.querySelectorAll("[data-drag-folder]").forEach((tile) => {
      tile.addEventListener("dragstart", (e) => {
        if (e.target.closest("[data-move-folder]")) {
          e.preventDefault();
          return;
        }
        tile.classList.add("is-dragging");
        const folderId = Number(tile.dataset.dragFolder);
        state.explorerDragPayload = { type: "folder", id: folderId };
        e.dataTransfer?.setData("text/plain", `folder:${folderId}`);
        e.dataTransfer.effectAllowed = "move";
      });
      tile.addEventListener("dragend", () => {
        tile.classList.remove("is-dragging");
        state.explorerDragPayload = null;
      });
    });

    list.querySelectorAll("[data-drop-folder]").forEach((zone) => {
      zone.addEventListener("dragover", (e) => {
        const payload = state.explorerDragPayload;
        const targetFolderId = Number(zone.dataset.dropFolder);
        const invalid =
          payload?.type === "folder" && isInvalidFolderMove(folders, payload.id, targetFolderId);
        if (invalid) {
          zone.classList.add("is-drop-invalid");
          zone.classList.remove("is-drop-target");
          return;
        }
        zone.classList.remove("is-drop-invalid");
        e.preventDefault();
        if (e.dataTransfer) e.dataTransfer.dropEffect = "move";
        zone.classList.add("is-drop-target");
      });
      zone.addEventListener("dragleave", () => {
        zone.classList.remove("is-drop-target");
        zone.classList.remove("is-drop-invalid");
      });
      zone.addEventListener("drop", async (e) => {
        e.preventDefault();
        zone.classList.remove("is-drop-target");
        const payload = parseExplorerDragPayload(e.dataTransfer?.getData("text/plain"));
        const targetFolderId = Number(zone.dataset.dropFolder);
        if (!payload?.id || !targetFolderId) return;

        try {
          if (payload.type === "channel") {
            await folderApi({ action: "assign", chat_id: payload.id, folder_id: targetFolderId });
            await reloadChannels();
            showToast("کانال منتقل شد", { type: "success" });
          } else if (payload.type === "folder") {
            if (isInvalidFolderMove(folders, payload.id, targetFolderId)) {
              showToast("نمی‌توانید پوشه را داخل خودش یا زیرپوشه‌اش ببرید", { type: "warning" });
              return;
            }
            await folderApi({
              action: "move_folder",
              folder_id: payload.id,
              parent_id: targetFolderId,
            });
            if (Number(state.openFolderId) === Number(payload.id)) {
              state.openFolderId = targetFolderId;
            }
            await reloadChannels();
            showToast("پوشه منتقل شد", { type: "success" });
          }
          tg?.HapticFeedback?.notificationOccurred("success");
        } catch (err) {
          showToast(payload.type === "folder" ? "انتقال پوشه ناموفق بود" : "انتقال ناموفق بود", {
            type: "error",
          });
        }
      });
    });
  }

  function openFolderModal(options = {}) {
    const mode = options.mode || "create";
    const folder = options.folder || null;
    const modal = document.getElementById("folderModal");
    const input = document.getElementById("folderNameInput");
    const picker = document.getElementById("folderIconPicker");
    const title = document.getElementById("folderModalTitle");
    const saveBtn = document.getElementById("btnSaveFolder");
    if (!modal || !picker) return;

    state.folderModalMode = mode;
    state.editingFolderId = mode === "edit" && folder ? Number(folder.id) : null;
    const icon = folder?.icon || "folder";
    state.selectedFolderIcon = icon;
    if (input) input.value = folder?.name || "";
    if (title) title.textContent = mode === "edit" ? "تغییر نام پوشه" : "پوشه جدید";
    if (saveBtn) saveBtn.textContent = mode === "edit" ? "ذخیره تغییرات" : "ذخیره";

    picker.innerHTML = FOLDER_ICONS.map(
      (iconName) => `
      <button type="button" class="icon-picker__btn ${iconName === icon ? "is-active" : ""}" data-icon="${iconName}" aria-label="${iconName}">
        <i class="fa-solid fa-${iconName}"></i>
      </button>`
    ).join("");

    picker.querySelectorAll("[data-icon]").forEach((btn) => {
      btn.addEventListener("click", () => {
        state.selectedFolderIcon = btn.dataset.icon || "folder";
        picker.querySelectorAll(".is-active").forEach((el) => el.classList.remove("is-active"));
        btn.classList.add("is-active");
      });
    });

    modal.hidden = false;
    input?.focus();
  }

  function closeFolderModal() {
    const modal = document.getElementById("folderModal");
    if (modal) modal.hidden = true;
    state.folderModalMode = "create";
    state.editingFolderId = null;
  }

  function initFolderExplorerUi() {
    document.getElementById("btnNewFolder")?.addEventListener("click", () => openFolderModal({ mode: "create" }));
    document.querySelectorAll("[data-close-folder-details]").forEach((el) => {
      el.addEventListener("click", closeFolderDetailsModal);
    });
    document.getElementById("btnChannelsUp")?.addEventListener("click", () => {
      const folders = state.cache?.channels?.folders || [];
      if (state.openFolderId == null) return;
      const current = folders.find((f) => Number(f.id) === Number(state.openFolderId));
      state.openFolderId = current ? getFolderParentId(current) : null;
      renderChannels(state.cache?.channels || { channels: [], folders: [] });
    });
    document.getElementById("btnSaveFolder")?.addEventListener("click", async () => {
      const name = document.getElementById("folderNameInput")?.value?.trim() || "";
      if (!name) {
        showToast("نام پوشه را وارد کنید", { type: "warning" });
        return;
      }
      try {
        if (state.folderModalMode === "edit" && state.editingFolderId) {
          await folderApi({
            action: "update",
            folder_id: state.editingFolderId,
            name,
            icon: state.selectedFolderIcon,
          });
          closeFolderModal();
          await reloadChannels();
          showToast("پوشه به‌روز شد", { type: "success" });
        } else {
          await folderApi({
            action: "create",
            name,
            icon: state.selectedFolderIcon,
            parent_id: state.openFolderId,
          });
          closeFolderModal();
          await reloadChannels();
          showToast("پوشه ساخته شد", { type: "success" });
        }
        tg?.HapticFeedback?.notificationOccurred("success");
      } catch (e) {
        showToast(state.folderModalMode === "edit" ? "ذخیره ناموفق بود" : "ساخت پوشه ناموفق بود", { type: "error" });
      }
    });
    document.querySelectorAll("[data-close-modal]").forEach((el) => {
      el.addEventListener("click", closeFolderModal);
    });
    document.querySelectorAll("[data-close-folder-tree-picker]").forEach((el) => {
      el.addEventListener("click", closeFolderTreePicker);
    });
  }

  function computeBotFolderDetails(folderId) {
    const data = state.cache?.bots || {};
    const folder = (data.folders || []).find((f) => Number(f.id) === Number(folderId));
    const bots = (data.bots || []).filter((b) => Number(b.folder_id) === Number(folderId));
    let totalUsers = 0;
    let totalGrowth = 0;
    for (const bot of bots) {
      const users = Number(bot.user_count);
      const growth = Number(bot.user_growth_24h);
      if (!Number.isNaN(users)) totalUsers += users;
      if (!Number.isNaN(growth)) totalGrowth += growth;
    }
    const botCount = bots.length > 0 ? bots.length : Number(folder?.bot_count ?? 0);
    return {
      folder,
      bots,
      bot_count: botCount,
      total_users: totalUsers,
      user_growth_24h: totalGrowth,
      created_at: folder?.created_at ?? null,
    };
  }

  function renderBotFolderDetailsBotsList(bots) {
    const listEl = document.getElementById("botFolderDetailsBotsList");
    if (!listEl) return;

    if (!bots?.length) {
      listEl.innerHTML = `<p class="folder-details__empty">هنوز رباتی در این پوشه نیست</p>`;
      return;
    }

    listEl.innerHTML = bots
      .map((bot) => {
        const title = bot.bot_name || (bot.bot_username ? `@${bot.bot_username}` : "ربات");
        const initial = title.replace(/^@/, "").charAt(0).toUpperCase() || "B";
        const username = bot.bot_username ? `@${bot.bot_username}` : "—";
        const users = formatNumber(bot.user_count ?? 0);
        const growth = Number(bot.user_growth_24h ?? 0);
        const growthLabel =
          growth > 0 ? `+${formatNumber(growth)}` : growth < 0 ? formatNumber(growth) : "۰";
        const growthClass =
          growth > 0 ? "folder-details__bot-stat--up" : growth < 0 ? "folder-details__bot-stat--down" : "";
        const banned = isBotBanned(bot);

        return `
        <article class="folder-details__bot-row${banned ? " folder-details__bot-row--banned" : ""}" data-bot-id="${bot.id}">
          ${buildBotIconHtml({ initial, photoUrl: bot.photo_url, banned, size: "row" })}
          <div class="folder-details__bot-main">
            <p class="folder-details__bot-name">${escapeHtml(title)}</p>
            <p class="folder-details__bot-meta" dir="ltr">${escapeHtml(username)}</p>
          </div>
          <div class="folder-details__bot-stats">
            <span class="folder-details__bot-stat">
              <span class="folder-details__bot-stat-label">کاربر</span>
              <strong>${users}</strong>
            </span>
            <span class="folder-details__bot-stat ${growthClass}">
              <span class="folder-details__bot-stat-label">۲۴س</span>
              <strong>${growthLabel}</strong>
            </span>
          </div>
        </article>`;
      })
      .join("");

    listEl.querySelectorAll("[data-bot-id]").forEach((el) => {
      el.addEventListener("click", () => {
        const botId = Number(el.dataset.botId);
        if (!botId) return;
        closeBotFolderDetailsModal();
        openBotDetail(botId);
      });
    });
    bindBotIconPhotoFallbacks(listEl);
  }

  async function openBotFolderDetailsModal(folderId) {
    const cached = computeBotFolderDetails(folderId);
    const folder = cached.folder;
    if (!folder) {
      showToast("پوشه یافت نشد", { type: "error" });
      return;
    }

    const modal = document.getElementById("botFolderDetailsModal");
    const iconEl = document.getElementById("botFolderDetailsIcon");
    const icon = folder.icon || "folder";
    if (iconEl) iconEl.innerHTML = `<i class="fa-solid fa-${escapeHtml(icon)}"></i>`;
    setText("botFolderDetailsName", folder.name || "پوشه");
    setText("botFolderDetailsBots", formatNumber(cached.bot_count));
    setText("botFolderDetailsUsers", formatNumber(cached.total_users));
    setText(
      "botFolderDetailsGrowth",
      cached.user_growth_24h > 0
        ? `+${formatNumber(cached.user_growth_24h)}`
        : formatNumber(cached.user_growth_24h ?? 0)
    );
    setText("botFolderDetailsCreated", cached.created_at ? formatDate(cached.created_at) : "—");
    renderBotFolderDetailsBotsList(cached.bots);
    if (modal) modal.hidden = false;

    try {
      const data = await api(`bot_folders.php?folder_id=${encodeURIComponent(folderId)}`);
      const details = data.details;
      if (!details?.folder) return;

      setText("botFolderDetailsBots", formatNumber(details.bot_count ?? 0));
      setText("botFolderDetailsUsers", formatNumber(details.total_users ?? 0));
      setText(
        "botFolderDetailsGrowth",
        Number(details.user_growth_24h ?? 0) > 0
          ? `+${formatNumber(details.user_growth_24h)}`
          : formatNumber(details.user_growth_24h ?? 0)
      );
      setText(
        "botFolderDetailsCreated",
        details.created_at ? formatDate(details.created_at) : "—"
      );
      renderBotFolderDetailsBotsList(details.bots || []);
    } catch (err) {
      const listEl = document.getElementById("botFolderDetailsBotsList");
      if (listEl && !cached.bots?.length) {
        listEl.innerHTML = `<p class="folder-details__empty">بارگذاری لیست ربات‌ها ناموفق بود</p>`;
      }
    }
  }

  function closeBotFolderContextMenu() {
    const menu = document.getElementById("botFolderContextMenu");
    const panel = document.getElementById("botFolderContextPanel");
    if (menu) menu.hidden = true;
    if (panel) {
      panel.innerHTML = "";
      panel.removeAttribute("style");
    }
    state.activeBotContextFolderId = null;
    document.querySelectorAll(".explorer-tile--bot-folder.is-context-active").forEach((el) => {
      el.classList.remove("is-context-active");
    });
  }

  async function deleteBotFolderById(folderId) {
    if (!folderId) return;
    try {
      await botFolderApi({ action: "delete", folder_id: folderId });
      if (Number(state.openBotFolderId) === Number(folderId)) {
        const folders = state.cache?.bots?.folders || [];
        const current = folders.find((f) => Number(f.id) === Number(folderId));
        state.openBotFolderId = current ? getFolderParentId(current) : null;
        persistBotFolderNav(state.openBotFolderId);
      }
      await reloadBots();
      showToast("پوشه حذف شد", { type: "success" });
    } catch (e) {
      showToast("حذف پوشه ناموفق بود", { type: "error" });
    }
  }

  function closeBotFolderDetailsModal() {
    const modal = document.getElementById("botFolderDetailsModal");
    if (modal) modal.hidden = true;
  }

  function showBotFolderContextMenu(folderId, clientX, clientY, folders, tileEl) {
    const folder = folders.find((f) => Number(f.id) === Number(folderId));
    if (!folder) return;

    const menu = ensureBotFolderContextMenuRoot();
    const panel = document.getElementById("botFolderContextPanel");
    if (!menu || !panel) return;

    closeBotFolderContextMenu();
    state.activeBotContextFolderId = folderId;
    state.botFolderOpenSuppressUntil = Date.now() + 700;
    state.botFolderContextBlockClick = true;
    window.setTimeout(() => {
      state.botFolderContextBlockClick = false;
    }, 700);

    const tile = tileEl || document.querySelector(`[data-bot-folder-id="${folderId}"]`);
    tile?.classList.add("is-context-active");

    const details = computeBotFolderDetails(folderId);
    const icon = folder.icon || "folder";
    const createdMeta = details.created_at ? ` · ${formatDate(details.created_at)}` : "";
    const usersMeta =
      details.total_users > 0 ? ` · ${formatNumber(details.total_users)} کاربر` : "";
    const preview = buildContextMenuPreview({
      icon,
      iconTone: "folder",
      name: folder.name || "پوشه",
      meta: `${formatNumber(details.bot_count)} ربات${usersMeta}${createdMeta}`,
    });

    panel.innerHTML = `${preview}
      <button type="button" class="context-menu__item" data-bot-folder-ctx="rename">
        <i class="fa-solid fa-pen"></i>
        <span>تغییر نام پوشه</span>
      </button>
      <button type="button" class="context-menu__item" data-bot-folder-ctx="details">
        <i class="fa-solid fa-circle-info"></i>
        <span>جزئیات</span>
      </button>
      <button type="button" class="context-menu__item" data-bot-folder-ctx="pin">
        <i class="fa-solid fa-thumbtack"></i>
        <span>${pinMenuLabel(folder.is_pinned)}</span>
      </button>
      <button type="button" class="context-menu__item" data-bot-folder-ctx="move">
        <i class="fa-solid fa-folder-tree"></i>
        <span>انتقال به پوشه</span>
      </button>
      <button type="button" class="context-menu__item context-menu__item--join" data-bot-folder-ctx="joins">
        <i class="fa-solid fa-user-lock"></i>
        <span>جوین اجباری</span>
      </button>
      <div class="context-menu__sep" role="separator"></div>
      <button type="button" class="context-menu__item context-menu__item--danger" data-bot-folder-ctx="delete">
        <i class="fa-solid fa-trash-can"></i>
        <span>حذف پوشه</span>
      </button>`;

    menu.hidden = false;
    placeFolderContextMenu(panel, tile, clientX, clientY);

    const onBackdrop = (e) => {
      if (e.target.matches("[data-bot-folder-context-close]")) {
        closeBotFolderContextMenu();
      }
    };
    menu.addEventListener("click", onBackdrop, { once: true });

    panel.querySelectorAll("[data-bot-folder-ctx]").forEach((btn) => {
      btn.addEventListener("click", async (e) => {
        e.stopPropagation();
        const action = btn.getAttribute("data-bot-folder-ctx");
        closeBotFolderContextMenu();
        if (action === "rename") openBotFolderModal({ mode: "edit", folder });
        else if (action === "details") openBotFolderDetailsModal(folderId);
        else if (action === "pin") {
          await botFolderApi({ action: "pin", folder_id: folderId, pinned: !folder.is_pinned });
          await reloadBots();
          showToast(folder.is_pinned ? "پین برداشته شد" : "پین شد", { type: "success" });
        } else if (action === "move") showMoveBotFolderPicker(folderId, folders);
        else if (action === "joins") openBotFolderJoinManageModal(folderId, folder);
        else if (action === "delete") await deleteBotFolderById(folderId);
      });
    });

    tg?.HapticFeedback?.impactOccurred("medium");
  }

  function closeBotContextMenu() {
    const menu = document.getElementById("botContextMenu");
    const panel = document.getElementById("botContextPanel");
    if (menu) menu.hidden = true;
    if (panel) {
      panel.innerHTML = "";
      panel.removeAttribute("style");
    }
    state.activeBotContextBotId = null;
    document.querySelectorAll(".explorer-tile--bot.is-context-active").forEach((el) => {
      el.classList.remove("is-context-active");
    });
  }

  function showBotContextMenu(botId, clientX, clientY, bots, tileEl) {
    const bot = (bots || []).find((b) => Number(b.id) === Number(botId));
    if (!bot) return;

    const menu = ensureBotContextMenuRoot();
    const panel = document.getElementById("botContextPanel");
    if (!menu || !panel) return;

    closeBotContextMenu();
    state.activeBotContextBotId = botId;
    state.botOpenSuppressUntil = Date.now() + 700;
    state.botContextBlockClick = true;
    window.setTimeout(() => {
      state.botContextBlockClick = false;
    }, 700);

    const tile = tileEl || document.querySelector(`[data-bot-id="${botId}"]`);
    tile?.classList.add("is-context-active");

    const title = bot.bot_name || (bot.bot_username ? `@${bot.bot_username}` : "ربات");
    const initial = title.replace(/^@/, "").charAt(0).toUpperCase() || "B";
    const banned = isBotBanned(bot);
    const typeLabel = getBotTypeLabel(bot);
    const usernameMeta = bot.bot_username ? `@${bot.bot_username}` : "—";
    const problemMeta = banned && bot.health_message ? ` · ${bot.health_message}` : "";
    const preview = buildContextMenuPreview({
      iconLetter: initial,
      iconTone: "bot",
      name: title,
      meta: `${typeLabel} · ${usernameMeta}${problemMeta}`,
      banned,
      photoUrl: bot.photo_url,
    });

    panel.innerHTML = `${preview}
      <button type="button" class="context-menu__item" data-bot-ctx="rename">
        <i class="fa-solid fa-pen"></i>
        <span>تغییر نام</span>
      </button>
      <button type="button" class="context-menu__item" data-bot-ctx="details">
        <i class="fa-solid fa-circle-info"></i>
        <span>جزئیات</span>
      </button>
      <button type="button" class="context-menu__item" data-bot-ctx="pin">
        <i class="fa-solid fa-thumbtack"></i>
        <span>${pinMenuLabel(bot.is_pinned)}</span>
      </button>
      <button type="button" class="context-menu__item context-menu__item--join" data-bot-ctx="joins">
        <i class="fa-solid fa-user-lock"></i>
        <span>جوین اجباری</span>
      </button>`;

    menu.hidden = false;
    placeFolderContextMenu(panel, tile, clientX, clientY);
    bindBotIconPhotoFallbacks(panel);

    const onBackdrop = (e) => {
      if (e.target.matches("[data-bot-context-close]")) {
        closeBotContextMenu();
      }
    };
    menu.addEventListener("click", onBackdrop, { once: true });

    panel.querySelectorAll("[data-bot-ctx]").forEach((btn) => {
      btn.addEventListener("click", async (e) => {
        e.stopPropagation();
        const action = btn.getAttribute("data-bot-ctx");
        closeBotContextMenu();
        if (action === "rename") openRenameBotModal(bot);
        else if (action === "details") openBotDetail(botId);
        else if (action === "pin") {
          await botFolderApi({ action: "pin", entity: "bot", bot_id: botId, pinned: !bot.is_pinned });
          await reloadBots();
          showToast(bot.is_pinned ? "پین برداشته شد" : "پین شد", { type: "success" });
        } else if (action === "joins") {
          await openBotDetail(botId);
          showBotTab("settings");
        }
      });
    });

    tg?.HapticFeedback?.impactOccurred("medium");
  }

  function bindBotLongPress(el, botId, bots) {
    if (el.dataset.botMenuBound === "1") return;
    el.dataset.botMenuBound = "1";

    const LONG_MS = 480;
    const MOVE_PX = 14;
    let timer = null;
    let startX = 0;
    let startY = 0;
    let longFired = false;

    const clear = () => {
      if (timer) {
        window.clearTimeout(timer);
        timer = null;
      }
    };

    const openAt = (x, y) => {
      longFired = true;
      showBotContextMenu(botId, x, y, bots, el);
    };

    el.addEventListener("contextmenu", (e) => {
      e.preventDefault();
      e.stopPropagation();
      clear();
      openAt(e.clientX, e.clientY);
    });

    el.addEventListener(
      "pointerdown",
      (e) => {
        if (e.pointerType === "mouse" && e.button === 2) {
          e.preventDefault();
          clear();
          openAt(e.clientX, e.clientY);
          return;
        }
        if (e.pointerType === "mouse" && e.button !== 0) return;

        longFired = false;
        startX = e.clientX;
        startY = e.clientY;
        clear();
        timer = window.setTimeout(() => openAt(e.clientX, e.clientY), LONG_MS);
      },
      { passive: false }
    );

    el.addEventListener(
      "pointermove",
      (e) => {
        if (!timer) return;
        const dx = Math.abs(e.clientX - startX);
        const dy = Math.abs(e.clientY - startY);
        if (dx > MOVE_PX || dy > MOVE_PX) clear();
      },
      { passive: true }
    );

    el.addEventListener("pointerup", clear);
    el.addEventListener("pointercancel", clear);
    el.addEventListener("pointerleave", (e) => {
      if (e.pointerType === "mouse") clear();
    });

    el.addEventListener(
      "click",
      (e) => {
        if (longFired || state.botContextBlockClick || Date.now() < state.botOpenSuppressUntil) {
          e.preventDefault();
          e.stopImmediatePropagation();
          longFired = false;
        }
      },
      true
    );
  }

  function joinErrorMessage(err) {
    const code = String(err?.message || err || "");
    if (code.includes("manage_bot_not_admin")) {
      return "یکی از ربات‌های مدیریت باید ادمین کانال باشد";
    }
    if (code.includes("folder_empty")) return "این پوشه ربات ندارد";
    return "عملیات ناموفق بود";
  }

  async function loadFolderJoinSettings(folderId) {
    const data = await api(`bot_joins.php?folder_id=${folderId}`);
    state.cache = state.cache || {};
    state.cache.folderJoins = state.cache.folderJoins || {};
    state.cache.folderJoins[folderId] = data.joins || {};
    renderFolderJoinSettings(folderId, data.joins || {});
    return data.joins || {};
  }

  function renderFolderJoinSettings(folderId, joins) {
    const forcedList = document.getElementById("botFolderForcedJoinsList");
    const fakeList = document.getElementById("botFolderFakeJoinsList");
    const hint = document.getElementById("botFolderJoinManageHint");
    const forced = joins.forced_joins || [];
    const fake = joins.fake_joins || [];
    const botCount = joins.bot_count ?? 0;

    if (hint) {
      hint.textContent =
        botCount > 0
          ? `${botCount} ربات در این پوشه · جوین‌های زیر روی همه اعمال شده‌اند`
          : "این پوشه ربات ندارد";
    }

    if (forcedList) {
      forcedList.innerHTML = forced.length
        ? forced
            .map(
              (item) => `
          <div class="join-rule-row">
            <div class="join-rule-row__body">
              <span class="join-rule-row__link">${escapeHtml(item.link)}</span>
              <span class="join-rule-row__meta">کانال: <span dir="ltr">${escapeHtml(item.channel_id || "—")}</span> · ${formatNumber(item.bot_count || 0)} ربات</span>
            </div>
            <button type="button" class="btn btn--ghost btn--sm" data-folder-remove-forced="${escapeHtml(item.link)}">حذف</button>
          </div>`
            )
            .join("")
        : `<p class="channel-empty__sub">جوین اجباری ثبت نشده</p>`;
      forcedList.querySelectorAll("[data-folder-remove-forced]").forEach((btn) => {
        btn.addEventListener("click", async () => {
          try {
            await botJoinApi({
              action: "folder_remove_forced",
              folder_id: folderId,
              link: btn.getAttribute("data-folder-remove-forced"),
            });
            await loadFolderJoinSettings(folderId);
            showToast("جوین اجباری از همه ربات‌ها حذف شد", { type: "success" });
          } catch (e) {
            showToast(joinErrorMessage(e), { type: "error" });
          }
        });
      });
    }

    if (fakeList) {
      fakeList.innerHTML = fake.length
        ? fake
            .map(
              (item) => `
          <div class="join-rule-row">
            <div class="join-rule-row__body">
              <span class="join-rule-row__link">${escapeHtml(item.link)}</span>
              <span class="join-rule-row__meta">${formatNumber(item.bot_count || 0)} ربات</span>
            </div>
            <button type="button" class="btn btn--ghost btn--sm" data-folder-remove-fake="${escapeHtml(item.link)}">حذف</button>
          </div>`
            )
            .join("")
        : `<p class="channel-empty__sub">جوین فیک ثبت نشده</p>`;
      fakeList.querySelectorAll("[data-folder-remove-fake]").forEach((btn) => {
        btn.addEventListener("click", async () => {
          try {
            await botJoinApi({
              action: "folder_remove_fake",
              folder_id: folderId,
              link: btn.getAttribute("data-folder-remove-fake"),
            });
            await loadFolderJoinSettings(folderId);
            showToast("جوین فیک از همه ربات‌ها حذف شد", { type: "success" });
          } catch (e) {
            showToast(joinErrorMessage(e), { type: "error" });
          }
        });
      });
    }
  }

  async function openBotFolderJoinManageModal(folderId, folder) {
    state.botFolderJoinManageFolderId = folderId;
    const modal = document.getElementById("botFolderJoinManageModal");
    const nameEl = document.getElementById("botFolderJoinManageName");
    if (nameEl) nameEl.textContent = folder?.name || "پوشه";
    if (modal) modal.hidden = false;
    try {
      await loadFolderJoinSettings(folderId);
    } catch (e) {
      showToast("بارگذاری جوین‌ها ناموفق بود", { type: "error" });
    }
  }

  function closeBotFolderJoinManageModal() {
    const modal = document.getElementById("botFolderJoinManageModal");
    if (modal) modal.hidden = true;
    state.botFolderJoinManageFolderId = null;
  }

  async function botJoinApi(body) {
    return api("bot_joins.php", { method: "POST", body });
  }

  async function loadBotJoinSettings(botId) {
    const data = await api(`bot_joins.php?bot_id=${botId}`);
    state.cache = state.cache || {};
    state.cache.botJoins = state.cache.botJoins || {};
    state.cache.botJoins[botId] = data.joins || {};
    renderBotJoinSettings(data.joins || {});
    return data.joins || {};
  }

  function renderBotJoinSettings(joins) {
    const forcedList = document.getElementById("botForcedJoinsList");
    const fakeList = document.getElementById("botFakeJoinsList");
    const forced = joins.forced_joins || [];
    const fake = joins.fake_joins || [];

    if (forcedList) {
      forcedList.innerHTML = forced.length
        ? forced
            .map(
              (item) => `
          <div class="join-rule-row">
            <div class="join-rule-row__body">
              <span class="join-rule-row__link">${escapeHtml(item.link)}</span>
              <span class="join-rule-row__meta">کانال: <span dir="ltr">${escapeHtml(item.channel_id || "—")}</span></span>
            </div>
            <button type="button" class="btn btn--ghost btn--sm" data-remove-forced="${escapeHtml(item.link)}">حذف</button>
          </div>`
            )
            .join("")
        : `<p class="channel-empty__sub">جوین اجباری ثبت نشده</p>`;
      forcedList.querySelectorAll("[data-remove-forced]").forEach((btn) => {
        btn.addEventListener("click", async () => {
          if (!state.activeBotId) return;
          try {
            await botJoinApi({
              action: "remove_forced",
              bot_id: state.activeBotId,
              link: btn.getAttribute("data-remove-forced"),
            });
            await loadBotJoinSettings(state.activeBotId);
            showToast("جوین اجباری حذف شد", { type: "success" });
          } catch (e) {
            showToast("حذف ناموفق بود", { type: "error" });
          }
        });
      });
    }

    if (fakeList) {
      fakeList.innerHTML = fake.length
        ? fake
            .map(
              (item) => `
          <div class="join-rule-row">
            <div class="join-rule-row__body">
              <span class="join-rule-row__link">${escapeHtml(item.link)}</span>
            </div>
            <button type="button" class="btn btn--ghost btn--sm" data-remove-fake="${escapeHtml(item.link)}">حذف</button>
          </div>`
            )
            .join("")
        : `<p class="channel-empty__sub">جوین فیک ثبت نشده</p>`;
      fakeList.querySelectorAll("[data-remove-fake]").forEach((btn) => {
        btn.addEventListener("click", async () => {
          if (!state.activeBotId) return;
          try {
            await botJoinApi({
              action: "remove_fake",
              bot_id: state.activeBotId,
              link: btn.getAttribute("data-remove-fake"),
            });
            await loadBotJoinSettings(state.activeBotId);
            showToast("جوین فیک حذف شد", { type: "success" });
          } catch (e) {
            showToast("حذف ناموفق بود", { type: "error" });
          }
        });
      });
    }
  }

  function normalizeProfileText(value) {
    return String(value || "").trim().replace(/\s+/g, " ");
  }

  function buildBotProfilePayload(botId, fields) {
    const original = state.botProfileOriginal?.[botId] || {};
    const payload = { bot_id: botId };
    let hasChange = false;

    const maybeSet = (key, nextValue) => {
      if (!Object.prototype.hasOwnProperty.call(fields, key)) return;
      const next = normalizeProfileText(fields[key]);
      const prev = normalizeProfileText(original[key] ?? "");
      if (next !== prev) {
        payload[key] = next;
        hasChange = true;
      }
    };

    maybeSet("name", fields.name);
    maybeSet("description", fields.description);
    maybeSet("short_description", fields.short_description);

    if (state.botProfilePendingPhoto?.base64) {
      payload.photo_base64 = state.botProfilePendingPhoto.base64;
      hasChange = true;
    }
    if (state.botProfileDeletePhoto) {
      payload.delete_photo = true;
      hasChange = true;
    }

    return { payload, hasChange };
  }

  function botProfileFieldLabel(field) {
    if (field === "description") return "بیو";
    if (field === "short_description") return "توضیح کوتاه";
    if (field === "name") return "نام";
    if (field === "photo") return "عکس";
    return field;
  }

  function botProfileSuccessMessage(data) {
    const updated = Array.isArray(data?.updated_fields) ? data.updated_fields : [];
    const failed = data?.failed_fields && typeof data.failed_fields === "object" ? data.failed_fields : {};
    const failedEntries = Object.entries(failed);

    if (updated.length === 0 && failedEntries.length > 0) {
      return botProfileErrorMessage({ message: failedEntries[0][1] });
    }

    const savedParts = updated.map(botProfileFieldLabel);
    let message = savedParts.length === 1
      ? `${savedParts[0]} ربات ذخیره شد`
      : savedParts.length > 1
        ? `${savedParts.join(" و ")} ذخیره شد`
        : "پروفایل ربات ذخیره شد";

    if (failedEntries.length > 0) {
      const failParts = failedEntries.map(([field, err]) => {
        const label = botProfileFieldLabel(field);
        const detail = botProfileErrorMessage({ message: err });
        return `${label}: ${detail}`;
      });
      message = `${message} — ${failParts.join(" · ")}`;
    }

    return message;
  }

  function botProfileErrorMessage(err) {
    const code = String(err?.message || err || "");
    if (code.includes("token_invalid") || code.includes("Unauthorized")) {
      return "توکن ربات نامعتبر است — ربات را دوباره بسازید یا توکن را در BotFather بررسی کنید";
    }
    if (code.startsWith("rate_limit:")) {
      const seconds = parseInt(code.split(":")[1] || "0", 10);
      if (seconds > 3600) {
        const hours = Math.ceil(seconds / 3600);
        return `تلگرام اجازه تغییر نام از API را نمی‌دهد — می‌توانید از BotFather تغییر دهید یا حدود ${hours} ساعت دیگر دوباره امتحان کنید`;
      }
      if (seconds > 60) {
        const mins = Math.ceil(seconds / 60);
        return `تلگرام محدودیت دارد — ${mins} دقیقه دیگر دوباره امتحان کنید`;
      }
      return "تلگرام محدودیت موقت دارد — چند لحظه بعد دوباره امتحان کنید";
    }
    if (code.includes("rate_limit") || code.includes("Too Many Requests")) {
      return "تلگرام محدودیت تغییر نام دارد — کمی بعد دوباره امتحان کنید";
    }
    if (code.includes("name_required")) return "نام ربات را وارد کنید";
    if (code.includes("name_too_long")) return "نام ربات حداکثر ۶۴ کاراکتر";
    if (code.includes("description_too_long")) return "بیو حداکثر ۵۱۲ کاراکتر";
    if (code.includes("short_description_too_long")) return "توضیح کوتاه حداکثر ۱۲۰ کاراکتر";
    if (code.includes("invalid_photo")) return "فایل عکس نامعتبر است";
    if (code.includes("photo_too_large")) return "حجم عکس بیش از ۱۰ مگابایت است";
    if (code.includes("photo_too_small")) return "عکس خیلی کوچک است — تصویر بزرگ‌تری انتخاب کنید";
    if (code.includes("set_photo_failed") || code.includes("PHOTO")) return "آپلود عکس پروفایل ناموفق بود";
    if (code.includes("set_name_failed")) return "تغییر نام ربات در تلگرام ناموفق بود";
    if (code.includes("set_description_failed")) return "تغییر بیو ربات ناموفق بود";
    if (code.includes("bot_not_found")) return "ربات پیدا نشد";
    if (code.includes("nothing_to_update")) return "تغییری برای ذخیره نیست";
    if (code.length > 0 && code !== "request_failed") return code;
    return "به‌روزرسانی پروفایل ناموفق بود";
  }

  function updateBotProfileCharCounts() {
    const desc = document.getElementById("botProfileDescInput");
    const shortDesc = document.getElementById("botProfileShortDescInput");
    const descCount = document.getElementById("botProfileDescCount");
    const shortCount = document.getElementById("botProfileShortDescCount");
    if (desc && descCount) {
      descCount.textContent = `${(desc.value || "").length} / 512`;
    }
    if (shortDesc && shortCount) {
      shortCount.textContent = `${(shortDesc.value || "").length} / 120`;
    }
  }

  function setBotProfileStatus(message, type = "") {
    const el = document.getElementById("botProfileStatus");
    if (!el) return;
    if (!message) {
      el.hidden = true;
      el.textContent = "";
      el.classList.remove("is-ok", "is-err");
      return;
    }
    el.hidden = false;
    el.textContent = message;
    el.classList.toggle("is-ok", type === "ok");
    el.classList.toggle("is-err", type === "err");
  }

  function renderBotProfilePhoto(profile) {
    const preview = document.getElementById("botProfilePhotoPreview");
    const placeholder = document.getElementById("botProfilePhotoPlaceholder");
    const deleteBtn = document.getElementById("btnBotProfilePhotoDelete");
    const pending = state.botProfilePendingPhoto;
    const url = pending?.previewUrl || profile?.photo_url || "";

    if (preview) {
      if (url) {
        preview.src = url;
        preview.hidden = false;
      } else {
        preview.removeAttribute("src");
        preview.hidden = true;
      }
    }
    if (placeholder) {
      placeholder.hidden = Boolean(url);
    }
    if (deleteBtn) {
      const hasPhoto = Boolean(url) || Boolean(profile?.has_photo);
      deleteBtn.hidden = !hasPhoto;
    }
  }

  function renderBotProfileEditor(profile) {
    const nameInput = document.getElementById("botProfileNameInput");
    const descInput = document.getElementById("botProfileDescInput");
    const shortInput = document.getElementById("botProfileShortDescInput");
    const saveBtn = document.getElementById("btnSaveBotProfile");

    if (nameInput) nameInput.value = profile?.name || profile?.local_name || "";
    if (descInput) descInput.value = profile?.description || "";
    if (shortInput) shortInput.value = profile?.short_description || "";
    updateBotProfileCharCounts();
    renderBotProfilePhoto(profile);
    setBotProfileStatus("");

    if (saveBtn) {
      saveBtn.disabled = Boolean(profile?.telegram_error);
    }
    if (profile?.telegram_error) {
      setBotProfileStatus("اتصال به تلگرام برقرار نشد — توکن ربات را بررسی کنید", "err");
    }
  }

  function applyBotProfilePhotoToHeader(photoUrl) {
    const avatar = document.getElementById("botDetailAvatar");
    if (!avatar || !photoUrl) return;
    const bot = state.cache?.botStats?.bot || {};
    if (isBotBanned(bot)) return;
    avatar.innerHTML = `<img src="${photoUrl}" alt="">`;
  }

  async function loadBotProfileSettings(botId) {
    const data = await api(`bot_profile.php?bot_id=${botId}`);
    state.cache = state.cache || {};
    state.cache.botProfiles = state.cache.botProfiles || {};
    state.cache.botProfiles[botId] = data.profile || {};
    state.botProfileOriginal = state.botProfileOriginal || {};
    state.botProfileOriginal[botId] = {
      name: normalizeProfileText(data.profile?.name || data.profile?.local_name || ""),
      description: normalizeProfileText(data.profile?.description || ""),
      short_description: normalizeProfileText(data.profile?.short_description || ""),
    };
    state.botProfilePendingPhoto = null;
    state.botProfileDeletePhoto = false;
    renderBotProfileEditor(data.profile || {});
    if (data.profile?.photo_url) {
      applyBotProfilePhotoToHeader(data.profile.photo_url);
    }
    return data.profile || {};
  }

  function readFileAsBase64(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => {
        const result = String(reader.result || "");
        const comma = result.indexOf(",");
        resolve(comma >= 0 ? result.slice(comma + 1) : result);
      };
      reader.onerror = () => reject(new Error("read_failed"));
      reader.readAsDataURL(file);
    });
  }

  async function saveBotProfile() {
    if (!state.activeBotId) return;
    const name = document.getElementById("botProfileNameInput")?.value?.trim() || "";
    const description = document.getElementById("botProfileDescInput")?.value?.trim() || "";
    const shortDescription = document.getElementById("botProfileShortDescInput")?.value?.trim() || "";
    const saveBtn = document.getElementById("btnSaveBotProfile");

    if (!name) {
      showToast("نام ربات را وارد کنید", { type: "warning" });
      return;
    }

    const { payload, hasChange } = buildBotProfilePayload(state.activeBotId, {
      name,
      description,
      short_description: shortDescription,
    });

    if (!hasChange) {
      showToast("تغییری برای ذخیره نیست", { type: "info" });
      return;
    }

    const changedKeys = Object.keys(payload).filter((key) => key !== "bot_id");

    if (saveBtn) saveBtn.disabled = true;
    setBotProfileStatus("در حال ذخیره...", "");

    try {
      const data = await api("bot_profile.php", { method: "POST", body: payload });
      const profile = data.profile || {};
      state.cache = state.cache || {};
      state.cache.botProfiles = state.cache.botProfiles || {};
      state.cache.botProfiles[state.activeBotId] = profile;
      state.botProfileOriginal = state.botProfileOriginal || {};
      state.botProfileOriginal[state.activeBotId] = {
        name: normalizeProfileText(profile.name || name),
        description: normalizeProfileText(profile.description ?? description),
        short_description: normalizeProfileText(profile.short_description ?? shortDescription),
      };
      state.botProfilePendingPhoto = null;
      state.botProfileDeletePhoto = false;

      if (state.cache.botStats?.bot) {
        state.cache.botStats.bot.bot_name = profile.name || name;
      }
      renderBotProfileEditor(profile);
      renderBotDetail(state.cache.botStats || { bot: { bot_name: name }, summary: {} });
      if (profile.photo_url) {
        applyBotProfilePhotoToHeader(profile.photo_url);
      }

      const successMsg = botProfileSuccessMessage(data);
      const hasWarnings = data?.failed_fields && Object.keys(data.failed_fields).length > 0;
      setBotProfileStatus(successMsg, hasWarnings ? "err" : "ok");
      showToast(successMsg, { type: hasWarnings ? "warning" : "success", duration: hasWarnings ? 5000 : 3000 });
      tg?.HapticFeedback?.notificationOccurred(hasWarnings ? "warning" : "success");
    } catch (err) {
      setBotProfileStatus(botProfileErrorMessage(err), "err");
      showToast(botProfileErrorMessage(err), { type: "error" });
    } finally {
      if (saveBtn) saveBtn.disabled = false;
    }
  }

  const TELEGRAM_LINK_PREFIX = "https://t.me/";

  function getJoinModalLinkInput(prefix) {
    const suffixWrap = document.getElementById(`${prefix}LinkPrefixWrap`);
    const suffixInput = document.getElementById(`${prefix}LinkSuffixInput`);
    const fullInput = document.getElementById(`${prefix}LinkInput`);
    if (suffixWrap && !suffixWrap.hidden && suffixInput) return suffixInput;
    return fullInput;
  }

  function handleJoinModalEnter(e, prefix) {
    const target = e.target;
    if (!target || target.tagName !== "INPUT") return false;

    const type = document.getElementById(`${prefix}ModalType`)?.value || "forced";
    const channelInput = document.getElementById(`${prefix}ChannelIdInput`);
    const linkInput = getJoinModalLinkInput(prefix);

    if (type !== "fake" && target === channelInput && linkInput) {
      const channelVal = (channelInput.value || "").trim();
      const linkVal = (linkInput.value || "").trim();
      if (channelVal && !linkVal) {
        e.preventDefault();
        linkInput.focus();
        return true;
      }
    }
    return false;
  }

  function bindModalEnterSubmit(modalId, saveBtnId, options = {}) {
    const modal = document.getElementById(modalId);
    const saveBtn = document.getElementById(saveBtnId);
    if (!modal || !saveBtn || modal.dataset.enterSubmitBound === "1") return;
    modal.dataset.enterSubmitBound = "1";

    modal.addEventListener("keydown", (e) => {
      if (e.key !== "Enter" || e.isComposing || e.shiftKey || e.ctrlKey || e.altKey || e.metaKey) return;
      if (modal.hidden) return;

      const target = e.target;
      if (!target) return;
      const tag = target.tagName;
      if (tag !== "INPUT" && tag !== "TEXTAREA" && tag !== "SELECT") return;

      const inputType = (target.type || "").toLowerCase();
      if (inputType === "button" || inputType === "submit" || inputType === "checkbox" || inputType === "radio") {
        return;
      }

      if (typeof options.onEnter === "function" && options.onEnter(e, modal)) return;

      const fieldOrder = options.fieldOrder || [];
      if (fieldOrder.length) {
        const idx = fieldOrder.findIndex((sel) => target.matches(sel));
        if (idx >= 0 && idx < fieldOrder.length - 1) {
          const next = modal.querySelector(fieldOrder[idx + 1]);
          if (next && !next.disabled && !next.hidden && next.offsetParent !== null) {
            const curVal = (target.value || "").trim();
            const nextVal = (next.value || "").trim();
            if (curVal && !nextVal) {
              e.preventDefault();
              next.focus();
              return;
            }
          }
        }
      }

      e.preventDefault();
      if (!saveBtn.disabled) saveBtn.click();
    });
  }

  function initModalEnterSubmit() {
    bindModalEnterSubmit("folderModal", "btnSaveFolder");
    bindModalEnterSubmit("botFolderModal", "btnSaveBotFolder");
    bindModalEnterSubmit("addBotModal", "btnSaveAddBot", {
      fieldOrder: ["#addBotTokenInput", "#addBotChannelFolderInput"],
    });
    bindModalEnterSubmit("renameBotModal", "btnSaveRenameBot");
    bindModalEnterSubmit("globalBotOwnerModal", "btnSaveGlobalBotOwner");
    bindModalEnterSubmit("uploaderVersionModal", "btnSaveUploaderVersion", {
      fieldOrder: [
        "#uploaderVersionNameInput",
        "#uploaderVersionPathInput",
        "#uploaderVersionWebhookInput",
      ],
    });
    bindModalEnterSubmit("botJoinModal", "btnSaveBotJoin", {
      onEnter: (e) => handleJoinModalEnter(e, "botJoin"),
    });
    bindModalEnterSubmit("botFolderJoinModal", "btnSaveBotFolderJoin", {
      onEnter: (e) => handleJoinModalEnter(e, "botFolderJoin"),
    });
  }

  function setJoinLinkFieldMode(prefix, type) {
    const isFake = type === "fake";
    const fullWrap = document.getElementById(`${prefix}LinkFullWrap`);
    const prefixWrap = document.getElementById(`${prefix}LinkPrefixWrap`);
    if (fullWrap) fullWrap.hidden = isFake;
    if (prefixWrap) prefixWrap.hidden = !isFake;
  }

  function clearJoinLinkFields(prefix) {
    const fullInput = document.getElementById(`${prefix}LinkInput`);
    const suffixInput = document.getElementById(`${prefix}LinkSuffixInput`);
    if (fullInput) fullInput.value = "";
    if (suffixInput) suffixInput.value = "";
  }

  function normalizeFakeJoinLink(raw) {
    let value = (raw || "").trim();
    if (!value) return "";
    value = value.replace(/^https?:\/\/(t\.me|telegram\.me)\//i, "");
    value = value.replace(/^@+/, "");
    value = value.replace(/\/+$/, "");
    if (!value) return "";
    return TELEGRAM_LINK_PREFIX + value;
  }

  function readJoinLinkFromModal(prefix, type) {
    if (type === "fake") {
      const suffix = document.getElementById(`${prefix}LinkSuffixInput`)?.value?.trim() || "";
      return normalizeFakeJoinLink(suffix);
    }
    return document.getElementById(`${prefix}LinkInput`)?.value?.trim() || "";
  }

  function openBotJoinModal(type = "forced") {
    const modal = document.getElementById("botJoinModal");
    const title = document.getElementById("botJoinModalTitle");
    const typeInput = document.getElementById("botJoinModalType");
    const channelField = document.getElementById("botJoinChannelIdField");
    const channelInput = document.getElementById("botJoinChannelIdInput");
    const suffixInput = document.getElementById("botJoinLinkSuffixInput");
    if (typeInput) typeInput.value = type;
    if (title) title.textContent = type === "fake" ? "افزودن جوین فیک" : "افزودن جوین اجباری";
    if (channelField) channelField.hidden = type === "fake";
    if (channelInput) channelInput.value = "";
    clearJoinLinkFields("botJoin");
    setJoinLinkFieldMode("botJoin", type);
    if (modal) modal.hidden = false;
    window.requestAnimationFrame(() => {
      if (type === "fake") suffixInput?.focus();
      else channelInput?.focus();
    });
  }

  function closeBotJoinModal() {
    const modal = document.getElementById("botJoinModal");
    if (modal) modal.hidden = true;
  }

  function openBotFolderJoinModal(folderId, type = "forced") {
    state.botFolderJoinFolderId = folderId;
    const modal = document.getElementById("botFolderJoinModal");
    const title = document.getElementById("botFolderJoinModalTitle");
    const typeInput = document.getElementById("botFolderJoinModalType");
    const channelField = document.getElementById("botFolderJoinChannelIdField");
    const channelInput = document.getElementById("botFolderJoinChannelIdInput");
    const suffixInput = document.getElementById("botFolderJoinLinkSuffixInput");
    if (typeInput) typeInput.value = type;
    if (title) title.textContent = type === "fake" ? "جوین فیک گروهی" : "جوین اجباری گروهی";
    if (channelField) channelField.hidden = type === "fake";
    if (channelInput) channelInput.value = "";
    clearJoinLinkFields("botFolderJoin");
    setJoinLinkFieldMode("botFolderJoin", type);
    if (modal) {
      if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
      }
      modal.hidden = false;
    }
    window.requestAnimationFrame(() => {
      if (type === "fake") suffixInput?.focus();
      else channelInput?.focus();
    });
  }

  function closeBotFolderJoinModal() {
    const modal = document.getElementById("botFolderJoinModal");
    if (modal) modal.hidden = true;
    state.botFolderJoinFolderId = null;
  }

  function openRenameBotModal(bot) {
    state.editingRenameBotId = Number(bot.id);
    const modal = document.getElementById("renameBotModal");
    const input = document.getElementById("renameBotNameInput");
    if (input) input.value = bot.bot_name || "";
    if (modal) modal.hidden = false;
    input?.focus();
  }

  function closeRenameBotModal() {
    const modal = document.getElementById("renameBotModal");
    if (modal) modal.hidden = true;
    state.editingRenameBotId = null;
  }

  function bindBotFolderLongPress(el, folderId, folders) {
    if (el.dataset.botFolderMenuBound === "1") return;
    el.dataset.botFolderMenuBound = "1";

    const LONG_MS = 480;
    const MOVE_PX = 14;
    let timer = null;
    let startX = 0;
    let startY = 0;
    let longFired = false;

    const clear = () => {
      if (timer) {
        window.clearTimeout(timer);
        timer = null;
      }
    };

    const openAt = (x, y) => {
      longFired = true;
      showBotFolderContextMenu(folderId, x, y, folders, el);
    };

    el.addEventListener("contextmenu", (e) => {
      e.preventDefault();
      e.stopPropagation();
      clear();
      openAt(e.clientX, e.clientY);
    });

    el.addEventListener(
      "pointerdown",
      (e) => {
        if (e.pointerType === "mouse" && e.button === 2) {
          e.preventDefault();
          clear();
          openAt(e.clientX, e.clientY);
          return;
        }
        if (e.pointerType === "mouse" && e.button !== 0) return;

        longFired = false;
        startX = e.clientX;
        startY = e.clientY;
        clear();
        timer = window.setTimeout(() => openAt(e.clientX, e.clientY), LONG_MS);
      },
      { passive: false }
    );

    el.addEventListener(
      "pointermove",
      (e) => {
        if (!timer) return;
        const dx = Math.abs(e.clientX - startX);
        const dy = Math.abs(e.clientY - startY);
        if (dx > MOVE_PX || dy > MOVE_PX) clear();
      },
      { passive: true }
    );

    el.addEventListener("pointerup", clear);
    el.addEventListener("pointercancel", clear);
    el.addEventListener("pointerleave", (e) => {
      if (e.pointerType === "mouse") clear();
    });

    el.addEventListener(
      "click",
      (e) => {
        if (longFired || state.botFolderContextBlockClick || Date.now() < state.botFolderOpenSuppressUntil) {
          e.preventDefault();
          e.stopImmediatePropagation();
          longFired = false;
        }
      },
      true
    );
  }

  function renderBots(data) {
    const bots = data.bots || [];
    const folders = data.folders || [];
    const total = data.total ?? bots.length;

    state.cache = state.cache || {};
    state.cache.bots = data;

    const inFolder = state.openBotFolderId != null;
    setText(
      "botsCount",
      inFolder
        ? folders.find((f) => Number(f.id) === Number(state.openBotFolderId))?.name || "پوشه"
        : total > 0
          ? `${total} ربات`
          : "هنوز رباتی اضافه نشده"
    );

    const list = document.getElementById("botsList");
    const btnUp = document.getElementById("btnBotsUp");
    renderBotsBreadcrumb(folders, state.openBotFolderId);
    if (btnUp) btnUp.hidden = !inFolder;
    if (!list) return;

    const visibleBots = sortExplorerPinned(
      bots.filter((b) => {
      const fid = b.folder_id ?? null;
      if (inFolder) return Number(fid) === Number(state.openBotFolderId);
      if (fid == null || fid === "") return true;
      const folderExists = folders.some((f) => Number(f.id) === Number(fid));
      return !folderExists;
    })
    );

    const visibleFolders = getChildFolders(folders, inFolder ? state.openBotFolderId : null);

    if (!bots.length && !folders.length) {
      list.innerHTML = `
        <div class="explorer-empty">
          <i class="fa-solid fa-robot"></i>
          <p>هنوز رباتی اضافه نشده</p>
          <span>روی «افزودن ربات» بزنید؛ توکن و پوشه کانال را وارد کنید</span>
        </div>`;
      return;
    }

    if (!visibleBots.length && !visibleFolders.length) {
      const folderHints = !inFolder ? foldersWithAssignedBots(folders, bots) : [];
      list.innerHTML = `
        <div class="explorer-empty">
          <i class="fa-solid fa-robot"></i>
          <p>${inFolder ? "این پوشه خالی است" : total > 0 ? `${total} ربات داخل پوشه‌هاست` : "هنوز رباتی اضافه نشده"}</p>
          <span>${
            inFolder
              ? "ربات‌ها را بکشید و اینجا رها کنید"
              : folderHints.length
                ? `پوشه «${escapeHtml(folderHints[0].name)}» را باز کنید${folderHints.length > 1 ? " (یا پوشه‌های دیگر)" : ""}`
                : total > 0
                  ? "پوشه‌های بالا را باز کنید تا ربات‌ها را ببینید"
                  : "روی «افزودن ربات» بزنید"
          }</span>
        </div>`;
      return;
    }

    const folderTiles = visibleFolders
      .map((folder) => {
        const icon = folder.icon || "folder";
        return `
        <div class="explorer-tile explorer-tile--folder explorer-tile--bot-folder${explorerPinnedClass(folder.is_pinned)}" draggable="true" data-bot-folder-id="${folder.id}" data-drag-bot-folder="${folder.id}" data-drop-bot-folder="${folder.id}" tabindex="0">
          ${explorerPinBadge(folder.is_pinned)}
          <button type="button" class="explorer-tile__move" data-move-bot-folder="${folder.id}" aria-label="انتقال پوشه">
            <i class="fa-solid fa-folder-tree"></i>
          </button>
          <span class="explorer-tile__icon explorer-tile__icon--folder"><i class="fa-solid fa-${escapeHtml(icon)}"></i></span>
          <span class="explorer-tile__name">${escapeHtml(folder.name)}</span>
          <span class="explorer-tile__meta">${escapeHtml(formatBotFolderMeta(folder))}</span>
        </div>`;
      })
      .join("");

    const botTiles = visibleBots
      .map((bot) => {
        const title = bot.bot_name || (bot.bot_username ? `@${bot.bot_username}` : "ربات");
        const initial = title.replace(/^@/, "").charAt(0).toUpperCase() || "B";
        const typeLabel = getBotTypeLabel(bot);
        const channelFolderLabel = bot.channel_folder_name ? ` · ${bot.channel_folder_name}` : "";
        const uploadMeta = bot.bot_type === "uploader" && bot.uploads_count != null
          ? ` · ${formatNumber(bot.uploads_count)} آپلود`
          : "";
        const meta = `${typeLabel}${channelFolderLabel}${uploadMeta}`;
        const banned = isBotBanned(bot);

        return `
        <div class="explorer-tile explorer-tile--bot${banned ? " explorer-tile--bot-banned" : ""}${explorerPinnedClass(bot.is_pinned)}" draggable="true" data-bot-id="${bot.id}" data-drag-bot="${bot.id}">
          ${explorerPinBadge(bot.is_pinned)}
          <button type="button" class="explorer-tile__move" data-move-bot="${bot.id}" aria-label="انتقال به پوشه">
            <i class="fa-solid fa-folder-plus"></i>
          </button>
          ${buildBotIconHtml({ initial, photoUrl: bot.photo_url, banned, size: "tile" })}
          <span class="explorer-tile__name">${escapeHtml(title)}</span>
          <span class="explorer-tile__meta">${escapeHtml(meta)}</span>
        </div>`;
      })
      .join("");

    list.innerHTML = folderTiles + botTiles;
    bindBotExplorerInteractions(list, folders);
    bindBotIconPhotoFallbacks(list);
  }

  function renderContentGroups(data) {
    const groups = data.groups || [];
    const folders = data.folders || [];
    const total = data.total ?? groups.length;

    state.cache = state.cache || {};
    state.cache.contentGroups = data;

    const inFolder = state.openContentGroupFolderId != null;
    setText(
      "contentGroupsCount",
      inFolder
        ? folders.find((f) => f.id === state.openContentGroupFolderId)?.name || "پوشه"
        : total > 0
          ? `${total} گروه`
          : "هنوز گروهی شناسایی نشده"
    );

    const list = document.getElementById("contentGroupsList");
    const breadcrumb = document.getElementById("contentGroupsBreadcrumb");
    const btnUp = document.getElementById("btnContentGroupsUp");
    if (breadcrumb) {
      breadcrumb.textContent = inFolder
        ? `صفحه اصلی / ${folders.find((f) => f.id === state.openContentGroupFolderId)?.name || "پوشه"}`
        : "صفحه اصلی";
    }
    if (btnUp) btnUp.hidden = !inFolder;
    if (!list) return;

    const visibleGroups = sortExplorerPinned(
      groups.filter((g) => {
      const fid = g.folder_id ?? null;
      if (inFolder) return Number(fid) === Number(state.openContentGroupFolderId);
      if (fid == null || fid === "") return true;
      const folderExists = folders.some((f) => Number(f.id) === Number(fid));
      return !folderExists;
    })
    );

    const visibleFolders = sortExplorerPinned(inFolder ? [] : folders);

    if (!groups.length && !folders.length) {
      list.innerHTML = `
        <div class="explorer-empty">
          <i class="fa-solid fa-users-rectangle"></i>
          <p>هنوز گروه محتوایی شناسایی نشده</p>
          <span>ربات manage را در گروه/سوپرگروه ادمین کنید · فقط گروه‌های با بیو <strong>01</strong></span>
        </div>`;
      return;
    }

    if (!visibleGroups.length && !visibleFolders.length) {
      list.innerHTML = `
        <div class="explorer-empty">
          <i class="fa-solid fa-users-rectangle"></i>
          <p>${inFolder ? "این پوشه خالی است" : "گروه‌ها در پوشه‌ها هستند"}</p>
          <span>${inFolder ? "گروه‌ها را بکشید و اینجا رها کنید" : "پوشه‌ها را در صفحه اصلی باز کنید"}</span>
        </div>`;
      return;
    }

    const folderTiles = visibleFolders
      .map((folder) => {
        const icon = folder.icon || "folder";
        return `
        <div class="explorer-tile explorer-tile--folder explorer-tile--content-group-folder${explorerPinnedClass(folder.is_pinned)}" data-content-group-folder-id="${folder.id}" data-drop-content-group-folder="${folder.id}" tabindex="0">
          ${explorerPinBadge(folder.is_pinned)}
          <span class="explorer-tile__icon explorer-tile__icon--folder"><i class="fa-solid fa-${escapeHtml(icon)}"></i></span>
          <span class="explorer-tile__name">${escapeHtml(folder.name)}</span>
          <span class="explorer-tile__meta">${formatNumber(folder.group_count ?? 0)} گروه</span>
        </div>`;
      })
      .join("");

    const groupTiles = visibleGroups
      .map((group) => {
        const title = group.title || "گروه";
        const initial = title.charAt(0).toUpperCase();
        const banned = isChannelBanned(group);
        const members = group.member_count != null ? formatNumber(group.member_count) : "—";
        const privacy = group.is_private ? "خصوصی" : group.username ? `@${group.username}` : "عمومی";
        const meta = `${privacy} · ${members} عضو`;

        return `
        <div class="explorer-tile explorer-tile--group${banned ? " explorer-tile--group-banned" : ""}${explorerPinnedClass(group.is_pinned)}" draggable="true" data-content-group-id="${group.chat_id}" data-drag-content-group="${group.chat_id}">
          ${explorerPinBadge(group.is_pinned)}
          <button type="button" class="explorer-tile__move" data-move-content-group="${group.chat_id}" aria-label="انتقال به پوشه">
            <i class="fa-solid fa-folder-plus"></i>
          </button>
          ${buildChannelIconHtml({ initial, photoUrl: group.photo_url, banned }).replaceAll("explorer-tile__icon--channel", "explorer-tile__icon--group")}
          <span class="explorer-tile__name">${escapeHtml(title)}</span>
          <span class="explorer-tile__meta">${escapeHtml(meta)}</span>
        </div>`;
      })
      .join("");

    list.innerHTML = folderTiles + groupTiles;
    bindContentGroupExplorerInteractions(list, folders);
  }

  async function contentGroupFolderApi(body) {
    return api("content_group_folders.php", { method: "POST", body });
  }

  async function reloadContentGroups() {
    const data = await api("content_groups.php");
    renderContentGroups(data);
    return data;
  }

  function closeContentGroupFolderContextMenu() {
    const menu = document.getElementById("contentGroupFolderContextMenu");
    const panel = document.getElementById("contentGroupFolderContextPanel");
    if (menu) menu.hidden = true;
    if (panel) {
      panel.innerHTML = "";
      panel.removeAttribute("style");
    }
    state.activeContentGroupContextFolderId = null;
    document.querySelectorAll(".explorer-tile--content-group-folder.is-context-active").forEach((el) => {
      el.classList.remove("is-context-active");
    });
  }

  function showContentGroupFolderContextMenu(folderId, clientX, clientY, folders, tileEl) {
    const folder = (folders || []).find((f) => Number(f.id) === Number(folderId));
    if (!folder) return;

    const menu = ensureContentGroupFolderContextMenuRoot();
    const panel = document.getElementById("contentGroupFolderContextPanel");
    if (!menu || !panel) return;

    closeContentGroupFolderContextMenu();
    state.activeContentGroupContextFolderId = folderId;
    state.contentGroupFolderOpenSuppressUntil = Date.now() + 700;
    state.contentGroupFolderContextBlockClick = true;
    window.setTimeout(() => {
      state.contentGroupFolderContextBlockClick = false;
    }, 700);

    const tile = tileEl || document.querySelector(`[data-content-group-folder-id="${folderId}"]`);
    tile?.classList.add("is-context-active");

    const icon = folder.icon || "folder";
    const preview = buildContextMenuPreview({
      icon,
      iconTone: "folder",
      name: folder.name || "پوشه",
      meta: `${formatNumber(folder.group_count ?? 0)} گروه`,
    });

    panel.innerHTML = `${preview}
      <button type="button" class="context-menu__item" data-cg-folder-ctx="rename">
        <i class="fa-solid fa-pen"></i>
        <span>تغییر نام پوشه</span>
      </button>
      <button type="button" class="context-menu__item" data-cg-folder-ctx="pin">
        <i class="fa-solid fa-thumbtack"></i>
        <span>${pinMenuLabel(folder.is_pinned)}</span>
      </button>
      <div class="context-menu__sep" role="separator"></div>
      <button type="button" class="context-menu__item context-menu__item--danger" data-cg-folder-ctx="delete">
        <i class="fa-solid fa-trash-can"></i>
        <span>حذف پوشه</span>
      </button>`;

    menu.hidden = false;
    placeFolderContextMenu(panel, tile, clientX, clientY);

    menu.addEventListener(
      "click",
      (e) => {
        if (e.target.matches("[data-content-group-folder-context-close]")) closeContentGroupFolderContextMenu();
      },
      { once: true }
    );

    panel.querySelectorAll("[data-cg-folder-ctx]").forEach((btn) => {
      btn.addEventListener("click", async (e) => {
        e.stopPropagation();
        const action = btn.getAttribute("data-cg-folder-ctx");
        closeContentGroupFolderContextMenu();
        if (action === "rename") openContentGroupFolderModal({ mode: "edit", folder });
        else if (action === "pin") {
          await contentGroupFolderApi({ action: "pin", folder_id: folderId, pinned: !folder.is_pinned });
          await reloadContentGroups();
          showToast(folder.is_pinned ? "پین برداشته شد" : "پین شد", { type: "success" });
        } else if (action === "delete") {
          try {
            await contentGroupFolderApi({ action: "delete", folder_id: folderId });
            await reloadContentGroups();
            showToast("پوشه حذف شد", { type: "success" });
          } catch (err) {
            showToast("حذف پوشه ناموفق بود", { type: "error" });
          }
        }
      });
    });

    tg?.HapticFeedback?.impactOccurred("medium");
  }

  function bindContentGroupFolderLongPress(el, folderId, folders) {
    if (el.dataset.contentGroupFolderMenuBound === "1") return;
    el.dataset.contentGroupFolderMenuBound = "1";

    const LONG_MS = 480;
    const MOVE_PX = 14;
    let timer = null;
    let startX = 0;
    let startY = 0;
    let longFired = false;

    const clear = () => {
      if (timer) {
        window.clearTimeout(timer);
        timer = null;
      }
    };

    const openAt = (x, y) => {
      longFired = true;
      showContentGroupFolderContextMenu(folderId, x, y, folders, el);
    };

    el.addEventListener("contextmenu", (e) => {
      e.preventDefault();
      e.stopPropagation();
      clear();
      openAt(e.clientX, e.clientY);
    });

    el.addEventListener(
      "pointerdown",
      (e) => {
        if (e.pointerType === "mouse" && e.button === 2) {
          e.preventDefault();
          clear();
          openAt(e.clientX, e.clientY);
          return;
        }
        if (e.pointerType === "mouse" && e.button !== 0) return;

        longFired = false;
        startX = e.clientX;
        startY = e.clientY;
        clear();
        timer = window.setTimeout(() => openAt(e.clientX, e.clientY), LONG_MS);
      },
      { passive: false }
    );

    el.addEventListener(
      "pointermove",
      (e) => {
        if (!timer) return;
        const dx = Math.abs(e.clientX - startX);
        const dy = Math.abs(e.clientY - startY);
        if (dx > MOVE_PX || dy > MOVE_PX) clear();
      },
      { passive: true }
    );

    el.addEventListener("pointerup", clear);
    el.addEventListener("pointercancel", clear);
    el.addEventListener("pointerleave", (e) => {
      if (e.pointerType === "mouse") clear();
    });

    el.addEventListener(
      "click",
      (e) => {
        if (
          longFired ||
          state.contentGroupFolderContextBlockClick ||
          Date.now() < state.contentGroupFolderOpenSuppressUntil
        ) {
          e.preventDefault();
          e.stopImmediatePropagation();
          longFired = false;
        }
      },
      true
    );
  }

  function closeContentGroupContextMenu() {
    const menu = document.getElementById("contentGroupContextMenu");
    const panel = document.getElementById("contentGroupContextPanel");
    if (menu) menu.hidden = true;
    if (panel) {
      panel.innerHTML = "";
      panel.removeAttribute("style");
    }
    state.activeContextContentGroupId = null;
    document.querySelectorAll(".explorer-tile--group.is-context-active").forEach((el) => {
      el.classList.remove("is-context-active");
    });
  }

  function showContentGroupContextMenu(chatId, clientX, clientY, groups, tileEl) {
    const group = (groups || []).find((g) => Number(g.chat_id) === Number(chatId));
    if (!group) return;

    const menu = ensureContentGroupContextMenuRoot();
    const panel = document.getElementById("contentGroupContextPanel");
    if (!menu || !panel) return;

    closeContentGroupContextMenu();
    state.activeContextContentGroupId = chatId;
    state.contentGroupOpenSuppressUntil = Date.now() + 700;
    state.contentGroupContextBlockClick = true;
    window.setTimeout(() => {
      state.contentGroupContextBlockClick = false;
    }, 700);

    const tile = tileEl || document.querySelector(`[data-content-group-id="${chatId}"]`);
    tile?.classList.add("is-context-active");

    const title = group.title || "گروه";
    const initial = title.charAt(0).toUpperCase();
    const banned = isChannelBanned(group);
    const privacy = group.is_private ? "خصوصی" : group.username ? `@${group.username}` : "عمومی";
    const preview = buildContextMenuPreview({
      iconLetter: initial,
      iconTone: "folder",
      name: title,
      meta: privacy,
      banned,
      photoUrl: group.photo_url,
    });

    panel.innerHTML = `${preview}
      <button type="button" class="context-menu__item" data-cg-ctx="details">
        <i class="fa-solid fa-circle-info"></i>
        <span>جزئیات</span>
      </button>
      <button type="button" class="context-menu__item" data-cg-ctx="pin">
        <i class="fa-solid fa-thumbtack"></i>
        <span>${pinMenuLabel(group.is_pinned)}</span>
      </button>
      <button type="button" class="context-menu__item" data-cg-ctx="move">
        <i class="fa-solid fa-folder-tree"></i>
        <span>انتقال به پوشه</span>
      </button>`;

    menu.hidden = false;
    placeFolderContextMenu(panel, tile, clientX, clientY);
    bindBotIconPhotoFallbacks(panel);

    menu.addEventListener(
      "click",
      (e) => {
        if (e.target.matches("[data-content-group-context-close]")) closeContentGroupContextMenu();
      },
      { once: true }
    );

    panel.querySelectorAll("[data-cg-ctx]").forEach((btn) => {
      btn.addEventListener("click", async (e) => {
        e.stopPropagation();
        const action = btn.getAttribute("data-cg-ctx");
        closeContentGroupContextMenu();
        const folders = state.cache?.contentGroups?.folders || [];
        if (action === "pin") {
          await contentGroupFolderApi({ action: "pin", entity: "group", chat_id: chatId, pinned: !group.is_pinned });
          await reloadContentGroups();
          showToast(group.is_pinned ? "پین برداشته شد" : "پین شد", { type: "success" });
        } else if (action === "details") {
          openContentGroupDetailsModal(chatId);
        } else if (action === "move") showMoveContentGroupToFolderPicker(chatId, folders);
      });
    });

    tg?.HapticFeedback?.impactOccurred("medium");
  }

  function bindContentGroupLongPress(el, chatId, groups) {
    if (el.dataset.contentGroupMenuBound === "1") return;
    el.dataset.contentGroupMenuBound = "1";

    const LONG_MS = 480;
    const MOVE_PX = 14;
    let timer = null;
    let startX = 0;
    let startY = 0;
    let longFired = false;

    const clear = () => {
      if (timer) {
        window.clearTimeout(timer);
        timer = null;
      }
    };

    const openAt = (x, y) => {
      longFired = true;
      showContentGroupContextMenu(chatId, x, y, groups, el);
    };

    el.addEventListener("contextmenu", (e) => {
      e.preventDefault();
      e.stopPropagation();
      clear();
      openAt(e.clientX, e.clientY);
    });

    el.addEventListener(
      "pointerdown",
      (e) => {
        if (e.pointerType === "mouse" && e.button === 2) {
          e.preventDefault();
          clear();
          openAt(e.clientX, e.clientY);
          return;
        }
        if (e.pointerType === "mouse" && e.button !== 0) return;

        longFired = false;
        startX = e.clientX;
        startY = e.clientY;
        clear();
        timer = window.setTimeout(() => openAt(e.clientX, e.clientY), LONG_MS);
      },
      { passive: false }
    );

    el.addEventListener(
      "pointermove",
      (e) => {
        if (!timer) return;
        const dx = Math.abs(e.clientX - startX);
        const dy = Math.abs(e.clientY - startY);
        if (dx > MOVE_PX || dy > MOVE_PX) clear();
      },
      { passive: true }
    );

    el.addEventListener("pointerup", clear);
    el.addEventListener("pointercancel", clear);
    el.addEventListener("pointerleave", (e) => {
      if (e.pointerType === "mouse") clear();
    });

    el.addEventListener(
      "click",
      (e) => {
        if (longFired || state.contentGroupContextBlockClick || Date.now() < state.contentGroupOpenSuppressUntil) {
          e.preventDefault();
          e.stopImmediatePropagation();
          longFired = false;
        }
      },
      true
    );
  }

  function bindContentGroupExplorerInteractions(list, folders) {
    list.querySelectorAll("[data-content-group-folder-id]").forEach((el) => {
      const id = Number(el.dataset.contentGroupFolderId);
      bindContentGroupFolderLongPress(el, id, folders);
      el.addEventListener("click", (e) => {
        if (
          state.contentGroupFolderContextBlockClick ||
          state.activeContentGroupContextFolderId != null ||
          Date.now() < state.contentGroupFolderOpenSuppressUntil
        ) {
          return;
        }
        state.openContentGroupFolderId = id;
        renderContentGroups(state.cache.contentGroups);
        tg?.HapticFeedback?.selectionChanged();
      });
    });

    list.querySelectorAll("[data-content-group-id]").forEach((el) => {
      const id = Number(el.dataset.contentGroupId);
      bindContentGroupLongPress(el, id, state.cache?.contentGroups?.groups || []);
    });

    list.querySelectorAll("[data-move-content-group]").forEach((btn) => {
      btn.addEventListener("click", async (e) => {
        e.stopPropagation();
        const chatId = Number(btn.dataset.moveContentGroup);
        showMoveContentGroupToFolderPicker(chatId, folders);
      });
    });

    list.querySelectorAll("[data-drag-content-group]").forEach((tile) => {
      tile.addEventListener("dragstart", (e) => {
        tile.classList.add("is-dragging");
        e.dataTransfer?.setData("text/plain", tile.dataset.dragContentGroup);
        e.dataTransfer.effectAllowed = "move";
      });
      tile.addEventListener("dragend", () => tile.classList.remove("is-dragging"));
    });

    list.querySelectorAll("[data-drop-content-group-folder]").forEach((zone) => {
      zone.addEventListener("dragover", (e) => {
        e.preventDefault();
        zone.classList.add("is-drop-target");
      });
      zone.addEventListener("dragleave", () => zone.classList.remove("is-drop-target"));
      zone.addEventListener("drop", async (e) => {
        e.preventDefault();
        zone.classList.remove("is-drop-target");
        const chatId = Number(e.dataTransfer?.getData("text/plain"));
        const folderId = Number(zone.dataset.dropContentGroupFolder);
        if (!chatId || !folderId) return;
        try {
          await contentGroupFolderApi({ action: "assign", chat_id: chatId, folder_id: folderId });
          await reloadContentGroups();
          showToast("گروه منتقل شد", { type: "success" });
          tg?.HapticFeedback?.notificationOccurred("success");
        } catch (err) {
          showToast("انتقال ناموفق بود", { type: "error" });
        }
      });
    });
  }

  function showMoveContentGroupToFolderPicker(chatId, folders) {
    if (!folders.length) {
      showToast("اول یک پوشه بسازید", { type: "warning" });
      return;
    }
    openActionSheet(
      "انتقال به پوشه",
      [
        ...folders.map((f) => ({
          label: f.name,
          icon: `fa-${f.icon || "folder"}`,
          action: async () => {
            await contentGroupFolderApi({ action: "assign", chat_id: chatId, folder_id: f.id });
            await reloadContentGroups();
            showToast("گروه منتقل شد", { type: "success" });
          },
        })),
        {
          label: "صفحه اصلی (بدون پوشه)",
          icon: "fa-house",
          action: async () => {
            await contentGroupFolderApi({ action: "assign", chat_id: chatId, folder_id: null });
            await reloadContentGroups();
            showToast("گروه به صفحه اصلی منتقل شد", { type: "success" });
          },
        },
      ]
    );
  }

  function openContentGroupFolderModal(options = {}) {
    const mode = options.mode || "create";
    const folder = options.folder || null;
    const modal = document.getElementById("contentGroupFolderModal");
    const input = document.getElementById("contentGroupFolderNameInput");
    const picker = document.getElementById("contentGroupFolderIconPicker");
    const title = document.getElementById("contentGroupFolderModalTitle");
    const saveBtn = document.getElementById("btnSaveContentGroupFolder");
    if (!modal || !picker) return;

    state.contentGroupFolderModalMode = mode;
    state.editingContentGroupFolderId = mode === "edit" && folder ? Number(folder.id) : null;
    const icon = folder?.icon || "folder";
    state.selectedContentGroupFolderIcon = icon;
    if (input) input.value = folder?.name || "";
    if (title) title.textContent = mode === "edit" ? "تغییر نام پوشه" : "پوشه جدید";
    if (saveBtn) saveBtn.textContent = mode === "edit" ? "ذخیره تغییرات" : "ذخیره";

    picker.innerHTML = FOLDER_ICONS.map(
      (name) =>
        `<button type="button" class="icon-picker__btn${name === icon ? " is-active" : ""}" data-content-group-folder-icon="${name}" aria-label="${name}"><i class="fa-solid fa-${name}"></i></button>`
    ).join("");
    picker.querySelectorAll("[data-content-group-folder-icon]").forEach((btn) => {
      btn.addEventListener("click", () => {
        state.selectedContentGroupFolderIcon = btn.dataset.contentGroupFolderIcon;
        picker.querySelectorAll(".icon-picker__btn").forEach((b) => b.classList.remove("is-active"));
        btn.classList.add("is-active");
      });
    });

    modal.hidden = false;
    input?.focus();
  }

  function closeContentGroupFolderModal() {
    const modal = document.getElementById("contentGroupFolderModal");
    if (modal) modal.hidden = true;
  }

  function renderContentGroupDetailsIcon(group) {
    const iconEl = document.getElementById("contentGroupDetailsIcon");
    if (!iconEl) return;

    const title = group.title || "گروه";
    const initial = title.charAt(0).toUpperCase();
    const banned = isChannelBanned(group);
    const photoUrl = group.photo_url || null;

    iconEl.innerHTML = buildChannelIconHtml({ initial, photoUrl, banned });
    bindBotIconPhotoFallbacks(iconEl);
  }

  function fillContentGroupDetailsModal(group) {
    const title = group.title || "گروه";
    const privacy = group.is_private
      ? "خصوصی"
      : group.username
        ? `@${group.username}`
        : "عمومی";

    setText("contentGroupDetailsName", title);
    setText("contentGroupDetailsMeta", privacy);
    setText("contentGroupDetailsMessages", formatNumber(group.message_count ?? 0));
    setText("contentGroupDetailsPhotos", formatNumber(group.photo_count ?? 0));
    setText("contentGroupDetailsVideos", formatNumber(group.video_count ?? 0));
    setText("contentGroupDetailsMembers", formatNumber(group.member_count ?? 0));
    setText(
      "contentGroupDetailsLastMessage",
      group.last_message_at ? formatDate(group.last_message_at) : "—"
    );
    setText("contentGroupDetailsAdded", group.added_at ? formatDate(group.added_at) : "—");
    renderContentGroupDetailsIcon(group);
  }

  async function openContentGroupDetailsModal(chatId) {
    const modal = document.getElementById("contentGroupDetailsModal");
    if (!modal) return;

    const cached = (state.cache?.contentGroups?.groups || []).find(
      (g) => Number(g.chat_id) === Number(chatId)
    );

    if (cached) {
      fillContentGroupDetailsModal(cached);
    } else {
      setText("contentGroupDetailsName", "در حال بارگذاری...");
      setText("contentGroupDetailsMeta", "—");
      setText("contentGroupDetailsMessages", "…");
      setText("contentGroupDetailsPhotos", "…");
      setText("contentGroupDetailsVideos", "…");
      setText("contentGroupDetailsMembers", "…");
      setText("contentGroupDetailsLastMessage", "…");
      setText("contentGroupDetailsAdded", "…");
    }

    modal.hidden = false;
    tg?.HapticFeedback?.selectionChanged();

    try {
      const data = await api(`content_group_details.php?chat_id=${encodeURIComponent(chatId)}`);
      if (!data?.group) {
        showToast("گروه یافت نشد", { type: "error" });
        closeContentGroupDetailsModal();
        return;
      }
      fillContentGroupDetailsModal(data.group);
    } catch (err) {
      if (!cached) {
        showToast("بارگذاری جزئیات ناموفق بود", { type: "error" });
        closeContentGroupDetailsModal();
      } else {
        showToast("آمار به‌روز نشد", { type: "warning" });
      }
    }
  }

  function closeContentGroupDetailsModal() {
    const modal = document.getElementById("contentGroupDetailsModal");
    if (modal) modal.hidden = true;
  }

  function initContentGroupExplorerUi() {
    document.getElementById("btnNewContentGroupFolder")?.addEventListener("click", () =>
      openContentGroupFolderModal({ mode: "create" })
    );
    document.querySelectorAll("[data-close-content-group-folder-modal]").forEach((el) => {
      el.addEventListener("click", closeContentGroupFolderModal);
    });
    document.querySelectorAll("[data-close-content-group-details]").forEach((el) => {
      el.addEventListener("click", closeContentGroupDetailsModal);
    });
    document.getElementById("btnContentGroupsUp")?.addEventListener("click", () => {
      state.openContentGroupFolderId = null;
      renderContentGroups(state.cache?.contentGroups || { groups: [], folders: [] });
    });
    document.getElementById("btnSaveContentGroupFolder")?.addEventListener("click", async () => {
      const name = document.getElementById("contentGroupFolderNameInput")?.value?.trim() || "";
      if (!name) {
        showToast("نام پوشه را وارد کنید", { type: "warning" });
        return;
      }
      try {
        if (state.contentGroupFolderModalMode === "edit" && state.editingContentGroupFolderId) {
          await contentGroupFolderApi({
            action: "update",
            folder_id: state.editingContentGroupFolderId,
            name,
            icon: state.selectedContentGroupFolderIcon,
          });
          closeContentGroupFolderModal();
          await reloadContentGroups();
          showToast("پوشه به‌روز شد", { type: "success" });
        } else {
          await contentGroupFolderApi({
            action: "create",
            name,
            icon: state.selectedContentGroupFolderIcon,
          });
          closeContentGroupFolderModal();
          await reloadContentGroups();
          showToast("پوشه ساخته شد", { type: "success" });
        }
      } catch (err) {
        showToast("ذخیره پوشه ناموفق بود", { type: "error" });
      }
    });
  }

  function normalizeAutoPostRootName(name) {
    return String(name || "")
      .replace(/\s+/g, "")
      .toLowerCase();
  }

  function isAutoPostAllowedChannelRoot(name) {
    const n = normalizeAutoPostRootName(name);
    if (!n || n.includes("تبلیغ")) return false;
    if (n.includes("غیر") && n.includes("اخلاق")) return true;
    if (n.includes("اخلاق")) return true;
    return false;
  }

  function getAutoPostAllowedChannelFolders(folders, allowedIds) {
    const all = folders || [];
    const allowedSet = new Set((allowedIds || []).map((id) => Number(id)));
    if (allowedSet.size > 0) {
      return all.filter((f) => allowedSet.has(Number(f.id)));
    }
    const roots = all.filter((f) => !f.parent_id && isAutoPostAllowedChannelRoot(f.name));
    const ids = new Set();
    function addTree(folderId) {
      ids.add(Number(folderId));
      all.filter((f) => Number(f.parent_id) === Number(folderId)).forEach((f) => addTree(f.id));
    }
    roots.forEach((r) => addTree(r.id));
    return all.filter((f) => ids.has(Number(f.id)));
  }

  async function autoPostApi(body) {
    return api("auto_post.php", body ? { method: "POST", body } : undefined);
  }

  async function reloadAutoPost() {
    const data = await autoPostApi();
    renderAutoPost(data);
    return data;
  }

  function renderAutoPost(data) {
    const folders = data?.folders || [];
    const sessions = data?.sessions || [];
    const total = data?.total ?? sessions.length;

    state.cache = state.cache || {};
    state.cache.autoPost = data;

    const inFolder = state.openAutoPostFolderId != null;
    setText(
      "autoPostCount",
      inFolder
        ? folders.find((f) => f.id === state.openAutoPostFolderId)?.name || "پوشه"
        : total > 0
          ? `${total} سشن`
          : "هنوز سشنی ساخته نشده"
    );

    const list = document.getElementById("autoPostList");
    const breadcrumb = document.getElementById("autoPostBreadcrumb");
    const btnUp = document.getElementById("btnAutoPostUp");
    if (breadcrumb) {
      breadcrumb.textContent = inFolder
        ? `صفحه اصلی / ${folders.find((f) => f.id === state.openAutoPostFolderId)?.name || "پوشه"}`
        : "صفحه اصلی";
    }
    if (btnUp) btnUp.hidden = !inFolder;
    if (!list) return;

    const visibleSessions = sortExplorerPinned(
      sessions.filter((s) => {
        const fid = s.folder_id ?? null;
        if (inFolder) return Number(fid) === Number(state.openAutoPostFolderId);
        if (fid == null || fid === "") return true;
        return !folders.some((f) => Number(f.id) === Number(fid));
      })
    );

    const visibleFolders = sortExplorerPinned(getChildFolders(folders, inFolder ? state.openAutoPostFolderId : null));

    if (!folders.length && !sessions.length) {
      list.innerHTML = `
        <div class="explorer-empty">
          <i class="fa-solid fa-calendar-check"></i>
          <p>هنوز سشن پستی ساخته نشده</p>
          <span>سشن پست را بزنید و پوشه کانال + ربات را انتخاب کنید</span>
        </div>`;
      return;
    }

    if (!visibleFolders.length && !visibleSessions.length) {
      list.innerHTML = `
        <div class="explorer-empty">
          <i class="fa-solid fa-layer-group"></i>
          <p>${inFolder ? "این پوشه خالی است" : "سشن‌ها در پوشه‌ها هستند"}</p>
        </div>`;
      return;
    }

    const folderTiles = visibleFolders
      .map((folder) => {
        const icon = folder.icon || "folder";
        const meta = `${formatNumber(folder.session_count ?? 0)} سشن · ${formatNumber(folder.subfolder_count ?? 0)} زیرپوشه`;
        return `
        <div class="explorer-tile explorer-tile--folder explorer-tile--auto-post-folder${explorerPinnedClass(folder.is_pinned)}" data-auto-post-folder-id="${folder.id}" tabindex="0">
          ${explorerPinBadge(folder.is_pinned)}
          <span class="explorer-tile__icon explorer-tile__icon--folder"><i class="fa-solid fa-${escapeHtml(icon)}"></i></span>
          <span class="explorer-tile__name">${escapeHtml(folder.name)}</span>
          <span class="explorer-tile__meta">${escapeHtml(meta)}</span>
        </div>`;
      })
      .join("");

    const sessionTiles = visibleSessions
      .map((session) => {
        const meta = `${session.channel_folder_path || "—"} · ${session.bot_folder_path || session.bot_channel_folder_path || "—"}`;
        return `
        <div class="explorer-tile explorer-tile--session${explorerPinnedClass(session.is_pinned)}" data-post-session-id="${session.id}" tabindex="0">
          ${explorerPinBadge(session.is_pinned)}
          <span class="explorer-tile__icon explorer-tile__icon--session"><i class="fa-solid fa-layer-group"></i></span>
          <span class="explorer-tile__name">${escapeHtml(session.name || "سشن")}</span>
          <span class="explorer-tile__meta">${escapeHtml(meta)}</span>
        </div>`;
      })
      .join("");

    list.innerHTML = folderTiles + sessionTiles;
    bindAutoPostExplorerInteractions(list, folders);
  }

  function bindAutoPostExplorerInteractions(list, folders) {
    list.querySelectorAll("[data-auto-post-folder-id]").forEach((el) => {
      const id = Number(el.dataset.autoPostFolderId);
      bindAutoPostFolderLongPress(el, id, folders);
      el.addEventListener("click", () => {
        if (
          state.autoPostFolderContextBlockClick ||
          state.activeAutoPostContextFolderId != null ||
          Date.now() < state.autoPostFolderOpenSuppressUntil
        ) {
          return;
        }
        state.openAutoPostFolderId = id;
        renderAutoPost(state.cache?.autoPost || { folders: [], sessions: [] });
        tg?.HapticFeedback?.selectionChanged();
      });
    });

    list.querySelectorAll("[data-post-session-id]").forEach((el) => {
      const id = Number(el.dataset.postSessionId);
      bindPostSessionLongPress(el, id);
      el.addEventListener("click", () => {
        if (
          state.postSessionContextBlockClick ||
          state.activePostSessionContextId != null ||
          Date.now() < state.postSessionOpenSuppressUntil
        ) {
          return;
        }
        openPostSessionDetail(id);
      });
    });
  }

  function bindAutoPostFolderLongPress(el, folderId, folders) {
    if (el.dataset.autoPostFolderMenuBound === "1") return;
    el.dataset.autoPostFolderMenuBound = "1";
    let timer = null;
    let longFired = false;
    const clear = () => {
      if (timer) clearTimeout(timer);
      timer = null;
    };
    const start = (clientX, clientY) => {
      clear();
      longFired = false;
      timer = setTimeout(() => {
        longFired = true;
        showAutoPostFolderContextMenu(folderId, clientX, clientY, folders, el);
      }, 520);
    };
    el.addEventListener("mousedown", (e) => {
      if (e.button !== 0) return;
      start(e.clientX, e.clientY);
    });
    el.addEventListener("mouseup", clear);
    el.addEventListener("mouseleave", clear);
    el.addEventListener("touchstart", (e) => {
      const t = e.touches[0];
      if (t) start(t.clientX, t.clientY);
    }, { passive: true });
    el.addEventListener("touchend", clear);
    el.addEventListener("touchcancel", clear);
    el.addEventListener("contextmenu", (e) => {
      e.preventDefault();
      showAutoPostFolderContextMenu(folderId, e.clientX, e.clientY, folders, el);
    });
    el.addEventListener("click", (e) => {
      if (longFired) e.stopPropagation();
    });
  }

  function bindPostSessionLongPress(el, sessionId) {
    if (el.dataset.postSessionMenuBound === "1") return;
    el.dataset.postSessionMenuBound = "1";
    let timer = null;
    let longFired = false;
    const clear = () => {
      if (timer) clearTimeout(timer);
      timer = null;
    };
    const start = (clientX, clientY) => {
      clear();
      longFired = false;
      timer = setTimeout(() => {
        longFired = true;
        showPostSessionContextMenu(sessionId, clientX, clientY, el);
      }, 520);
    };
    el.addEventListener("mousedown", (e) => {
      if (e.button !== 0) return;
      start(e.clientX, e.clientY);
    });
    el.addEventListener("mouseup", clear);
    el.addEventListener("mouseleave", clear);
    el.addEventListener("touchstart", (e) => {
      const t = e.touches[0];
      if (t) start(t.clientX, t.clientY);
    }, { passive: true });
    el.addEventListener("touchend", clear);
    el.addEventListener("touchcancel", clear);
    el.addEventListener("contextmenu", (e) => {
      e.preventDefault();
      showPostSessionContextMenu(sessionId, e.clientX, e.clientY, el);
    });
    el.addEventListener("click", (e) => {
      if (longFired) e.stopPropagation();
    });
  }

  function closeAutoPostFolderContextMenu() {
    const menu = document.getElementById("autoPostFolderContextMenu");
    const panel = document.getElementById("autoPostFolderContextPanel");
    if (menu) menu.hidden = true;
    if (panel) {
      panel.innerHTML = "";
      panel.removeAttribute("style");
    }
    state.activeAutoPostContextFolderId = null;
    document.querySelectorAll(".explorer-tile--auto-post-folder.is-context-active").forEach((el) => {
      el.classList.remove("is-context-active");
    });
  }

  function showAutoPostFolderContextMenu(folderId, clientX, clientY, folders, tileEl) {
    const folder = (folders || []).find((f) => Number(f.id) === Number(folderId));
    if (!folder) return;
    const menu = ensureAutoPostFolderContextMenuRoot();
    const panel = document.getElementById("autoPostFolderContextPanel");
    if (!menu || !panel) return;

    closeAutoPostFolderContextMenu();
    state.activeAutoPostContextFolderId = folderId;
    state.autoPostFolderOpenSuppressUntil = Date.now() + 700;
    state.autoPostFolderContextBlockClick = true;
    window.setTimeout(() => {
      state.autoPostFolderContextBlockClick = false;
    }, 700);

    const tile = tileEl || document.querySelector(`[data-auto-post-folder-id="${folderId}"]`);
    tile?.classList.add("is-context-active");

    const icon = folder.icon || "folder";
    const createdMeta = folder.created_at ? ` · ${formatDate(folder.created_at)}` : "";
    const preview = buildContextMenuPreview({
      icon,
      iconTone: "folder",
      name: folder.name || "پوشه",
      meta: `${formatNumber(folder.session_count ?? 0)} سشن${createdMeta}`,
    });

    panel.innerHTML = `${preview}
      <button type="button" class="context-menu__item" data-auto-post-folder-ctx="rename">
        <i class="fa-solid fa-pen"></i>
        <span>تغییر نام</span>
      </button>
      <button type="button" class="context-menu__item" data-auto-post-folder-ctx="pin">
        <i class="fa-solid fa-thumbtack"></i>
        <span>${pinMenuLabel(folder.is_pinned)}</span>
      </button>
      <div class="context-menu__sep" role="separator"></div>
      <button type="button" class="context-menu__item context-menu__item--danger" data-auto-post-folder-ctx="delete">
        <i class="fa-solid fa-trash-can"></i>
        <span>حذف پوشه</span>
      </button>`;

    menu.hidden = false;
    placeFolderContextMenu(panel, tile, clientX, clientY);

    menu.addEventListener(
      "click",
      (e) => {
        if (e.target.matches("[data-auto-post-folder-context-close]")) closeAutoPostFolderContextMenu();
      },
      { once: true }
    );

    panel.querySelectorAll("[data-auto-post-folder-ctx]").forEach((btn) => {
      btn.addEventListener("click", async (e) => {
        e.stopPropagation();
        const action = btn.getAttribute("data-auto-post-folder-ctx");
        closeAutoPostFolderContextMenu();
        if (action === "rename") openAutoPostFolderModal({ mode: "edit", folder });
        else if (action === "pin") {
          try {
            await autoPostApi({ action: "pin_folder", folder_id: folderId, pinned: !folder.is_pinned });
            await reloadAutoPost();
            showToast(folder.is_pinned ? "پین برداشته شد" : "پین شد", { type: "success" });
          } catch (err) {
            showToast("پین ناموفق بود", { type: "error" });
          }
        } else if (action === "delete") {
          if (!confirm(`پوشه «${folder.name}» حذف شود؟`)) return;
          try {
            await autoPostApi({ action: "delete_folder", folder_id: folderId });
            if (Number(state.openAutoPostFolderId) === Number(folderId)) state.openAutoPostFolderId = null;
            await reloadAutoPost();
            showToast("پوشه حذف شد", { type: "success" });
          } catch (err) {
            showToast("حذف ناموفق بود", { type: "error" });
          }
        }
      });
    });

    tg?.HapticFeedback?.impactOccurred("medium");
  }

  function closePostSessionContextMenu() {
    const menu = document.getElementById("postSessionContextMenu");
    const panel = document.getElementById("postSessionContextPanel");
    if (menu) menu.hidden = true;
    if (panel) {
      panel.innerHTML = "";
      panel.removeAttribute("style");
    }
    state.activePostSessionContextId = null;
    document.querySelectorAll(".explorer-tile--session.is-context-active").forEach((el) => {
      el.classList.remove("is-context-active");
    });
  }

  function showPostSessionContextMenu(sessionId, clientX, clientY, tileEl) {
    const sessions = state.cache?.autoPost?.sessions || [];
    const session = sessions.find((s) => Number(s.id) === Number(sessionId));
    if (!session) return;
    const menu = ensurePostSessionContextMenuRoot();
    const panel = document.getElementById("postSessionContextPanel");
    if (!menu || !panel) return;

    closePostSessionContextMenu();
    state.activePostSessionContextId = sessionId;
    state.postSessionOpenSuppressUntil = Date.now() + 700;
    state.postSessionContextBlockClick = true;
    window.setTimeout(() => {
      state.postSessionContextBlockClick = false;
    }, 700);

    const tile = tileEl || document.querySelector(`[data-post-session-id="${sessionId}"]`);
    tile?.classList.add("is-context-active");

    const botPath = session.bot_folder_path || session.bot_channel_folder_path || "—";
    const preview = buildContextMenuPreview({
      icon: "layer-group",
      iconTone: "session",
      name: session.name || "سشن",
      meta: `${session.channel_folder_path || "—"} · ${botPath}`,
    });

    panel.innerHTML = `${preview}
      <button type="button" class="context-menu__item" data-post-session-ctx="rename">
        <i class="fa-solid fa-pen"></i>
        <span>تغییر نام</span>
      </button>
      <button type="button" class="context-menu__item" data-post-session-ctx="stats">
        <i class="fa-solid fa-chart-line"></i>
        <span>آمار سشن</span>
      </button>
      <button type="button" class="context-menu__item" data-post-session-ctx="pin">
        <i class="fa-solid fa-thumbtack"></i>
        <span>${pinMenuLabel(session.is_pinned)}</span>
      </button>
      <div class="context-menu__sep" role="separator"></div>
      <button type="button" class="context-menu__item context-menu__item--danger" data-post-session-ctx="delete">
        <i class="fa-solid fa-trash-can"></i>
        <span>حذف سشن</span>
      </button>`;

    menu.hidden = false;
    placeFolderContextMenu(panel, tile, clientX, clientY);

    menu.addEventListener(
      "click",
      (e) => {
        if (e.target.matches("[data-post-session-context-close]")) closePostSessionContextMenu();
      },
      { once: true }
    );

    panel.querySelectorAll("[data-post-session-ctx]").forEach((btn) => {
      btn.addEventListener("click", async (e) => {
        e.stopPropagation();
        const action = btn.getAttribute("data-post-session-ctx");
        closePostSessionContextMenu();
        if (action === "rename") openPostSessionRenameModal(session);
        else if (action === "stats") openPostSessionStatsModal(sessionId);
        else if (action === "pin") {
          try {
            await autoPostApi({ action: "pin_session", session_id: sessionId, pinned: !session.is_pinned });
            await reloadAutoPost();
            showToast(session.is_pinned ? "پین برداشته شد" : "پین شد", { type: "success" });
          } catch (err) {
            showToast("پین ناموفق بود", { type: "error" });
          }
        } else if (action === "delete") {
          if (!confirm(`سشن «${session.name}» حذف شود؟`)) return;
          try {
            await autoPostApi({ action: "delete_session", session_id: sessionId });
            await reloadAutoPost();
            showToast("سشن حذف شد", { type: "success" });
          } catch (err) {
            showToast("حذف ناموفق بود", { type: "error" });
          }
        }
      });
    });

    tg?.HapticFeedback?.impactOccurred("medium");
  }

  function openAutoPostFolderModal(options = {}) {
    const mode = options.mode || "create";
    const folder = options.folder || null;
    const modal = document.getElementById("autoPostFolderModal");
    const input = document.getElementById("autoPostFolderNameInput");
    const picker = document.getElementById("autoPostFolderIconPicker");
    const title = document.getElementById("autoPostFolderModalTitle");
    if (!modal || !picker) return;

    state.autoPostFolderModalMode = mode;
    state.editingAutoPostFolderId = mode === "edit" && folder ? Number(folder.id) : null;
    const icon = folder?.icon || "folder";
    state.selectedAutoPostFolderIcon = icon;
    if (input) input.value = folder?.name || "";
    if (title) title.textContent = mode === "edit" ? "تغییر نام پوشه" : "پوشه جدید";

    picker.innerHTML = FOLDER_ICONS.map(
      (iconName) => `
      <button type="button" class="icon-picker__btn ${iconName === icon ? "is-active" : ""}" data-auto-post-icon="${iconName}">
        <i class="fa-solid fa-${iconName}"></i>
      </button>`
    ).join("");
    picker.querySelectorAll("[data-auto-post-icon]").forEach((btn) => {
      btn.addEventListener("click", () => {
        state.selectedAutoPostFolderIcon = btn.dataset.autoPostIcon || "folder";
        picker.querySelectorAll(".is-active").forEach((el) => el.classList.remove("is-active"));
        btn.classList.add("is-active");
      });
    });

    modal.hidden = false;
    input?.focus();
  }

  function closeAutoPostFolderModal() {
    const modal = document.getElementById("autoPostFolderModal");
    if (modal) modal.hidden = true;
    state.autoPostFolderModalMode = "create";
    state.editingAutoPostFolderId = null;
  }

  function setPostSessionChannelFolderSelection(folderId, options = {}) {
    const hidden = document.getElementById("postSessionChannelFolderInput");
    const label = document.getElementById("postSessionChannelFolderLabel");
    const btn = document.getElementById("postSessionChannelFolderBtn");
    const folders = state.cache?.channels?.folders || [];
    const id = Number(folderId || 0);
    if (hidden) hidden.value = id > 0 ? String(id) : "";
    if (label) label.textContent = id > 0 ? getFolderDisplayPath(folders, id) : "— انتخاب پوشه کانال —";
    if (btn) btn.classList.toggle("is-selected", id > 0);
    if (!options.silent && id > 0) showToast("پوشه کانال انتخاب شد", { type: "success", duration: 1800 });
  }

  function setPostSessionBotFolderSelection(folderId, options = {}) {
    const hidden = document.getElementById("postSessionBotFolderInput");
    const label = document.getElementById("postSessionBotFolderLabel");
    const btn = document.getElementById("postSessionBotFolderBtn");
    const folders = state.cache?.bots?.folders || [];
    const id = Number(folderId || 0);
    if (hidden) hidden.value = id > 0 ? String(id) : "";
    if (label) label.textContent = id > 0 ? getFolderDisplayPath(folders, id) : "— انتخاب پوشه ربات —";
    if (btn) btn.classList.toggle("is-selected", id > 0);
    if (!options.silent && id > 0) showToast("پوشه ربات انتخاب شد", { type: "success", duration: 1800 });
  }

  function openPostSessionChannelFolderPicker() {
    const allFolders = state.cache?.channels?.folders || [];
    const allowedIds = state.cache?.autoPost?.allowed_channel_folder_ids || [];
    const folders = getAutoPostAllowedChannelFolders(allFolders, allowedIds);
    if (!folders.length) {
      showToast("پوشه اخلاقی / غیراخلاقی پیدا نشد", { type: "warning" });
      return;
    }
    openFolderTreePicker({
      mode: "post_session_channel",
      folders,
      allowRoot: false,
    });
  }

  function openPostSessionBotFolderPicker() {
    const folders = state.cache?.bots?.folders || [];
    if (!folders.length) {
      showToast("ابتدا در بخش ربات‌های من یک پوشه بسازید", { type: "warning" });
      return;
    }
    openFolderTreePicker({
      mode: "post_session_bot",
      folderKind: "bot",
      folders,
      allowRoot: false,
    });
  }

  async function openPostSessionModal() {
    if (!state.cache?.bots?.folders?.length) {
      try {
        await reloadBots();
      } catch (e) {
        /* ignore */
      }
    }
    const modal = document.getElementById("postSessionModal");
    const nameInput = document.getElementById("postSessionNameInput");
    if (nameInput) nameInput.value = "";
    setPostSessionChannelFolderSelection(0, { silent: true });
    setPostSessionBotFolderSelection(0, { silent: true });
    if (modal) modal.hidden = false;
    nameInput?.focus();
  }

  function closePostSessionModal() {
    const modal = document.getElementById("postSessionModal");
    if (modal) modal.hidden = true;
  }

  function openPostSessionRenameModal(session) {
    state.editingPostSessionId = Number(session?.id || 0) || null;
    const modal = document.getElementById("postSessionRenameModal");
    const input = document.getElementById("postSessionRenameInput");
    if (input) input.value = session?.name || "";
    if (modal) modal.hidden = false;
    input?.focus();
    input?.select?.();
  }

  function closePostSessionRenameModal() {
    const modal = document.getElementById("postSessionRenameModal");
    if (modal) modal.hidden = true;
    state.editingPostSessionId = null;
  }

  async function openPostSessionDetail(sessionId) {
    state.activePostSessionId = sessionId;
    const detail = document.getElementById("screenPostSessionDetail");
    const channelsScreen = screens.channels;

    channelsScreen?.classList.remove("is-active");
    detail?.removeAttribute("hidden");
    detail?.classList.add("is-active");
    bottomNav?.setAttribute("hidden", "hidden");

    setText("postSessionDetailTitle", "در حال بارگذاری...");
    setText("postSessionDetailMeta", "...");
    tg?.HapticFeedback?.selectionChanged();

    try {
      const data = await api(`auto_post.php?session_id=${sessionId}`);
      state.cache = state.cache || {};
      state.cache.postSessionStats = data;
      renderPostSessionDetail(data);
      showPostSessionTab("overview");
    } catch (error) {
      setText("postSessionDetailTitle", "خطا");
      setText("postSessionDetailMeta", "بارگذاری آمار سشن ممکن نشد");
      showToast("بارگذاری آمار سشن ناموفق بود", { type: "error" });
      console.error(error);
    }
  }

  function closePostSessionDetail() {
    const detail = document.getElementById("screenPostSessionDetail");
    detail?.setAttribute("hidden", "");
    detail?.classList.remove("is-active");
    screens.channels?.classList.add("is-active");
    bottomNav?.removeAttribute("hidden");
    destroyCharts();
    state.activePostSessionId = null;
    state.postSessionTab = "overview";
  }

  function showPostSessionTab(tab) {
    state.postSessionTab = tab;
    const panels = {
      overview: document.getElementById("postSessionPanelOverview"),
      channels: document.getElementById("postSessionPanelChannels"),
      bots: document.getElementById("postSessionPanelBots"),
      send: document.getElementById("postSessionPanelSend"),
    };

    Object.entries(panels).forEach(([name, el]) => {
      if (!el) return;
      if (name === tab) {
        el.removeAttribute("hidden");
        el.classList.add("is-active");
      } else {
        el.setAttribute("hidden", "");
        el.classList.remove("is-active");
      }
    });

    document.querySelectorAll("[data-post-session-tab]").forEach((btn) => {
      btn.classList.toggle("is-active", btn.dataset.postSessionTab === tab);
    });

    if (tab === "overview" && state.cache?.postSessionStats) {
      requestAnimationFrame(() => renderPostSessionCombinedCharts(state.cache.postSessionStats.stats || {}));
    } else if (tab === "channels" && state.cache?.postSessionStats) {
      requestAnimationFrame(() => renderPostSessionChannelCharts(state.cache.postSessionStats.stats || {}));
    } else if (tab === "bots" && state.cache?.postSessionStats) {
      requestAnimationFrame(() => renderPostSessionBotCharts(state.cache.postSessionStats.stats || {}));
    } else if (tab === "send" && state.activePostSessionId) {
      loadAutoPostSchedules(state.activePostSessionId);
    }
  }

  function renderPostSessionDetail(data) {
    const session = data.session || {};
    const stats = data.stats || {};
    const channels = data.channels || [];
    const bots = data.bots || [];

    setText("postSessionDetailTitle", session.name || "سشن پست");
    setText(
      "postSessionDetailMeta",
      `${session.channel_folder_path || "—"} → ${session.bot_folder_path || "—"}`
    );

    setText("psOverviewChannels", formatNumber(stats.channel_count ?? 0));
    setText("psOverviewChannelMembers", formatNumber(stats.channel_members ?? 0));
    setText("psOverviewBots", formatNumber(stats.bot_count ?? 0));
    setText("psOverviewBotUsers", formatNumber(stats.unique_bot_users ?? 0));
    setText("psOverviewChannelPath", session.channel_folder_path || "—");
    setText("psOverviewBotPath", session.bot_folder_path || "—");
    setText("psOverviewCombined", formatNumber(stats.combined_reach ?? 0));
    setText("psOverviewCreated", session.created_at ? formatDate(session.created_at) : "—");

    setText("psChannelKpiJoins1h", formatNumber(stats.channel_joins_1h ?? 0));
    setText("psChannelKpiJoins24h", formatNumber(stats.channel_joins_24h ?? stats.channel_growth_24h ?? 0));
    setText("psChannelKpiMembers", formatNumber(stats.channel_members ?? 0));

    setText("psBotKpiJoins1h", formatNumber(stats.bot_joins_1h ?? 0));
    setText("psBotKpiJoins24h", formatNumber(stats.bot_joins_24h ?? stats.bot_users_24h ?? 0));
    setText("psBotKpiUsers", formatNumber(stats.unique_bot_users ?? 0));

    setText("psStatsKpiCombined", formatNumber(stats.combined_reach ?? 0));
    setText("psStatsKpiNewUnique", formatNumber(stats.combined_new_24h ?? 0));

    const channelList = document.getElementById("psChannelList");
    if (channelList) {
      channelList.innerHTML = channels.length
        ? channels
            .map(
              (c) => `
          <button type="button" class="folder-details__bot-row" data-ps-channel-id="${Number(c.chat_id)}">
            <span class="folder-details__bot-name">${escapeHtml(c.title || "کانال")}${c.is_banned ? ' <span class="bot-ban-badge">BAN</span>' : ""}</span>
            <span class="folder-details__bot-meta">${c.username ? `@${escapeHtml(c.username)} · ` : ""}${formatNumber(c.member_count ?? 0)} عضو</span>
          </button>`
            )
            .join("")
        : '<p class="folder-details__empty">کانالی در این پوشه نیست</p>';

      channelList.querySelectorAll("[data-ps-channel-id]").forEach((btn) => {
        btn.addEventListener("click", () => {
          const chatId = Number(btn.dataset.psChannelId);
          if (chatId) openChannelDetail(chatId);
        });
      });
    }

    const botList = document.getElementById("psBotList");
    if (botList) {
      botList.innerHTML = bots.length
        ? bots
            .map((b) => {
              const typeLabel = b.bot_type === "guardian" ? "محافظ" : "آپلودر";
              return `
          <button type="button" class="folder-details__bot-row" data-ps-bot-id="${Number(b.id)}">
            <span class="folder-details__bot-name">${escapeHtml(b.bot_name || "ربات")}${b.is_banned ? ' <span class="bot-ban-badge">BAN</span>' : ""}</span>
            <span class="folder-details__bot-meta">${typeLabel}${b.bot_username ? ` · @${escapeHtml(b.bot_username)}` : ""} · ${formatNumber(b.user_count ?? 0)} کاربر</span>
          </button>`;
            })
            .join("")
        : '<p class="folder-details__empty">رباتی در این پوشه نیست</p>';

      botList.querySelectorAll("[data-ps-bot-id]").forEach((btn) => {
        btn.addEventListener("click", () => {
          const botId = Number(btn.dataset.psBotId);
          if (botId) openBotDetail(botId);
        });
      });
    }

    if (state.postSessionTab === "overview") {
      renderPostSessionCombinedCharts(stats);
    } else if (state.postSessionTab === "channels") {
      renderPostSessionChannelCharts(stats);
    } else if (state.postSessionTab === "bots") {
      renderPostSessionBotCharts(stats);
    }
  }

  function renderPostSessionChannelCharts(stats) {
    setText("psChartChannelJoinsRange", formatChartRange(stats.joins_range));
    setText("psChartChannelMembersRange", formatChartRange(stats.members_range));

    destroyCharts();
    if (typeof Chart === "undefined") return;

    const channelJoinsCtx = document.getElementById("chartPsChannelJoins");
    if (channelJoinsCtx) {
      state.charts.psChannelJoins = createDashboardLineChart(channelJoinsCtx, stats.joins_hourly || [], {
        tooltipLabel: "عضویت",
        valueSuffix: " عضویت",
        beginAtZero: true,
      });
    }

    const channelMembersCtx = document.getElementById("chartPsChannelMembers");
    if (channelMembersCtx) {
      state.charts.psChannelMembers = createDashboardLineChart(channelMembersCtx, stats.members_hourly || [], {
        borderColor: "#0ea5e9",
        fillTop: "rgba(14, 165, 233, 0.26)",
        fillBottom: "rgba(14, 165, 233, 0.02)",
        tooltipLabel: "اعضا",
        valueSuffix: " عضو",
        beginAtZero: false,
      });
    }
  }

  function renderPostSessionBotCharts(stats) {
    setText("psChartBotJoinsRange", formatChartRange(stats.bot_joins_range));
    setText("psChartBotUsersRange", formatChartRange(stats.bot_users_range));

    destroyCharts();
    if (typeof Chart === "undefined") return;

    const botJoinsCtx = document.getElementById("chartPsBotJoins");
    if (botJoinsCtx) {
      state.charts.psBotJoins = createDashboardLineChart(botJoinsCtx, stats.bot_joins_hourly || [], {
        borderColor: "#8b5cf6",
        fillTop: "rgba(139, 92, 246, 0.24)",
        fillBottom: "rgba(139, 92, 246, 0.02)",
        tooltipLabel: "کاربر جدید",
        valueSuffix: " کاربر",
        beginAtZero: true,
      });
    }

    const botUsersCtx = document.getElementById("chartPsBotUsers");
    if (botUsersCtx) {
      state.charts.psBotUsers = createDashboardLineChart(botUsersCtx, stats.bot_users_hourly || [], {
        borderColor: "#22c55e",
        fillTop: "rgba(34, 197, 94, 0.22)",
        fillBottom: "rgba(34, 197, 94, 0.02)",
        tooltipLabel: "کاربران",
        valueSuffix: " کاربر",
        beginAtZero: false,
      });
    }
  }

  function renderPostSessionCombinedCharts(stats) {
    setText("psChartCombinedReachRange", formatChartRange(stats.combined_reach_range));
    setText("psChartCombinedNewRange", formatChartRange(stats.combined_new_range));

    destroyCharts();
    if (typeof Chart === "undefined") return;

    const combinedReachCtx = document.getElementById("chartPsCombinedReach");
    if (combinedReachCtx) {
      state.charts.psCombinedReach = createDashboardLineChart(combinedReachCtx, stats.combined_reach_hourly || [], {
        borderColor: "#f59e0b",
        fillTop: "rgba(245, 158, 11, 0.24)",
        fillBottom: "rgba(245, 158, 11, 0.02)",
        tooltipLabel: "دسترسی",
        valueSuffix: " نفر",
        beginAtZero: false,
      });
    }

    const combinedNewCtx = document.getElementById("chartPsCombinedNew");
    if (combinedNewCtx) {
      state.charts.psCombinedNew = createDashboardLineChart(combinedNewCtx, stats.combined_new_hourly || [], {
        borderColor: "#ec4899",
        fillTop: "rgba(236, 72, 153, 0.22)",
        fillBottom: "rgba(236, 72, 153, 0.02)",
        tooltipLabel: "کاربر جدید",
        valueSuffix: " نفر",
        beginAtZero: true,
      });
    }
  }

  /** @deprecated split into channel/bot/combined renderers */
  function renderPostSessionCharts(stats) {
    renderPostSessionCombinedCharts(stats);
  }

  async function autoPostScheduleApi(body) {
    return api("auto_post_schedules.php", body ? { method: "POST", body } : undefined);
  }

  async function loadAutoPostSchedules(sessionId) {
    try {
      await fetchAutoPostSchedules(sessionId);
    } catch (_) {
      const list = document.getElementById("autoPostScheduleList");
      if (list) list.innerHTML = '<p class="folder-details__empty">بارگذاری ناموفق بود</p>';
    }
  }

  async function fetchAutoPostSchedules(sessionId) {
    const data = await api(`auto_post_schedules.php?session_id=${sessionId}`);
    state.cache = state.cache || {};
    state.cache.autoPostSchedules = data.schedules || [];
    renderAutoPostScheduleList(data.schedules || []);
    return data;
  }

  function autoPostScheduleErrorMessage(code) {
    const map = {
      schedule_not_found: "پست خودکار یافت نشد",
      schedule_inactive: "پست خودکار متوقف است — ابتدا فعالش کنید",
      no_media: "محتوایی برای ارسال انتخاب نشده",
      no_channels: "کانالی در این سشن برای ارسال نیست",
      bots_missing: "ربات محافظ یا آپلودر در سشن نیست",
      bots_pick_failed: "انتخاب ربات از استخر ناموفق بود",
      cache_failed: "کش محتوا برای آپلودر ناموفق بود",
      channel_post_failed: "ارسال به کانال ناموفق بود — دسترسی ربات مدیریت را بررسی کنید",
      send_failed: "ارسال ناموفق بود",
      request_failed: "خطا در ارتباط با سرور",
    };
    return map[code] || map.send_failed;
  }

  function renderAutoPostScheduleList(schedules) {
    const list = document.getElementById("autoPostScheduleList");
    if (!list) return;
    if (!schedules.length) {
      list.innerHTML = '<p class="folder-details__empty">هنوز پست خودکاری ثبت نشده — «افزودن پست خودکار» را بزنید.</p>';
      return;
    }
    list.innerHTML = schedules
      .map((s) => {
        const paused = s.status === "paused";
        const nextAt = s.next_post_at ? formatDate(s.next_post_at) : "—";
        return `
        <article class="auto-post-schedule-card ${paused ? "is-paused" : ""}">
          <div class="auto-post-schedule-card__head">
            <strong>${escapeHtml(s.name || "پست خودکار")}</strong>
            <span class="auto-post-schedule-card__badge">${escapeHtml(s.media_type_label || "")}</span>
          </div>
          <p class="auto-post-schedule-card__meta">
            ${escapeHtml(s.group_title || "گروه")} · ${Number(s.media_count || 0)} محتوا · ${escapeHtml(s.schedule_mode_label || "")}
          </p>
          <p class="auto-post-schedule-card__meta">ساعت ${escapeHtml(s.start_time || "—")} · بعدی: ${escapeHtml(nextAt)}</p>
          <div class="auto-post-schedule-card__actions">
            <button type="button" class="btn btn--ghost btn--sm" data-ap-edit="${Number(s.id)}">ویرایش</button>
            <button type="button" class="btn btn--ghost btn--sm" data-ap-run-now="${Number(s.id)}">ارسال الان</button>
            <button type="button" class="btn btn--ghost btn--sm" data-ap-toggle="${Number(s.id)}" data-ap-active="${paused ? "1" : "0"}">${paused ? "فعال" : "توقف"}</button>
            <button type="button" class="btn btn--ghost btn--sm btn--danger" data-ap-delete="${Number(s.id)}">حذف</button>
          </div>
        </article>`;
      })
      .join("");

    list.querySelectorAll("[data-ap-edit]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = Number(btn.dataset.apEdit);
        if (id) openAutoPostScheduleWizardForEdit(id);
      });
    });
    list.querySelectorAll("[data-ap-delete]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const id = Number(btn.dataset.apDelete);
        if (!id || !confirm("این پست خودکار حذف شود؟")) return;
        try {
          await autoPostScheduleApi({ action: "delete_schedule", schedule_id: id });
          await fetchAutoPostSchedules(state.activePostSessionId);
          showToast("حذف شد", { type: "success" });
        } catch (_) {
          showToast("حذف ناموفق بود", { type: "error" });
        }
      });
    });
    list.querySelectorAll("[data-ap-toggle]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const id = Number(btn.dataset.apToggle);
        const active = btn.dataset.apActive === "1";
        try {
          await autoPostScheduleApi({ action: "toggle_schedule", schedule_id: id, active });
          await fetchAutoPostSchedules(state.activePostSessionId);
          showToast(active ? "فعال شد" : "متوقف شد", { type: "success" });
        } catch (_) {
          showToast("تغییر وضعیت ناموفق بود", { type: "error" });
        }
      });
    });
    list.querySelectorAll("[data-ap-run-now]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const id = Number(btn.dataset.apRunNow);
        const originalLabel = btn.textContent;
        btn.disabled = true;
        btn.textContent = "در حال ارسال…";
        try {
          const data = await autoPostScheduleApi({ action: "run_now", schedule_id: id });
          const result = data?.result || {};
          if (!result.ok && (result.posted ?? 0) <= 0) {
            throw new Error(result.error || "send_failed");
          }
          await fetchAutoPostSchedules(state.activePostSessionId);
          const posted = Number(result.posted ?? 0);
          const failed = Number(result.failed ?? 0);
          if (posted > 0) {
            showToast(
              failed > 0 ? `ارسال شد — ${posted} کانال · ${failed} ناموفق` : `ارسال شد — ${posted} کانال`,
              { type: "success" }
            );
          } else {
            showToast("ارسال انجام شد", { type: "success" });
          }
        } catch (error) {
          showToast(autoPostScheduleErrorMessage(error?.message || "send_failed"), { type: "error" });
        } finally {
          btn.disabled = false;
          btn.textContent = originalLabel;
        }
      });
    });
  }

  function apMediaKey(item) {
    return `${item.chat_id}:${item.message_id}`;
  }

  function renderApMediaPreviewStrip() {
    const strip = document.getElementById("apMediaPreviewStrip");
    if (!strip) return;
    const items = state.apWizardSelectedMedia || [];
    if (!items.length) {
      strip.innerHTML = '<p class="hint-text">هنوز محتوایی انتخاب نشده</p>';
      return;
    }
    strip.innerHTML = items
      .map(
        (item, idx) => `
      <button type="button" class="ap-media-chip is-selected" data-ap-remove-media="${idx}">
        <i class="fa-solid fa-${state.apWizardMediaType === "video" ? "film" : state.apWizardMediaType === "voice" ? "microphone" : state.apWizardMediaType === "file" ? "file" : "image"}"></i>
        <span>${escapeHtml(item.label || `محتوا ${idx + 1}`)}</span>
        <i class="fa-solid fa-xmark ap-media-chip__remove"></i>
      </button>`
      )
      .join("");
    strip.querySelectorAll("[data-ap-remove-media]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const idx = Number(btn.dataset.apRemoveMedia);
        state.apWizardSelectedMedia.splice(idx, 1);
        renderApMediaPreviewStrip();
      });
    });
  }

  async function loadApWizardContentGroups() {
    const container = document.getElementById("apContentGroupList");
    if (!container) return;
    container.innerHTML = '<p class="folder-details__empty">در حال بارگذاری...</p>';
    try {
      const data = await api("content_groups.php");
      const groups = data.groups || [];
      if (!groups.length) {
        container.innerHTML = '<p class="folder-details__empty">گروه محتوایی یافت نشد — ربات manage را در گروه ادمین کنید.</p>';
        return;
      }
      container.innerHTML = groups
        .map(
          (g) => `
        <button type="button" class="ap-content-group-row" data-ap-group-id="${Number(g.chat_id)}">
          <span class="ap-content-group-row__title">${escapeHtml(g.title || "گروه")}${g.is_private ? ' <span class="badge badge--muted">خصوصی</span>' : ""}</span>
          <span class="ap-content-group-row__meta">${g.username ? `@${escapeHtml(g.username)} · ` : ""}${formatNumber(g.photo_count ?? 0)} عکس · ${formatNumber(g.video_count ?? 0)} ویدیو</span>
        </button>`
        )
        .join("");
      container.querySelectorAll("[data-ap-group-id]").forEach((btn) => {
        btn.addEventListener("click", () => {
          state.apWizardGroupChatId = Number(btn.dataset.apGroupId);
          const group = groups.find((g) => Number(g.chat_id) === state.apWizardGroupChatId);
          state.apWizardGroupTitle = group?.title || "گروه";
          container.querySelectorAll(".ap-content-group-row").forEach((el) => {
            el.classList.toggle("is-selected", Number(el.dataset.apGroupId) === state.apWizardGroupChatId);
          });
          tg?.HapticFeedback?.selectionChanged();
        });
      });
    } catch (_) {
      container.innerHTML = '<p class="folder-details__empty">بارگذاری گروه‌ها ناموفق بود</p>';
    }
  }

  async function loadApWizardMediaSuggestions() {
    if (!state.apWizardGroupChatId) return;
    try {
      const data = await api(
        `auto_post_schedules.php?content_group_chat_id=${state.apWizardGroupChatId}&media_type=${encodeURIComponent(state.apWizardMediaType)}&limit=12`
      );
      const items = data.items || [];
      if (!state.apWizardSelectedMedia.length && items.length) {
        state.apWizardSelectedMedia = [items[0]];
      }
      renderApMediaPreviewStrip();
    } catch (_) {
      renderApMediaPreviewStrip();
    }
  }

  function setApWizardStep(step) {
    state.apWizardStep = step;
    ["apWizardStep1", "apWizardStep2", "apWizardStep3"].forEach((id, idx) => {
      const el = document.getElementById(id);
      if (!el) return;
      if (idx + 1 === step) {
        el.removeAttribute("hidden");
      } else {
        el.setAttribute("hidden", "");
      }
    });
    const backBtn = document.getElementById("btnApWizardBack");
    const nextBtn = document.getElementById("btnApWizardNext");
    const saveBtn = document.getElementById("btnApWizardSave");
    if (backBtn) backBtn.hidden = step <= 1;
    if (nextBtn) nextBtn.hidden = step >= 3;
    if (saveBtn) saveBtn.hidden = step !== 3;
  }

  function openAutoPostScheduleWizard(editScheduleId = null) {
    if (!state.activePostSessionId) {
      showToast("ابتدا یک سشن را باز کنید", { type: "warning" });
      return;
    }
    state.apWizardEditScheduleId = editScheduleId ? Number(editScheduleId) : null;
    state.apWizardStep = 1;
    state.apWizardGroupChatId = null;
    state.apWizardGroupTitle = "";
    state.apWizardMediaType = "photo";
    state.apWizardSelectedMedia = [];
    state.apMediaPickerSelectedKeys = [];
    document.querySelectorAll("[data-ap-media-type]").forEach((chip) => {
      chip.classList.toggle("is-active", chip.dataset.apMediaType === "photo");
    });
    const timeInput = document.getElementById("apStartTimeInput");
    if (timeInput) timeInput.value = "17:30";
    const rotateField = document.getElementById("apRotateHoursField");
    if (rotateField) rotateField.hidden = true;
    const fixedRadio = document.querySelector('input[name="apScheduleMode"][value="daily_fixed"]');
    if (fixedRadio) fixedRadio.checked = true;
    const nameInput = document.getElementById("apScheduleNameInput");
    if (nameInput) nameInput.value = "";
    const titleEl = document.getElementById("autoPostWizardTitle");
    const saveBtn = document.getElementById("btnApWizardSave");
    if (titleEl) titleEl.textContent = state.apWizardEditScheduleId ? "ویرایش پست خودکار" : "افزودن پست خودکار";
    if (saveBtn) saveBtn.textContent = state.apWizardEditScheduleId ? "ذخیره تغییرات" : "ایجاد پست خودکار";
    setApWizardStep(1);
    loadApWizardContentGroups();
    const modal = document.getElementById("autoPostScheduleWizard");
    modal?.removeAttribute("hidden");
    document.body.classList.add("modal-open");
  }

  async function openAutoPostScheduleWizardForEdit(scheduleId) {
    try {
      const data = await api(`auto_post_schedules.php?schedule_id=${scheduleId}`);
      const s = data.schedule;
      if (!s) {
        showToast("پست خودکار پیدا نشد", { type: "error" });
        return;
      }
      openAutoPostScheduleWizard(scheduleId);
      state.apWizardGroupChatId = Number(s.content_group_chat_id) || null;
      state.apWizardGroupTitle = s.group_title || "گروه";
      state.apWizardMediaType = s.media_type || "photo";
      state.apWizardSelectedMedia = (s.media_items || []).map((item) => ({ ...item }));
      document.querySelectorAll("[data-ap-media-type]").forEach((chip) => {
        chip.classList.toggle("is-active", chip.dataset.apMediaType === state.apWizardMediaType);
      });
      const timeInput = document.getElementById("apStartTimeInput");
      if (timeInput) timeInput.value = s.start_time || "17:30";
      const rotateField = document.getElementById("apRotateHoursField");
      const rotateInput = document.getElementById("apRotateHoursInput");
      const mode = s.schedule_mode || "daily_fixed";
      const rotateRadio = document.querySelector(`input[name="apScheduleMode"][value="${mode}"]`);
      if (rotateRadio) rotateRadio.checked = true;
      if (rotateField) rotateField.hidden = mode !== "daily_rotate";
      if (rotateInput) rotateInput.value = String(s.rotate_hours ?? 1);
      const nameInput = document.getElementById("apScheduleNameInput");
      if (nameInput) nameInput.value = s.name || "";
      renderApMediaPreviewStrip();
    } catch (_) {
      showToast("بارگذاری پست خودکار ناموفق بود", { type: "error" });
    }
  }

  function closeAutoPostScheduleWizard() {
    document.getElementById("autoPostScheduleWizard")?.setAttribute("hidden", "");
    document.body.classList.remove("modal-open");
    state.apWizardEditScheduleId = null;
  }

  async function openApMediaPicker() {
    if (!state.apWizardGroupChatId) {
      showToast("ابتدا گروه را انتخاب کنید", { type: "warning" });
      return;
    }
    state.apMediaPickerSelectedKeys = (state.apWizardSelectedMedia || []).map(apMediaKey);
    setText("apMediaPickerMeta", `${state.apWizardGroupTitle} · ${state.apWizardMediaType}`);
    const screen = document.getElementById("apMediaPickerScreen");
    screen?.removeAttribute("hidden");
    screen?.classList.add("is-active");
    await renderApMediaPickerGrid();
  }

  function closeApMediaPicker() {
    const screen = document.getElementById("apMediaPickerScreen");
    screen?.setAttribute("hidden", "");
    screen?.classList.remove("is-active");
  }

  async function renderApMediaPickerGrid() {
    const grid = document.getElementById("apMediaPickerGrid");
    if (!grid) return;
    grid.innerHTML = '<p class="folder-details__empty">در حال بارگذاری...</p>';
    try {
      const data = await api(
        `auto_post_schedules.php?content_group_chat_id=${state.apWizardGroupChatId}&media_type=${encodeURIComponent(state.apWizardMediaType)}&limit=60`
      );
      const items = data.items || [];
      if (!items.length) {
        grid.innerHTML = '<p class="folder-details__empty">محتوایی از این نوع در گروه ثبت نشده — ابتدا در گروه پست کنید.</p>';
        updateApMediaPickerCount();
        return;
      }
      grid.innerHTML = items
        .map((item) => {
          const key = apMediaKey(item);
          const selected = state.apMediaPickerSelectedKeys.includes(key);
          const icon =
            item.media_kind === "video"
              ? "film"
              : item.media_kind === "voice"
              ? "microphone"
              : item.media_kind === "document"
              ? "file"
              : "image";
          return `
          <button type="button" class="ap-media-grid-item ${selected ? "is-selected" : ""}" data-ap-media-key="${escapeHtml(key)}">
            <span class="ap-media-grid-item__icon"><i class="fa-solid fa-${icon}"></i></span>
            <span class="ap-media-grid-item__label">${escapeHtml(item.label || "محتوا")}</span>
            ${item.seen_at ? `<span class="ap-media-grid-item__date">${escapeHtml(formatDate(item.seen_at))}</span>` : ""}
            ${selected ? '<span class="ap-media-grid-item__check"><i class="fa-solid fa-check"></i></span>' : ""}
          </button>`;
        })
        .join("");
      grid.querySelectorAll("[data-ap-media-key]").forEach((btn) => {
        btn.addEventListener("click", () => {
          const key = btn.dataset.apMediaKey;
          const idx = state.apMediaPickerSelectedKeys.indexOf(key);
          if (idx >= 0) {
            state.apMediaPickerSelectedKeys.splice(idx, 1);
            btn.classList.remove("is-selected");
            btn.querySelector(".ap-media-grid-item__check")?.remove();
          } else {
            state.apMediaPickerSelectedKeys.push(key);
            btn.classList.add("is-selected");
            if (!btn.querySelector(".ap-media-grid-item__check")) {
              const check = document.createElement("span");
              check.className = "ap-media-grid-item__check";
              check.innerHTML = '<i class="fa-solid fa-check"></i>';
              btn.appendChild(check);
            }
          }
          updateApMediaPickerCount();
          tg?.HapticFeedback?.selectionChanged();
        });
      });
      updateApMediaPickerCount();
    } catch (_) {
      grid.innerHTML = '<p class="folder-details__empty">بارگذاری ناموفق بود</p>';
    }
  }

  function updateApMediaPickerCount() {
    setText("apMediaPickerCount", `${state.apMediaPickerSelectedKeys.length} مورد انتخاب شده`);
  }

  async function confirmApMediaPicker() {
    if (!state.apMediaPickerSelectedKeys.length) {
      showToast("حداقل یک محتوا انتخاب کنید", { type: "warning" });
      return;
    }
    try {
      const data = await api(
        `auto_post_schedules.php?content_group_chat_id=${state.apWizardGroupChatId}&media_type=${encodeURIComponent(state.apWizardMediaType)}&limit=80`
      );
      const items = (data.items || []).filter((item) => state.apMediaPickerSelectedKeys.includes(apMediaKey(item)));
      state.apWizardSelectedMedia = items;
      renderApMediaPreviewStrip();
      closeApMediaPicker();
    } catch (_) {
      showToast("ذخیره انتخاب ناموفق بود", { type: "error" });
    }
  }

  async function saveAutoPostScheduleWizard() {
    const sessionId = Number(state.activePostSessionId || 0);
    if (!sessionId || !state.apWizardGroupChatId) {
      showToast("گروه انتخاب نشده", { type: "warning" });
      return;
    }
    if (!state.apWizardSelectedMedia.length) {
      showToast("حداقل یک محتوا انتخاب کنید", { type: "warning" });
      return;
    }
    const startTime = document.getElementById("apStartTimeInput")?.value || "17:30";
    const mode = document.querySelector('input[name="apScheduleMode"]:checked')?.value || "daily_fixed";
    const rotateHours = Number(document.getElementById("apRotateHoursInput")?.value || 1);
    const name = document.getElementById("apScheduleNameInput")?.value?.trim() || "";
    const btn = document.getElementById("btnApWizardSave");
    if (btn) btn.disabled = true;
    try {
      const payload = {
        session_id: sessionId,
        content_group_chat_id: state.apWizardGroupChatId,
        media_type: state.apWizardMediaType,
        media_items: state.apWizardSelectedMedia,
        schedule_mode: mode,
        start_time: startTime,
        rotate_hours: rotateHours,
        name: name || undefined,
      };
      if (state.apWizardEditScheduleId) {
        await autoPostScheduleApi({
          action: "update_schedule",
          schedule_id: state.apWizardEditScheduleId,
          ...payload,
        });
      } else {
        await autoPostScheduleApi({
          action: "create_schedule",
          ...payload,
        });
      }
      closeAutoPostScheduleWizard();
      await fetchAutoPostSchedules(sessionId);
      showPostSessionTab("send");
      showToast(state.apWizardEditScheduleId ? "پست خودکار به‌روز شد" : "پست خودکار ایجاد شد", { type: "success" });
    } catch (e) {
      const msg = String(e?.message || "");
      const friendly =
        msg === "session_not_found"
          ? "سشن پیدا نشد"
          : msg === "content_group_not_found"
          ? "گروه محتوا پیدا نشد"
          : msg === "media_items_required"
          ? "حداقل یک محتوا انتخاب کنید"
          : msg === "invalid_start_time"
          ? "ساعت شروع نامعتبر است"
          : msg === "server_error"
          ? "خطای سرور — دوباره امتحان کنید"
          : msg && msg !== "request_failed"
          ? msg
          : "ایجاد پست خودکار ناموفق بود";
      showToast(friendly, { type: "error" });
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  function initAutoPostScheduleUi() {
    document.getElementById("btnAddAutoPostSchedule")?.addEventListener("click", openAutoPostScheduleWizard);
    document.querySelectorAll("[data-close-auto-post-wizard]").forEach((el) => {
      el.addEventListener("click", closeAutoPostScheduleWizard);
    });
    document.getElementById("btnApWizardBack")?.addEventListener("click", () => {
      if (state.apWizardStep > 1) setApWizardStep(state.apWizardStep - 1);
    });
    document.getElementById("btnApWizardNext")?.addEventListener("click", async () => {
      if (state.apWizardStep === 1) {
        if (!state.apWizardGroupChatId) {
          showToast("گروه پست را انتخاب کنید", { type: "warning" });
          return;
        }
        setApWizardStep(2);
        await loadApWizardMediaSuggestions();
        return;
      }
      if (state.apWizardStep === 2) {
        if (!state.apWizardSelectedMedia.length) {
          showToast("حداقل یک محتوا انتخاب کنید", { type: "warning" });
          return;
        }
        setApWizardStep(3);
      }
    });
    document.getElementById("btnApWizardSave")?.addEventListener("click", saveAutoPostScheduleWizard);
    document.getElementById("btnApOpenMediaPicker")?.addEventListener("click", openApMediaPicker);
    document.getElementById("btnApMediaPickerBack")?.addEventListener("click", closeApMediaPicker);
    document.getElementById("btnApMediaPickerConfirm")?.addEventListener("click", confirmApMediaPicker);
    document.querySelectorAll("[data-ap-media-type]").forEach((chip) => {
      chip.addEventListener("click", async () => {
        state.apWizardMediaType = chip.dataset.apMediaType || "photo";
        document.querySelectorAll("[data-ap-media-type]").forEach((c) => {
          c.classList.toggle("is-active", c === chip);
        });
        state.apWizardSelectedMedia = [];
        await loadApWizardMediaSuggestions();
      });
    });
    document.querySelectorAll('input[name="apScheduleMode"]').forEach((radio) => {
      radio.addEventListener("change", () => {
        const rotateField = document.getElementById("apRotateHoursField");
        if (rotateField) rotateField.hidden = radio.value !== "daily_rotate" || !radio.checked;
        if (radio.value === "daily_rotate" && radio.checked && rotateField) rotateField.hidden = false;
      });
    });
  }

  async function hashtagToolsApi(body, query = {}) {
    let path = "hashtag_tools.php";
    if (query?.set_id) {
      path += `?set_id=${encodeURIComponent(query.set_id)}`;
    }
    return api(path, body ? { method: "POST", body } : undefined);
  }

  async function reloadHashtagTools() {
    const data = await hashtagToolsApi();
    renderHashtagTools(data);
    return data;
  }

  function setHashtagChannelFolderSelection(folderId, options = {}) {
    const id = Number(folderId) || 0;
    const folders = state.cache?.channels?.folders || [];
    const hidden = document.getElementById("hashtagChannelFolderInput");
    const label = document.getElementById("hashtagChannelFolderLabel");
    const btn = document.getElementById("hashtagChannelFolderBtn");
    if (hidden) hidden.value = id > 0 ? String(id) : "";
    if (label) label.textContent = id > 0 ? getFolderDisplayPath(folders, id) : "— انتخاب پوشه کانال —";
    if (btn) btn.classList.toggle("is-selected", id > 0);
    if (!options.silent && id > 0) showToast("پوشه کانال انتخاب شد", { type: "success", duration: 1800 });
  }

  function openHashtagChannelFolderPicker() {
    const folders = state.cache?.channels?.folders || [];
    if (!folders.length) {
      showToast("ابتدا یک پوشه کانال بسازید", { type: "warning" });
      return;
    }
    openFolderTreePicker({ mode: "hashtag_channel", folders, allowRoot: false });
  }

  function renderHashtagTagsEditor() {
    const list = document.getElementById("hashtagTagsList");
    if (!list) return;
    const tags = state.hashtagWizardTags || [];
    if (!tags.length) {
      list.innerHTML = '<p class="hint-text">حداقل یک هشتگ اضافه کنید</p>';
      return;
    }
    list.innerHTML = tags
      .map(
        (tag, idx) => `
      <div class="hashtag-tag-row" data-hashtag-idx="${idx}">
        <input class="field__input hashtag-tag-row__tag" type="text" placeholder="#فیلم" value="${escapeHtml(tag.tag_text || "")}" dir="ltr" />
        <input class="field__input hashtag-tag-row__keywords" type="text" placeholder="کلیدواژه: فیلم,سریال (اختیاری)" value="${escapeHtml((tag.trigger_keywords || []).join(","))}" />
        <label class="hashtag-tag-row__req"><input type="checkbox" class="hashtag-tag-row__required" ${tag.is_required ? "checked" : ""} /> اجباری</label>
        <button type="button" class="btn btn--ghost btn--sm btn--danger" data-remove-hashtag="${idx}"><i class="fa-solid fa-trash"></i></button>
      </div>`
      )
      .join("");
    list.querySelectorAll("[data-remove-hashtag]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const idx = Number(btn.dataset.removeHashtag);
        state.hashtagWizardTags.splice(idx, 1);
        renderHashtagTagsEditor();
      });
    });
  }

  function collectHashtagTagsFromEditor() {
    const list = document.getElementById("hashtagTagsList");
    if (!list) return [];
    const tags = [];
    list.querySelectorAll(".hashtag-tag-row").forEach((row) => {
      const tagText = row.querySelector(".hashtag-tag-row__tag")?.value?.trim() || "";
      const kwRaw = row.querySelector(".hashtag-tag-row__keywords")?.value?.trim() || "";
      const required = row.querySelector(".hashtag-tag-row__required")?.checked || false;
      if (!tagText) return;
      const keywords = kwRaw
        ? kwRaw.split(/[,،]/).map((s) => s.trim()).filter(Boolean)
        : [];
      tags.push({
        tag_text: tagText.startsWith("#") ? tagText : `#${tagText}`,
        rule_mode: keywords.length ? "keyword" : "pool",
        trigger_keywords: keywords,
        is_required: required,
      });
    });
    return tags;
  }

  function openHashtagSetWizard(editSetId = null) {
    state.hashtagWizardEditSetId = editSetId ? Number(editSetId) : null;
    const titleEl = document.getElementById("hashtagWizardTitle");
    const saveBtn = document.getElementById("btnSaveHashtagSet");
    if (titleEl) titleEl.textContent = state.hashtagWizardEditSetId ? "ویرایش هشتگ پست" : "ایجاد هشتگ پست";
    if (saveBtn) saveBtn.textContent = state.hashtagWizardEditSetId ? "ذخیره تغییرات" : "ذخیره";
    document.getElementById("hashtagSetNameInput").value = "";
    setHashtagChannelFolderSelection(0, { silent: true });
    document.querySelector('input[name="hashtagSelectionMode"][value="random"]').checked = true;
    document.getElementById("hashtagRandomCountInput").value = "5";
    state.hashtagWizardTags = [{ tag_text: "#", rule_mode: "pool", trigger_keywords: [], is_required: false }];
    if (state.hashtagWizardEditSetId) {
      const set = (state.cache?.hashtagTools?.sets || []).find((s) => Number(s.id) === state.hashtagWizardEditSetId);
      if (set) {
        document.getElementById("hashtagSetNameInput").value = set.name || "";
        setHashtagChannelFolderSelection(set.channel_folder_id, { silent: true });
        const modeRadio = document.querySelector(`input[name="hashtagSelectionMode"][value="${set.selection_mode || "random"}"]`);
        if (modeRadio) modeRadio.checked = true;
        document.getElementById("hashtagRandomCountInput").value = String(set.random_count || 5);
        state.hashtagWizardTags = (set.tags || []).map((t) => ({
          tag_text: t.tag_text,
          rule_mode: t.rule_mode,
          trigger_keywords: t.trigger_keywords || [],
          is_required: !!t.is_required,
        }));
      }
    }
    renderHashtagTagsEditor();
    document.getElementById("hashtagSetWizard")?.removeAttribute("hidden");
    document.body.classList.add("modal-open");
  }

  function closeHashtagSetWizard() {
    document.getElementById("hashtagSetWizard")?.setAttribute("hidden", "");
    document.body.classList.remove("modal-open");
    state.hashtagWizardEditSetId = null;
  }

  async function saveHashtagSetWizard() {
    const name = document.getElementById("hashtagSetNameInput")?.value?.trim() || "";
    const folderId = Number(document.getElementById("hashtagChannelFolderInput")?.value || 0);
    const mode = document.querySelector('input[name="hashtagSelectionMode"]:checked')?.value || "random";
    const randomCount = Number(document.getElementById("hashtagRandomCountInput")?.value || 5);
    const tags = collectHashtagTagsFromEditor();
    if (!name) {
      showToast("نام مجموعه را وارد کنید", { type: "warning" });
      return;
    }
    if (!folderId) {
      showToast("پوشه کانال را انتخاب کنید", { type: "warning" });
      return;
    }
    if (!tags.length) {
      showToast("حداقل یک هشتگ اضافه کنید", { type: "warning" });
      return;
    }
    const btn = document.getElementById("btnSaveHashtagSet");
    if (btn) btn.disabled = true;
    try {
      if (state.hashtagWizardEditSetId) {
        await hashtagToolsApi({
          action: "update_set",
          set_id: state.hashtagWizardEditSetId,
          name,
          channel_folder_id: folderId,
          selection_mode: mode,
          random_count: randomCount,
          tags,
        });
      } else {
        await hashtagToolsApi({
          action: "create_set",
          name,
          channel_folder_id: folderId,
          selection_mode: mode,
          random_count: randomCount,
          tags,
          folder_id:
            state.openHashtagToolsFolderId ||
            state.cache?.hashtagTools?.default_folder_id ||
            undefined,
        });
      }
      closeHashtagSetWizard();
      await reloadHashtagTools();
      showToast("هشتگ پست ذخیره شد", { type: "success" });
    } catch (e) {
      showToast(e?.message === "tags_required" ? "حداقل یک هشتگ لازم است" : "ذخیره ناموفق بود", { type: "error" });
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  function getHashtagToolFolderName(folderId) {
    const folders = state.cache?.hashtagTools?.folders || [];
    const folder = folders.find((f) => Number(f.id) === Number(folderId));
    return folder?.name || "—";
  }

  function getHashtagPluginStats(data) {
    const sets = data?.sets || [];
    const totalTags = sets.reduce((sum, s) => sum + (s.tag_count || 0), 0);
    const folderPaths = new Set(sets.map((s) => s.channel_folder_path).filter(Boolean));
    return {
      setCount: sets.length,
      totalTags,
      folderCount: folderPaths.size,
    };
  }

  function renderToolsExplorer(hashtagData, bannerData, glassData, zapasData) {
    state.cache = state.cache || {};
    state.cache.hashtagTools = hashtagData || { folders: [], sets: [] };
    state.cache.bannerTools = bannerData || { groups: [], bindings: [], stats: {} };
    state.cache.glassButtonTools = glassData || { settings: [], stats: {} };
    state.cache.zapasTools = zapasData || { bots: [], bindings: [], replacements: [], stats: {} };

    const hashtagStats = getHashtagPluginStats(state.cache.hashtagTools);
    const bannerStats = state.cache.bannerTools.stats || {};
    const glassStats = state.cache.glassButtonTools.stats || {};
    const zapasStats = state.cache.zapasTools.stats || {};
    const pluginCount = 4;
    setText(
      "toolsExplorerCount",
      `${pluginCount} افزونه · ${zapasStats.replacement_count || 0} جایگزینی · ${glassStats.active_count || 0} دکمه فعال`
    );

    const list = document.getElementById("hashtagToolsList");
    if (!list) return;

    const hashtagMeta =
      hashtagStats.setCount > 0
        ? `${hashtagStats.folderCount} پوشه · ${hashtagStats.totalTags} هشتگ`
        : "افزودن هشتگ به کپشن پست";
    const bannerMeta =
      (bannerStats.group_count || 0) > 0
        ? `${bannerStats.group_count} گروه · ${bannerStats.photo_count || 0} عکس`
        : "گروه‌های با بیو 02";
    const glassMeta =
      (glassStats.active_count || 0) > 0
        ? `${glassStats.active_count} پوشه فعال`
        : "متن و استایل دکمه دریافت";
    const zapasMeta =
      (zapasStats.standby_count || 0) > 0
        ? `${zapasStats.standby_count} آماده · ${zapasStats.replacement_count || 0} جایگزینی`
        : "جایگزینی خودکار ربات بن‌شده";

    list.innerHTML = `
      <div class="explorer-tile explorer-tile--tool" data-hashtag-plugin="1" tabindex="0" role="button">
        <span class="explorer-tile__icon explorer-tile__icon--tool"><i class="fa-solid fa-hashtag"></i></span>
        <span class="explorer-tile__name">هشتگ پست</span>
        <span class="explorer-tile__meta">${escapeHtml(hashtagMeta)}</span>
      </div>
      <div class="explorer-tile explorer-tile--banner" data-banner-plugin="1" tabindex="0" role="button">
        <span class="explorer-tile__icon explorer-tile__icon--banner"><i class="fa-solid fa-panorama"></i></span>
        <span class="explorer-tile__name">عکس بنر</span>
        <span class="explorer-tile__meta">${escapeHtml(bannerMeta)}</span>
      </div>
      <div class="explorer-tile explorer-tile--tool explorer-tile--glass" data-glass-plugin="1" tabindex="0" role="button">
        <span class="explorer-tile__icon explorer-tile__icon--glass"><i class="fa-solid fa-up-right-from-square"></i></span>
        <span class="explorer-tile__name">دکمه شیشه‌ای</span>
        <span class="explorer-tile__meta">${escapeHtml(glassMeta)}</span>
      </div>
      <div class="explorer-tile explorer-tile--tool explorer-tile--zapas" data-zapas-plugin="1" tabindex="0" role="button">
        <span class="explorer-tile__icon explorer-tile__icon--zapas"><i class="fa-solid fa-shield-halved"></i></span>
        <span class="explorer-tile__name">زاپاس</span>
        <span class="explorer-tile__meta">${escapeHtml(zapasMeta)}</span>
      </div>`;

    list.querySelector("[data-hashtag-plugin]")?.addEventListener("click", () => {
      openHashtagPluginDetail();
    });
    list.querySelector("[data-banner-plugin]")?.addEventListener("click", () => {
      openBannerPluginDetail();
    });
    list.querySelector("[data-glass-plugin]")?.addEventListener("click", () => {
      openGlassButtonPluginDetail();
    });
    list.querySelector("[data-zapas-plugin]")?.addEventListener("click", () => {
      openZapasPluginDetail();
    });
  }

  function renderHashtagTools(data) {
    renderToolsExplorer(data, state.cache?.bannerTools, state.cache?.glassButtonTools, state.cache?.zapasTools);
  }

  function renderHashtagPluginConfigsList(activeSetId) {
    const list = document.getElementById("hashtagPluginConfigsList");
    if (!list) return;
    const sets = state.cache?.hashtagTools?.sets || [];
    if (!sets.length) {
      list.innerHTML =
        '<p class="channel-empty__sub">هنوز پوشه کانالی متصل نشده — «افزودن» را بزنید.</p>';
      return;
    }
    list.innerHTML = sets
      .map((s) => {
        const isActive = Number(s.id) === Number(activeSetId);
        return `
        <button type="button" class="hashtag-config-item${isActive ? " is-active" : ""}" data-hashtag-config-id="${s.id}">
          <span class="hashtag-config-item__main">
            <span class="hashtag-config-item__name">${escapeHtml(s.channel_folder_path || s.name || "پیکربندی")}</span>
            <span class="hashtag-config-item__meta">${escapeHtml(s.selection_mode_label || "—")} · ${formatNumber(s.tag_count || 0)} هشتگ</span>
          </span>
          <span class="hashtag-config-item__count"><i class="fa-solid fa-chevron-left"></i></span>
        </button>`;
      })
      .join("");
    list.querySelectorAll("[data-hashtag-config-id]").forEach((el) => {
      el.addEventListener("click", async () => {
        const id = Number(el.dataset.hashtagConfigId);
        await openHashtagSetDetail(id, { keepScreen: true });
      });
    });
  }

  function formatHashtagSetCreatedAt(value) {
    if (!value) return "—";
    try {
      return new Intl.DateTimeFormat("fa-IR", {
        dateStyle: "medium",
        timeStyle: "short",
      }).format(new Date(value.replace(" ", "T")));
    } catch (_) {
      return value;
    }
  }

  function renderHashtagTagsEditorPanel() {
    const list = document.getElementById("hashtagTagsEditorList");
    if (!list) return;
    const tags = state.hashtagSettingsTags || [];
    if (!tags.length) {
      list.innerHTML = '<p class="hint-text">هنوز هشتگی نیست — «افزودن هشتگ» را بزنید.</p>';
      return;
    }
    list.innerHTML = tags
      .map(
        (tag, idx) => `
      <div class="hashtag-tag-row" data-hashtag-settings-idx="${idx}">
        <input class="field__input hashtag-tag-row__tag" type="text" placeholder="#فیلم" value="${escapeHtml(tag.tag_text || "")}" dir="ltr" />
        <input class="field__input hashtag-tag-row__keywords" type="text" placeholder="کلیدواژه: فیلم,سریال (اختیاری)" value="${escapeHtml((tag.trigger_keywords || []).join(","))}" />
        <label class="hashtag-tag-row__req"><input type="checkbox" class="hashtag-tag-row__required" ${tag.is_required ? "checked" : ""} /> اجباری</label>
        <button type="button" class="btn btn--ghost btn--sm btn--danger" data-remove-hashtag-settings="${idx}"><i class="fa-solid fa-trash"></i></button>
      </div>`
      )
      .join("");
    list.querySelectorAll("[data-remove-hashtag-settings]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const idx = Number(btn.dataset.removeHashtagSettings);
        state.hashtagSettingsTags.splice(idx, 1);
        renderHashtagTagsEditorPanel();
      });
    });
  }

  function collectHashtagSettingsTagsFromEditor() {
    const list = document.getElementById("hashtagTagsEditorList") || document.getElementById("hashtagSetSettingsTagsList");
    if (!list) return [];
    const tags = [];
    list.querySelectorAll(".hashtag-tag-row").forEach((row) => {
      const tagText = row.querySelector(".hashtag-tag-row__tag")?.value?.trim() || "";
      const kwRaw = row.querySelector(".hashtag-tag-row__keywords")?.value?.trim() || "";
      const required = row.querySelector(".hashtag-tag-row__required")?.checked || false;
      if (!tagText) return;
      tags.push({
        tag_text: tagText,
        rule_mode: kwRaw ? "keyword" : "pool",
        trigger_keywords: kwRaw
          ? kwRaw
              .split(/[,،]/)
              .map((s) => s.trim())
              .filter(Boolean)
          : [],
        is_required: required,
      });
    });
    return tags;
  }

  function renderHashtagSetPreviewChips(tags, containerId = "hashtagSetDetailPreview") {
    const el = document.getElementById(containerId);
    if (!el) return;
    if (!tags?.length) {
      el.innerHTML = '<p class="channel-empty__sub">—</p>';
      return;
    }
    el.innerHTML = tags.map((t) => `<span class="hashtag-chip">${escapeHtml(t.tag_text)}</span>`).join("");
  }

  function renderHashtagPluginHeader(set) {
    const data = state.cache?.hashtagTools || {};
    const stats = getHashtagPluginStats(data);
    setText("hashtagSetDetailTitle", "هشتگ پست");
    if (set) {
      const tags = set.tags || [];
      setText(
        "hashtagSetDetailMeta",
        `${set.channel_folder_path || "—"} · ${formatNumber(tags.length)} هشتگ · ${set.selection_mode_label || "—"}`
      );
      setText("hashtagActiveConfigLabel", set.channel_folder_path || set.name || "—");
      setText("hashtagTagsConfigBanner", `پیکربندی: ${set.channel_folder_path || set.name || "—"}`);
    } else {
      setText(
        "hashtagSetDetailMeta",
        stats.setCount > 0
          ? `${stats.setCount} پیکربندی · ${stats.totalTags} هشتگ`
          : "افزونه · هنوز پیکربندی نشده"
      );
      setText("hashtagActiveConfigLabel", "هنوز پوشه کانالی انتخاب نشده");
      setText("hashtagTagsConfigBanner", "ابتدا از تب «نگاه کلی» یک پوشه کانال اضافه کنید");
    }
  }

  function renderHashtagSetDetail(set) {
    const data = state.cache?.hashtagTools || {};
    const stats = getHashtagPluginStats(data);
    const tags = set?.tags || [];
    const requiredCount = tags.filter((t) => t.is_required).length;

    renderHashtagPluginHeader(set);

    setText("hashtagSetDetailConfigCount", formatNumber(stats.setCount));
    setText("hashtagSetDetailTagCount", formatNumber(set ? tags.length : stats.totalTags));
    setText("hashtagSetDetailRequiredCount", formatNumber(set ? requiredCount : 0));
    setText(
      "hashtagSetDetailModeLabel",
      set
        ? set.selection_mode === "random"
          ? `تصادفی ${set.random_count || 0}`
          : set.selection_mode_label || set.selection_mode || "—"
        : "—"
    );

    renderHashtagSetPreviewChips(tags);
    renderHashtagPluginConfigsList(set?.id);
    if (set) {
      renderHashtagSetSettingsEditor(set);
      renderHashtagTagsEditorPanel();
    }
  }

  function renderHashtagSetSettingsEditor(set) {
    if (!set) return;
    document.getElementById("hashtagSetSettingsNameInput").value = set.name || "";
    setHashtagSetSettingsChannelFolderSelection(set.channel_folder_id, { silent: true });
    const modeRadio = document.querySelector(
      `input[name="hashtagSetSettingsMode"][value="${set.selection_mode || "random"}"]`
    );
    if (modeRadio) modeRadio.checked = true;
    document.getElementById("hashtagSetSettingsRandomCountInput").value = String(set.random_count || 5);
    state.hashtagSettingsTags = (set.tags || []).map((t) => ({
      tag_text: t.tag_text,
      rule_mode: t.rule_mode,
      trigger_keywords: t.trigger_keywords || [],
      is_required: !!t.is_required,
    }));
    renderHashtagTagsEditorPanel();
  }

  function setHashtagSetSettingsChannelFolderSelection(folderId, options = {}) {
    const id = Number(folderId) || 0;
    const folders = state.cache?.channels?.folders || [];
    const hidden = document.getElementById("hashtagSetSettingsChannelFolderInput");
    const label = document.getElementById("hashtagSetSettingsChannelFolderLabel");
    if (hidden) hidden.value = id > 0 ? String(id) : "";
    if (label) {
      label.textContent = id > 0 ? getFolderDisplayPath(folders, id) : "— انتخاب پوشه کانال —";
    }
    if (!options.silent && id <= 0) {
      showToast("پوشه کانال را انتخاب کنید", { type: "warning" });
    }
  }

  function openHashtagSetSettingsChannelFolderPicker() {
    const folders = state.cache?.channels?.folders || [];
    openFolderTreePicker({ mode: "hashtag_set_settings_channel", folders, allowRoot: false });
  }

  function showHashtagSetTab(tab) {
    state.hashtagSetTab = tab;
    const panels = {
      overview: document.getElementById("hashtagSetPanelOverview"),
      tags: document.getElementById("hashtagSetPanelTags"),
      settings: document.getElementById("hashtagSetPanelSettings"),
    };
    Object.entries(panels).forEach(([name, el]) => {
      if (!el) return;
      if (name === tab) {
        el.removeAttribute("hidden");
        el.classList.add("is-active");
      } else {
        el.setAttribute("hidden", "");
        el.classList.remove("is-active");
      }
    });
    document.querySelectorAll("[data-hashtag-set-tab]").forEach((btn) => {
      btn.classList.toggle("is-active", btn.dataset.hashtagSetTab === tab);
    });
    if (tab === "settings" && state.cache?.hashtagSetDetail) {
      renderHashtagSetSettingsEditor(state.cache.hashtagSetDetail);
    }
    if (tab === "tags" && state.cache?.hashtagSetDetail) {
      renderHashtagSetSettingsEditor(state.cache.hashtagSetDetail);
    }
  }

  async function createHashtagConfigForChannelFolder(folderId) {
    const folders = state.cache?.channels?.folders || [];
    const folderPath = getFolderDisplayPath(folders, folderId);
    const existing = (state.cache?.hashtagTools?.sets || []).find(
      (s) => Number(s.channel_folder_id) === Number(folderId)
    );
    if (existing) {
      await openHashtagSetDetail(Number(existing.id), { keepScreen: true });
      showToast("این پوشه کانال قبلاً پیکربندی شده", { type: "info", duration: 2200 });
      return;
    }
    try {
      const result = await hashtagToolsApi({
        action: "create_set",
        name: folderPath || "پیکربندی جدید",
        channel_folder_id: folderId,
        selection_mode: "random",
        random_count: 5,
        tags: [{ tag_text: "#", rule_mode: "pool", trigger_keywords: [], is_required: false }],
        folder_id: state.cache?.hashtagTools?.default_folder_id || undefined,
      });
      await reloadHashtagTools();
      const setId = Number(result?.set?.id || 0);
      if (setId > 0) {
        await openHashtagSetDetail(setId, { keepScreen: true });
        showHashtagSetTab("tags");
        showToast("پیکربندی ساخته شد — هشتگ‌ها را اضافه کنید", { type: "success" });
      }
    } catch (_) {
      showToast("ساخت پیکربندی ناموفق بود", { type: "error" });
    }
  }

  function openHashtagAddChannelFolderPicker() {
    const folders = state.cache?.channels?.folders || [];
    if (!folders.length) {
      showToast("ابتدا یک پوشه کانال بسازید", { type: "warning" });
      return;
    }
    openFolderTreePicker({ mode: "hashtag_add_channel", folders, allowRoot: false });
  }

  async function openHashtagPluginDetail() {
    if (!state.cache?.hashtagTools) {
      try {
        await reloadHashtagTools();
      } catch (_) {
        showToast("بارگذاری ابزار ناموفق بود", { type: "error" });
        return;
      }
    }

    const detail = document.getElementById("screenHashtagSetDetail");
    screens.channels?.classList.remove("is-active");
    detail?.removeAttribute("hidden");
    detail?.classList.add("is-active");
    bottomNav?.setAttribute("hidden", "hidden");

    const sets = state.cache?.hashtagTools?.sets || [];
    if (!sets.length) {
      state.activeHashtagSetId = null;
      state.cache.hashtagSetDetail = null;
      renderHashtagSetDetail(null);
      showHashtagSetTab("overview");
      tg?.HapticFeedback?.selectionChanged();
      return;
    }

    const preferredId = state.activeHashtagSetId || sets[0]?.id;
    await openHashtagSetDetail(Number(preferredId), { keepScreen: true });
  }

  async function openHashtagSetDetail(setId, options = {}) {
    state.activeHashtagSetId = setId;
    const detail = document.getElementById("screenHashtagSetDetail");
    const channelsScreen = screens.channels;

    if (!options.keepScreen) {
      channelsScreen?.classList.remove("is-active");
      detail?.removeAttribute("hidden");
      detail?.classList.add("is-active");
      bottomNav?.setAttribute("hidden", "hidden");
    }

    renderHashtagPluginHeader(state.cache?.hashtagSetDetail || null);
    setText("hashtagSetDetailMeta", "در حال بارگذاری...");
    if (!options.keepScreen) tg?.HapticFeedback?.selectionChanged();

    try {
      const data = await hashtagToolsApi(null, { set_id: setId });
      const set = data.set || (state.cache?.hashtagTools?.sets || []).find((s) => Number(s.id) === setId);
      if (!set) throw new Error("set_not_found");
      state.cache = state.cache || {};
      state.cache.hashtagSetDetail = set;
      renderHashtagSetDetail(set);
      showHashtagSetTab(options.tab || state.hashtagSetTab || "overview");
    } catch (error) {
      renderHashtagPluginHeader(null);
      setText("hashtagSetDetailMeta", "دریافت جزئیات ممکن نشد");
      showToast("بارگذاری جزئیات هشتگ ممکن نشد", { type: "error" });
      console.error(error);
    }
  }

  function closeHashtagSetDetail() {
    const detail = document.getElementById("screenHashtagSetDetail");
    detail?.setAttribute("hidden", "");
    detail?.classList.remove("is-active");
    screens.channels?.classList.add("is-active");
    bottomNav?.removeAttribute("hidden");
    state.activeHashtagSetId = null;
    state.hashtagSetTab = "overview";
    state.hashtagSettingsTags = [];
  }

  async function bannerToolsApi(body, query = {}) {
    let path = "banner_tools.php";
    const qs = new URLSearchParams(query).toString();
    if (qs) path += "?" + qs;
    return api(path, body ? { method: "POST", body } : undefined);
  }

  async function reloadBannerTools() {
    const data = await bannerToolsApi();
    state.cache = state.cache || {};
    state.cache.bannerTools = data;
    renderToolsExplorer(state.cache.hashtagTools, data, state.cache?.glassButtonTools, state.cache?.zapasTools);
    if (document.getElementById("screenBannerDetail")?.classList.contains("is-active")) {
      renderBannerDetail(data);
    }
    return data;
  }

  function showBannerTab(tab) {
    state.bannerTab = tab;
    ["overview", "groups", "settings"].forEach((name) => {
      const panel = document.getElementById(`bannerPanel${name.charAt(0).toUpperCase()}${name.slice(1)}`);
      if (panel) panel.hidden = name !== tab;
      if (panel) panel.classList.toggle("is-active", name === tab);
    });
    document.querySelectorAll("[data-banner-tab]").forEach((btn) => {
      btn.classList.toggle("is-active", btn.dataset.bannerTab === tab);
    });
  }

  function renderBannerDetail(data) {
    const stats = data?.stats || {};
    const groups = data?.groups || [];
    const bindings = data?.bindings || [];

    setText("bannerDetailMeta", `${stats.group_count || 0} گروه بنر · ${stats.photo_count || 0} عکس`);
    setText("bannerStatGroupCount", formatNumber(stats.group_count || 0));
    setText("bannerStatPhotoCount", formatNumber(stats.photo_count || 0));
    setText("bannerStatBindingCount", formatNumber(stats.active_binding_count || 0));

    const groupsList = document.getElementById("bannerGroupsList");
    if (groupsList) {
      if (!groups.length) {
        groupsList.innerHTML =
          '<p class="channel-empty__sub">گروهی با بیو <strong>02</strong> پیدا نشد — بیو گروه را در تلگرام تنظیم کنید و «بروزرسانی» بزنید.</p>';
      } else {
        groupsList.innerHTML = groups
          .map(
            (g) => `
          <div class="hashtag-config-item">
            <span class="hashtag-config-item__main">
              <span class="hashtag-config-item__name">${escapeHtml(g.title || "گروه")}${g.is_private ? ' <span class="badge badge--muted">خصوصی</span>' : g.username ? ` · @${escapeHtml(g.username)}` : ""}</span>
              <span class="hashtag-config-item__meta">${formatNumber(g.photo_count || 0)} عکس · ${formatNumber(g.message_count || 0)} پیام · بیو: 02</span>
            </span>
          </div>`
          )
          .join("");
      }
    }

    const bindingsList = document.getElementById("bannerBindingsList");
    if (bindingsList) {
      if (!bindings.length) {
        bindingsList.innerHTML =
          '<p class="channel-empty__sub">هنوز پوشه‌ای فعال نشده — «افزودن» را بزنید.</p>';
      } else {
        bindingsList.innerHTML = bindings
          .map(
            (b) => `
          <div class="hashtag-config-item">
            <span class="hashtag-config-item__main">
              <span class="hashtag-config-item__name">${escapeHtml(b.channel_folder_path || "پوشه")}</span>
              <span class="hashtag-config-item__meta">${b.has_auto_post_session ? "سشن پست دارد" : "بدون سشن پست"} · ${b.is_enabled ? "فعال" : "غیرفعال"}</span>
            </span>
            <button type="button" class="btn btn--ghost btn--sm${b.is_enabled ? " btn--danger" : ""}" data-banner-toggle="${b.channel_folder_id}" data-enabled="${b.is_enabled ? "0" : "1"}">
              ${b.is_enabled ? "غیرفعال" : "فعال"}
            </button>
            <button type="button" class="btn btn--ghost btn--sm btn--danger" data-banner-delete="${b.channel_folder_id}"><i class="fa-solid fa-trash"></i></button>
          </div>`
          )
          .join("");

        bindingsList.querySelectorAll("[data-banner-toggle]").forEach((btn) => {
          btn.addEventListener("click", async () => {
            const folderId = Number(btn.dataset.bannerToggle);
            const enabled = btn.dataset.enabled === "1";
            try {
              await bannerToolsApi({ action: "set_binding", channel_folder_id: folderId, enabled });
              await reloadBannerTools();
              showToast(enabled ? "بنر برای پوشه فعال شد" : "بنر غیرفعال شد", { type: "success" });
            } catch (_) {
              showToast("ذخیره ناموفق بود", { type: "error" });
            }
          });
        });
        bindingsList.querySelectorAll("[data-banner-delete]").forEach((btn) => {
          btn.addEventListener("click", async () => {
            const folderId = Number(btn.dataset.bannerDelete);
            try {
              await bannerToolsApi({ action: "delete_binding", channel_folder_id: folderId });
              await reloadBannerTools();
              showToast("پوشه حذف شد", { type: "success" });
            } catch (_) {
              showToast("حذف ناموفق بود", { type: "error" });
            }
          });
        });
      }
    }
  }

  async function openBannerPluginDetail() {
    try {
      const data = state.cache?.bannerTools || (await reloadBannerTools());
      const detail = document.getElementById("screenBannerDetail");
      screens.channels?.classList.remove("is-active");
      document.getElementById("screenHashtagSetDetail")?.classList.remove("is-active");
      detail?.removeAttribute("hidden");
      detail?.classList.add("is-active");
      bottomNav?.setAttribute("hidden", "hidden");
      renderBannerDetail(data);
      showBannerTab(state.bannerTab || "overview");
      tg?.HapticFeedback?.selectionChanged();
    } catch (_) {
      showToast("بارگذاری عکس بنر ناموفق بود", { type: "error" });
    }
  }

  function closeBannerDetail() {
    const detail = document.getElementById("screenBannerDetail");
    detail?.setAttribute("hidden", "");
    detail?.classList.remove("is-active");
    screens.channels?.classList.add("is-active");
    bottomNav?.removeAttribute("hidden");
    state.bannerTab = "overview";
  }

  async function createBannerBindingForFolder(folderId) {
    try {
      await bannerToolsApi({ action: "set_binding", channel_folder_id: folderId, enabled: true });
      await reloadBannerTools();
      showToast("پوشه برای بنر فعال شد", { type: "success" });
      showBannerTab("settings");
    } catch (e) {
      showToast(e?.message === "invalid_channel_folder" ? "پوشه نامعتبر است" : "فعال‌سازی ناموفق بود", {
        type: "error",
      });
    }
  }

  async function syncBannerGroupsFromTelegram() {
    try {
      await bannerToolsApi({ action: "sync_groups" });
      await reloadBannerTools();
      showToast("بیو گروه‌ها بروزرسانی شد", { type: "success" });
    } catch (_) {
      showToast("همگام‌سازی ناموفق بود", { type: "error" });
    }
  }

  async function glassButtonToolsApi(body, query = {}) {
    let path = "glass_button_tools.php";
    const qs = new URLSearchParams(query).toString();
    if (qs) path += "?" + qs;
    return api(path, body ? { method: "POST", body } : undefined);
  }

  async function zapasBotsApi(body, query = {}) {
    let path = "zapas_bots.php";
    const qs = new URLSearchParams(query).toString();
    if (qs) path += "?" + qs;
    return api(path, body ? { method: "POST", body } : undefined);
  }

  async function reloadGlassButtonTools() {
    const data = await glassButtonToolsApi();
    state.cache = state.cache || {};
    state.cache.glassButtonTools = data;
    renderToolsExplorer(state.cache.hashtagTools, state.cache.bannerTools, data, state.cache.zapasTools);
    if (document.getElementById("screenGlassButtonDetail")?.classList.contains("is-active")) {
      renderGlassButtonDetail(data);
    }
    return data;
  }

  async function reloadZapasTools() {
    const data = await zapasBotsApi();
    state.cache = state.cache || {};
    state.cache.zapasTools = data;
    renderToolsExplorer(state.cache.hashtagTools, state.cache.bannerTools, state.cache.glassButtonTools, data);
    if (document.getElementById("screenZapasDetail")?.classList.contains("is-active")) {
      renderZapasDetail(data);
    }
    return data;
  }

  function glassButtonDisplayModeLabel(mode) {
    return mode === "caption_links" ? "لینک در کپشن" : "دکمه شیشه‌ای";
  }

  function glassButtonRowsLabel(rows) {
    return Number(rows) === 2 ? "دو ردیف" : "یک ردیف";
  }

  function getGlassButtonSettingByFolder(folderId) {
    const settings = state.cache?.glassButtonTools?.settings || [];
    return settings.find((s) => Number(s.channel_folder_id) === Number(folderId)) || null;
  }

  function buildGlassConfigItemHtml(setting, isActive) {
    const folderPath = setting.channel_folder_path || "پوشه";
    const modeLabel = glassButtonDisplayModeLabel(setting.display_mode);
    const statusBadge = setting.is_enabled
      ? '<span class="glass-config-item__badge glass-config-item__badge--on">فعال</span>'
      : '<span class="glass-config-item__badge glass-config-item__badge--off">غیرفعال</span>';

    return `
      <button type="button" class="glass-config-item${isActive ? " is-active" : ""}" data-glass-config-folder="${setting.channel_folder_id}">
        <span class="glass-config-item__icon" aria-hidden="true"><i class="fa-solid fa-folder"></i></span>
        <span class="glass-config-item__main">
          <span class="glass-config-item__name">${escapeHtml(folderPath)}</span>
          <span class="glass-config-item__meta">${escapeHtml(setting.button_text || "دریافت محتوا")} · ${escapeHtml(modeLabel)} · ${glassButtonRowsLabel(setting.button_rows)}</span>
        </span>
        ${statusBadge}
        <i class="fa-solid fa-chevron-left glass-config-item__chevron" aria-hidden="true"></i>
      </button>`;
  }

  function renderGlassButtonConfigsList() {
    const settings = state.cache?.glassButtonTools?.settings || [];
    const selectedId = Number(state.glassButtonSelectedFolderId || 0);
    const lists = [
      document.getElementById("glassButtonSettingsList"),
      document.getElementById("glassButtonFoldersList"),
    ];

    const emptyHtml =
      '<p class="channel-empty__sub">هنوز تنظیمی ذخیره نشده — «افزودن» را بزنید.</p>';
    const html = settings.length
      ? settings.map((s) => buildGlassConfigItemHtml(s, Number(s.channel_folder_id) === selectedId)).join("")
      : emptyHtml;

    lists.forEach((list) => {
      if (!list) return;
      list.innerHTML = html;
      if (settings.length) {
        list.querySelectorAll("[data-glass-config-folder]").forEach((btn) => {
          btn.addEventListener("click", () => {
            selectGlassButtonConfig(Number(btn.dataset.glassConfigFolder), { openSettings: true });
            tg?.HapticFeedback?.selectionChanged();
          });
        });
      }
    });

    const selected = selectedId > 0 ? getGlassButtonSettingByFolder(selectedId) : null;
    const folders = state.cache?.channels?.folders || [];
    const activeLabel = document.getElementById("glassButtonActiveConfigLabel");
    if (activeLabel) {
      activeLabel.textContent = selected
        ? `در حال ویرایش: ${selected.channel_folder_path || getFolderDisplayPath(folders, selectedId)}`
        : settings.length
          ? "یک پوشه را برای ویرایش انتخاب کنید"
          : "هنوز پوشه کانالی متصل نشده — «افزودن» را بزنید";
    }
  }

  function renderGlassButtonPreview(setting) {
    const preview = document.getElementById("glassButtonPreviewBody");
    if (!preview) return;

    const current =
      setting ||
      (state.glassButtonSelectedFolderId
        ? getGlassButtonSettingByFolder(state.glassButtonSelectedFolderId)
        : null) ||
      (state.cache?.glassButtonTools?.settings || [])[0] ||
      null;

    if (!current) {
      preview.innerHTML =
        '<p class="channel-empty__sub">پس از افزودن پوشه، پیش‌نمایش دکمه اینجا نمایش داده می‌شود.</p>';
      return;
    }

    const buttonText = current.button_text || "دریافت محتوا";
    const lineText = current.line_text || "📥 مشاهده کردن";
    const rows = Math.max(1, Math.min(2, Number(current.button_rows || 1)));
    const mode = current.display_mode || "inline_buttons";

    if (mode === "caption_links") {
      const lines = Array.from({ length: rows }, () => lineText).join("<br>");
      preview.innerHTML = `<div class="glass-button-preview__caption-links">${lines}</div>`;
      return;
    }

    preview.innerHTML = Array.from(
      { length: rows },
      () =>
        `<span class="glass-button-preview__btn"><span class="glass-button-preview__dot" aria-hidden="true"></span>${escapeHtml(buttonText)}</span>`
    ).join("");
  }

  function loadGlassButtonSettingsEditor(setting, folderId = 0) {
    const folders = state.cache?.channels?.folders || [];
    const folder = Number(folderId || setting?.channel_folder_id || 0);
    const defaults = {
      button_text: "دریافت محتوا",
      line_text: "📥 مشاهده کردن",
      display_mode: "inline_buttons",
      button_rows: 1,
      is_enabled: true,
    };
    const data = setting || defaults;

    setGlassButtonFolderSelection(folder, { silent: true });
    document.getElementById("glassButtonText").value = data.button_text || defaults.button_text;
    document.getElementById("glassButtonLineText").value = data.line_text || defaults.line_text;

    const modeRadio = document.querySelector(
      `input[name="glassButtonDisplayMode"][value="${data.display_mode || defaults.display_mode}"]`
    );
    if (modeRadio) modeRadio.checked = true;

    const rowsRadio = document.querySelector(
      `input[name="glassButtonRows"][value="${String(data.button_rows || defaults.button_rows)}"]`
    );
    if (rowsRadio) rowsRadio.checked = true;

    document.getElementById("glassButtonEnabled").checked = setting ? !!setting.is_enabled : true;

    const deleteBtn = document.getElementById("btnDeleteGlassButton");
    if (deleteBtn) deleteBtn.hidden = !setting;

    const banner = document.getElementById("glassButtonSettingsBanner");
    if (banner) {
      banner.textContent = folder > 0
        ? `پیکربندی: ${setting?.channel_folder_path || getFolderDisplayPath(folders, folder)}`
        : "ابتدا پوشه کانال را انتخاب کنید";
    }

    renderGlassButtonPreview(setting || { ...defaults, ...data, channel_folder_id: folder });
  }

  function setGlassButtonFolderSelection(folderId, options = {}) {
    const id = Number(folderId) || 0;
    const folders = state.cache?.channels?.folders || [];
    const hidden = document.getElementById("glassButtonFolderInput");
    const label = document.getElementById("glassButtonFolderLabel");
    if (hidden) hidden.value = id > 0 ? String(id) : "";
    if (label) {
      label.textContent = id > 0 ? getFolderDisplayPath(folders, id) : "— انتخاب پوشه کانال —";
    }
    if (!options.silent && id <= 0) {
      showToast("پوشه کانال را انتخاب کنید", { type: "warning" });
    }
  }

  function selectGlassButtonConfig(folderId, options = {}) {
    const id = Number(folderId) || 0;
    if (id <= 0) return;
    state.glassButtonSelectedFolderId = id;
    const setting = getGlassButtonSettingByFolder(id);
    if (!setting && options.createIfMissing) {
      loadGlassButtonSettingsEditor(null, id);
      renderGlassButtonConfigsList();
      if (options.openSettings) showGlassButtonTab("settings");
      return;
    }
    loadGlassButtonSettingsEditor(setting, id);
    renderGlassButtonConfigsList();
    if (options.openSettings) showGlassButtonTab("settings");
  }

  function showGlassButtonTab(tab) {
    state.glassButtonTab = tab;
    const panels = {
      overview: document.getElementById("glassButtonPanelOverview"),
      folders: document.getElementById("glassButtonPanelFolders"),
      settings: document.getElementById("glassButtonPanelSettings"),
    };
    Object.entries(panels).forEach(([name, el]) => {
      if (!el) return;
      if (name === tab) {
        el.removeAttribute("hidden");
        el.classList.add("is-active");
      } else {
        el.setAttribute("hidden", "");
        el.classList.remove("is-active");
      }
    });
    document.querySelectorAll("[data-glass-button-tab]").forEach((btn) => {
      btn.classList.toggle("is-active", btn.dataset.glassButtonTab === tab);
    });
    if (tab === "settings" && state.glassButtonSelectedFolderId) {
      const setting = getGlassButtonSettingByFolder(state.glassButtonSelectedFolderId);
      loadGlassButtonSettingsEditor(setting, state.glassButtonSelectedFolderId);
    }
    if (tab === "overview" || tab === "folders") {
      renderGlassButtonPreview();
    }
  }

  function openGlassButtonFolderPicker(mode = "glass_add_folder") {
    const folders = state.cache?.channels?.folders || [];
    if (!folders.length) {
      showToast("ابتدا یک پوشه کانال بسازید", { type: "warning" });
      return;
    }
    openFolderTreePicker({ mode, folders, allowRoot: false });
  }

  function renderGlassButtonDetail(data) {
    const stats = data?.stats || {};
    const settings = data?.settings || [];
    const inlineCount = settings.filter((s) => s.display_mode !== "caption_links").length;
    const captionCount = settings.filter((s) => s.display_mode === "caption_links").length;

    setText(
      "glassButtonDetailMeta",
      `${stats.active_count || 0} پوشه فعال از ${stats.settings_count || 0}`
    );
    setText("glassButtonStatActive", formatNumber(stats.active_count || 0));
    setText("glassButtonStatTotal", formatNumber(stats.settings_count || 0));
    setText("glassButtonStatInline", formatNumber(inlineCount));
    setText("glassButtonStatCaption", formatNumber(captionCount));

    if (!state.glassButtonSelectedFolderId && settings.length) {
      state.glassButtonSelectedFolderId = Number(settings[0].channel_folder_id);
    }

    renderGlassButtonConfigsList();
    const selected = state.glassButtonSelectedFolderId
      ? getGlassButtonSettingByFolder(state.glassButtonSelectedFolderId)
      : null;
    loadGlassButtonSettingsEditor(selected, state.glassButtonSelectedFolderId || 0);
    renderGlassButtonPreview(selected);
  }

  async function openGlassButtonPluginDetail() {
    try {
      const data = state.cache?.glassButtonTools || (await reloadGlassButtonTools());
      const detail = document.getElementById("screenGlassButtonDetail");
      screens.channels?.classList.remove("is-active");
      document.getElementById("screenHashtagSetDetail")?.classList.remove("is-active");
      document.getElementById("screenBannerDetail")?.classList.remove("is-active");
      document.getElementById("screenZapasDetail")?.classList.remove("is-active");
      detail?.removeAttribute("hidden");
      detail?.classList.add("is-active");
      bottomNav?.setAttribute("hidden", "hidden");
      renderGlassButtonDetail(data);
      showGlassButtonTab(state.glassButtonTab || "overview");
      tg?.HapticFeedback?.selectionChanged();
    } catch (_) {
      showToast("بارگذاری دکمه شیشه‌ای ناموفق بود", { type: "error" });
    }
  }

  function closeGlassButtonDetail() {
    const detail = document.getElementById("screenGlassButtonDetail");
    detail?.setAttribute("hidden", "");
    detail?.classList.remove("is-active");
    screens.channels?.classList.add("is-active");
    bottomNav?.removeAttribute("hidden");
  }

  async function saveGlassButtonSettings() {
    const folderId = Number(document.getElementById("glassButtonFolderInput")?.value || 0);
    if (!folderId) {
      showToast("پوشه کانال را انتخاب کنید", { type: "warning" });
      return;
    }
    const displayMode =
      document.querySelector('input[name="glassButtonDisplayMode"]:checked')?.value || "inline_buttons";
    const buttonRows =
      Number(document.querySelector('input[name="glassButtonRows"]:checked')?.value || 1);
    try {
      await glassButtonToolsApi({
        action: "save_settings",
        channel_folder_id: folderId,
        button_text: document.getElementById("glassButtonText")?.value?.trim() || "دریافت محتوا",
        line_text: document.getElementById("glassButtonLineText")?.value?.trim() || "📥 مشاهده کردن",
        display_mode: displayMode,
        button_rows: buttonRows,
        button_style: "green",
        is_enabled: !!document.getElementById("glassButtonEnabled")?.checked,
      });
      state.glassButtonSelectedFolderId = folderId;
      const data = await reloadGlassButtonTools();
      renderGlassButtonDetail(data);
      showGlassButtonTab("overview");
      showToast("تنظیمات دکمه شیشه‌ای ذخیره شد", { type: "success" });
    } catch (_) {
      showToast("ذخیره ناموفق بود", { type: "error" });
    }
  }

  async function deleteGlassButtonSettings() {
    const folderId = Number(state.glassButtonSelectedFolderId || document.getElementById("glassButtonFolderInput")?.value || 0);
    if (!folderId) {
      showToast("پوشه‌ای انتخاب نشده", { type: "warning" });
      return;
    }
    try {
      await glassButtonToolsApi({ action: "delete_settings", channel_folder_id: folderId });
      state.glassButtonSelectedFolderId = 0;
      const data = await reloadGlassButtonTools();
      renderGlassButtonDetail(data);
      showGlassButtonTab("overview");
      showToast("تنظیمات حذف شد", { type: "success" });
    } catch (_) {
      showToast("حذف ناموفق بود", { type: "error" });
    }
  }

  function updateGlassButtonPreviewFromForm() {
    const folderId = Number(document.getElementById("glassButtonFolderInput")?.value || 0);
    const previewSetting = {
      channel_folder_id: folderId,
      button_text: document.getElementById("glassButtonText")?.value?.trim() || "دریافت محتوا",
      line_text: document.getElementById("glassButtonLineText")?.value?.trim() || "📥 مشاهده کردن",
      display_mode:
        document.querySelector('input[name="glassButtonDisplayMode"]:checked')?.value || "inline_buttons",
      button_rows: Number(document.querySelector('input[name="glassButtonRows"]:checked')?.value || 1),
      is_enabled: !!document.getElementById("glassButtonEnabled")?.checked,
    };
    renderGlassButtonPreview(previewSetting);
  }

  function showZapasTab(tab) {
    state.zapasTab = tab;
    const panels = {
      overview: document.getElementById("zapasPanelOverview"),
      bots: document.getElementById("zapasPanelBots"),
      folders: document.getElementById("zapasPanelFolders"),
      history: document.getElementById("zapasPanelHistory"),
    };
    Object.entries(panels).forEach(([name, el]) => {
      if (!el) return;
      if (name === tab) {
        el.removeAttribute("hidden");
        el.classList.add("is-active");
      } else {
        el.setAttribute("hidden", "");
        el.classList.remove("is-active");
      }
    });
    document.querySelectorAll("[data-zapas-tab]").forEach((btn) => {
      btn.classList.toggle("is-active", btn.dataset.zapasTab === tab);
    });
  }

  function renderZapasDetail(data) {
    const stats = data?.stats || {};
    setText("zapasDetailMeta", `${stats.standby_count || 0} آماده · ${stats.replacement_count || 0} جایگزینی`);
    setText("zapasStatStandby", formatNumber(stats.standby_count || 0));
    setText("zapasStatReplacements", formatNumber(stats.replacement_count || 0));
    setText("zapasStatPosts", formatNumber(stats.posts_updated_total || 0));

    const bots = data?.bots || [];
    const botsList = document.getElementById("zapasBotsList");
    if (botsList) {
      botsList.innerHTML = bots.length
        ? bots
            .map(
              (b) => `
          <div class="hashtag-config-item">
            <span class="hashtag-config-item__main">
              <span class="hashtag-config-item__name">${escapeHtml(b.bot_name || "ربات")} @${escapeHtml(b.bot_username || "—")}</span>
              <span class="hashtag-config-item__meta">${b.pool_status === "standby" ? "آماده" : "فعال"}${b.assigned_role ? ` · نقش: ${escapeHtml(b.assigned_role)}` : ""}</span>
            </span>
          </div>`
            )
            .join("")
        : '<p class="channel-empty__sub">هنوز ربات زاپاسی اضافه نشده.</p>';
    }

    const bindings = data?.bindings || [];
    const bindingsList = document.getElementById("zapasBindingsList");
    if (bindingsList) {
      bindingsList.innerHTML = bindings.length
        ? bindings
            .map(
              (b) => `
          <div class="hashtag-config-item">
            <span class="hashtag-config-item__main">
              <span class="hashtag-config-item__name">${escapeHtml(b.channel_folder_path || "پوشه")}</span>
              <span class="hashtag-config-item__meta">${b.is_enabled ? "فعال" : "غیرفعال"}</span>
            </span>
          </div>`
            )
            .join("")
        : '<p class="channel-empty__sub">همه پوشه‌ها فعال هستند (پوشه خاصی انتخاب نشده).</p>';
    }

    const replacements = data?.replacements || [];
    const repList = document.getElementById("zapasReplacementsList");
    if (repList) {
      repList.innerHTML = replacements.length
        ? replacements
            .map(
              (r) => `
          <div class="hashtag-config-item">
            <span class="hashtag-config-item__main">
              <span class="hashtag-config-item__name">@${escapeHtml(r.old_bot_username || "—")} → @${escapeHtml(r.new_bot_username || "—")}</span>
              <span class="hashtag-config-item__meta">${escapeHtml(r.old_bot_type || "")} · ${r.posts_updated || 0} پست ویرایش · ${formatHashtagSetCreatedAt(r.created_at)}</span>
            </span>
          </div>`
            )
            .join("")
        : '<p class="channel-empty__sub">هنوز جایگزینی انجام نشده.</p>';
    }
  }

  async function openZapasPluginDetail() {
    try {
      const data = state.cache?.zapasTools || (await reloadZapasTools());
      const detail = document.getElementById("screenZapasDetail");
      screens.channels?.classList.remove("is-active");
      document.getElementById("screenHashtagSetDetail")?.classList.remove("is-active");
      document.getElementById("screenBannerDetail")?.classList.remove("is-active");
      document.getElementById("screenGlassButtonDetail")?.classList.remove("is-active");
      detail?.removeAttribute("hidden");
      detail?.classList.add("is-active");
      bottomNav?.setAttribute("hidden", "hidden");
      renderZapasDetail(data);
      showZapasTab(state.zapasTab || "overview");
      tg?.HapticFeedback?.selectionChanged();
    } catch (_) {
      showToast("بارگذاری زاپاس ناموفق بود", { type: "error" });
    }
  }

  function closeZapasDetail() {
    const detail = document.getElementById("screenZapasDetail");
    detail?.setAttribute("hidden", "");
    detail?.classList.remove("is-active");
    state.zapasTab = "overview";
    screens.channels?.classList.add("is-active");
    bottomNav?.removeAttribute("hidden");
  }

  async function addZapasBotFromForm() {
    const token = document.getElementById("zapasBotToken")?.value?.trim() || "";
    if (!token) {
      showToast("توکن ربات را وارد کنید", { type: "warning" });
      return;
    }
    try {
      await zapasBotsApi({ action: "add_bot", token });
      document.getElementById("zapasBotToken").value = "";
      await reloadZapasTools();
      showZapasTab("bots");
      showToast("ربات زاپاس اضافه شد", { type: "success" });
    } catch (e) {
      showToast(e?.message === "invalid_token" ? "توکن نامعتبر است" : "افزودن ناموفق بود", { type: "error" });
    }
  }

  async function createZapasBindingForFolder(folderId) {
    try {
      await zapasBotsApi({ action: "set_binding", channel_folder_id: folderId, enabled: true });
      await reloadZapasTools();
      showZapasTab("folders");
      showToast("پوشه برای زاپاس فعال شد", { type: "success" });
    } catch (_) {
      showToast("فعال‌سازی ناموفق بود", { type: "error" });
    }
  }

  async function runZapasHealthCheck() {
    try {
      const data = await zapasBotsApi({ action: "run_check" });
      await reloadZapasTools();
      showToast(`بررسی انجام شد · ${data.replaced_count || 0} جایگزینی`, { type: "success" });
    } catch (_) {
      showToast("بررسی ناموفق بود", { type: "error" });
    }
  }

  async function saveHashtagSetSettings() {
    const setId = state.activeHashtagSetId;
    if (!setId) return;
    const name = document.getElementById("hashtagSetSettingsNameInput")?.value?.trim() || "";
    const folderId = Number(document.getElementById("hashtagSetSettingsChannelFolderInput")?.value || 0);
    const mode = document.querySelector('input[name="hashtagSetSettingsMode"]:checked')?.value || "random";
    const randomCount = Number(document.getElementById("hashtagSetSettingsRandomCountInput")?.value || 5);
    const statusEl = document.getElementById("hashtagSetSettingsStatus");
    if (!name) {
      showToast("نام پیکربندی را وارد کنید", { type: "warning" });
      return;
    }
    if (!folderId) {
      showToast("پوشه کانال را انتخاب کنید", { type: "warning" });
      return;
    }
    const btn = document.getElementById("btnSaveHashtagSetSettings");
    if (btn) btn.disabled = true;
    if (statusEl) {
      statusEl.hidden = true;
      statusEl.textContent = "";
    }
    try {
      await hashtagToolsApi({
        action: "update_set",
        set_id: setId,
        name,
        channel_folder_id: folderId,
        selection_mode: mode,
        random_count: randomCount,
      });
      await reloadHashtagTools();
      const refreshed = await hashtagToolsApi(null, { set_id: setId });
      const set = refreshed.set || state.cache?.hashtagTools?.sets?.find((s) => Number(s.id) === setId);
      if (set) {
        state.cache.hashtagSetDetail = set;
        renderHashtagSetDetail(set);
        renderHashtagSetSettingsEditor(set);
      }
      if (statusEl) {
        statusEl.hidden = false;
        statusEl.textContent = "تنظیمات ذخیره شد";
        statusEl.className = "hint-text hint-text--ok";
      }
      showToast("تنظیمات ذخیره شد", { type: "success" });
    } catch (e) {
      if (statusEl) {
        statusEl.hidden = false;
        statusEl.textContent = "ذخیره ناموفق بود";
        statusEl.className = "hint-text hint-text--error";
      }
      showToast("ذخیره ناموفق بود", { type: "error" });
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  async function saveHashtagTags() {
    const setId = state.activeHashtagSetId;
    if (!setId) {
      showToast("ابتدا یک پیکربندی انتخاب کنید", { type: "warning" });
      return;
    }
    const tags = collectHashtagSettingsTagsFromEditor().filter((t) => t.tag_text && t.tag_text !== "#");
    const statusEl = document.getElementById("hashtagTagsSaveStatus");
    if (!tags.length) {
      showToast("حداقل یک هشتگ معتبر اضافه کنید", { type: "warning" });
      return;
    }
    const btn = document.getElementById("btnSaveHashtagTags");
    if (btn) btn.disabled = true;
    if (statusEl) {
      statusEl.hidden = true;
      statusEl.textContent = "";
    }
    try {
      await hashtagToolsApi({
        action: "update_set",
        set_id: setId,
        tags,
      });
      await reloadHashtagTools();
      const refreshed = await hashtagToolsApi(null, { set_id: setId });
      const set = refreshed.set || state.cache?.hashtagTools?.sets?.find((s) => Number(s.id) === setId);
      if (set) {
        state.cache.hashtagSetDetail = set;
        renderHashtagSetDetail(set);
      }
      if (statusEl) {
        statusEl.hidden = false;
        statusEl.textContent = "هشتگ‌ها ذخیره شد";
        statusEl.className = "hint-text hint-text--ok";
      }
      showToast("هشتگ‌ها ذخیره شد", { type: "success" });
    } catch (e) {
      if (statusEl) {
        statusEl.hidden = false;
        statusEl.textContent = "ذخیره ناموفق بود";
        statusEl.className = "hint-text hint-text--error";
      }
      showToast(e?.message === "tags_required" ? "حداقل یک هشتگ لازم است" : "ذخیره ناموفق بود", {
        type: "error",
      });
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  function initHashtagToolsUi() {
    document.getElementById("btnHashtagAddChannelFolder")?.addEventListener("click", openHashtagAddChannelFolderPicker);
    document.getElementById("hashtagChannelFolderBtn")?.addEventListener("click", openHashtagChannelFolderPicker);
    document.getElementById("hashtagSetSettingsChannelFolderBtn")?.addEventListener(
      "click",
      openHashtagSetSettingsChannelFolderPicker
    );
    document.getElementById("btnAddHashtagTag")?.addEventListener("click", () => {
      state.hashtagWizardTags = state.hashtagWizardTags || [];
      state.hashtagWizardTags.push({ tag_text: "#", rule_mode: "pool", trigger_keywords: [], is_required: false });
      renderHashtagTagsEditor();
    });
    document.getElementById("btnHashtagTagsAddTag")?.addEventListener("click", () => {
      state.hashtagSettingsTags = state.hashtagSettingsTags || [];
      state.hashtagSettingsTags.push({ tag_text: "#", rule_mode: "pool", trigger_keywords: [], is_required: false });
      renderHashtagTagsEditorPanel();
    });
    document.getElementById("btnSaveHashtagSet")?.addEventListener("click", saveHashtagSetWizard);
    document.getElementById("btnSaveHashtagSetSettings")?.addEventListener("click", saveHashtagSetSettings);
    document.getElementById("btnSaveHashtagTags")?.addEventListener("click", saveHashtagTags);
    document.querySelectorAll("[data-close-hashtag-wizard]").forEach((el) => {
      el.addEventListener("click", closeHashtagSetWizard);
    });
    document.querySelectorAll("[data-close-hashtag-folder-modal]").forEach((el) => {
      el.addEventListener("click", () => document.getElementById("hashtagFolderModal")?.setAttribute("hidden", ""));
    });
  }

  async function loadChannelProfileSettings(chatId) {
    try {
      const data = await api(`channel_profile.php?chat_id=${chatId}`);
      const profile = data.profile || {};
      state.cache = state.cache || {};
      state.cache.channelProfiles = state.cache.channelProfiles || {};
      state.cache.channelProfiles[chatId] = profile;
      state.channelProfilePendingPhoto = null;
      state.channelProfileDeletePhoto = false;
      const titleInput = document.getElementById("channelProfileTitleInput");
      if (titleInput) titleInput.value = profile.title || profile.local_title || "";
      renderChannelProfilePhoto(profile);
    } catch (_) {
      setChannelProfileStatus("بارگذاری پروفایل ناموفق بود", "err");
    }
  }

  function renderChannelProfilePhoto(profile) {
    const preview = document.getElementById("channelProfilePhotoPreview");
    const placeholder = document.getElementById("channelProfilePhotoPlaceholder");
    if (!preview) return;
    const pending = state.channelProfilePendingPhoto;
    if (pending?.previewUrl) {
      preview.innerHTML = `<img src="${pending.previewUrl}" alt="">`;
      if (placeholder) placeholder.hidden = true;
      return;
    }
    if (state.channelProfileDeletePhoto) {
      preview.innerHTML = "";
      if (placeholder) {
        placeholder.hidden = false;
        placeholder.textContent = (profile?.title || "C").charAt(0).toUpperCase();
      }
      return;
    }
    if (profile?.photo_url) {
      preview.innerHTML = `<img src="${profile.photo_url}" alt="">`;
      if (placeholder) placeholder.hidden = true;
    } else {
      preview.innerHTML = "";
      if (placeholder) {
        placeholder.hidden = false;
        placeholder.textContent = (profile?.title || "C").charAt(0).toUpperCase();
      }
    }
  }

  function setChannelProfileStatus(msg, type = "") {
    const el = document.getElementById("channelProfileStatus");
    if (!el) return;
    el.textContent = msg || "";
    el.hidden = !msg;
    el.className = `hint-text${type === "err" ? " hint-text--error" : type === "ok" ? " hint-text--ok" : ""}`;
  }

  async function saveChannelProfileSettings() {
    const chatId = state.activeChannelId;
    if (!chatId) return;
    const title = document.getElementById("channelProfileTitleInput")?.value?.trim() || "";
    const payload = { action: "update", chat_id: chatId, title };
    if (state.channelProfilePendingPhoto?.base64) payload.photo_base64 = state.channelProfilePendingPhoto.base64;
    if (state.channelProfileDeletePhoto) payload.delete_photo = true;
    const btn = document.getElementById("btnSaveChannelProfile");
    if (btn) btn.disabled = true;
    setChannelProfileStatus("در حال ذخیره...");
    try {
      const data = await api("channel_profile.php", { method: "POST", body: payload });
      state.cache.channelProfiles = state.cache.channelProfiles || {};
      state.cache.channelProfiles[chatId] = data.profile || {};
      state.channelProfilePendingPhoto = null;
      state.channelProfileDeletePhoto = false;
      renderChannelProfilePhoto(data.profile);
      if (data.profile?.title) {
        setText("detailTitle", data.profile.title);
        if (state.cache?.channelStats?.channel) state.cache.channelStats.channel.title = data.profile.title;
      }
      const updated = data.updated || [];
      setChannelProfileStatus(updated.length ? "پروفایل کانال ذخیره شد" : "ذخیره شد", "ok");
      showToast("پروفایل کانال ذخیره شد", { type: "success" });
      await reloadChannels();
    } catch (e) {
      const msg = String(e?.message || "ذخیره ناموفق بود");
      setChannelProfileStatus(msg, "err");
      showToast(msg, { type: "error" });
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  function initChannelProfileUi() {
    document.getElementById("btnChannelProfilePickPhoto")?.addEventListener("click", () => {
      document.getElementById("channelProfilePhotoInput")?.click();
    });
    document.getElementById("channelProfilePhotoInput")?.addEventListener("change", async (e) => {
      const file = e.target.files?.[0];
      if (!file) return;
      try {
        const base64 = await readFileAsBase64(file);
        const previewUrl = URL.createObjectURL(file);
        state.channelProfilePendingPhoto = { base64, previewUrl };
        state.channelProfileDeletePhoto = false;
        renderChannelProfilePhoto(state.cache?.channelProfiles?.[state.activeChannelId] || {});
      } catch (_) {
        showToast("خواندن عکس ناموفق بود", { type: "error" });
      }
    });
    document.getElementById("btnChannelProfileRemovePhoto")?.addEventListener("click", () => {
      state.channelProfilePendingPhoto = null;
      state.channelProfileDeletePhoto = true;
      renderChannelProfilePhoto(state.cache?.channelProfiles?.[state.activeChannelId] || {});
    });
    document.getElementById("btnSaveChannelProfile")?.addEventListener("click", saveChannelProfileSettings);
  }

  /** @deprecated use openPostSessionDetail */
  async function openPostSessionStatsModal(sessionId) {
    await openPostSessionDetail(sessionId);
  }

  function closePostSessionStatsModal() {
    closePostSessionDetail();
  }

  function initAutoPostExplorerUi() {
    document.getElementById("btnNewAutoPostFolder")?.addEventListener("click", () =>
      openAutoPostFolderModal({ mode: "create" })
    );
    document.getElementById("btnNewPostSession")?.addEventListener("click", openPostSessionModal);
    document.getElementById("postSessionChannelFolderBtn")?.addEventListener("click", openPostSessionChannelFolderPicker);
    document.getElementById("postSessionBotFolderBtn")?.addEventListener("click", openPostSessionBotFolderPicker);
    document.getElementById("btnAutoPostUp")?.addEventListener("click", () => {
      state.openAutoPostFolderId = null;
      renderAutoPost(state.cache?.autoPost || { folders: [], sessions: [] });
    });
    document.querySelectorAll("[data-close-auto-post-folder-modal]").forEach((el) => {
      el.addEventListener("click", closeAutoPostFolderModal);
    });
    document.querySelectorAll("[data-close-post-session-modal]").forEach((el) => {
      el.addEventListener("click", closePostSessionModal);
    });
    document.querySelectorAll("[data-close-post-session-rename]").forEach((el) => {
      el.addEventListener("click", closePostSessionRenameModal);
    });
    document.querySelectorAll("[data-close-post-session-stats]").forEach((el) => {
      el.addEventListener("click", closePostSessionStatsModal);
    });
    document.querySelectorAll("[data-auto-post-folder-context-close]").forEach((el) => {
      el.addEventListener("click", closeAutoPostFolderContextMenu);
    });
    document.querySelectorAll("[data-post-session-context-close]").forEach((el) => {
      el.addEventListener("click", closePostSessionContextMenu);
    });
    document.getElementById("btnSaveAutoPostFolder")?.addEventListener("click", async () => {
      const name = document.getElementById("autoPostFolderNameInput")?.value?.trim() || "";
      if (!name) {
        showToast("نام پوشه را وارد کنید", { type: "warning" });
        return;
      }
      try {
        if (state.autoPostFolderModalMode === "edit" && state.editingAutoPostFolderId) {
          await autoPostApi({
            action: "update_folder",
            folder_id: state.editingAutoPostFolderId,
            name,
            icon: state.selectedAutoPostFolderIcon,
          });
        } else {
          await autoPostApi({
            action: "create_folder",
            name,
            icon: state.selectedAutoPostFolderIcon,
            parent_id: state.openAutoPostFolderId,
          });
        }
        closeAutoPostFolderModal();
        await reloadAutoPost();
        showToast("پوشه ذخیره شد", { type: "success" });
      } catch (e) {
        showToast("ذخیره پوشه ناموفق بود", { type: "error" });
      }
    });
    document.getElementById("btnSavePostSession")?.addEventListener("click", async () => {
      const channelFolderId = Number(document.getElementById("postSessionChannelFolderInput")?.value || 0);
      const botFolderId = Number(document.getElementById("postSessionBotFolderInput")?.value || 0);
      const name = document.getElementById("postSessionNameInput")?.value?.trim() || "";
      if (!channelFolderId) {
        showToast("پوشه کانال را انتخاب کنید", { type: "warning" });
        return;
      }
      if (!botFolderId) {
        showToast("پوشه ربات را انتخاب کنید", { type: "warning" });
        return;
      }
      const btn = document.getElementById("btnSavePostSession");
      if (btn) btn.disabled = true;
      try {
        await autoPostApi({
          action: "create_session",
          channel_folder_id: channelFolderId,
          bot_folder_id: botFolderId,
          name: name || undefined,
          folder_id: state.openAutoPostFolderId,
        });
        closePostSessionModal();
        await reloadAutoPost();
        showToast("سشن پست ساخته شد", { type: "success" });
      } catch (e) {
        const msg =
          e?.message === "channel_folder_not_allowed"
            ? "این پوشه کانال مجاز نیست (فقط اخلاقی / غیراخلاقی)"
            : "ساخت سشن ناموفق بود";
        showToast(msg, { type: "error" });
      } finally {
        if (btn) btn.disabled = false;
      }
    });
    bindModalEnterSubmit("postSessionModal", "btnSavePostSession");
    bindModalEnterSubmit("postSessionRenameModal", "btnSavePostSessionRename");
    bindModalEnterSubmit("autoPostFolderModal", "btnSaveAutoPostFolder");
    document.getElementById("btnSavePostSessionRename")?.addEventListener("click", async () => {
      const sessionId = Number(state.editingPostSessionId || 0);
      const name = document.getElementById("postSessionRenameInput")?.value?.trim() || "";
      if (!sessionId) return;
      if (!name) {
        showToast("نام سشن را وارد کنید", { type: "warning" });
        return;
      }
      const btn = document.getElementById("btnSavePostSessionRename");
      if (btn) btn.disabled = true;
      try {
        await autoPostApi({ action: "update_session", session_id: sessionId, name });
        closePostSessionRenameModal();
        await reloadAutoPost();
        if (state.activePostSessionId === sessionId) {
          setText("postSessionDetailTitle", name);
        }
        showToast("نام سشن ذخیره شد", { type: "success" });
      } catch (e) {
        showToast("تغییر نام ناموفق بود", { type: "error" });
      } finally {
        if (btn) btn.disabled = false;
      }
    });
  }

  async function botFolderApi(body) {
    return api("bot_folders.php", { method: "POST", body });
  }

  async function reloadBots() {
    const data = await api("my_bots.php");
    renderBots(data);
    return data;
  }

  function bindBotExplorerInteractions(list, folders) {
    list.querySelectorAll("[data-bot-folder-id]").forEach((el) => {
      const id = Number(el.dataset.botFolderId);
      bindBotFolderLongPress(el, id, folders);
      el.addEventListener("click", (e) => {
        if (e.target.closest("[data-move-bot-folder]")) return;
        if (el.classList.contains("is-dragging")) return;
        if (
          state.botFolderContextBlockClick ||
          state.activeBotContextFolderId != null ||
          Date.now() < state.botFolderOpenSuppressUntil
        ) {
          return;
        }
        state.openBotFolderId = id;
        persistBotFolderNav(id);
        renderBots(state.cache.bots);
        tg?.HapticFeedback?.selectionChanged();
      });
    });

    list.querySelectorAll("[data-move-bot-folder]").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        showMoveBotFolderPicker(Number(btn.dataset.moveBotFolder), folders);
      });
    });

    list.querySelectorAll("[data-move-bot]").forEach((btn) => {
      btn.addEventListener("click", async (e) => {
        e.stopPropagation();
        const botId = Number(btn.dataset.moveBot);
        showMoveBotToFolderPicker(botId, folders);
      });
    });

    list.querySelectorAll("[data-drag-bot-folder]").forEach((tile) => {
      tile.addEventListener("dragstart", (e) => {
        if (e.target.closest("[data-move-bot-folder]")) {
          e.preventDefault();
          return;
        }
        tile.classList.add("is-dragging");
        const folderId = Number(tile.dataset.dragBotFolder);
        state.botExplorerDragPayload = { type: "folder", id: folderId };
        e.dataTransfer?.setData("text/plain", `bot-folder:${folderId}`);
        e.dataTransfer.effectAllowed = "move";
      });
      tile.addEventListener("dragend", () => {
        tile.classList.remove("is-dragging");
        state.botExplorerDragPayload = null;
      });
    });

    list.querySelectorAll("[data-drag-bot]").forEach((tile) => {
      const botId = Number(tile.dataset.dragBot);
      const bots = state.cache?.bots?.bots || [];
      bindBotLongPress(tile, botId, bots);
      tile.addEventListener("click", (e) => {
        if (e.target.closest("[data-move-bot]")) return;
        if (tile.classList.contains("is-dragging")) return;
        if (state.botContextBlockClick || Date.now() < state.botOpenSuppressUntil) return;
        openBotDetail(botId);
      });
      tile.addEventListener("dragstart", (e) => {
        tile.classList.add("is-dragging");
        const id = tile.dataset.dragBot;
        state.botExplorerDragPayload = { type: "bot", id: Number(id) };
        e.dataTransfer?.setData("text/plain", `bot:${id}`);
        e.dataTransfer.effectAllowed = "move";
      });
      tile.addEventListener("dragend", () => {
        tile.classList.remove("is-dragging");
        state.botExplorerDragPayload = null;
      });
    });

    list.querySelectorAll("[data-drop-bot-folder]").forEach((zone) => {
      zone.addEventListener("dragover", (e) => {
        const payload = state.botExplorerDragPayload;
        const targetFolderId = Number(zone.dataset.dropBotFolder);
        const invalid =
          payload?.type === "folder" && isInvalidFolderMove(folders, payload.id, targetFolderId);
        if (invalid) {
          zone.classList.add("is-drop-invalid");
          zone.classList.remove("is-drop-target");
          return;
        }
        zone.classList.remove("is-drop-invalid");
        e.preventDefault();
        if (e.dataTransfer) e.dataTransfer.dropEffect = "move";
        zone.classList.add("is-drop-target");
      });
      zone.addEventListener("dragleave", () => {
        zone.classList.remove("is-drop-target");
        zone.classList.remove("is-drop-invalid");
      });
      zone.addEventListener("drop", async (e) => {
        e.preventDefault();
        zone.classList.remove("is-drop-target");
        zone.classList.remove("is-drop-invalid");
        const raw = e.dataTransfer?.getData("text/plain") || "";
        const targetFolderId = Number(zone.dataset.dropBotFolder);
        let payload = state.botExplorerDragPayload;
        if (!payload?.id && raw.startsWith("bot-folder:")) {
          payload = { type: "folder", id: Number(raw.slice(11)) };
        } else if (!payload?.id && raw.startsWith("bot:")) {
          payload = { type: "bot", id: Number(raw.slice(4)) };
        } else if (!payload?.id) {
          const legacyBotId = Number(raw);
          payload = legacyBotId ? { type: "bot", id: legacyBotId } : null;
        }
        if (!payload?.id || !targetFolderId) return;

        try {
          if (payload.type === "bot") {
            await botFolderApi({ action: "assign", bot_id: payload.id, folder_id: targetFolderId });
            await reloadBots();
            showToast("ربات منتقل شد", { type: "success" });
          } else if (payload.type === "folder") {
            if (isInvalidFolderMove(folders, payload.id, targetFolderId)) {
              showToast("نمی‌توانید پوشه را داخل خودش یا زیرپوشه‌اش ببرید", { type: "warning" });
              return;
            }
            await botFolderApi({
              action: "move_folder",
              folder_id: payload.id,
              parent_id: targetFolderId,
            });
            if (Number(state.openBotFolderId) === Number(payload.id)) {
              state.openBotFolderId = targetFolderId;
              persistBotFolderNav(targetFolderId);
            }
            await reloadBots();
            showToast("پوشه منتقل شد", { type: "success" });
          }
          tg?.HapticFeedback?.notificationOccurred("success");
        } catch (err) {
          showToast(payload.type === "folder" ? "انتقال پوشه ناموفق بود" : "انتقال ناموفق بود", {
            type: "error",
          });
        }
      });
    });
  }

  function showMoveBotToFolderPicker(botId, folders) {
    if (!folders.length) {
      showToast("اول یک پوشه بسازید", { type: "warning" });
      return;
    }
    openFolderTreePicker({
      mode: "move_bot",
      folderKind: "bot",
      targetId: botId,
      folders,
    });
  }

  function showMoveBotFolderPicker(folderId, folders) {
    const descendants = getFolderDescendantIds(folders, folderId);
    openFolderTreePicker({
      mode: "move_bot_folder",
      folderKind: "bot",
      targetId: folderId,
      folders,
      excludeFolderIds: [Number(folderId), ...descendants],
    });
  }

  function openBotFolderModal(options = {}) {
    const mode = options.mode || "create";
    const folder = options.folder || null;
    const modal = document.getElementById("botFolderModal");
    const input = document.getElementById("botFolderNameInput");
    const picker = document.getElementById("botFolderIconPicker");
    const title = document.getElementById("botFolderModalTitle");
    const saveBtn = document.getElementById("btnSaveBotFolder");
    if (!modal || !picker) return;

    state.botFolderModalMode = mode;
    state.editingBotFolderId = mode === "edit" && folder ? Number(folder.id) : null;
    const icon = folder?.icon || "folder";
    state.selectedBotFolderIcon = icon;
    if (input) input.value = folder?.name || "";
    if (title) title.textContent = mode === "edit" ? "تغییر نام پوشه" : "پوشه جدید";
    if (saveBtn) saveBtn.textContent = mode === "edit" ? "ذخیره تغییرات" : "ذخیره";

    picker.innerHTML = FOLDER_ICONS.map(
      (iconName) => `
      <button type="button" class="icon-picker__btn ${iconName === icon ? "is-active" : ""}" data-bot-icon="${iconName}" aria-label="${iconName}">
        <i class="fa-solid fa-${iconName}"></i>
      </button>`
    ).join("");

    picker.querySelectorAll("[data-bot-icon]").forEach((btn) => {
      btn.addEventListener("click", () => {
        state.selectedBotFolderIcon = btn.dataset.botIcon || "folder";
        picker.querySelectorAll(".is-active").forEach((el) => el.classList.remove("is-active"));
        btn.classList.add("is-active");
      });
    });

    modal.hidden = false;
    input?.focus();
  }

  function closeBotFolderModal() {
    const modal = document.getElementById("botFolderModal");
    if (modal) modal.hidden = true;
    state.botFolderModalMode = "create";
    state.editingBotFolderId = null;
  }

  function getFolderDisplayPath(folders, folderId) {
    return buildFolderPath(folders, folderId)
      .map((folder) => folder.name || "پوشه")
      .join(" / ");
  }

  function populateAddBotChannelFolderSelect() {
    const hidden = document.getElementById("addBotChannelFolderInput");
    const label = document.getElementById("addBotChannelFolderLabel");
    const hint = document.getElementById("addBotChannelFolderHint");
    const saveBtn = document.getElementById("btnSaveAddBot");
    const pickBtn = document.getElementById("addBotChannelFolderBtn");

    const folders = state.cache?.channels?.folders || [];
    const hasFolders = folders.length > 0;

    if (hint) hint.hidden = hasFolders;
    if (saveBtn) saveBtn.disabled = !hasFolders;
    if (pickBtn) pickBtn.disabled = !hasFolders;

    const currentId = Number(hidden?.value || 0);
    if (currentId > 0 && folders.some((f) => Number(f.id) === currentId)) {
      setAddBotChannelFolderSelection(currentId, { silent: true });
    } else {
      if (hidden) hidden.value = "";
      if (label) label.textContent = "— انتخاب پوشه کانال —";
      if (pickBtn) pickBtn.classList.remove("is-selected");
    }

    return folders;
  }

  function setAddBotChannelFolderSelection(folderId, options = {}) {
    const hidden = document.getElementById("addBotChannelFolderInput");
    const label = document.getElementById("addBotChannelFolderLabel");
    const pickBtn = document.getElementById("addBotChannelFolderBtn");
    const folders = state.cache?.channels?.folders || [];
    const id = Number(folderId || 0);

    if (hidden) hidden.value = id > 0 ? String(id) : "";
    if (label) {
      label.textContent = id > 0 ? getFolderDisplayPath(folders, id) : "— انتخاب پوشه کانال —";
    }
    if (pickBtn) pickBtn.classList.toggle("is-selected", id > 0);

    if (!options.silent && id > 0) {
      showToast("پوشه کانال انتخاب شد", { type: "success", duration: 1800 });
    }
  }

  function openAddBotChannelFolderPicker() {
    const folders = state.cache?.channels?.folders || [];
    if (!folders.length) {
      showToast("ابتدا یک پوشه کانال بسازید", { type: "warning" });
      return;
    }
    openFolderTreePicker({
      mode: "add_bot_folder",
      folders,
      allowRoot: false,
    });
  }

  function openAddBotModal() {
    const modal = document.getElementById("addBotModal");
    const tokenInput = document.getElementById("addBotTokenInput");
    setAddBotType("uploader");
    if (tokenInput) tokenInput.value = "";
    const folders = populateAddBotChannelFolderSelect();
    if (modal) modal.hidden = false;
    if (!folders.length) {
      showToast("ابتدا یک پوشه کانال بسازید", { type: "warning" });
      document.getElementById("addBotChannelFolderBtn")?.focus();
      return;
    }
    tokenInput?.focus();
  }

  function closeAddBotModal() {
    const modal = document.getElementById("addBotModal");
    if (modal) modal.hidden = true;
  }

  function initBotExplorerUi() {
    document.getElementById("btnNewBotFolder")?.addEventListener("click", () => openBotFolderModal({ mode: "create" }));
    document.getElementById("btnAddBot")?.addEventListener("click", openAddBotModal);
    document.getElementById("addBotChannelFolderBtn")?.addEventListener("click", openAddBotChannelFolderPicker);
    document.getElementById("folderTreePickerSearch")?.addEventListener("input", (e) => {
      state.folderTreePicker.searchQuery = e.target.value || "";
      const picker = state.folderTreePicker || {};
      let folders =
        picker.folderKind === "bot"
          ? state.cache?.bots?.folders || []
          : state.cache?.channels?.folders || [];
      if (picker.mode === "post_session_channel") {
        const allowedIds = state.cache?.autoPost?.allowed_channel_folder_ids || [];
        folders = getAutoPostAllowedChannelFolders(folders, allowedIds);
      }
      if (picker.mode === "hashtag_channel" || picker.mode === "hashtag_set_settings_channel" || picker.mode === "hashtag_add_channel") {
        /* all channel folders */
      }
      renderFolderTreePickerList(folders);
    });
    document.querySelectorAll("[data-close-bot-folder-details]").forEach((el) => {
      el.addEventListener("click", closeBotFolderDetailsModal);
    });
    document.querySelectorAll("[data-close-bot-folder-modal]").forEach((el) => {
      el.addEventListener("click", closeBotFolderModal);
    });
    document.querySelectorAll("[data-close-add-bot]").forEach((el) => {
      el.addEventListener("click", closeAddBotModal);
    });
    document.querySelectorAll("#addBotTypeTabs [data-bot-type]").forEach((tab) => {
      tab.addEventListener("click", () => setAddBotType(tab.dataset.botType || "uploader"));
    });
    document.querySelectorAll("[data-bot-folder-context-close]").forEach((el) => {
      el.addEventListener("click", closeBotFolderContextMenu);
    });
    document.getElementById("btnBotsUp")?.addEventListener("click", () => {
      const folders = state.cache?.bots?.folders || [];
      if (state.openBotFolderId == null) return;
      const current = folders.find((f) => Number(f.id) === Number(state.openBotFolderId));
      state.openBotFolderId = current ? getFolderParentId(current) : null;
      persistBotFolderNav(state.openBotFolderId);
      renderBots(state.cache?.bots || { bots: [], folders: [] });
    });
    document.getElementById("btnSaveBotFolder")?.addEventListener("click", async () => {
      const name = document.getElementById("botFolderNameInput")?.value?.trim() || "";
      if (!name) {
        showToast("نام پوشه را وارد کنید", { type: "warning" });
        return;
      }
      try {
        if (state.botFolderModalMode === "edit" && state.editingBotFolderId) {
          await botFolderApi({
            action: "update",
            folder_id: state.editingBotFolderId,
            name,
            icon: state.selectedBotFolderIcon,
          });
          closeBotFolderModal();
          await reloadBots();
          showToast("پوشه به‌روز شد", { type: "success" });
        } else {
          await botFolderApi({
            action: "create",
            name,
            icon: state.selectedBotFolderIcon,
            parent_id: state.openBotFolderId,
          });
          closeBotFolderModal();
          await reloadBots();
          showToast("پوشه ساخته شد", { type: "success" });
        }
        tg?.HapticFeedback?.notificationOccurred("success");
      } catch (e) {
        showToast(state.botFolderModalMode === "edit" ? "ذخیره ناموفق بود" : "ساخت پوشه ناموفق بود", { type: "error" });
      }
    });
    document.getElementById("btnSaveAddBot")?.addEventListener("click", async () => {
      const token = document.getElementById("addBotTokenInput")?.value?.trim() || "";
      const type = selectedAddBotType;
      const channelFolderId = Number(document.getElementById("addBotChannelFolderInput")?.value || 0);
      if (!token) {
        showToast("توکن ربات را وارد کنید", { type: "warning" });
        return;
      }
      if (!channelFolderId) {
        showToast("پوشه کانال را انتخاب کنید", { type: "warning" });
        return;
      }
      const btn = document.getElementById("btnSaveAddBot");
      if (btn) btn.disabled = true;
      try {
        const result = await api("create_bot.php", {
          method: "POST",
          body: {
            token,
            type,
            channel_folder_id: channelFolderId,
            bot_folder_id: state.openBotFolderId || undefined,
          },
        });
        closeAddBotModal();
        if (result?.bot?.id && state.openBotFolderId) {
          try {
            await botFolderApi({
              action: "assign",
              bot_id: Number(result.bot.id),
              folder_id: Number(state.openBotFolderId),
            });
          } catch (assignErr) {
            console.warn("bot folder assign failed", assignErr);
          }
        }
        await reloadBots();
        showToast(result.message || "ربات اضافه شد", { type: "success" });
        tg?.HapticFeedback?.notificationOccurred("success");
      } catch (e) {
        const err = e?.message || "";
        if (err.includes("channel_folder")) {
          showToast("پوشه کانال نامعتبر است", { type: "error" });
        } else {
          showToast("توکن نامعتبر است یا webhook تنظیم نشد", { type: "error", duration: 4000 });
        }
      } finally {
        if (btn) btn.disabled = false;
      }
    });

    document.getElementById("btnAddBotForcedJoin")?.addEventListener("click", () => openBotJoinModal("forced"));
    document.getElementById("btnAddBotFakeJoin")?.addEventListener("click", () => openBotJoinModal("fake"));

    document.getElementById("botProfileDescInput")?.addEventListener("input", updateBotProfileCharCounts);
    document.getElementById("botProfileShortDescInput")?.addEventListener("input", updateBotProfileCharCounts);
    document.getElementById("btnBotProfilePhotoPick")?.addEventListener("click", () => {
      document.getElementById("botProfilePhotoInput")?.click();
    });
    document.getElementById("botProfilePhotoInput")?.addEventListener("change", async (e) => {
      const file = e.target.files?.[0];
      if (!file) return;
      if (!file.type.startsWith("image/")) {
        showToast("فقط فایل تصویر مجاز است", { type: "warning" });
        e.target.value = "";
        return;
      }
      if (file.size > 10 * 1024 * 1024) {
        showToast("حجم عکس بیش از ۱۰ مگابایت است", { type: "warning" });
        e.target.value = "";
        return;
      }
      try {
        const base64 = await readFileAsBase64(file);
        const previewUrl = URL.createObjectURL(file);
        state.botProfilePendingPhoto = { base64, previewUrl };
        state.botProfileDeletePhoto = false;
        renderBotProfilePhoto(state.cache?.botProfiles?.[state.activeBotId] || {});
        setBotProfileStatus("عکس انتخاب شد — برای اعمال «ذخیره پروفایل» را بزنید", "ok");
      } catch {
        showToast("خواندن عکس ناموفق بود", { type: "error" });
      }
      e.target.value = "";
    });
    document.getElementById("btnBotProfilePhotoDelete")?.addEventListener("click", () => {
      state.botProfilePendingPhoto = null;
      state.botProfileDeletePhoto = true;
      renderBotProfilePhoto({});
      setBotProfileStatus("عکس برای حذف علامت‌گذاری شد — «ذخیره پروفایل» را بزنید", "ok");
    });
    document.getElementById("btnSaveBotProfile")?.addEventListener("click", saveBotProfile);
    document.getElementById("botProfileEditor")?.addEventListener("keydown", (e) => {
      if (e.key !== "Enter" || e.isComposing || e.shiftKey || e.ctrlKey || e.altKey || e.metaKey) return;
      const tag = e.target?.tagName;
      if (tag === "TEXTAREA") return;
      e.preventDefault();
      saveBotProfile();
    });
    document.querySelectorAll("[data-close-bot-join]").forEach((el) => {
      el.addEventListener("click", closeBotJoinModal);
    });
    document.querySelectorAll("[data-close-bot-folder-join]").forEach((el) => {
      el.addEventListener("click", closeBotFolderJoinModal);
    });
    document.querySelectorAll("[data-close-bot-folder-join-manage]").forEach((el) => {
      el.addEventListener("click", closeBotFolderJoinManageModal);
    });
    document.getElementById("btnFolderAddForcedJoin")?.addEventListener("click", () => {
      const folderId = state.botFolderJoinManageFolderId;
      if (!folderId) return;
      openBotFolderJoinModal(folderId, "forced");
    });
    document.getElementById("btnFolderAddFakeJoin")?.addEventListener("click", () => {
      const folderId = state.botFolderJoinManageFolderId;
      if (!folderId) return;
      openBotFolderJoinModal(folderId, "fake");
    });
    document.querySelectorAll("[data-close-rename-bot]").forEach((el) => {
      el.addEventListener("click", closeRenameBotModal);
    });
    document.querySelectorAll("[data-bot-context-close]").forEach((el) => {
      el.addEventListener("click", closeBotContextMenu);
    });
    document.getElementById("btnSaveBotJoin")?.addEventListener("click", async () => {
      if (!state.activeBotId) return;
      const type = document.getElementById("botJoinModalType")?.value || "forced";
      const channelId = document.getElementById("botJoinChannelIdInput")?.value?.trim() || "";
      const link = readJoinLinkFromModal("botJoin", type);
      if (!link) {
        showToast(type === "fake" ? "نام کانال یا یوزرنیم را وارد کنید" : "لینک عضویت را وارد کنید", {
          type: "warning",
        });
        return;
      }
      if (type !== "fake" && !channelId) {
        showToast("آیدی کانال را وارد کنید", { type: "warning" });
        return;
      }
      try {
        await botJoinApi({
          action: type === "fake" ? "add_fake" : "add_forced",
          bot_id: state.activeBotId,
          channel_id: channelId,
          link,
        });
        closeBotJoinModal();
        await loadBotJoinSettings(state.activeBotId);
        showToast(type === "fake" ? "جوین فیک اضافه شد" : "جوین اجباری اضافه شد", { type: "success" });
      } catch (e) {
        showToast(joinErrorMessage(e), { type: "error" });
      }
    });
    document.getElementById("btnSaveBotFolderJoin")?.addEventListener("click", async () => {
      const folderId = state.botFolderJoinFolderId;
      if (!folderId) return;
      const type = document.getElementById("botFolderJoinModalType")?.value || "forced";
      const channelId = document.getElementById("botFolderJoinChannelIdInput")?.value?.trim() || "";
      const link = readJoinLinkFromModal("botFolderJoin", type);
      if (!link) {
        showToast(type === "fake" ? "نام کانال یا یوزرنیم را وارد کنید" : "لینک عضویت را وارد کنید", {
          type: "warning",
        });
        return;
      }
      if (type !== "fake" && !channelId) {
        showToast("آیدی کانال را وارد کنید", { type: "warning" });
        return;
      }
      try {
        await botJoinApi({
          action: type === "fake" ? "folder_add_fake" : "folder_add_forced",
          folder_id: folderId,
          channel_id: channelId,
          link,
        });
        closeBotFolderJoinModal();
        if (state.botFolderJoinManageFolderId === folderId) {
          await loadFolderJoinSettings(folderId);
        }
        showToast("برای همه ربات‌های پوشه اعمال شد", { type: "success" });
        tg?.HapticFeedback?.notificationOccurred("success");
      } catch (e) {
        showToast(joinErrorMessage(e), { type: "error" });
      }
    });
    document.getElementById("btnSaveRenameBot")?.addEventListener("click", async () => {
      const botId = state.editingRenameBotId;
      const name = document.getElementById("renameBotNameInput")?.value?.trim() || "";
      if (!botId || !name) {
        showToast("نام ربات را وارد کنید", { type: "warning" });
        return;
      }
      try {
        await api("update_bot.php", { method: "POST", body: { bot_id: botId, name } });
        closeRenameBotModal();
        await reloadBots();
        if (state.activeBotId === botId) {
          await openBotDetail(botId);
        }
        showToast("نام ربات به‌روز شد", { type: "success" });
      } catch (e) {
        showToast("ذخیره ناموفق بود", { type: "error" });
      }
    });
  }

  function destroyDashboardCharts() {
    Object.values(state.dashboardCharts).forEach((chart) => chart?.destroy?.());
    state.dashboardCharts = {};
  }

  function computeDashboardSignature(data) {
    const dash = getDashboardPayload(data) || {};
    const channels = dash.channels || [];
    const totals = dash.totals || {};
    const joinsHourly = dash.joins_hourly || [];
    const membersHourly = dash.members_hourly || [];
    const folders = getDashboardEligibleFolders(data.folders || [], data.promo_folder_id);
    return JSON.stringify({
      mc: totals.member_count,
      n: channels.length,
      jh: joinsHourly.map((p) => p.count),
      mh: membersHourly.map((p) => p.count),
      folders: folders.map((f) => [f.id, f.name, f.channel_count]),
      top: [...channels]
        .sort((a, b) => (b.member_count ?? 0) - (a.member_count ?? 0))
        .slice(0, 6)
        .map((c) => [c.chat_id, c.member_count, c.title]),
    });
  }

  function buildMembersFolderSelectOptions(folders, promoFolderId) {
    const eligibleFolders = getDashboardEligibleFolders(folders, promoFolderId);
    const active = String(state.dashboardMembersFolderId ?? "all");
    const options = [
      `<option value="all"${active === "all" ? " selected" : ""}>همه پوشه‌ها</option>`,
    ];
    eligibleFolders.forEach((folder) => {
      const id = String(folder.id);
      const label = escapeHtml(folder.name || "پوشه");
      options.push(
        `<option value="${escapeHtml(id)}"${active === id ? " selected" : ""}>${label}</option>`
      );
    });
    return options.join("");
  }

  function syncDashboardMembersFolderSelect(folders, promoFolderId) {
    const select = document.getElementById("dashMembersFolderSelect");
    if (!select) return;
    const eligibleFolders = getDashboardEligibleFolders(folders, promoFolderId);
    const current = String(state.dashboardMembersFolderId ?? "all");
    select.innerHTML = buildMembersFolderSelectOptions(folders, promoFolderId);
    const hasFolder = eligibleFolders.some((f) => String(f.id) === current);
    select.value = current === "all" || hasFolder ? current : "all";
    if (select.value !== current) {
      state.dashboardMembersFolderId = select.value;
    }
    select.dataset.bound = "";
    bindDashboardMembersFolderSelect();
  }

  function bindDashboardMembersFolderSelect() {
    const select = document.getElementById("dashMembersFolderSelect");
    if (!select || select.dataset.bound === "1") return;
    select.dataset.bound = "1";
    select.addEventListener("change", () => {
      const folderId = select.value || "all";
      state.dashboardMembersFolderId = folderId;
      void loadDashboardMembersTrend(folderId);
    });
  }

  function buildDashboardOverviewHtml(folders) {
    return `
      <div class="dash-kpis">
        <article class="dash-kpi">
          <span class="dash-kpi__label">کانال‌های فعال</span>
          <strong class="dash-kpi__value" id="dashKpiChannels">—</strong>
        </article>
        <article class="dash-kpi dash-kpi--accent">
          <span class="dash-kpi__label">مجموع اعضا</span>
          <strong class="dash-kpi__value" id="dashKpiMembers">—</strong>
        </article>
        <article class="dash-kpi">
          <span class="dash-kpi__label">عضویت · ۱ ساعت</span>
          <strong class="dash-kpi__value" id="dashKpiJoins1h">—</strong>
        </article>
        <article class="dash-kpi">
          <span class="dash-kpi__label">عضویت · ۲۴ ساعت</span>
          <strong class="dash-kpi__value" id="dashKpiJoins24h">—</strong>
        </article>
      </div>
      <div class="dash-charts">
        <section class="dash-chart-card dash-chart-card--wide">
          <header class="dash-chart-card__head"><meta charset="utf-8">
            <h4>روند عضویت اخیر</h4>
            <span id="dashJoinsRange" class="dash-chart-card__sub">۲۴ ساعت گذشته · پوشه‌های فعال</span>
          </header>
          <div class="dash-chart-card__canvas chart-wrap chart-wrap--dash-line">
            <canvas id="dashJoinsChart" aria-label="نمودار ساعتی عضویت ۲۴ ساعته"></canvas>
          </div>
        </section>
        <section class="dash-chart-card dash-chart-card--wide dash-chart-card--members-trend">
          <header class="dash-chart-card__head dash-chart-card__head--members-trend">
            <div class="dash-chart-card__head-main">
              <h4>روند مجموع اعضا</h4>
              <span id="dashMembersTrendRange" class="dash-chart-card__sub">۲۴ ساعت گذشته · همه پوشه‌ها</span>
            </div>
            <label class="dash-folder-select-wrap" for="dashMembersFolderSelect">
              <span class="dash-folder-select__label"><i class="fa-solid fa-folder-tree" aria-hidden="true"></i> پوشه</span>
              <select id="dashMembersFolderSelect" class="dash-folder-select" aria-label="انتخاب پوشه برای نمودار اعضا">
                ${buildMembersFolderSelectOptions(folders)}
              </select>
            </label>
          </header>
          <div class="dash-chart-card__canvas chart-wrap chart-wrap--dash-line">
            <canvas id="dashMembersTrendChart" aria-label="نمودار ساعتی مجموع اعضا"></canvas>
          </div>
        </section>
        <section class="dash-chart-card">
          <header class="dash-chart-card__head">
            <h4>توزیع اعضا</h4>
            <span class="dash-chart-card__sub">۵ کانال با بیشترین عضو</span>
          </header>
          <div class="dash-chart-card__canvas chart-wrap chart-wrap--dash-donut">
            <canvas id="dashMembersChart" aria-label="نمودار سهم اعضا"></canvas>
          </div>
        </section>
      </div>
      <p class="dash-analytics__note">
        <i class="fa-solid fa-circle-info"></i>
        فقط کانال‌های داخل پوشه (بدون تبلیغات و بدون صفحه اصلی) · جزئیات در تب «کانال‌ها»
      </p>`;
  }

  function ensureDashboardFolderPickerBound() {
    bindDashboardMembersFolderSelect();
  }

  function membersTrendFolderLabel(folderId) {
    if (folderId === "all" || folderId == null || folderId === "") {
      return "همه پوشه‌ها";
    }
    const match = (state.dashboardFolders || []).find((f) => String(f.id) === String(folderId));
    return match?.name || "پوشه";
  }

  function updateDashboardKpiDom(channels, totals) {
    setText("dashKpiChannels", formatNumber(channels.length));
    setText("dashKpiMembers", formatNumber(totals.member_count ?? 0));
    setText("dashKpiJoins1h", formatNumber(totals.joins_1h ?? 0));
    setText("dashKpiJoins24h", formatNumber(totals.joins_24h ?? 0));
  }

  function refreshDashboardKpisIfPresent(data) {
    if (!state.dashboardBuilt) return;
    const dash = getDashboardPayload(data);
    if (!dash) return;
    const channels = dash.channels || [];
    if (!channels.length) return;
    const totals = dash.totals || {};
    updateDashboardKpiDom(channels, totals);
  }

  function updateDashboardLineChart(chart, hourly) {
    if (!chart) return;
    const series = buildHourlySeries(hourly);
    chart.data.labels = series.map((p) => formatHourLabel(p.hour));
    chart.data.datasets[0].data = series.map((p) => p.count ?? 0);
    chart.$hourlySeries = series;
    chart.update("none");
  }

  function updateDashboardDoughnutChart(chart, labels, values) {
    if (!chart) return;
    chart.data.labels = labels.length ? labels : ["بدون داده"];
    chart.data.datasets[0].data = values.length ? values : [1];
    chart.data.datasets[0].backgroundColor = DASH_CHART_PALETTE.slice(0, labels.length || 1);
    chart.update("none");
  }

  function buildDashboardDonutSeries(channels, totals) {
    const sorted = [...channels]
      .filter((c) => c.member_count != null && c.member_count > 0)
      .sort((a, b) => (b.member_count ?? 0) - (a.member_count ?? 0));

    const top = sorted.slice(0, 5);
    const topSum = top.reduce((s, c) => s + (c.member_count ?? 0), 0);
    const allSum = totals.member_count ?? topSum;
    const other = Math.max(0, allSum - topSum);

    const labels = top.map((c) => {
      const t = c.title || "کانال";
      return t.length > 14 ? `${t.slice(0, 12)}…` : t;
    });
    const values = top.map((c) => c.member_count ?? 0);
    if (other > 0 && sorted.length > 5) {
      labels.push("سایر");
      values.push(other);
    }
    return { labels, values, allSum };
  }

  function computeFolderMembersHourlyEstimate(folderKey) {
    const allSeries = buildHourlySeries(state.cacheMembersHourly || []);
    if (!allSeries.length) return [];
    const dash = getDashboardPayload(state.cache?.channels) || {};
    const channels = dash.channels || [];
    const folderChannels = channels.filter((c) => String(c.folder_id ?? "") === String(folderKey));
    const folderSum = folderChannels.reduce((s, c) => s + (Number(c.member_count) || 0), 0);
    const allSum = Number(dash.totals?.member_count) || 0;
    if (folderSum <= 0 || allSum <= 0) {
      return allSeries.map((p) => ({ hour: p.hour, count: folderSum > 0 ? folderSum : 0 }));
    }
    const lastIdx = allSeries.length - 1;
    const ratio = folderSum / allSum;
    return allSeries.map((p, i) => {
      if (i === lastIdx) {
        return { hour: p.hour, count: folderSum };
      }
      return { hour: p.hour, count: Math.round((p.count ?? 0) * ratio) };
    });
  }

  async function fetchMembersHourlyForFolder(folderKey) {
    const id = encodeURIComponent(folderKey);
    const endpoints = [
      `dashboard_members.php?folder_id=${id}`,
      `channels.php?action=folder_members&folder_id=${id}`,
    ];
    let lastError = null;
    for (const path of endpoints) {
      try {
        return await api(path);
      } catch (e) {
        lastError = e;
      }
    }
    throw lastError || new Error("request_failed");
  }

  async function loadDashboardMembersTrend(folderId) {
    const folderKey = folderId == null || folderId === "" ? "all" : String(folderId);
    const membersRangeEl = document.getElementById("dashMembersTrendRange");
    const label = membersTrendFolderLabel(folderKey);
    if (membersRangeEl) {
      membersRangeEl.textContent = `در حال بارگذاری… · ${label}`;
    }

    state.dashboardMembersTrendLoading = true;
    let usedEstimate = false;
    try {
      let hourly;
      let range;
      if (folderKey === "all") {
        hourly = state.cacheMembersHourly || [];
        range = state.cacheMembersRange;
      } else {
        let res;
        try {
          res = await fetchMembersHourlyForFolder(folderKey);
        } catch (apiError) {
          const estimate = computeFolderMembersHourlyEstimate(folderKey);
          if (!estimate.length) {
            throw apiError;
          }
          hourly = estimate;
          range = state.cacheMembersRange;
          usedEstimate = true;
        }
        if (!usedEstimate) {
          hourly = res.members_hourly || [];
          range = res.members_range || null;
        }
      }
      if (membersRangeEl) {
        const estimateNote = usedEstimate ? " · تخمینی" : "";
        membersRangeEl.textContent = `${formatChartRange(range)} · ${label}${estimateNote}`;
      }
      const canvas = document.getElementById("dashMembersTrendChart");
      if (!canvas || typeof Chart === "undefined") return;
      const options = {
        borderColor: "#0ea5e9",
        fillTop: "rgba(14, 165, 233, 0.26)",
        fillBottom: "rgba(14, 165, 233, 0.02)",
        tooltipLabel: "مجموع اعضا",
        valueSuffix: " عضو",
        beginAtZero: false,
      };
      if (state.dashboardCharts.membersTrend) {
        updateDashboardLineChart(state.dashboardCharts.membersTrend, hourly);
      } else {
        const chart = createDashboardLineChart(canvas, hourly, options);
        state.dashboardCharts.membersTrend = chart;
      }
    } catch (e) {
      console.warn("dashboard members trend failed", e);
      if (membersRangeEl) {
        membersRangeEl.textContent = `خطا در بارگذاری · ${label}`;
      }
    } finally {
      state.dashboardMembersTrendLoading = false;
    }
  }

  function applyDashboardDataUpdate(channels, totals, joinsHourly, joinsRange, membersHourly, membersRange, folders, promoFolderId) {
    state.cacheJoinsHourly = joinsHourly;
    state.cacheJoinsRange = joinsRange;
    state.cacheMembersHourly = membersHourly;
    state.cacheMembersRange = membersRange;
    state.dashboardFolders = getDashboardEligibleFolders(folders, promoFolderId);

    updateDashboardKpiDom(channels, totals);

    const rangeEl = document.getElementById("dashJoinsRange");
    if (rangeEl) {
      rangeEl.textContent = `${formatChartRange(joinsRange)} · پوشه‌های فعال`;
    }

    syncDashboardMembersFolderSelect(folders, promoFolderId);

    if (typeof Chart === "undefined") return;

    const joinsCtx = document.getElementById("dashJoinsChart");
    if (joinsCtx) {
      if (state.dashboardCharts.joins) {
        updateDashboardLineChart(state.dashboardCharts.joins, joinsHourly);
      } else {
        state.dashboardCharts.joins = createDashboardLineChart(joinsCtx, joinsHourly, {
          tooltipLabel: "عضویت",
          valueSuffix: " عضویت",
          beginAtZero: true,
        });
      }
    }

    const { labels, values, allSum } = buildDashboardDonutSeries(channels, totals);
    const membersCtx = document.getElementById("dashMembersChart");
    if (membersCtx) {
      if (state.dashboardCharts.members) {
        updateDashboardDoughnutChart(state.dashboardCharts.members, labels, values);
      } else {
        state.dashboardCharts.members = createDashboardDoughnutChart(
          membersCtx,
          labels,
          values.length ? values : [1],
          allSum
        );
      }
    }

    const folderKey = String(state.dashboardMembersFolderId ?? "all");
    if (folderKey === "all") {
      const membersRangeEl = document.getElementById("dashMembersTrendRange");
      if (membersRangeEl) {
        membersRangeEl.textContent = `${formatChartRange(membersRange)} · همه کانال‌ها`;
      }
      const membersTrendCtx = document.getElementById("dashMembersTrendChart");
      if (membersTrendCtx) {
        if (state.dashboardCharts.membersTrend) {
          updateDashboardLineChart(state.dashboardCharts.membersTrend, membersHourly);
        } else {
          state.dashboardCharts.membersTrend = createDashboardLineChart(membersTrendCtx, membersHourly, {
            borderColor: "#0ea5e9",
            fillTop: "rgba(14, 165, 233, 0.26)",
            fillBottom: "rgba(14, 165, 233, 0.02)",
            tooltipLabel: "مجموع اعضا",
            valueSuffix: " عضو",
            beginAtZero: false,
          });
        }
      }
    } else if (!state.dashboardMembersTrendLoading) {
      void loadDashboardMembersTrend(folderKey);
    }
  }

  function renderDashboardChannelsOverview(data) {
    const root = document.getElementById("dashboardChannelsOverview");
    if (!root) return;

    const dash = getDashboardPayload(data) || {};
    const channels = dash.channels || [];
    const folders = data.folders ?? state.cache?.channels?.folders ?? [];
    const promoFolderId = data.promo_folder_id ?? dash.promo_folder_id ?? null;
    const totals = dash.totals || {};
    const joinsHourly = dash.joins_hourly || [];
    const joinsRange = dash.joins_range || null;
    const membersHourly = dash.members_hourly || [];
    const membersRange = dash.members_range || null;

    if (!channels.length) {
      destroyDashboardCharts();
      state.dashboardBuilt = false;
      state.dashboardDataSig = "";
      root.innerHTML = `
        <div class="dash-analytics__empty">
          <span class="dash-analytics__empty-icon"><i class="fa-solid fa-chart-pie"></i></span>
          <p>هنوز کانالی در پوشه‌ها برای نمایش آمار نیست</p>
          <p class="hint-text">کانال‌های تبلیغات و کانال‌های بدون پوشه در این آمار نیستند.</p>
          <button type="button" class="btn btn--ghost btn--sm" data-go="channels">رفتن به کانال‌ها</button>
        </div>`;
      renderHomeQuickStats();
      return;
    }

    const sig = computeDashboardSignature(data);

    if (!state.dashboardBuilt || !root.querySelector(".dash-kpis") || !root.querySelector("#dashMembersFolderSelect")) {
      destroyDashboardCharts();
      root.innerHTML = buildDashboardOverviewHtml(folders);
      state.dashboardBuilt = true;
      ensureDashboardFolderPickerBound();
      state.dashboardDataSig = sig;
      requestAnimationFrame(() => {
        applyDashboardDataUpdate(
          channels,
          totals,
          joinsHourly,
          joinsRange,
          membersHourly,
          membersRange,
          folders,
          promoFolderId
        );
      });
      renderHomeQuickStats();
      return;
    }

    if (sig === state.dashboardDataSig) {
      updateDashboardKpiDom(channels, totals);
      syncDashboardMembersFolderSelect(folders, promoFolderId);
      renderHomeQuickStats();
      return;
    }

    state.dashboardDataSig = sig;
    applyDashboardDataUpdate(
      channels,
      totals,
      joinsHourly,
      joinsRange,
      membersHourly,
      membersRange,
      folders,
      promoFolderId
    );
    renderHomeQuickStats();
  }

  function formatHourLabel(isoHour) {
    const date = new Date(String(isoHour).replace(" ", "T"));
    if (Number.isNaN(date.getTime())) return "";
    return new Intl.DateTimeFormat("fa-IR", { hour: "2-digit", minute: "2-digit" }).format(date);
  }

  function formatChartRange(range) {
    if (!range?.from || !range?.to) return "۲۴ ساعت گذشته";
    const from = new Date(String(range.from).replace(" ", "T"));
    const to = new Date(String(range.to).replace(" ", "T"));
    if (Number.isNaN(from.getTime()) || Number.isNaN(to.getTime())) return "۲۴ ساعت گذشته";
    const fmt = new Intl.DateTimeFormat("fa-IR", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });
    return `${fmt.format(from)} — ${fmt.format(to)}`;
  }

  function buildHourlySeries(hourly) {
    if (hourly.length) {
      return hourly;
    }
    return Array.from({ length: 24 }, (_, i) => {
      const d = new Date();
      d.setMinutes(0, 0, 0);
      d.setHours(d.getHours() - (23 - i));
      const pad = (n) => String(n).padStart(2, "0");
      const hour = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:00:00`;
      return { hour, count: 0 };
    });
  }

  function createDashboardLineChart(canvas, hourly, options = {}) {
    const {
      borderColor = "#3b82f6",
      fillTop = "rgba(59, 130, 246, 0.28)",
      fillBottom = "rgba(59, 130, 246, 0.02)",
      tooltipLabel = "مقدار",
      valueSuffix = "",
      beginAtZero = true,
    } = options;

    const series = buildHourlySeries(hourly);
    const labels = series.map((p) => formatHourLabel(p.hour));
    const values = series.map((p) => p.count ?? 0);

    const ctx = canvas.getContext("2d");
    const lineFill = ctx?.createLinearGradient(0, 0, 0, 240);
    if (lineFill) {
      lineFill.addColorStop(0, fillTop);
      lineFill.addColorStop(1, fillBottom);
    }

    const chart = new Chart(canvas, {
      type: "line",
      data: {
        labels,
        datasets: [
          {
            label: tooltipLabel,
            data: values,
            borderColor,
            backgroundColor: lineFill || fillTop,
            borderWidth: 2.5,
            tension: 0.35,
            fill: true,
            pointRadius: 0,
            pointHoverRadius: 4,
            pointHitRadius: 12,
            pointBackgroundColor: borderColor,
            pointBorderColor: "#ffffff",
            pointBorderWidth: 2,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        interaction: { mode: "index", intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            ...dashboardChartTooltip(),
            callbacks: {
              title(items) {
                const chartRef = items[0]?.chart;
                const hourlySeries = chartRef?.$hourlySeries || series;
                const i = items[0]?.dataIndex ?? 0;
                return hourlySeries[i] ? formatHourLabel(hourlySeries[i].hour) : "";
              },
              label(ctx) {
                return ` ${formatNumber(ctx.parsed.y ?? 0)}${valueSuffix}`;
              },
            },
          },
        },
        scales: dashboardChartScales(beginAtZero),
      },
    });
    chart.$hourlySeries = series;
    return chart;
  }

  const DASH_CHART_PALETTE = ["#3b82f6", "#0ea5e9", "#6366f1", "#14b8a6", "#f59e0b", "#94a3b8"];

  function dashboardChartTooltip() {
    return {
      backgroundColor: "#1e293b",
      titleFont: { family: "Peyda, Tahoma", size: 11 },
      bodyFont: { family: "Peyda, Tahoma", size: 12, weight: "600" },
      padding: 10,
      cornerRadius: 8,
    };
  }

  function dashboardChartScales(beginAtZero = true) {
    return {
      x: {
        grid: { display: false },
        ticks: {
          color: "#94a3b8",
          maxTicksLimit: 6,
          maxRotation: 0,
          font: { family: "Peyda, Tahoma", size: 10, weight: "600" },
        },
        border: { display: false },
      },
      y: {
        beginAtZero,
        ticks: {
          color: "#94a3b8",
          font: { family: "Peyda, Tahoma", size: 10 },
          precision: 0,
          maxTicksLimit: 5,
        },
        grid: { color: "rgba(15, 23, 42, 0.07)", drawBorder: false },
        border: { display: false },
      },
    };
  }

  function createDashboardBarChart(canvas, labels, values, options = {}) {
    const {
      tooltipLabel = "مقدار",
      valueSuffix = "",
      barColor = "rgba(59, 130, 246, 0.5)",
    } = options;

    return new Chart(canvas, {
      type: "bar",
      data: {
        labels: labels.length ? labels : ["—"],
        datasets: [
          {
            label: tooltipLabel,
            data: values.length ? values : [0],
            backgroundColor: barColor,
            borderRadius: 6,
            maxBarThickness: 32,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            ...dashboardChartTooltip(),
            callbacks: {
              label(ctx) {
                return ` ${formatNumber(ctx.parsed.y ?? 0)}${valueSuffix}`;
              },
            },
          },
        },
        scales: dashboardChartScales(true),
      },
    });
  }

  function createDashboardDoughnutChart(canvas, labels, values, totalForPct) {
    const allSum = totalForPct ?? values.reduce((s, v) => s + v, 0);

    return new Chart(canvas, {
      type: "doughnut",
      data: {
        labels: labels.length ? labels : ["بدون داده"],
        datasets: [
          {
            data: values.length ? values : [1],
            backgroundColor: DASH_CHART_PALETTE.slice(0, labels.length || 1),
            borderWidth: 2,
            borderColor: "#ffffff",
            hoverOffset: 6,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        cutout: "68%",
        plugins: {
          legend: {
            position: "bottom",
            labels: {
              color: "#64748b",
              boxWidth: 10,
              boxHeight: 10,
              padding: 12,
              font: { family: "Peyda, Tahoma", size: 10 },
            },
          },
          tooltip: {
            ...dashboardChartTooltip(),
            callbacks: {
              label(ctx) {
                const v = ctx.parsed ?? 0;
                const pct = allSum > 0 ? ((v / allSum) * 100).toFixed(1) : 0;
                return ` ${formatNumber(v)} (${pct}٪)`;
              },
            },
          },
        },
      },
    });
  }

  function destroyCharts() {
    Object.values(state.charts).forEach((chart) => chart?.destroy?.());
    state.charts = {};
  }

  function showChannelTab(tab) {
    state.channelTab = tab;
    const panels = {
      overview: document.getElementById("channelPanelOverview"),
      stats: document.getElementById("channelPanelStats"),
      invites: document.getElementById("channelPanelInvites"),
    };

    Object.entries(panels).forEach(([name, el]) => {
      if (!el) return;
      if (name === tab) {
        el.removeAttribute("hidden");
        el.classList.add("is-active");
      } else {
        el.setAttribute("hidden", "");
        el.classList.remove("is-active");
      }
    });

    document.querySelectorAll("[data-channel-tab]").forEach((btn) => {
      btn.classList.toggle("is-active", btn.dataset.channelTab === tab);
    });

    if (tab === "stats" && state.cache?.channelStats) {
      requestAnimationFrame(() => renderChannelCharts(state.cache.channelStats));
    }
    if (tab === "overview" && state.activeChannelId) {
      loadChannelProfileSettings(state.activeChannelId).catch(() => {});
    }
  }

  async function loadInviteLinks(chatId) {
    const list = document.getElementById("inviteLinksList");
    try {
      const data = await api(`channel_invites.php?chat_id=${chatId}`);
      renderInviteLinks(data.links || []);
      return data;
    } catch (error) {
      console.error(error);
      if (list) {
        list.innerHTML = '<p class="channel-empty__sub">خطا در بارگذاری لینک‌ها. دوباره تلاش کنید.</p>';
      }
      throw error;
    }
  }

  function renderInviteLinks(links) {
    const list = document.getElementById("inviteLinksList");
    if (!list) return;

    if (!links.length) {
      list.innerHTML = '<p class="channel-empty__sub">هنوز لینکی ساخته نشده</p>';
      return;
    }

    list.innerHTML = links
      .map(
        (link) => {
          const members = link.members || [];
          const membersHtml = members.length
            ? `<div class="invite-members">
                <p class="invite-members__title">کاربران ثبت‌شده (${members.length})</p>
                <div class="invite-members__table">
                  ${members
                    .map((m) => {
                      const handle = m.username ? `@${m.username}` : `ID ${m.telegram_user_id}`;
                      const status = m.is_active ? "فعال" : "خارج شده";
                      return `<div class="invite-member-row">
                        <div>
                          <strong>${escapeHtml(m.display_name)}</strong>
                          <span>${escapeHtml(handle)}</span>
                        </div>
                        <div class="invite-member-row__meta">
                          <span>لینک #${m.invite_link_id}</span>
                          <span>${formatDate(m.joined_at)}</span>
                          <span class="${m.is_active ? "is-active" : "is-left"}">${status}</span>
                        </div>
                      </div>`;
                    })
                    .join("")}
                </div>
              </div>`
            : `<p class="channel-empty__sub">هنوز کاربری با این لینک ثبت نشده</p>`;

          return `
        <article class="invite-link-card">
          <p class="invite-link-card__title">${escapeHtml(link.name)} <span class="invite-link-card__id">#${link.id}</span></p>
          <p class="invite-link-card__url">${escapeHtml(link.invite_url)}</p>
          <div class="invite-link-card__stats">
            <span class="invite-link-stat">عضویت: <strong>${formatNumber(link.join_count)}</strong></span>
            <span class="invite-link-stat">خروج: <strong>${formatNumber(link.leave_count)}</strong></span>
            <span class="invite-link-stat">خالص: <strong>${formatNumber(link.net_count)}</strong></span>
          </div>
          ${membersHtml}
          <div class="invite-link-card__actions">
            <button type="button" class="btn btn--primary btn--sm" data-copy-invite="${escapeHtml(link.invite_url)}">کپی لینک</button>
          </div>
        </article>`;
        }
      )
      .join("");

    list.querySelectorAll("[data-copy-invite]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const url = btn.getAttribute("data-copy-invite") || "";
        try {
          await navigator.clipboard.writeText(url);
          showToast("لینک کپی شد", { type: "success" });
        } catch (e) {
          showToast(url, { type: "info", duration: 4500 });
        }
      });
    });
  }

  function renderChannelCharts(stats) {
    const sources = stats.viewer_sources || [];
    const summary = stats.summary || {};
    const hint = document.getElementById("detailSourcesHint");
    if (hint) {
      hint.innerHTML = sources.length
        ? '<i class="fa-solid fa-circle-info"></i> منبع عضویت از رویدادهای join تلگرام ثبت می‌شود.'
        : '<i class="fa-solid fa-circle-info"></i> هنوز داده منبع عضویت ثبت نشده. با عضو شدن کاربران جدید پر می‌شود.';
    }

    setText("channelKpiJoins1h", formatNumber(summary.joins_1h ?? 0));
    setText("channelKpiJoins24h", formatNumber(summary.joins_24h ?? 0));
    setText("channelKpiMembers", formatNumber(summary.member_count));

    const joinsRangeEl = document.getElementById("channelJoinsRange");
    if (joinsRangeEl) {
      joinsRangeEl.textContent = formatChartRange(stats.joins_range);
    }
    const membersRangeEl = document.getElementById("channelMembersRange");
    if (membersRangeEl) {
      membersRangeEl.textContent = formatChartRange(stats.members_range);
    }

    destroyCharts();
    if (typeof Chart === "undefined") return;

    const joinsCtx = document.getElementById("chartChannelJoins");
    if (joinsCtx) {
      state.charts.joins = createDashboardLineChart(joinsCtx, stats.joins_hourly || [], {
        tooltipLabel: "عضویت",
        valueSuffix: " عضویت",
        beginAtZero: true,
      });
    }

    const membersCtx = document.getElementById("chartMembers");
    if (membersCtx) {
      state.charts.members = createDashboardLineChart(membersCtx, stats.members_hourly || [], {
        borderColor: "#0ea5e9",
        fillTop: "rgba(14, 165, 233, 0.26)",
        fillBottom: "rgba(14, 165, 233, 0.02)",
        tooltipLabel: "اعضا",
        valueSuffix: " عضو",
        beginAtZero: false,
      });
    }

    const viewsCtx = document.getElementById("chartViews");
    const posts = stats.recent_posts || [];
    if (viewsCtx) {
      const labels = posts.map((_, index) => `پست ${index + 1}`);
      const values = posts.map((post) => post.views ?? 0);
      state.charts.views = createDashboardBarChart(viewsCtx, labels, values, {
        tooltipLabel: "ویو",
        valueSuffix: " ویو",
        barColor: "rgba(14, 165, 233, 0.48)",
      });
    }

    const sourcesCtx = document.getElementById("chartSources");
    if (sourcesCtx) {
      const labels = sources.length ? sources.map((s) => s.label) : ["داده‌ای ثبت نشده"];
      const values = sources.length ? sources.map((s) => s.count) : [1];
      const total = values.reduce((s, v) => s + v, 0);
      state.charts.sources = createDashboardDoughnutChart(sourcesCtx, labels, values, total);
    }
  }

  async function openChannelDetail(chatId) {
    state.activeChannelId = chatId;
    const detail = document.getElementById("screenChannelDetail");
    const channelsScreen = screens.channels;

    channelsScreen?.classList.remove("is-active");
    detail?.removeAttribute("hidden");
    detail?.classList.add("is-active");
    bottomNav?.setAttribute("hidden", "hidden");

    setText("detailTitle", "در حال بارگذاری...");
    setText("detailMeta", "...");
    tg?.HapticFeedback?.selectionChanged();

    try {
      const data = await api(`channel_stats.php?chat_id=${chatId}`);
      state.cache = state.cache || {};
      state.cache.channelStats = data.stats || {};
      renderChannelDetail(data.stats || {});
      showChannelTab("overview");
      await loadInviteLinks(chatId);
    } catch (error) {
      setText("detailTitle", "خطا");
      setText("detailMeta", "دریافت آمار کانال ممکن نشد");
      showToast("دریافت آمار کانال ممکن نشد", { type: "error" });
      console.error(error);
    }
  }

  function closeChannelDetail() {
    const detail = document.getElementById("screenChannelDetail");
    detail?.setAttribute("hidden", "");
    detail?.classList.remove("is-active");
    screens.channels?.classList.add("is-active");
    bottomNav?.removeAttribute("hidden");
    destroyCharts();
    state.activeChannelId = null;
    state.channelTab = "overview";
  }

  function renderChannelDetail(stats) {
    const channel = stats.channel || {};
    const summary = stats.summary || {};
    const avatar = document.getElementById("detailAvatar");

    setText("detailTitle", channel.title || "کانال");
    setText(
      "detailMeta",
      `${channel.is_private ? "خصوصی" : "عمومی"} · ${channel.username ? `@${channel.username}` : `ID ${channel.chat_id}`}`
    );
    setText("detailMembers", formatNumber(summary.member_count));
    setText("detailGrowth", summary.member_growth > 0 ? `+${formatNumber(summary.member_growth)}` : formatNumber(summary.member_growth || 0));
    setText("detailAvgViews", formatNumber(summary.avg_post_views));
    setText("detailPosts", formatNumber(summary.tracked_posts));
    setText("detailChatId", String(channel.chat_id || "—"));

    const link = channel.public_link || channel.invite_link || "—";
    setText("detailLink", link);

    const copyBtn = document.getElementById("btnCopyChannelLink");
    if (copyBtn) {
      const hasLink = Boolean(channel.public_link || channel.invite_link);
      copyBtn.hidden = !hasLink;
      copyBtn.dataset.link = channel.public_link || channel.invite_link || "";
    }

    if (avatar) {
      const banned = isChannelBanned(channel);
      avatar.classList.toggle("channel-avatar--bot-banned", banned);
      if (channel.photo_url && !banned) {
        avatar.innerHTML = `<img src="${channel.photo_url}" alt="">`;
      } else {
        const initial = (channel.title || "C").charAt(0).toUpperCase();
        avatar.innerHTML = banned
          ? `${escapeHtml(initial)}<span class="bot-ban-badge bot-ban-badge--avatar" aria-label="مسدود">BAN</span>`
          : escapeHtml(initial);
      }
    }

    const sources = stats.viewer_sources || [];
    const hint = document.getElementById("detailSourcesHint");
    if (hint) {
      hint.innerHTML = sources.length
        ? '<i class="fa-solid fa-circle-info"></i> منبع عضویت از رویدادهای join تلگرام ثبت می‌شود.'
        : '<i class="fa-solid fa-circle-info"></i> هنوز داده منبع عضویت ثبت نشده. با عضو شدن کاربران جدید پر می‌شود.';
    }

    if (state.channelTab === "stats") {
      renderChannelCharts(stats);
    }
  }

  function showBotTab(tab) {
    state.botTab = tab;
    const panels = {
      overview: document.getElementById("botPanelOverview"),
      stats: document.getElementById("botPanelStats"),
      settings: document.getElementById("botPanelSettings"),
    };

    Object.entries(panels).forEach(([name, el]) => {
      if (!el) return;
      if (name === tab) {
        el.removeAttribute("hidden");
        el.classList.add("is-active");
      } else {
        el.setAttribute("hidden", "");
        el.classList.remove("is-active");
      }
    });

    document.querySelectorAll("[data-bot-tab]").forEach((btn) => {
      btn.classList.toggle("is-active", btn.dataset.botTab === tab);
    });

    if (tab === "stats" && state.cache?.botStats) {
      requestAnimationFrame(() => renderBotCharts(state.cache.botStats));
    }
    if (tab === "settings" && state.activeBotId) {
      loadBotProfileSettings(state.activeBotId).catch(() => {
        showToast("بارگذاری پروفایل ربات ناموفق بود", { type: "error" });
      });
      loadBotJoinSettings(state.activeBotId).catch(() => {
        showToast("بارگذاری تنظیمات جوین ناموفق بود", { type: "error" });
      });
    }
  }

  function renderBotCharts(stats) {
    const summary = stats.summary || {};

    setText("botKpiJoins1h", formatNumber(summary.joins_1h ?? 0));
    setText("botKpiJoins24h", formatNumber(summary.joins_24h ?? 0));
    setText("botKpiUsers", formatNumber(summary.user_count));

    const joinsRangeEl = document.getElementById("botJoinsRange");
    if (joinsRangeEl) {
      joinsRangeEl.textContent = formatChartRange(stats.joins_range);
    }
    const usersRangeEl = document.getElementById("botUsersRange");
    if (usersRangeEl) {
      usersRangeEl.textContent = formatChartRange(stats.users_range);
    }

    destroyCharts();
    if (typeof Chart === "undefined") return;

    const joinsCtx = document.getElementById("chartBotJoins");
    if (joinsCtx) {
      state.charts.botJoins = createDashboardLineChart(joinsCtx, stats.joins_hourly || [], {
        tooltipLabel: "کاربر جدید",
        valueSuffix: " کاربر",
        beginAtZero: true,
      });
    }

    const usersCtx = document.getElementById("chartBotUsers");
    if (usersCtx) {
      state.charts.botUsers = createDashboardLineChart(usersCtx, stats.users_hourly || [], {
        borderColor: "#8b5cf6",
        fillTop: "rgba(139, 92, 246, 0.26)",
        fillBottom: "rgba(139, 92, 246, 0.02)",
        tooltipLabel: "کاربران",
        valueSuffix: " کاربر",
        beginAtZero: false,
      });
    }
  }

  async function openBotDetail(botId) {
    state.activeBotId = botId;
    const detail = document.getElementById("screenBotDetail");
    const channelsScreen = screens.channels;

    channelsScreen?.classList.remove("is-active");
    detail?.removeAttribute("hidden");
    detail?.classList.add("is-active");
    bottomNav?.setAttribute("hidden", "hidden");

    setText("botDetailTitle", "در حال بارگذاری...");
    setText("botDetailVersionBadge", "...");
    setText("botDetailHandle", "");
    tg?.HapticFeedback?.selectionChanged();

    try {
      const data = await api(`bot_stats.php?bot_id=${botId}`);
      state.cache = state.cache || {};
      state.cache.botStats = data.stats || {};
      renderBotDetail(data.stats || {});
      showBotTab("overview");
    } catch (error) {
      setText("botDetailTitle", "خطا");
      setText("botDetailVersionBadge", "دریافت آمار ربات ممکن نشد");
      setText("botDetailHandle", "");
      showToast("دریافت آمار ربات ممکن نشد", { type: "error" });
      console.error(error);
    }
  }

  function closeBotDetail() {
    const detail = document.getElementById("screenBotDetail");
    detail?.setAttribute("hidden", "");
    detail?.classList.remove("is-active");
    screens.channels?.classList.add("is-active");
    bottomNav?.removeAttribute("hidden");
    destroyCharts();
    state.activeBotId = null;
    state.botTab = "overview";
  }

  function renderBotDetail(stats) {
    const bot = stats.bot || {};
    const summary = stats.summary || {};
    const avatar = document.getElementById("botDetailAvatar");
    const title = bot.bot_name || (bot.bot_username ? `@${bot.bot_username}` : "ربات");

    setText("botDetailTitle", title);
    setText("botDetailVersionBadge", bot.uploader_version_name || BOT_TYPE_LABEL);
    setText(
      "botDetailHandle",
      bot.bot_username ? `@${bot.bot_username}` : bot.bot_telegram_id ? `ID ${bot.bot_telegram_id}` : "—"
    );
    setText("botDetailUsers", formatNumber(summary.user_count));
    const growth = summary.user_growth_24h ?? 0;
    setText("botDetailGrowth", growth > 0 ? `+${formatNumber(growth)}` : formatNumber(growth));
    setText("botDetailActive", formatNumber(summary.active_users_24h));
    setText("botDetailUploads", formatNumber(summary.uploads_count));
    setText("botDetailId", String(bot.id || "—"));
    setText("botDetailTelegramId", String(bot.bot_telegram_id || "—"));
    setText("botDetailVersion", bot.uploader_version_name || BOT_TYPE_LABEL);
    setText("botDetailChannelFolder", bot.channel_folder_name || "—");
    setText("botDetailCreated", bot.created_at ? formatDate(bot.created_at) : "—");

    const link = bot.public_link || "—";
    setText("botDetailLink", link);

    const copyBtn = document.getElementById("btnCopyBotLink");
    if (copyBtn) {
      const hasLink = Boolean(bot.public_link);
      copyBtn.hidden = !hasLink;
      copyBtn.dataset.link = bot.public_link || "";
    }

    if (avatar) {
      const banned = isBotBanned(bot);
      avatar.classList.toggle("channel-avatar--bot-banned", banned);
      const initial = title.replace(/^@/, "").charAt(0).toUpperCase() || "B";
      if (banned) {
        avatar.innerHTML = `${escapeHtml(initial)}<span class="bot-ban-badge bot-ban-badge--avatar" aria-label="مسدود">BAN</span>`;
      } else if (bot.photo_url) {
        avatar.innerHTML = `<img src="${bot.photo_url}" alt="" loading="lazy" decoding="async">`;
      } else {
        avatar.innerHTML = escapeHtml(initial);
      }
    }

    if (state.botTab === "stats") {
      renderBotCharts(stats);
    }
  }

  function renderManageBots(server) {
    const card = document.getElementById("manageBotsCard");
    const list = document.getElementById("manageBotsList");
    const statsLine = document.getElementById("manageBotsStatsLine");
    const registerPanel = document.getElementById("manageBotRegisterPanel");
    if (!card || !list) return;

    if (!server?.is_admin) {
      card.hidden = false;
      if (registerPanel) registerPanel.hidden = true;
      if (statsLine) statsLine.textContent = "دسترسی محدود";
      list.innerHTML =
        '<p class="channel-empty__sub">فقط ادمین اصلی می‌تواند ربات کمکی ثبت کند. اگر ادمین هستید، آیدی تلگرام خود را به پشتیبانی بدهید.</p>';
      return;
    }

    card.hidden = false;
    if (registerPanel) registerPanel.hidden = false;
    const bots = server.manage_bots || [];
    const stats = server.manage_bots_stats || {};

    if (server.manage_bots_available === false) {
      if (statsLine) {
        statsLine.textContent = "فایل‌های سرور هنوز به‌روز نشده.";
      }
      list.innerHTML = `<p class="channel-empty__sub">فایل <code>lib/manage_bots.php</code> روی هاست نیست. پس از آپلود، <code>install.php</code> را اجرا کنید.</p>`;
      return;
    }

    if (statsLine) {
      const suggested = stats.suggested_bot_username
        ? `@${stats.suggested_bot_username}`
        : stats.all_bots_at_capacity
          ? "همه ربات‌ها پر — ربات کمکی جدید بسازید"
          : "";
      const base = `${stats.active_bot_count || bots.length} ربات · ${stats.total_channel_bindings || 0} کانال · سقف ${stats.channel_limit || 500}/ربات`;
      statsLine.textContent = suggested ? `${base} · کانال جدید: ${suggested}` : base;
    }

    if (!bots.length) {
      list.innerHTML = `<p class="channel-empty__sub">هنوز فقط ربات اصلی ثبت شده — توکن ربات کمکی را بالا وارد کنید.</p>`;
      return;
    }

    list.innerHTML = bots
      .map((bot) => {
        const username = bot.bot_username ? `@${escapeHtml(bot.bot_username)}` : "ربات";
        const name = bot.bot_name ? escapeHtml(bot.bot_name) : username;
        const primaryBadge = bot.is_primary
          ? '<span class="version-badge version-badge--default">اصلی · پنل</span>'
          : '<span class="version-badge">کمکی · API کانال</span>';
        const healthChipClass =
          bot.health_status === "ok"
            ? "manage-bot-status-chip--ok"
            : bot.near_capacity || bot.at_capacity
              ? "manage-bot-status-chip--warn"
              : "manage-bot-status-chip--danger";
        const healthLabel =
          bot.health_status === "ok"
            ? "سالم"
            : bot.health_message || bot.health_status || "نیاز به بررسی";
        const capacity = `${formatNumber(bot.channel_count || 0)} / ${formatNumber(bot.channel_limit || 500)} کانال`;
        const capacityWarn = bot.near_capacity
          ? '<p class="uploader-version-row__meta manage-bot-row__warn"><i class="fa-solid fa-triangle-exclamation"></i> نزدیک سقف ۵۰۰ کانال</p>'
          : bot.at_capacity
            ? '<p class="uploader-version-row__meta manage-bot-row__warn"><i class="fa-solid fa-ban"></i> ظرفیت پر شده</p>'
            : "";

        return `
        <div class="uploader-version-row manage-bot-row${bot.is_primary ? " manage-bot-row--primary" : ""}">
          <div class="uploader-version-row__head">
            <strong class="uploader-version-row__title">${name}</strong>
            ${primaryBadge}
            <span class="manage-bot-status-chip ${healthChipClass}"><i class="fa-solid fa-heart-pulse"></i> ${escapeHtml(healthLabel)}</span>
          </div>
          <p class="uploader-version-row__meta" dir="ltr">${username}</p>
          <p class="uploader-version-row__meta">${capacity}</p>
          ${capacityWarn}
          <div class="manage-bot-row__actions">
            ${
              bot.is_primary
                ? '<span class="manage-bot-status-chip manage-bot-status-chip--ok"><i class="fa-solid fa-link"></i> webhook: index.php</span>'
                : `<button type="button" class="btn btn--ghost btn--sm" data-refresh-manage-bot="${bot.id}"><i class="fa-solid fa-rotate"></i> بررسی</button>
                   <button type="button" class="btn btn--ghost btn--sm btn--danger" data-remove-manage-bot="${bot.id}"><i class="fa-solid fa-trash"></i> حذف</button>`
            }
          </div>
        </div>`;
      })
      .join("");

    list.querySelectorAll("[data-refresh-manage-bot]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const id = Number(btn.dataset.refreshManageBot || 0);
        if (!id) return;
        btn.disabled = true;
        try {
          await manageBotsApi({ action: "refresh_health", manage_bot_id: id });
          await loadServerTab();
          showToast("سلامت ربات بررسی شد", { type: "success" });
        } catch (_) {
          showToast("بررسی سلامت ناموفق بود", { type: "error" });
        } finally {
          btn.disabled = false;
        }
      });
    });

    list.querySelectorAll("[data-remove-manage-bot]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const id = Number(btn.dataset.removeManageBot || 0);
        if (!id) return;
        btn.disabled = true;
        try {
          await manageBotsApi({ action: "remove_bot", manage_bot_id: id });
          await loadServerTab();
          showToast("ربات مدیریت حذف شد", { type: "success" });
        } catch (e) {
          showToast(manageBotErrorMessage(e?.message), { type: "error", duration: 4500 });
        } finally {
          btn.disabled = false;
        }
      });
    });
  }

  async function manageBotsApi(body) {
    return api("manage_bots.php", { method: "POST", body });
  }

  function manageBotErrorMessage(code) {
    const map = {
      token_required: "توکن ربات را وارد کنید",
      invalid_token: "توکن ربات نامعتبر است",
      primary_bot_exists: "این همان ربات اصلی است",
      cannot_replace_primary: "ربات اصلی قابل جایگزینی نیست",
      cannot_remove_primary: "ربات اصلی قابل حذف نیست",
      manage_bot_has_channels: "این ربات هنوز کانال دارد — ابتدا کانال‌ها را به ربات دیگر منتقل کنید",
      remove_failed: "حذف ناموفق بود",
      forbidden: "دسترسی ندارید",
    };
    return map[code] || "عملیات ناموفق بود";
  }

  function openManageBotModal() {
    const modal = document.getElementById("manageBotModal");
    const input = document.getElementById("manageBotTokenInput");
    if (input) input.value = "";
    if (modal) modal.hidden = false;
    input?.focus();
  }

  function closeManageBotModal() {
    const modal = document.getElementById("manageBotModal");
    if (modal) modal.hidden = true;
  }

  async function submitManageBotToken(token, button) {
    const trimmed = (token || "").trim();
    if (!trimmed) {
      showToast("توکن ربات را وارد کنید", { type: "warning" });
      return false;
    }
    if (button) button.disabled = true;
    try {
      await manageBotsApi({ action: "add_bot", token: trimmed });
      closeManageBotModal();
      const inline = document.getElementById("manageBotTokenInline");
      if (inline) inline.value = "";
      await loadServerTab();
      showToast("ربات کمکی ثبت و وب‌هوک تنظیم شد", { type: "success" });
      return true;
    } catch (e) {
      showToast(manageBotErrorMessage(e?.message), { type: "error", duration: 4500 });
      return false;
    } finally {
      if (button) button.disabled = false;
    }
  }

  function initManageBotsUi() {
    document.getElementById("btnAddManageBot")?.addEventListener("click", openManageBotModal);
    document.querySelectorAll("[data-close-manage-bot]").forEach((el) => {
      el.addEventListener("click", closeManageBotModal);
    });
    document.getElementById("btnSaveManageBot")?.addEventListener("click", async () => {
      const token = document.getElementById("manageBotTokenInput")?.value || "";
      await submitManageBotToken(token, document.getElementById("btnSaveManageBot"));
    });
    document.getElementById("btnSaveManageBotInline")?.addEventListener("click", async () => {
      const token = document.getElementById("manageBotTokenInline")?.value || "";
      await submitManageBotToken(token, document.getElementById("btnSaveManageBotInline"));
    });
    document.getElementById("manageBotTokenInline")?.addEventListener("keydown", (event) => {
      if (event.key === "Enter") {
        event.preventDefault();
        document.getElementById("btnSaveManageBotInline")?.click();
      }
    });
    document.getElementById("btnRefreshManageBotsHealth")?.addEventListener("click", async () => {
      const btn = document.getElementById("btnRefreshManageBotsHealth");
      if (btn) btn.disabled = true;
      try {
        await manageBotsApi({ action: "refresh_all_health" });
        await loadServerTab();
        showToast("سلامت همه ربات‌های مدیریت بررسی شد", { type: "success" });
      } catch (_) {
        showToast("بررسی سلامت ناموفق بود", { type: "error" });
      } finally {
        if (btn) btn.disabled = false;
      }
    });
  }

  function renderGlobalBotOwners(server) {
    const card = document.getElementById("globalBotOwnersCard");
    const list = document.getElementById("globalBotOwnersList");
    if (!card || !list) return;

    if (!server?.is_admin) {
      card.hidden = true;
      return;
    }

    card.hidden = false;
    const owners = server.global_bot_owners || [];
    if (!owners.length) {
      list.innerHTML = `<p class="channel-empty__sub">هنوز مالکی ثبت نشده. «افزودن مالک» را بزنید.</p>`;
      return;
    }

    list.innerHTML = owners
      .map((owner) => {
        const handle = owner.username ? `@${escapeHtml(owner.username)}` : `ID ${owner.telegram_id}`;
        const name = owner.display_name ? `<span class="uploader-version-row__meta">${escapeHtml(owner.display_name)}</span>` : "";
        return `
        <div class="uploader-version-row">
          <div class="uploader-version-row__head">
            <strong class="uploader-version-row__title">${handle}</strong>
            <span class="version-badge version-badge--default">مالک</span>
          </div>
          ${name}
          <p class="uploader-version-row__meta">شناسه: <span dir="ltr">${owner.telegram_id}</span></p>
          <button type="button" class="btn btn--ghost btn--sm uploader-version-row__action" data-remove-global-owner="${owner.id}">حذف</button>
        </div>`;
      })
      .join("");

    list.querySelectorAll("[data-remove-global-owner]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const id = Number(btn.getAttribute("data-remove-global-owner") || 0);
        if (!id) return;
        btn.disabled = true;
        try {
          await api("global_bot_owners.php", {
            method: "POST",
            body: { action: "remove", owner_id: id },
          });
          await loadServerTab();
          showToast("مالک حذف شد", { type: "success" });
        } catch (e) {
          showToast("حذف مالک ناموفق بود", { type: "error" });
        } finally {
          btn.disabled = false;
        }
      });
    });
  }

  function openGlobalBotOwnerModal() {
    const modal = document.getElementById("globalBotOwnerModal");
    const input = document.getElementById("globalBotOwnerInput");
    if (input) input.value = "";
    if (modal) modal.hidden = false;
    input?.focus();
  }

  function closeGlobalBotOwnerModal() {
    const modal = document.getElementById("globalBotOwnerModal");
    if (modal) modal.hidden = true;
  }

  function globalOwnerErrorMessage(error) {
    const map = {
      owner_input_required: "شناسه یا username را وارد کنید",
      invalid_telegram_id: "شناسه عددی نامعتبر است",
      invalid_username: "username نامعتبر است",
      username_not_found: "این username در دیتابیس نیست — ابتدا با ربات اصلی /start بزنید یا شناسه عددی وارد کنید",
    };
    return map[error] || "ذخیره مالک ناموفق بود";
  }

  function initGlobalBotOwnerUi() {
    document.getElementById("btnAddGlobalBotOwner")?.addEventListener("click", openGlobalBotOwnerModal);
    document.querySelectorAll("[data-close-global-owner]").forEach((el) => {
      el.addEventListener("click", closeGlobalBotOwnerModal);
    });
    document.getElementById("btnSaveGlobalBotOwner")?.addEventListener("click", async () => {
      const input = document.getElementById("globalBotOwnerInput")?.value?.trim() || "";
      if (!input) {
        showToast("شناسه یا username را وارد کنید", { type: "warning" });
        return;
      }
      const btn = document.getElementById("btnSaveGlobalBotOwner");
      if (btn) btn.disabled = true;
      try {
        await api("global_bot_owners.php", {
          method: "POST",
          body: { action: "create", input },
        });
        closeGlobalBotOwnerModal();
        await loadServerTab();
        showToast("مالک کل ربات‌ها اضافه شد", { type: "success" });
      } catch (e) {
        const err = e?.message || "";
        showToast(globalOwnerErrorMessage(err), { type: "error", duration: 4500 });
      } finally {
        if (btn) btn.disabled = false;
      }
    });
  }

  function renderUploaderVersions(server) {
    const card = document.getElementById("uploaderVersionsCard");
    const list = document.getElementById("uploaderVersionsList");
    if (!card || !list) return;

    if (!server?.is_admin) {
      card.hidden = true;
      return;
    }

    card.hidden = false;
    const versions = server.uploader_versions || [];
    if (!versions.length) {
      list.innerHTML = `<p class="channel-empty__sub">هنوز نسخه‌ای ثبت نشده. «افزودن نسخه» را بزنید.</p>`;
      return;
    }

    list.innerHTML = versions
      .map((version) => {
        const roleBadge = version.bot_role === "guardian"
          ? '<span class="version-badge version-badge--guardian">محافظ</span>'
          : '<span class="version-badge version-badge--uploader">آپلودر</span>';
        const badges = [
          roleBadge,
          version.is_default ? '<span class="version-badge version-badge--default">پیش‌فرض</span>' : "",
          !version.is_active ? '<span class="version-badge version-badge--off">غیرفعال</span>' : "",
        ]
          .filter(Boolean)
          .join(" ");
        return `
        <div class="uploader-version-row">
          <div class="uploader-version-row__head">
            <strong class="uploader-version-row__title">${escapeHtml(version.name)}</strong>
            <span class="uploader-version-row__badges">${badges}</span>
          </div>
          <p class="uploader-version-row__path">${escapeHtml(version.project_path || "")}</p>
          <p class="uploader-version-row__meta">
            <span>${escapeHtml(version.public_url || "")}/${escapeHtml(version.webhook_script || "index.php")}</span>
            · ${formatNumber(version.bots_count ?? 0)} ربات
          </p>
          ${
            !version.is_default && version.is_active
              ? `<button type="button" class="btn btn--ghost btn--sm uploader-version-row__action" data-set-default-version="${version.id}">تنظیم پیش‌فرض</button>`
              : ""
          }
        </div>`;
      })
      .join("");

    list.querySelectorAll("[data-set-default-version]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const id = Number(btn.getAttribute("data-set-default-version") || 0);
        if (!id) return;
        btn.disabled = true;
        try {
          await api("uploader_versions.php", {
            method: "POST",
            body: { action: "update", version_id: id, is_default: true },
          });
          await loadServerTab();
          const refreshed = state.cache?.server?.default_uploader_version;
          syncDefaultUploaderVersionLabel(refreshed);
          showToast("نسخه پیش‌فرض به‌روز شد", { type: "success" });
        } catch (e) {
          showToast("تغییر پیش‌فرض ناموفق بود", { type: "error" });
        } finally {
          btn.disabled = false;
        }
      });
    });
  }

  function openUploaderVersionModal() {
    const modal = document.getElementById("uploaderVersionModal");
    const nameInput = document.getElementById("uploaderVersionNameInput");
    const pathInput = document.getElementById("uploaderVersionPathInput");
    const webhookInput = document.getElementById("uploaderVersionWebhookInput");
    const defaultInput = document.getElementById("uploaderVersionDefaultInput");
    if (nameInput) nameInput.value = "";
    if (pathInput) pathInput.value = "/home/shombols/public_html/great/uploader";
    if (webhookInput) webhookInput.value = "index.php";
    if (defaultInput) defaultInput.checked = false;
    if (modal) modal.hidden = false;
    nameInput?.focus();
  }

  function closeUploaderVersionModal() {
    const modal = document.getElementById("uploaderVersionModal");
    if (modal) modal.hidden = true;
  }

  function initUploaderVersionUi() {
    document.getElementById("btnAddUploaderVersion")?.addEventListener("click", openUploaderVersionModal);
    document.querySelectorAll("[data-close-uploader-version]").forEach((el) => {
      el.addEventListener("click", closeUploaderVersionModal);
    });
    document.getElementById("btnSaveUploaderVersion")?.addEventListener("click", async () => {
      const name = document.getElementById("uploaderVersionNameInput")?.value?.trim() || "";
      const projectPath = document.getElementById("uploaderVersionPathInput")?.value?.trim() || "";
      const webhookScript = document.getElementById("uploaderVersionWebhookInput")?.value?.trim() || "index.php";
      const isDefault = Boolean(document.getElementById("uploaderVersionDefaultInput")?.checked);
      if (!name || !projectPath) {
        showToast("نام و مسیر پروژه را وارد کنید", { type: "warning" });
        return;
      }
      const btn = document.getElementById("btnSaveUploaderVersion");
      if (btn) btn.disabled = true;
      try {
        await api("uploader_versions.php", {
          method: "POST",
          body: {
            action: "create",
            name,
            project_path: projectPath,
            webhook_script: webhookScript,
            is_default: isDefault,
          },
        });
        closeUploaderVersionModal();
        await loadServerTab();
        syncDefaultUploaderVersionLabel(state.cache?.server?.default_uploader_version);
        showToast("نسخه اپلودر ذخیره شد", { type: "success" });
      } catch (e) {
        showToast("ذخیره نسخه ناموفق بود — مسیر را بررسی کنید", { type: "error", duration: 4000 });
      } finally {
        if (btn) btn.disabled = false;
      }
    });
  }

  function renderServerProblemRows(problems, type = "channel") {
    if (!problems.length) {
      return "";
    }

    return problems
      .map((p) => {
        if (type === "bot") {
          const username = p.bot_username ? `@${escapeHtml(p.bot_username)}` : "بدون یوزرنیم";
          const usersLine =
            p.user_count !== null && p.user_count !== undefined
              ? ` · ${formatNumber(p.user_count)} کاربر`
              : "";
          return `
          <div class="server-problem-row">
            <div class="server-problem-row__head">
              <strong class="server-problem-row__title">${escapeHtml(p.bot_name || "ربات")}</strong>
              <span class="server-problem-row__id">${username} · ID ${p.bot_telegram_id || "—"}</span>
            </div>
            <p class="server-problem-row__label">${escapeHtml(p.problem_label || "مشکل دسترسی")}</p>
            <span class="server-problem-row__time">${p.since_at ? formatDate(p.since_at) : "—"}${usersLine}</span>
          </div>`;
        }

        return `
          <div class="server-problem-row">
            <div class="server-problem-row__head">
              <strong class="server-problem-row__title">${escapeHtml(p.title || "کانال")}</strong>
              <span class="server-problem-row__id">${p.username ? `@${escapeHtml(p.username)}` : `ID ${p.chat_id}`}</span>
            </div>
            <p class="server-problem-row__label">${escapeHtml(p.problem_label || "مشکل دسترسی")}</p>
            <span class="server-problem-row__time">${p.since_at ? formatDate(p.since_at) : "—"}</span>
          </div>`;
      })
      .join("");
  }

  function renderServerError(message) {
    setText("serverCheckedAt", "آخرین بررسی: خطا");
    const list = document.getElementById("serverStatus");
    if (list) {
      list.innerHTML = `
        <div class="status-item is-bad">
          <span class="status-item__label">وضعیت سرور</span>
          <span class="status-item__value">بارگذاری ناموفق</span>
        </div>`;
    }
    const problemsRoot = document.getElementById("serverChannelProblems");
    if (problemsRoot) {
      problemsRoot.innerHTML = `<p class="channel-empty__sub">${escapeHtml(message || "دوباره تلاش کنید یا دکمه بررسی مجدد را بزنید.")}</p>`;
    }
    const botProblemsRoot = document.getElementById("serverBotProblems");
    if (botProblemsRoot) {
      botProblemsRoot.innerHTML = `<p class="channel-empty__sub">${escapeHtml(message || "دوباره تلاش کنید یا دکمه بررسی مجدد را بزنید.")}</p>`;
    }
  }

  async function loadServerTab(options = {}) {
    const deep = Boolean(options.deep);
    const status = document.getElementById("serverStatus");
    const problemsRoot = document.getElementById("serverChannelProblems");
    const botProblemsRoot = document.getElementById("serverBotProblems");
    if (status) {
      status.innerHTML = `
        <div class="status-item">
          <span class="status-item__label">در حال بررسی</span>
          <span class="status-item__value"><i class="fa-solid fa-spinner fa-spin"></i></span>
        </div>`;
    }
    if (problemsRoot) {
      problemsRoot.innerHTML = '<p class="channel-empty__sub"><i class="fa-solid fa-spinner fa-spin"></i> در حال بررسی کانال‌ها...</p>';
    }
    if (botProblemsRoot) {
      botProblemsRoot.innerHTML = '<p class="channel-empty__sub"><i class="fa-solid fa-spinner fa-spin"></i> در حال بررسی ربات‌ها...</p>';
    }

    try {
      const path = deep ? "server.php?deep_health=1" : "server.php";
      const data = await api(path);
      state.cache = state.cache || {};
      state.cache.server = data;
      renderServer(data);
      if (deep) {
        showToast("بررسی عمیق کانال‌ها و ربات‌ها انجام شد", { type: "success" });
      }
    } catch (error) {
      console.error(error);
      renderServerError("اتصال به سرور برقرار نشد. اینترنت یا مینی‌اپ را دوباره باز کنید.");
      showToast("بارگذاری وضعیت سرور ناموفق بود", { type: "error" });
    }
  }

  function renderServer(data) {
    const server = data.server || {};

    setText("homeLastSeen", formatDateDashboard(server.last_seen_at));
    renderHomeQuickStats();
    setText("serverCheckedAt", "آخرین بررسی: " + formatDate(server.checked_at));

    const list = document.getElementById("serverStatus");
    if (!list) return;

    const items = [
      { label: "ربات", value: server.bot || "—", ok: server.bot_ok },
      { label: "دیتابیس", value: server.database || "—", ok: server.db_ok },
      { label: "Webhook", value: server.webhook || "—", ok: server.webhook_ok },
      { label: "نسخه PHP", value: server.php_version || "—", ok: true },
      { label: "کل کاربران", value: String(server.total_users ?? "—"), ok: true },
      { label: "آپدیت معوق", value: String(server.pending_updates ?? 0), ok: (server.pending_updates ?? 0) === 0 },
      {
        label: "کانال‌های مشکل‌دار",
        value: String(server.channel_problems_count ?? (server.channel_problems?.length ?? 0)),
        ok: (server.channel_problems_count ?? server.channel_problems?.length ?? 0) === 0,
      },
      {
        label: "ربات‌های مشکل‌دار",
        value: String(server.bot_problems_count ?? (server.bot_problems?.length ?? 0)),
        ok: (server.bot_problems_count ?? server.bot_problems?.length ?? 0) === 0,
      },
    ];

    list.innerHTML = items
      .map(
        (item) => `
        <div class="status-item ${item.ok === false ? "is-bad" : "is-ok"}">
          <span class="status-item__label">${item.label}</span>
          <span class="status-item__value">${item.value}</span>
        </div>`
      )
      .join("");

    const problemsRoot = document.getElementById("serverChannelProblems");
    const problems = server.channel_problems || [];
    if (problemsRoot) {
      if (!problems.length) {
        problemsRoot.innerHTML =
          '<p class="channel-empty__sub"><i class="fa-solid fa-circle-check"></i> کانال مشکوکی ثبت نشده — دسترسی ربات به کانال‌ها سالم است.</p>';
      } else {
        problemsRoot.innerHTML = renderServerProblemRows(problems, "channel");
      }
    }

    const botProblemsRoot = document.getElementById("serverBotProblems");
    const botProblems = server.bot_problems || [];
    if (botProblemsRoot) {
      if (!botProblems.length) {
        botProblemsRoot.innerHTML =
          '<p class="channel-empty__sub"><i class="fa-solid fa-circle-check"></i> ربات مشکوک ثبت نشده — همه ربات‌های آپلودر سالم هستند.</p>';
      } else {
        botProblemsRoot.innerHTML = renderServerProblemRows(botProblems, "bot");
      }
    }

    syncDefaultUploaderVersionLabel(server.default_uploader_version);
    syncDefaultGuardianVersionLabel(server.default_guardian_version);
    renderGlobalBotOwners(server);
    renderManageBots(server);
    renderUploaderVersions(server);
  }

  async function loadAppData(auth) {
    state.cache = {
      auth: auth || state.cache?.auth || null,
      server: null,
      channels: null,
      bots: null,
      contentGroups: null,
      autoPost: null,
      hashtagTools: null,
      bannerTools: null,
      glassButtonTools: null,
      zapasTools: null,
    };
    if (auth) renderProfile(auth);

    try {
      const botsData = await api("my_bots.php");
      state.cache.bots = botsData;
      restoreBotFolderNav(botsData.folders || []);
      autoOpenBotFolderIfNeeded(botsData.folders || [], botsData.bots || []);
      renderBots(botsData);
      renderHomeQuickStats();
    } catch (error) {
      state.openBotFolderId = null;
      renderBots({ bots: [], total: 0, folders: [] });
      console.warn("bots load failed", error);
      showToast("بارگذاری ربات‌ها ناموفق بود", { type: "error", duration: 5000 });
    }

    try {
      const channelsData = await api("channels.php?lite=1");
      state.cache.channels = channelsData;
      renderChannels(channelsData);
      renderHomeQuickStats();
    } catch (error) {
      renderChannels({ channels: [], total: 0, totals: {}, dashboard: { channels: [] } });
      console.warn("channels load failed", error);
      showToast("بارگذاری کانال‌ها ناموفق بود", { type: "error" });
    }

    void api("channels.php")
      .then((fullChannels) => {
        state.cache.channels = fullChannels;
        renderChannels(fullChannels);
        renderHomeQuickStats();
      })
      .catch((error) => {
        console.warn("channels full stats failed", error);
      });

    const results = await Promise.allSettled([
      api("server.php"),
      api("content_groups.php"),
      autoPostApi(),
      hashtagToolsApi(),
      bannerToolsApi(),
      glassButtonToolsApi(),
      zapasBotsApi(),
    ]);

    if (results[0].status === "fulfilled") {
      state.cache.server = results[0].value;
      renderServer(results[0].value);
    } else {
      console.warn("server load failed", results[0].reason);
      if (screens.server?.classList.contains("is-active")) {
        renderServerError();
      }
    }

    if (results[1].status === "fulfilled") {
      state.cache.contentGroups = results[1].value;
      renderContentGroups(results[1].value);
    } else {
      renderContentGroups({ groups: [], total: 0, folders: [] });
      console.warn("content groups load failed", results[1].reason);
    }

    if (results[2].status === "fulfilled") {
      state.cache.autoPost = results[2].value;
      renderAutoPost(results[2].value);
    } else {
      renderAutoPost({ folders: [], sessions: [], total: 0 });
      console.warn("auto post load failed", results[2].reason);
    }

    if (results[3].status === "fulfilled") {
      state.cache.hashtagTools = results[3].value;
    } else {
      state.cache.hashtagTools = { folders: [], sets: [] };
      console.warn("hashtag tools load failed", results[3].reason);
    }

    if (results[4].status === "fulfilled") {
      state.cache.bannerTools = results[4].value;
    } else {
      state.cache.bannerTools = { groups: [], bindings: [], stats: {} };
      console.warn("banner tools load failed", results[4].reason);
    }

    if (results[5].status === "fulfilled") {
      state.cache.glassButtonTools = results[5].value;
    } else {
      state.cache.glassButtonTools = { settings: [], stats: {} };
      console.warn("glass button tools load failed", results[5].reason);
    }

    if (results[6].status === "fulfilled") {
      state.cache.zapasTools = results[6].value;
    } else {
      state.cache.zapasTools = { bots: [], bindings: [], replacements: [], stats: {} };
      console.warn("zapas tools load failed", results[6].reason);
    }

    renderToolsExplorer(state.cache.hashtagTools, state.cache.bannerTools, state.cache.glassButtonTools, state.cache.zapasTools);
    renderHomeQuickStats();

    return state.cache;
  }

  async function loadData() {
    const auth = await api("auth.php", {
      method: "POST",
      body: { initData: state.initData },
      timeout: 15000,
    });
    return loadAppData(auth);
  }

  async function authenticate() {
    setText("authStatus", "در حال احراز هویت...");

    const auth = await api("auth.php", {
      method: "POST",
      body: { initData: state.initData },
      timeout: 15000,
    });

    state.cache = state.cache || {};
    state.cache.auth = auth;
    renderProfile(auth);

    const loader = document.querySelector(".auth__loader");
    if (loader) loader.hidden = true;

    showScreen("home");
    tg?.HapticFeedback?.notificationOccurred("success");

    setText("authStatus", "در حال بارگذاری اطلاعات...");
    loadAppData(auth).catch((error) => {
      console.warn("background load failed", error);
      showToast("برخی بخش‌ها بارگذاری نشدند — دکمه بروزرسانی را بزنید", { type: "warning", duration: 5000 });
    });
  }

  async function loadAdsTab() {
    const listEl = document.getElementById("adCampaignsList");
    if (listEl) {
      listEl.innerHTML = `<p class="channel-empty__sub">در حال بارگذاری...</p>`;
    }
    try {
      const data = await api("ad_campaigns.php");
      state.adsFolders = data.folders || [];
      state.adsFolderNames = data.folder_names || {};
      state.adsPromoFolderId = data.promo_folder?.id ?? null;
      renderAdFolderSplitRows();
      renderAdCampaignsList(data.campaigns || [], state.adsFolderNames);
    } catch (e) {
      if (listEl) {
        listEl.innerHTML = `<p class="channel-empty__sub">بارگذاری ناموفق (فقط ادمین).</p>`;
      }
      console.warn("ads tab", e);
    }
  }

  function getUsedAdFolderIds(excludeSelect = null) {
    const ids = [];
    document.querySelectorAll("#adFolderSplits .ad-folder-split-row select").forEach((sel) => {
      if (sel === excludeSelect) return;
      const id = parseInt(sel.value || "0", 10);
      if (id) ids.push(id);
    });
    return ids;
  }

  function getNextAvailableAdFolderId() {
    const used = getUsedAdFolderIds();
    const next = (state.adsFolders || []).find((f) => !used.includes(f.id));
    return next ? next.id : null;
  }

  function sumAdPercents(excludeInput = null) {
    let sum = 0;
    document.querySelectorAll("#adFolderSplits .ad-folder-split-row").forEach((row) => {
      const input = row.querySelector(".ad-folder-split-row__pct");
      if (!input || input === excludeInput) return;
      const v = parseInt(input.value || "0", 10);
      if (!Number.isNaN(v)) sum += v;
    });
    return sum;
  }

  function updateAdPercentSumHint() {
    const el = document.getElementById("adPercentSumHint");
    if (!el) return;
    const sum = sumAdPercents();
    const remain = 100 - sum;
    if (sum > 100) {
      el.innerHTML = `مجموع فعلی: <strong style="color:#dc2626">${sum}٪</strong> — بیش از ۱۰۰ مجاز نیست.`;
    } else if (sum === 100) {
      el.innerHTML = "مجموع درصدها: <strong style=\"color:#16a34a\">۱۰۰٪</strong> ✓";
    } else {
      el.innerHTML = `مجموع فعلی: <strong>${sum}٪</strong> — باقی‌مانده: <strong>${remain}٪</strong> (باید به ۱۰۰ برسد)`;
    }
  }

  function bindAdPercentInput(input) {
    if (!input || input.dataset.adPctBound) return;
    input.dataset.adPctBound = "1";
    input.addEventListener("input", () => {
      let val = parseInt(input.value || "0", 10);
      if (input.value === "") {
        updateAdPercentSumHint();
        return;
      }
      if (Number.isNaN(val)) val = 0;
      val = Math.max(0, Math.min(100, val));
      const others = sumAdPercents(input);
      const maxHere = Math.max(0, 100 - others);
      if (val > maxHere) val = maxHere;
      input.value = String(val);
      updateAdPercentSumHint();
    });
  }

  function renderAdFolderSplitRows() {
    const root = document.getElementById("adFolderSplits");
    if (!root) return;
    root.innerHTML = "";
    if (!(state.adsFolders || []).length) {
      return;
    }
    addAdFolderSplitRow({ silent: true });
    updateAdPercentSumHint();
  }

  function refreshAdFolderSelect(select) {
    if (!select) return;
    const current = parseInt(select.value || "0", 10);
    const used = getUsedAdFolderIds(select);
    const folders = (state.adsFolders || []).filter(
      (f) => !used.includes(f.id) || f.id === current
    );
    select.innerHTML = folders.length
      ? folders
          .map(
            (f) =>
              `<option value="${f.id}">${escapeHtml(f.name || "پوشه")}</option>`
          )
          .join("")
      : `<option value="">پوشه آزاد نیست</option>`;
    if (current && folders.some((f) => f.id === current)) {
      select.value = String(current);
    } else if (folders[0]) {
      select.value = String(folders[0].id);
    }
  }

  function refreshAllAdFolderSelects() {
    document
      .querySelectorAll("#adFolderSplits .ad-folder-split-row select")
      .forEach((sel) => refreshAdFolderSelect(sel));
  }

  function addAdFolderSplitRow(options = {}) {
    const { silent = false } = options;
    const root = document.getElementById("adFolderSplits");
    if (!root) return false;

    if (!(state.adsFolders || []).length) {
      if (!silent) showToast("ابتدا در تب کانال‌ها پوشه بسازید", { type: "info" });
      return false;
    }

    const nextId = getNextAvailableAdFolderId();
    const existingCount = root.querySelectorAll(".ad-folder-split-row").length;
    if (existingCount > 0 && nextId == null) {
      if (!silent) showToast("پوشه دیگری برای افزودن نیست", { type: "info" });
      return false;
    }

    const row = document.createElement("div");
    row.className = "ad-folder-split-row";
    row.innerHTML = `
      <select class="field__input ad-folder-split-row__select"></select>
      <input type="number" class="field__input ad-folder-split-row__pct" min="0" max="100" step="1" placeholder="٪" inputmode="numeric" />
      <button type="button" class="btn btn--ghost btn--sm ad-folder-split-row__remove" aria-label="حذف"><i class="fa-solid fa-xmark"></i></button>
    `;
    const select = row.querySelector("select");
    refreshAdFolderSelect(select);
    if (nextId != null) {
      select.value = String(nextId);
    }
    select.addEventListener("change", () => {
      refreshAllAdFolderSelects();
    });
    bindAdPercentInput(row.querySelector(".ad-folder-split-row__pct"));
    row.querySelector(".ad-folder-split-row__remove")?.addEventListener("click", () => {
      if (root.querySelectorAll(".ad-folder-split-row").length <= 1) {
        showToast("حداقل یک پوشه لازم است", { type: "info" });
        return;
      }
      row.remove();
      refreshAllAdFolderSelects();
      updateAdPercentSumHint();
    });
    root.appendChild(row);
    refreshAllAdFolderSelects();
    updateAdPercentSumHint();
    return true;
  }

  function collectAdFolderSplits() {
    const rows = document.querySelectorAll("#adFolderSplits .ad-folder-split-row");
    const splits = [];
    const seen = new Set();
    rows.forEach((row) => {
      const folderId = parseInt(row.querySelector("select")?.value || "0", 10);
      if (!folderId) return;
      if (seen.has(folderId)) return;
      seen.add(folderId);
      const pctRaw = row.querySelector(".ad-folder-split-row__pct")?.value;
      const entry = { folder_id: folderId };
      if (pctRaw !== "" && pctRaw != null) {
        entry.percent = parseInt(pctRaw, 10);
      }
      splits.push(entry);
    });
    return splits;
  }

  function validateAdFolderSplits(splits) {
    if (!splits.length) {
      return "حداقل یک پوشه انتخاب کنید";
    }
    const ids = splits.map((s) => s.folder_id);
    if (new Set(ids).size !== ids.length) {
      return "هر پوشه فقط یک‌بار انتخاب شود";
    }
    let sum = 0;
    for (const s of splits) {
      if (s.percent == null || Number.isNaN(s.percent)) {
        return "برای هر پوشه درصد وارد کنید";
      }
      if (s.percent < 0 || s.percent > 100) {
        return "هر درصد باید بین ۰ تا ۱۰۰ باشد";
      }
      sum += s.percent;
    }
    if (sum !== 100) {
      return `مجموع درصدها ${sum} است — باید دقیقاً ۱۰۰ باشد`;
    }
    return null;
  }

  function renderAdCampaignsList(campaigns, folderNames) {
    const listEl = document.getElementById("adCampaignsList");
    if (!listEl) return;
    if (!campaigns.length) {
      listEl.innerHTML = `<p class="channel-empty__sub">هنوز سفارشی ثبت نشده است.</p>`;
      return;
    }
    listEl.innerHTML = campaigns
      .map((c) => {
        const ch =
          c.channel_username
            ? `@${escapeHtml(c.channel_username)}`
            : escapeHtml(c.channel_input || String(c.channel_chat_id));
        const splitText = (c.folder_splits || [])
          .map((s) => {
            const nm = folderNames[s.folder_id] || `پوشه ${s.folder_id}`;
            return `${escapeHtml(nm)} ${s.percent ?? "—"}٪`;
          })
          .join(" · ");
        return `<div class="ad-campaign-row">
          <div class="ad-campaign-row__head"><strong>#${c.id}</strong> · ${ch}</div>
          <div class="ad-campaign-row__meta">ممبر: <b>${c.member_order}</b></div>
          <div class="ad-campaign-row__meta">${splitText || "—"}</div>
          <div class="ad-campaign-row__time">${formatDate(c.created_at)}</div>
        </div>`;
      })
      .join("");
  }

  function bindEvents() {
    document.getElementById("btnAdAddFolder")?.addEventListener("click", () => {
      addAdFolderSplitRow();
      tg?.HapticFeedback?.selectionChanged();
    });

    document.getElementById("btnAdSubmit")?.addEventListener("click", async () => {
      const btn = document.getElementById("btnAdSubmit");
      const channel = document.getElementById("adChannelInput")?.value?.trim() || "";
      const members = parseInt(document.getElementById("adMemberOrderInput")?.value || "0", 10);
      const splits = collectAdFolderSplits();
      const splitErr = validateAdFolderSplits(splits);
      if (splitErr) {
        showToast(splitErr, { type: "error" });
        return;
      }
      if (!channel) {
        showToast("آدرس کانال را وارد کنید", { type: "error" });
        return;
      }
      if (!members || members < 1) {
        showToast("تعداد ممبر معتبر نیست", { type: "error" });
        return;
      }
      if (btn) btn.disabled = true;
      try {
        await api("ad_campaigns.php", {
          method: "POST",
          body: {
            action: "create",
            channel_address: channel,
            member_order: members,
            folder_splits: splits,
          },
        });
        showToast("سفارش تبلیغ ثبت شد؛ کانال به پوشه تبلیغات منتقل شد", { type: "success" });
        document.getElementById("adChannelInput").value = "";
        document.getElementById("adMemberOrderInput").value = "";
        await loadAdsTab();
        const ch = await api("channels.php");
        state.cache.channels = ch;
        if (screens.channels?.classList.contains("is-active")) {
          renderChannels(ch, { refreshDashboard: false });
        }
        tg?.HapticFeedback?.notificationOccurred("success");
      } catch (e) {
        const msg =
          e?.message === "channel_not_found"
            ? "کانال در لیست ربات نیست — ربات باید ادمین کانال باشد"
            : e?.message === "percent_sum_must_be_100"
              ? "مجموع درصدها باید دقیقاً ۱۰۰ باشد"
              : e?.message === "percent_required_for_all_folders"
                ? "برای هر پوشه درصد وارد کنید"
                : e?.message === "duplicate_folder"
                  ? "پوشه تکراری مجاز نیست"
                  : "ثبت ناموفق بود";
        showToast(msg, { type: "error" });
      } finally {
        if (btn) btn.disabled = false;
      }
    });

    initFolderExplorerUi();
    initBotExplorerUi();
    initContentGroupExplorerUi();
    initAutoPostExplorerUi();
    initAutoPostScheduleUi();
    initHashtagToolsUi();
    initChannelProfileUi();
    initGlobalBotOwnerUi();
    initManageBotsUi();
    initUploaderVersionUi();
    initModalEnterSubmit();

    document.querySelectorAll(".bottom-nav [data-nav]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const target = btn.dataset.nav;
        showScreen(target);
        tg?.HapticFeedback?.selectionChanged();
      });
    });

    appMain?.addEventListener("click", (event) => {
      const btn = event.target.closest("[data-go]");
      if (!btn || !appMain.contains(btn)) return;
      showScreen(btn.dataset.go);
      tg?.HapticFeedback?.selectionChanged();
    });

    document.getElementById("btnRefreshServer")?.addEventListener("click", async () => {
      const btn = document.getElementById("btnRefreshServer");
      if (btn) btn.disabled = true;
      try {
        await loadServerTab({ deep: true });
      } finally {
        if (btn) btn.disabled = false;
      }
    });

    document.getElementById("btnRefresh")?.addEventListener("click", async () => {
      const btn = document.getElementById("btnRefresh");
      if (btn) btn.disabled = true;
      try {
        await loadData();
        if (state.activeChannelId) {
          await openChannelDetail(state.activeChannelId);
        }
        if (state.activeBotId) {
          await openBotDetail(state.activeBotId);
        }
        showToast("داده‌ها به‌روز شد", { type: "success" });
        tg?.HapticFeedback?.impactOccurred("light");
      } catch (e) {
        showToast("به‌روزرسانی ناموفق بود", { type: "error" });
        console.error(e);
      } finally {
        if (btn) btn.disabled = false;
      }
    });

    document.getElementById("btnChannelBack")?.addEventListener("click", () => {
      closeChannelDetail();
      tg?.HapticFeedback?.selectionChanged();
    });

    document.getElementById("btnBotBack")?.addEventListener("click", () => {
      closeBotDetail();
      tg?.HapticFeedback?.selectionChanged();
    });

    document.getElementById("btnHashtagSetBack")?.addEventListener("click", () => {
      closeHashtagSetDetail();
      tg?.HapticFeedback?.selectionChanged();
    });

    document.getElementById("btnBannerBack")?.addEventListener("click", () => {
      closeBannerDetail();
      tg?.HapticFeedback?.selectionChanged();
    });

    document.querySelectorAll("[data-banner-tab]").forEach((btn) => {
      btn.addEventListener("click", () => {
        showBannerTab(btn.dataset.bannerTab || "overview");
        tg?.HapticFeedback?.selectionChanged();
      });
    });

    document.getElementById("btnBannerSyncGroups")?.addEventListener("click", syncBannerGroupsFromTelegram);
    document.getElementById("btnBannerSyncGroups2")?.addEventListener("click", syncBannerGroupsFromTelegram);

    document.getElementById("btnBannerAddFolder")?.addEventListener("click", () => {
      const folders = state.cache?.channels?.folders || [];
      if (!folders.length) {
        showToast("ابتدا یک پوشه کانال بسازید", { type: "warning" });
        return;
      }
      openFolderTreePicker({ mode: "banner_add_folder", folders, allowRoot: false });
    });

    document.getElementById("btnGlassButtonBack")?.addEventListener("click", () => {
      closeGlassButtonDetail();
      tg?.HapticFeedback?.selectionChanged();
    });
    document.getElementById("btnSaveGlassButton")?.addEventListener("click", saveGlassButtonSettings);
    document.getElementById("btnDeleteGlassButton")?.addEventListener("click", deleteGlassButtonSettings);
    document.getElementById("btnGlassButtonAddFolder")?.addEventListener("click", () => openGlassButtonFolderPicker("glass_add_folder"));
    document.getElementById("btnGlassButtonAddFolder2")?.addEventListener("click", () => openGlassButtonFolderPicker("glass_add_folder"));
    document.getElementById("glassButtonFolderBtn")?.addEventListener("click", () => openGlassButtonFolderPicker("glass_settings_folder"));
    document.querySelectorAll("[data-glass-button-tab]").forEach((btn) => {
      btn.addEventListener("click", () => {
        showGlassButtonTab(btn.dataset.glassButtonTab || "overview");
        tg?.HapticFeedback?.selectionChanged();
      });
    });
    ["glassButtonText", "glassButtonLineText"].forEach((id) => {
      document.getElementById(id)?.addEventListener("input", updateGlassButtonPreviewFromForm);
    });
    document.querySelectorAll('input[name="glassButtonDisplayMode"], input[name="glassButtonRows"]').forEach((input) => {
      input.addEventListener("change", updateGlassButtonPreviewFromForm);
    });

    document.getElementById("btnZapasBack")?.addEventListener("click", () => {
      closeZapasDetail();
      tg?.HapticFeedback?.selectionChanged();
    });
    document.querySelectorAll("[data-zapas-tab]").forEach((btn) => {
      btn.addEventListener("click", () => {
        showZapasTab(btn.dataset.zapasTab || "overview");
        tg?.HapticFeedback?.selectionChanged();
      });
    });
    document.getElementById("btnAddZapasBot")?.addEventListener("click", addZapasBotFromForm);
    document.getElementById("btnZapasRunCheck")?.addEventListener("click", runZapasHealthCheck);
    document.getElementById("btnZapasRunCheckOverview")?.addEventListener("click", runZapasHealthCheck);
    document.getElementById("btnZapasAddFolder")?.addEventListener("click", () => {
      const folders = state.cache?.channels?.folders || [];
      if (!folders.length) {
        showToast("ابتدا یک پوشه کانال بسازید", { type: "warning" });
        return;
      }
      openFolderTreePicker({ mode: "zapas_add_folder", folders, allowRoot: false });
    });

    document.querySelectorAll("[data-hashtag-set-tab]").forEach((btn) => {
      btn.addEventListener("click", () => {
        showHashtagSetTab(btn.dataset.hashtagSetTab || "overview");
        tg?.HapticFeedback?.selectionChanged();
      });
    });

    document.getElementById("btnPostSessionBack")?.addEventListener("click", () => {
      closePostSessionDetail();
      tg?.HapticFeedback?.selectionChanged();
    });

    document.querySelectorAll("[data-post-session-tab]").forEach((btn) => {
      btn.addEventListener("click", () => {
        showPostSessionTab(btn.dataset.postSessionTab || "overview");
        tg?.HapticFeedback?.selectionChanged();
      });
    });

    document.querySelectorAll("[data-bot-tab]").forEach((btn) => {
      btn.addEventListener("click", () => {
        showBotTab(btn.dataset.botTab || "overview");
        tg?.HapticFeedback?.selectionChanged();
      });
    });

    document.getElementById("btnCopyBotLink")?.addEventListener("click", async () => {
      const btn = document.getElementById("btnCopyBotLink");
      const url = btn?.dataset.link || "";
      if (!url) return;
      try {
        await navigator.clipboard.writeText(url);
        showToast("لینک کپی شد", { type: "success" });
      } catch (e) {
        showToast(url, { type: "info", duration: 4500 });
      }
    });

    document.querySelectorAll("[data-channel-tab]").forEach((btn) => {
      btn.addEventListener("click", () => {
        showChannelTab(btn.dataset.channelTab || "overview");
        tg?.HapticFeedback?.selectionChanged();
      });
    });

    document.getElementById("btnCreateInviteLink")?.addEventListener("click", async () => {
      if (!state.activeChannelId) return;
      const nameInput = document.getElementById("inviteLinkName");
      const limitInput = document.getElementById("inviteLinkLimit");
      const name = nameInput?.value?.trim() || "";
      const limit = limitInput?.value ? Number(limitInput.value) : null;
      const btn = document.getElementById("btnCreateInviteLink");
      if (btn) btn.disabled = true;
      try {
        await api("channel_invites.php", {
          method: "POST",
          body: {
            chat_id: state.activeChannelId,
            name,
            member_limit: limit,
          },
        });
        if (nameInput) nameInput.value = "";
        if (limitInput) limitInput.value = "";
        await loadInviteLinks(state.activeChannelId);
        showChannelTab("invites");
        showToast("لینک عضویت ساخته شد", { type: "success" });
        tg?.HapticFeedback?.notificationOccurred("success");
      } catch (e) {
        showToast("ساخت لینک ممکن نشد. دسترسی دعوت ربات را بررسی کنید.", { type: "error", duration: 4000 });
        console.error(e);
      } finally {
        if (btn) btn.disabled = false;
      }
    });

    document.getElementById("btnCopyChannelLink")?.addEventListener("click", async () => {
      const btn = document.getElementById("btnCopyChannelLink");
      const link = btn?.dataset.link || "";
      if (!link) return;
      try {
        await navigator.clipboard.writeText(link);
        showToast("لینک کانال کپی شد", { type: "success" });
        tg?.HapticFeedback?.notificationOccurred("success");
      } catch (e) {
        showToast(link, { type: "info", duration: 4500 });
      }
    });
  }

  async function boot() {
    if (!tg) {
      const err = document.getElementById("authError");
      if (err) {
        err.hidden = false;
        err.textContent = "این صفحه فقط داخل تلگرام قابل استفاده است.";
      }
      return;
    }

    tg.ready();
    tg.expand();
    if (typeof tg.disableVerticalSwipes === "function") {
      tg.disableVerticalSwipes();
    }
    document.documentElement.style.colorScheme = "light";
    tg.setHeaderColor("#eef1f6");
    tg.setBackgroundColor("#eef1f6");
    if (typeof tg.setBottomBarColor === "function") {
      tg.setBottomBarColor("#ffffff");
    }

    state.initData = tg.initData || "";
    bindEvents();

    try {
      await authenticate();
    } catch (error) {
      const err = document.getElementById("authError");
      if (err) {
        err.hidden = false;
        const code = error?.message || "";
        err.textContent = code === "invalid_init_data"
          ? "احراز هویت نامعتبر است. مینی‌اپ را دوباره از @gpro100_bot باز کنید."
          : "خطا در ورود. مینی‌اپ را از داخل @gpro100_bot باز کنید.";
      }
      console.error(error);
    }
  }

  document.addEventListener("DOMContentLoaded", boot);
})();
