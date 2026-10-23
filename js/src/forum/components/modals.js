import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import folders, { t, folderIdOf } from '../state';
import folderIcon from '../folderIcon';
import dragSort from '../dragSort';

const SWATCHES = ['#0f766e', '#2563eb', '#7c3aed', '#db2777', '#dc2626', '#ea580c', '#ca8a04', '#16a34a', '#475569'];
const ICONS = [
  'fas fa-folder',
  'fas fa-briefcase',
  'fas fa-house',
  'fas fa-heart',
  'fas fa-star',
  'fas fa-users',
  'fas fa-gamepad',
  'fas fa-cart-shopping',
  'fas fa-graduation-cap',
  'fas fa-plane',
  'fas fa-shield-halved',
  'fas fa-bookmark',
];
const ICON_PATTERN = /^[a-z0-9 -]*$/;

/** New folder, or change one: name, colour, icon. */
export class FolderEditModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);

    const folder = this.attrs.folder;

    this.name = folder ? folder.name() : '';
    this.color = folder ? folder.color() || '' : SWATCHES[0];
    this.icon = folder ? folder.icon() || '' : 'fas fa-folder';
  }

  className() {
    return 'PigeonholeEditModal Modal--small';
  }

  title() {
    return t(this.attrs.folder ? 'edit.edit_title' : 'edit.new_title');
  }

  content() {
    const preview = { color: () => this.color || null, icon: () => this.icon.trim() || null };

    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label for="pigeonhole-name">{t('edit.name_label')}</label>
            <div className="PigeonholeEditModal-name">
              {folderIcon(preview)}
              <input
                id="pigeonhole-name"
                className="FormControl"
                name="name"
                maxlength="50"
                autocomplete="off"
                value={this.name}
                oninput={(e) => (this.name = e.target.value)}
              />
            </div>
          </div>

          <div className="Form-group">
            <label>{t('edit.color_label')}</label>
            <div className="PigeonholeSwatches">
              <button
                type="button"
                className={classList('PigeonholeSwatch PigeonholeSwatch--none', { active: !this.color })}
                aria-pressed={!this.color ? 'true' : 'false'}
                onclick={() => (this.color = '')}
              >
                {t('edit.no_color')}
              </button>
              {SWATCHES.map((color) => (
                <button
                  type="button"
                  className={classList('PigeonholeSwatch', { active: this.color === color })}
                  style={{ '--pigeonhole-color': color }}
                  aria-label={color}
                  aria-pressed={this.color === color ? 'true' : 'false'}
                  onclick={() => (this.color = color)}
                />
              ))}
              <input
                type="color"
                className="PigeonholeSwatch-custom"
                aria-label={t('edit.color_label')}
                value={this.color || '#000000'}
                oninput={(e) => (this.color = e.target.value.toLowerCase())}
              />
            </div>
          </div>

          <div className="Form-group">
            <label for="pigeonhole-icon">{t('edit.icon_label')}</label>
            <div className="PigeonholeIcons">
              {ICONS.map((icon) => (
                <button
                  type="button"
                  className={classList('Button Button--icon PigeonholeIcons-choice', { active: this.icon === icon })}
                  aria-label={icon}
                  aria-pressed={this.icon === icon ? 'true' : 'false'}
                  onclick={() => (this.icon = icon)}
                >
                  <Icon name={icon} />
                </button>
              ))}
            </div>
            <input
              id="pigeonhole-icon"
              className="FormControl"
              name="icon"
              maxlength="64"
              autocomplete="off"
              placeholder="fas fa-folder"
              value={this.icon}
              oninput={(e) => {
                if (ICON_PATTERN.test(e.target.value)) this.icon = e.target.value;
                else e.target.value = this.icon;
              }}
            />
            <p className="helpText">{t('edit.icon_help')}</p>
          </div>

          <div className="Form-group">
            <Button type="submit" className="Button Button--primary Button--block" loading={this.loading} disabled={!this.name.trim()}>
              {t('edit.save_button')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e) {
    e.preventDefault();

    if (!this.name.trim()) return;

    this.loading = true;

    const folder = this.attrs.folder || app.store.createRecord('pigeonhole-folders');

    folder
      .save({ name: this.name.trim(), color: this.color || null, icon: this.icon.trim() || null })
      .then((saved) => {
        if (!folders.list?.includes(saved)) folders.list = [...(folders.list || []), saved];

        return this.attrs.onsave?.(saved);
      })
      .then(() => {
        folders.load();
        this.hide();
      })
      .catch((error) => {
        this.loading = false;
        this.onerror(error);
      });
  }
}

/**
 * On a phone the modal slides up while its close button takes focus, which
 * scrolls a long list to its end. It opens at the top.
 */
