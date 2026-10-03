import app from 'flarum/admin/app';

const t = (key) => app.translator.trans(`ernestdefoe-chronicle.admin.${key}`);

app.initializers.add('ernestdefoe-chronicle', () => {
  const type = (name, help) => ({
    setting: `ernestdefoe-chronicle.show_${name}`,
    type: 'boolean',
    label: t(`show_${name}`),
    help: help ? t(help) : undefined,
  });

  app.registry
    .for('ernestdefoe-chronicle')
    .registerSetting(() => (
      <div className="Form-group">
        <h3>{t('types_heading')}</h3>
        <p className="helpText">{t('types_help')}</p>
      </div>
    ))
    .registerSetting(type('discussion'))
    .registerSetting(type('reply'))
    .registerSetting(type('like', 'show_like_help'))
    .registerSetting(type('best_answer'))
    .registerSetting(type('badge'))
    .registerSetting(type('joined'))
    .registerPermission(
      {
        icon: 'fas fa-stream',
        label: t('permission_view'),
        permission: 'ernestdefoe-chronicle.viewActivity',
        allowGuest: true,
      },
      'view'
    );
});
