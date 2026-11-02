import { test } from 'node:test';
import assert from 'node:assert/strict';
import reorder from '../src/forum/reorder.js';

test('moves a folder down', () => {
  assert.deepEqual(reorder(['1', '2', '3', '4'], 0, 2), ['2', '3', '1', '4']);
});

test('moves a folder up', () => {
  assert.deepEqual(reorder(['1', '2', '3', '4'], 3, 0), ['4', '1', '2', '3']);
});

test('leaves the list it was given alone', () => {
  const ids = ['1', '2'];
  reorder(ids, 0, 1);
  assert.deepEqual(ids, ['1', '2']);
});