function toTop(modal) {
  requestAnimationFrame(() => modal.element?.closest('.ModalManager')?.scrollTo?.(0, 0));
}

/** The member's folders: drag to reorder, edit, delete, make one. */
export class ManageFoldersModal extends Modal {
  onready() {
    toTop(this);
  }

  className() {
    return 'PigeonholeManageModal Modal--small';
  }

  title() {
    return t('manage.title');
  }

  content() {
    const list = folders.list;
    const ids = (list || []).map((folder) => folder.id());

    return (
      <div className="Modal-body">
        {list === null ? (
          <LoadingIndicator />
        ) : !list.length ? (
          <p className="PigeonholeManageModal-empty">{t('manage.empty')}</p>
        ) : (
          <ul className="PigeonholeManageModal-list">
            {list.map((folder) => (
              <li key={folder.id()} className="PigeonholeManageModal-row">
                <span
                  className="PigeonholeManageModal-handle"
                  title={t('manage.drag_handle')}
                  aria-label={t('manage.drag_handle')}
                  onpointerdown={(e) => {
                    e.redraw = false;
                    const row = e.currentTarget.closest('li');
                    dragSort(e, { row, rows: Array.from(row.parentElement.children), ids, onsort: (order) => folders.sort(order) });
                  }}
                >
                  <Icon name="fas fa-grip-vertical" />
                </span>
                {folderIcon(folder)}
                <span className="PigeonholeManageModal-name">
                  {folder.name()}
                  <small>{t('manage.count', { count: folder.dialogCount() || 0 })}</small>
                </span>
                <Button
                  className="Button Button--icon Button--link"
                  icon="fas fa-pencil"
                  aria-label={t('manage.edit_button')}
                  title={t('manage.edit_button')}
                  onclick={() => app.modal.show(FolderEditModal, { folder }, true)}
                />
                <Button
                  className="Button Button--icon Button--link"
                  icon="fas fa-trash"
                  aria-label={t('manage.delete_button')}
                  title={t('manage.delete_button')}
                  onclick={() => this.delete(folder)}
                />
              </li>
            ))}
          </ul>
        )}
        <Button className="Button Button--primary Button--block" icon="fas fa-plus" onclick={() => app.modal.show(FolderEditModal, {}, true)}>
          {t('manage.new_button')}
        </Button>
      </div>
    );
  }

  delete(folder) {
    if (!confirm(extractText(t('manage.delete_confirm', { name: folder.name() })))) return;

    const id = folder.id();

    folder.delete().then(() => {
      folders.list = (folders.list || []).filter((other) => other !== folder);
      // Its conversations are now in no folder.
      app.store.all('dialogs').forEach((dialog) => {
        if (folderIdOf(dialog) === id) dialog.pushAttributes({ pigeonholeFolderId: null });
      });

      if (folders.selected === id) folders.select(null);
      folders.load();
    });
  }
}

/** Where to put this conversation. */
export class MoveModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);

    if (folders.list === null) folders.load();
  }

  onready() {
    toTop(this);
  }

  className() {
    return 'PigeonholeMoveModal Modal--small';
  }

  title() {
    return t('dialog.move_to');
  }

  content() {
    const dialog = this.attrs.dialog;
    const current = folderIdOf(dialog);
    const list = folders.list;

    return (
      <div className="Modal-body">
        {list === null ? (
          <LoadingIndicator />
        ) : (
          <ul className="PigeonholeMoveModal-list">
            {list.map((folder) => (
              <li>
                <Button
                  className={classList('Button PigeonholeMoveModal-choice', { active: current === folder.id() })}
                  aria-pressed={current === folder.id() ? 'true' : 'false'}
                  disabled={this.loading}
                  onclick={() => this.move(folder.id())}
                >
                  {folderIcon(folder)}
                  <span className="PigeonholeMoveModal-name">{folder.name()}</span>
                  {current === folder.id() ? <Icon name="fas fa-check" /> : null}
                </Button>
              </li>
            ))}
            {current ? (
              <li>
                <Button className="Button PigeonholeMoveModal-choice" disabled={this.loading} onclick={() => this.move(null)}>
                  <Icon name="fas fa-folder-minus" />
                  <span className="PigeonholeMoveModal-name">{t('dialog.remove_from_folder')}</span>
                </Button>
              </li>
            ) : null}
          </ul>
        )}
        <Button
          className="Button Button--block"
          icon="fas fa-folder-plus"
          onclick={() => app.modal.show(FolderEditModal, { onsave: (folder) => folders.move(dialog, folder.id()) }, true)}
        >
          {t('manage.new_button')}
        </Button>
      </div>
    );
  }

  move(folderId) {
    this.loading = true;

    folders
      .move(this.attrs.dialog, folderId)
      .then(() => this.hide())
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
