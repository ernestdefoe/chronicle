import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import LinkButton from 'flarum/common/components/LinkButton';
import Switch from 'flarum/common/components/Switch';

export { default as extend } from './extend';

const t = (key, params) => app.translator.trans(`ernestdefoe-chronicle.forum.${key}`, params);

app.initializers.add('ernestdefoe-chronicle', () => {
  /*
   * UserPage and SettingsPage are extended by path: SettingsPage is a lazily
   * loaded chunk, and a static import of it here would be a different, empty
   * module. Each extension is guarded so a fault here can't take the profile
   * page — or any other extension's initializer — down with it.
   */
  extend('flarum/forum/components/UserPage', 'navItems', function (items) {
    try {
      const user = this.user;
      if (!user) return;

      const self = app.session.user && app.session.user.id() === user.id();
      if (!self && !app.forum.attribute('canViewChronicle')) return;

      items.add(
        'chronicle',
        <LinkButton href={app.route('user.chronicle', { username: user.slug() })} icon="fas fa-stream">
          {t('nav')}
        </LinkButton>,
        105
      );
    } catch (e) {
      console.error('[chronicle]', e);
    }
  });

  extend('flarum/forum/components/SettingsPage', 'privacyItems', function (items) {
    try {
      if (!('flarum-likes' in (window.flarum?.extensions || {}))) return;

      const user = this.user;
      if (!user) return;

      items.add(
        'chronicleShowLikes',
        <Switch
          state={(user.preferences() || {}).chronicleShowLikes !== false}
          loading={this.chronicleLikesLoading}
          onchange={(value) => {
            this.chronicleLikesLoading = true;
            user.savePreferences({ chronicleShowLikes: value }).finally(() => {
              this.chronicleLikesLoading = false;
              m.redraw();
            });
          }}
        >
          {t('settings.show_likes')}
        </Switch>,
        90
      );
    } catch (e) {
      console.error('[chronicle]', e);
    }
  });
});
