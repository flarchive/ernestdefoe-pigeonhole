import Icon from 'flarum/common/components/Icon';

/** A folder's icon, in its colour. */
export default function folderIcon(folder) {
  return (
    <span className="PigeonholeIcon" style={folder?.color?.() ? { '--pigeonhole-color': folder.color() } : undefined}>
      <Icon name={folder?.icon?.() || 'fas fa-folder'} />
    </span>
  );
}
