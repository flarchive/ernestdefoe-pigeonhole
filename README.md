# Pigeonhole

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/ernestdefoe/pigeonhole.svg)](https://packagist.org/packages/ernestdefoe/pigeonhole)

Folders for private messages in [Flarum 2](https://flarum.org) ([flarum/messages](https://github.com/flarum/messages)). Each member sorts their own conversations into folders with a colour and an icon, archives the ones they are done with until someone writes again, and sees at a glance where the unread ones are.

![Folders on the Messages page](https://raw.githubusercontent.com/ernestdefoe/pigeonhole/main/.github/screenshots/folders.png)

## Features

- **Your own folders.** Make as many as you need (up to 50), each with a name, a colour and a Font Awesome icon. Nobody else ever sees them.
- **Filing is personal.** Putting a conversation in a folder changes only your view. The other people in it keep theirs.
- **Drag and drop.** Drag a conversation from the list onto a folder to file it, or onto Archive to put it away. Drag folders into the order you want, in the folder bar or in Manage folders (which also works by touch on phones and tablets).
- **Archive.** An archived conversation leaves your list and the header's messages dropdown, and is marked read. It comes back on its own, to the folder it was in, as soon as there is a new message.
- **Unread counts per folder**, beside each folder's name, so you can see where the new messages are without opening every folder.
- **From the conversation itself.** Its ⋯ menu has *Move to folder* and *Archive*, which is how you file on a phone.
- **Works with [Group Messages](https://github.com/ernestdefoe/group-messages).** Group conversations go in folders like any other, and its Messages-page search still finds every conversation, archived ones included.
- **Tidy by design.** Leave a group, or be removed from it, and it leaves your folders. Delete a folder and its conversations go back to your list. Delete an account and its folders go with it. With [flarum/gdpr](https://github.com/flarum/gdpr), a member's folders are in their data export and are removed when they are anonymised or erased.
- **Light.** Nothing is added to pages other than Messages. Folders load with the Messages page in one request, and each conversation's folder comes with the conversation list in a single query, however long the list is.

![Choosing a folder for a conversation](https://raw.githubusercontent.com/ernestdefoe/pigeonhole/main/.github/screenshots/move.png)

![Managing folders](https://raw.githubusercontent.com/ernestdefoe/pigeonhole/main/.github/screenshots/manage.png)

## Installation

```sh
composer require ernestdefoe/pigeonhole
php flarum migrate
php flarum cache:clear
```

Needs flarum/messages enabled.

## Updating

```sh
composer update ernestdefoe/pigeonhole
php flarum migrate
php flarum cache:clear
```

## For developers

- `GET /api/dialogs?filter[folder]=<id>` lists the actor's conversations in one of their folders, and `filter[folder]=archive` their archive. Without the filter (and without a search), archived conversations are left out.
- Conversations have two attributes for the actor, on the dialog list and a single dialog: `pigeonholeFolderId` and `pigeonholeArchived`. Both can be set with `PATCH /api/dialogs/{id}`.
- Folders are the `pigeonhole-folders` resource (`GET`, `POST`, `PATCH`, `DELETE`), with `POST /api/pigeonhole-folders/order` taking `{"order": [ids]}`.

## Links

- [Source](https://github.com/ernestdefoe/pigeonhole)
- [Report an issue](https://github.com/ernestdefoe/pigeonhole/issues)
- ☕ [Buy me a coffee](https://buymeacoffee.com/ernestdefoe)
