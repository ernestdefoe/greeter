import app from 'flarum/admin/app';
import TestButton from './components/TestButton';
import extractText from 'flarum/common/utils/extractText';

const t = (key, params) => app.translator.trans(`ernestdefoe-greeter.admin.${key}`, params);

// Placeholders are passed as their own names: the translator would otherwise
// treat {username} in the help text as one of ITS placeholders and print "{undefined}".
// 🚨 An input's placeholder attribute also needs extractText(): given params,
// trans() returns an array, which an attribute joins with commas.
const placeholders = { username: '{username}', display_name: '{display_name}', forum: '{forum}', url: '{url}' };

app.initializers.add('ernestdefoe-greeter', () => {
  app.registry
    .for('ernestdefoe-greeter')
    .registerSetting({ setting: 'ernestdefoe-greeter.enabled', type: 'boolean', label: t('enabled'), help: t('enabled_help') })
    .registerSetting({
      setting: 'ernestdefoe-greeter.channel',
      type: 'select',
      label: t('channel'),
      help: t('channel_help'),
      options: { message: t('channel_message'), email: t('channel_email'), both: t('channel_both') },
      default: 'message',
    })
    .registerSetting({
      setting: 'ernestdefoe-greeter.sender',
      type: 'text',
      label: t('sender'),
      help: t('sender_help'),
      placeholder: extractText(t('sender_placeholder')),
    })
    .registerSetting({
      setting: 'ernestdefoe-greeter.subject',
      type: 'text',
      label: t('subject'),
      help: t('subject_help', placeholders),
      placeholder: extractText(t('default_subject_placeholder', placeholders)),
    })
    .registerSetting({
      setting: 'ernestdefoe-greeter.body',
      type: 'textarea',
      label: t('body'),
      help: t('body_help', placeholders),
      placeholder: extractText(t('default_body_placeholder', placeholders)),
    })
    .registerSetting(() => <TestButton />);
});
