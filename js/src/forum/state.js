import app from 'flarum/forum/app';

export const t = (key, params) => app.translator.trans('ernestdefoe-pigeonhole.forum.' + key, params);

export const ARCHIVE = 'archive';

/** Where a conversation is filed, for the member reading it. */
export const folderIdOf = (dialog) => {
  const id = dialog.attribute('pigeonholeFolderId');

  return id === null || id === undefined ? null : String(id);
};

export const isArchived = (dialog) => !!dialog.attribute('pigeonholeArchived');

/** Takes a conversation out of a loaded list, without reloading it. */
export function dropFrom(list, dialog) {
  (list.pages || []).forEach((page) => {
    const index = page.items.indexOf(dialog);

    if (index !== -1) page.items.splice(index, 1);
  });
}

/**
 * The member's folders and which one the Messages page shows. Lives outside
 * the page, so opening a conversation (a route change that rebuilds the page)
 * keeps the folder. Loaded by the Messages page only, never elsewhere.
 */
const folders = {
  /** The member's folders in their order, or null before the first load. */
  list: null,
  /** null for every conversation, ARCHIVE, or a folder id. */
  selected: null,
  loading: null,
  /** The member's unread conversation count the folder counts were read at. */
  countedAt: null,

  load() {
    if (this.loading) return this.loading;

    this.countedAt = app.session.user?.attribute('unreadDialogCount') ?? 0;

    this.loading = app.store
      .find('pigeonhole-folders')
      .then((list) => {
        this.list = list.slice();
        // A folder deleted elsewhere is not one to stay in.
        if (this.selected && this.selected !== ARCHIVE && !this.byId(this.selected)) this.select(null);
      })
      .catch(() => {
        this.list ??= [];
      })
      .finally(() => {
        this.loading = null;
        m.redraw();
      });

    return this.loading;
  },

  /** Reloads the counts once the member's unread count has moved. */
  refreshIfStale() {
    if (this.list === null) return this.load();

    if ((app.session.user?.attribute('unreadDialogCount') ?? 0) !== this.countedAt) this.load();
  },

  byId(id) {
    return (this.list || []).find((folder) => folder.id() === String(id)) || null;
  },

  select(id) {
    id = id === null || id === undefined ? null : String(id);

    if (id === this.selected) return;

    this.selected = id;

    const params = app.dialogs.getParams();
    const filter = { ...(params.filter || {}) };

    if (id) filter.folder = id;
    else delete filter.folder;

    params.filter = filter;
    app.dialogs.refresh();

    // A phone shows the conversation over the list: back to the list.
    if (app.screen() === 'phone' && m.route.param('id')) m.route.set(app.route('messages'));
  },

  /** Whether the list on screen has room for this conversation. */
  shows(dialog) {
    if (this.selected === ARCHIVE) return isArchived(dialog);
    if (isArchived(dialog)) return false;

    return !this.selected || folderIdOf(dialog) === this.selected;
  },

  afterChange(dialog) {
    if (!this.shows(dialog)) dropFrom(app.dialogs, dialog);

    this.load();
    m.redraw();
  },

  /** Puts a conversation in a folder (or none), for this member only. */
  move(dialog, folderId) {
    return dialog.save({ pigeonholeFolderId: folderId === null ? null : Number(folderId) }).then(() => this.afterChange(dialog));
  },

  /** Archives a conversation (reading it), or brings it back. */
  archive(dialog, archived) {
    const unread = !!dialog.unreadCount();

    return dialog.save({ pigeonholeArchived: archived }).then(() => {
      if (archived) {
        dialog.pushAttributes({ unreadCount: 0, lastReadMessageId: dialog.lastMessageId() });
        dropFrom(app.dropdownDialogs, dialog);

        if (unread) {
          const user = app.session.user;
          user.pushAttributes({ unreadDialogCount: Math.max(0, (user.attribute('unreadDialogCount') || 0) - 1) });
        }

        app.alerts.show({ type: 'success' }, t('dialog.archived_alert'));
      }

      this.afterChange(dialog);
    });
  },

  /** Saves the order the member dragged their folders into. */
  sort(ids) {
    const byId = new Map((this.list || []).map((folder) => [folder.id(), folder]));
    this.list = ids.map((id) => byId.get(id)).filter(Boolean);
    this.list.forEach((folder, position) => folder.pushAttributes({ position }));
    m.redraw();

    return app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/pigeonhole-folders/order',
        body: { order: ids.map(Number) },
      })
      .catch(() => this.load());
  },
};

export default folders;
