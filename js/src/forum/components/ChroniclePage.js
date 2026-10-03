import app from 'flarum/forum/app';
import UserPage from 'flarum/forum/components/UserPage';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';

const t = (key, params) => app.translator.trans(`ernestdefoe-chronicle.forum.${key}`, params);

const ICONS = {
  discussion: 'far fa-comments',
  reply: 'fas fa-reply',
  like: 'far fa-thumbs-up',
  best_answer: 'fas fa-check',
  badge: 'fas fa-award',
  joined: 'fas fa-user-plus',
};

// The chips, in this order. "Joined" has none: it is one line at the very end.
const FILTERS = ['discussion', 'reply', 'like', 'best_answer', 'badge'];

/**
 * A member's activity: everything they have done, newest first, grouped by day.
 */
export default class ChroniclePage extends UserPage {
  oninit(vnode) {
    super.oninit(vnode);

    this.items = [];
    this.next = null;
    this.types = null;
    this.filter = 'all';
    this.loading = true;
    this.failed = false;
    this.request = 0;

    this.loadUser(m.route.param('username'));
  }

  show(user) {
    super.show(user);
    this.refresh();
  }

  refresh() {
    this.items = [];
    this.next = null;
    this.load();
  }

  load(cursor) {
    const ticket = ++this.request;
    const params = {};

    if (this.filter !== 'all') params.types = this.filter;
    if (cursor) {
      params.before = cursor.before;
      params.key = cursor.key;
    }

    this.loading = true;
    this.failed = false;

    return app
      .request({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/chronicle/${this.user.id()}`,
        params,
      })
      .then((response) => {
        // A chip clicked while this page was on its way makes it stale.
        if (ticket !== this.request) return;

        this.items = this.items.concat(response.items || []);
        this.next = response.next || null;
        if (!this.types) this.types = response.types || [];
      })
      .catch(() => {
        if (ticket === this.request) this.failed = true;
      })
      .finally(() => {
        if (ticket !== this.request) return;
        this.loading = false;
        m.redraw();
      });
  }

  choose(filter) {
    if (filter === this.filter) return;
    this.filter = filter;
    this.refresh();
  }

  content() {
    return (
      <div className="Chronicle">
        {this.filters()}
        {this.feed()}
      </div>
    );
  }

  filters() {
    const offered = FILTERS.filter((type) => (this.types || []).includes(type));

    // One kind of item only: there is nothing to choose between.
    if (offered.length < 2) return null;

    return (
      <div className="Chronicle-filters" role="toolbar" aria-label={t('filters.label')}>
        {['all', ...offered].map((type) => (
          <button
            type="button"
            className={'Chronicle-chip' + (this.filter === type ? ' is-active' : '')}
            aria-pressed={this.filter === type ? 'true' : 'false'}
            onclick={() => this.choose(type)}
          >
            {type !== 'all' ? <Icon name={ICONS[type]} /> : null}
            <span>{t(`filters.${type}`)}</span>
          </button>
        ))}
      </div>
    );
  }

  feed() {
    if (this.items.length === 0) {
      if (this.loading) return <LoadingIndicator />;
      if (this.failed) return this.failure();

      return (
        <div className="Chronicle-empty">
          <Icon name="fas fa-stream" />
          <p>{this.filter === 'all' ? t('empty') : t('empty_filtered')}</p>
        </div>
      );
    }

    return (
      <div className="Chronicle-feed">
        {this.days().map((day) => (
          <section className="Chronicle-day" key={day.key}>
            <h3 className="Chronicle-dayTitle">{day.label}</h3>
            <ol className="Chronicle-list">
              {day.items.map((item) => (
                <li className={`Chronicle-item Chronicle-item--${item.type}`} key={item.id}>
                  {this.item(item)}
                </li>
              ))}
            </ol>
          </section>
        ))}

        {this.failed ? this.failure() : null}

        {this.next && !this.failed ? (
          <div className="Chronicle-more">
            <Button className="Button" loading={this.loading} onclick={() => this.load(this.next)}>
              {t('load_more')}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }

  failure() {
    return (
      <div className="Chronicle-empty Chronicle-empty--failed">
        <p>{t('failed')}</p>
        <Button className="Button" onclick={() => this.load(this.next && this.items.length ? this.next : null)}>
          {t('retry')}
        </Button>
      </div>
    );
  }

  /** The items in runs of the same local day. */
  days() {
    const dayjs = window.dayjs;
    const today = dayjs().startOf('day');
    const yesterday = today.subtract(1, 'day');
    const days = [];

    this.items.forEach((item) => {
      const date = dayjs(item.date);
      const key = date.format('YYYY-MM-DD');
      let day = days[days.length - 1];

      if (!day || day.date !== key) {
        let label;
        if (date.isSame(today, 'day')) label = t('today');
        else if (date.isSame(yesterday, 'day')) label = t('yesterday');
        else label = app.translator.formatDateTime(date, 'ernestdefoe-chronicle.forum.day_format');

        // Keyed by position too: an imported forum's join date can sit out of
        // order, and two runs of one day must not share a key.
        day = { key: `${key}-${days.length}`, date: key, label, items: [] };
        days.push(day);
      }

      day.items.push(item);
    });

    return days;
  }

  item(item) {
    const showExcerpt = ['reply', 'discussion', 'best_answer'].includes(item.type) && item.excerpt;

    return [
      <span className="Chronicle-icon" aria-hidden="true">
        <Icon name={ICONS[item.type] || 'fas fa-circle'} />
      </span>,
      <div className="Chronicle-body">
        <div className="Chronicle-line">
          <span className="Chronicle-sentence">{this.sentence(item)}</span>
          <span className="Chronicle-time">{humanTime(new Date(item.date))}</span>
        </div>
        {showExcerpt ? (
          <Link className="Chronicle-excerpt" href={this.postUrl(item)}>
            {item.excerpt}
          </Link>
        ) : null}
      </div>,
    ];
  }

  sentence(item) {
    const title = item.discussion ? (
      <Link className="Chronicle-title" href={this.postUrl(item)}>
        {item.discussion.title}
      </Link>
    ) : (
      t('item.untitled')
    );

    switch (item.type) {
      case 'like':
        return item.author ? t('item.like', { author: <strong>{item.author.displayName}</strong>, title }) : t('item.like_no_author', { title });
      case 'badge': {
        const badge = item.badge || {};
        return t('item.badge', {
          badge: (
            <span className="Chronicle-badge" style={{ '--chronicle-badge-bg': badge.backgroundColor, '--chronicle-badge-fg': badge.iconColor }}>
              {badge.icon ? <Icon name={badge.icon} /> : null}
              {badge.name}
            </span>
          ),
        });
      }
      case 'joined':
        return t('item.joined');
      default:
        return t(`item.${item.type}`, { title });
    }
  }

  postUrl(item) {
    if (!item.discussion) return '#';
    const number = item.postNumber || 1;

    return number > 1
      ? app.route('discussion.near', { id: item.discussion.slug, near: number })
      : app.route('discussion', { id: item.discussion.slug });
  }
}
