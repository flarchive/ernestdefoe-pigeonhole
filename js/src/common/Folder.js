import Model from 'flarum/common/Model';

export default class Folder extends Model {}

Object.assign(Folder.prototype, {
  name: Model.attribute('name'),
  color: Model.attribute('color'),
  icon: Model.attribute('icon'),
  position: Model.attribute('position'),
  dialogCount: Model.attribute('dialogCount'),
  unreadCount: Model.attribute('unreadCount'),
});
