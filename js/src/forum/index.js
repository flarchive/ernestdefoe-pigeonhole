import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Component from 'flarum/common/Component';
import InfoTile from 'flarum/common/components/InfoTile';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import folders, { t, ARCHIVE, folderIdOf, isArchived, dropFrom } from './state';
import dragSort from './dragSort';
import folderIcon from './folderIcon';

const reg = flarum.reg;
const DIALOG_TYPE = 'text/x-pigeonhole-dialog';

let modals;
/** The folder dialogs, loaded the first time one is opened. */
export const openModal = (name, attrs = {}) => {
  modals ??= import('./components/modals').catch((error) => {
    modals = null;
    throw error;
  });

  return modals.then((module) => app.modal.show(module[name], attrs));
};

/** Where a dragged conversation can be dropped: a folder, or the archive. */
function dropTarget(onDrop) {
  const has = (e) => Array.from(e.dataTransfer?.types || []).includes(DIALOG_TYPE);

  return {
    ondragover(e) {
      e.redraw = false;
      if (!has(e)) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      e.currentTarget.classList.add('is-dropTarget');
    },
    ondragleave(e) {
      e.redraw = false;
      e.currentTarget.classList.remove('is-dropTarget');
    },
    ondrop(e) {
      e.currentTarget.classList.remove('is-dropTarget');
      if (!has(e)) return;
      e.preventDefault();
      const dialog = app.store.getById('dialogs', e.dataTransfer.getData(DIALOG_TYPE));
      if (dialog) onDrop(dialog);
    },
  };
}

/** The first vnode under `vnode` whose class list has `name`. */
function findByClass(vnode, name) {
  if (!vnode || typeof vnode !== 'object') return null;
  if (Array.isArray(vnode)) {
    for (const child of vnode) {
      const found = findByClass(child, name);
      if (found) return found;
    }
    return null;
  }
  if (
    String(vnode.attrs?.className || '')
      .split(' ')
      .includes(name)
  )
    return vnode;

  return findByClass(vnode.children, name);
}

/** One chip in the folder bar. */
function chip({ label, icon, color, count, active, onclick, ...attrs }) {
  return (
    <button
      type="button"
      className={classList('PigeonholeBar-chip', { active })}
      aria-pressed={active ? 'true' : 'false'}
      style={color ? { '--pigeonhole-color': color } : undefined}
      onclick={onclick}
      {...attrs}
    >
      {icon ? <Icon name={icon} className="PigeonholeBar-icon" /> : null}
      <span className="PigeonholeBar-label">{label}</span>
      {count ? <span className="PigeonholeBar-count">{count}</span> : null}
    </button>
  );
}

/** Two rows of folders until the member asks for the rest. */
let expanded = false;

class FolderBar extends Component {
  oncreate(vnode) {
    super.oncreate(vnode);
    this.measure();
  }

  onupdate(vnode) {
    super.onupdate(vnode);
    this.measure();
  }

  /** Whether the folders run past two rows, which is when More shows. */
  measure() {
    const chips = this.element.querySelectorAll('.PigeonholeBar-chip');
    const top = (el) => el.getBoundingClientRect().top;
    // A chip starting below the second row.
    const overflows = expanded || (chips.length > 1 && top(chips[chips.length - 1]) - top(chips[0]) > 48);

    if (overflows !== this.overflows) {
      this.overflows = overflows;
      m.redraw();
    }
  }

  view() {
    const list = folders.list || [];
    const ids = list.map((folder) => folder.id());
    const unreadAll = app.session.user?.attribute('unreadDialogCount') || 0;

    return (
      <div className={classList('PigeonholeBar', { 'PigeonholeBar--collapsed': !expanded })} role="toolbar" aria-label={t('nav.folders')}>
        {this.overflows ? (
          <button
            type="button"
            className="Button Button--icon Button--link PigeonholeBar-more"
            aria-expanded={expanded ? 'true' : 'false'}
            title={t(expanded ? 'nav.fewer' : 'nav.more')}
            aria-label={t(expanded ? 'nav.fewer' : 'nav.more')}
            onclick={() => (expanded = !expanded)}
          >
            <Icon name={expanded ? 'fas fa-chevron-up' : 'fas fa-chevron-down'} />
          </button>
        ) : null}
        <div className="PigeonholeBar-chips">
          {chip({ label: t('nav.all'), icon: 'far fa-envelope', count: unreadAll, active: !folders.selected, onclick: () => folders.select(null) })}
          {list.map((folder) =>
            chip({
              label: folder.name(),
              icon: folder.icon() || 'fas fa-folder',
              color: folder.color(),
              count: folder.unreadCount(),
              active: folders.selected === folder.id(),
              title: folder.unreadCount() ? extractText(t('nav.unread_count', { count: folder.unreadCount() })) : undefined,
              'data-folder-id': folder.id(),
              onclick: () => folders.select(folder.id()),
              onpointerdown: (e) => {
                e.redraw = false;
                const row = e.currentTarget;
                const rows = Array.from(row.parentElement.querySelectorAll('[data-folder-id]'));
                dragSort(e, { row, rows, ids, axis: 'x', mouseOnly: true, onsort: (order) => folders.sort(order) });
              },
              ...dropTarget((dialog) => folders.move(dialog, folder.id())),
            })
          )}
        </div>
      </div>
    );
  }
}

