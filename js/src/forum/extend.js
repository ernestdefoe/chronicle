import Extend from 'flarum/common/extenders';
import UserPageResolver from 'flarum/forum/resolvers/UserPageResolver';

export default [
  // The page is its own chunk: it loads when somebody opens the tab, not with
  // every page of the forum.
  new Extend.Routes().add('user.chronicle', '/u/:username/activity', () => import('./components/ChroniclePage'), UserPageResolver),
];