app.initializers.add('ernestdefoe-pigeonhole', () => {
  // ----- The folder bar above the conversation list: every conversation,
  // each folder, the archive.
  reg.onLoad('flarum-messages', 'forum/components/MessagesPage', (MessagesPage) => {
    extend(MessagesPage.prototype, 'contentItems', function (items) {
      if (!app.session.user || !items.has('sidebar')) return;

      const sidebar = items.get('sidebar');
      const children = Array.isArray(sidebar?.children) ? sidebar.children : null;
      if (!children) return;

      folders.refreshIfStale();

      // Nothing to choose between until the member has a folder.
      if (!folders.list?.length) return;

      // Just above the list (or Group Messages' search results in its place).
      const at = children.findIndex((child) => child && ['list', 'groupMessagesSearchResults'].includes(child.key));
      children.splice(at === -1 ? children.length : at, 0, <FolderBar key="pigeonholeBar" />);
    });
  });

  // ----- Each conversation in a list: draggable onto a folder, and says
  // which folder it is in when every conversation is shown.
  reg.onLoad('flarum-messages', 'forum/components/DialogListItem', (DialogListItem) => {
    extend(DialogListItem.prototype, 'view', function (vdom) {
      const dialog = this.attrs.dialog;
      if (!vdom?.attrs || !dialog) return;

      vdom.attrs.ondragstart = (e) => {
        e.redraw = false;
        e.dataTransfer?.setData(DIALOG_TYPE, dialog.id());
      };

      const folder = !folders.selected && folders.byId(folderIdOf(dialog));
      const preview = folder && findByClass(vdom, 'DialogListItem-lastMessage');

      if (preview) {
        preview.children = [
          <span className="PigeonholeChip" style={folder.color() ? { '--pigeonhole-color': folder.color() } : undefined}>
            {folderIcon(folder)}
            <span className="PigeonholeChip-name">{folder.name()}</span>
          </span>,
          ...(Array.isArray(preview.children) ? preview.children : [preview.children]),
        ];
      }
    });
  });

  // ----- A conversation's ⋯ menu: move it, archive it.
  reg.onLoad('flarum-messages', 'forum/components/DialogSection', (DialogSection) => {
    extend(DialogSection.prototype, 'controlItems', function (items) {
      const dialog = this.attrs.dialog;
      if (!app.session.user || !dialog) return;

      const archived = isArchived(dialog);

      items.add(
        'pigeonholeMove',
        <Button icon="fas fa-folder-open" onclick={() => openModal('MoveModal', { dialog })}>
          {t('dialog.move_to')}
        </Button>,
        55
      );

      items.add(
        'pigeonholeArchive',
        <Button icon={archived ? 'fas fa-box-open' : 'fas fa-box-archive'} onclick={() => folders.archive(dialog, !archived).catch(() => {})}>
          {t(archived ? 'dialog.unarchive' : 'dialog.archive')}
        </Button>,
        54
      );
    });
  });

  // ----- Beside refresh and mark-all-read: the archive and the folder settings.
  reg.onLoad('flarum-messages', 'forum/components/MessagesPage', (MessagesPage) => {
    extend(MessagesPage.prototype, 'actionItems', function (items) {
      if (!app.session.user) return;

      const archived = folders.selected === ARCHIVE;
      const any = (folders.list || []).length > 0;
      const manage = t(any ? 'nav.manage_folders' : 'nav.new_folder');

      items.add(
        'pigeonholeArchive',
        <Button
          className={classList('Button Button--icon PigeonholeArchiveButton', { active: archived })}
          icon="fas fa-box-archive"
          aria-pressed={archived ? 'true' : 'false'}
          title={t('nav.archive')}
          aria-label={t('nav.archive')}
          onclick={() => folders.select(archived ? null : ARCHIVE)}
          {...dropTarget((dialog) => folders.archive(dialog, true))}
        />,
        20
      );

      items.add(
        'pigeonholeManage',
        <Button
          className="Button Button--icon PigeonholeManageButton"
          icon={any ? 'fas fa-folder-tree' : 'fas fa-folder-plus'}
          title={manage}
          aria-label={manage}
          onclick={() => openModal(any ? 'ManageFoldersModal' : 'FolderEditModal')}
        />,
        15
      );
    });
  });

  // ----- An empty folder says so, rather than "no messages".
  reg.onLoad('flarum-messages', 'forum/components/MessagesPage', (MessagesPage) => {
    extend(MessagesPage.prototype, 'view', function (vdom) {
      if (!folders.selected) return;

      const children = Array.isArray(vdom?.children) ? vdom.children : [];
      const tile = children.find((child) => child?.tag === InfoTile);

      if (tile) {
        tile.attrs.icon = folders.selected === ARCHIVE ? 'fas fa-box-archive' : 'far fa-folder-open';
        tile.children = [t(folders.selected === ARCHIVE ? 'list.archive_empty' : 'list.folder_empty')];
      }
    });
  });

  reg.onLoad('flarum-messages', 'forum/components/DialogList', (DialogList) => {
    extend(DialogList.prototype, 'view', function (vdom) {
      const state = this.attrs.state;
      if (!folders.selected || state !== app.dialogs || state.isInitialLoading() || state.hasItems() || !vdom) return;

      vdom.children = [
        <p className="PigeonholeEmpty">{t(folders.selected === ARCHIVE ? 'list.archive_empty' : 'list.folder_empty')}</p>,
        ...(vdom.children || []),
      ];
    });
  });

  // ----- A new message: it leaves the archive, and only shows in the list
  // on screen if it belongs there.
  const lists = app.dialogs && Object.getPrototypeOf(app.dialogs);

  if (lists?.moveToTop) {
    override(lists, 'moveToTop', function (original, dialog, ...args) {
      if (isArchived(dialog)) dialog.pushAttributes({ pigeonholeArchived: false });

      if (this === app.dialogs && !folders.shows(dialog)) {
        dropFrom(this, dialog);
        return;
      }

      return original(dialog, ...args);
    });
  }
});
