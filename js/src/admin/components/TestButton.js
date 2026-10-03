import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';

const t = (key, params) => app.translator.trans(`ernestdefoe-greeter.admin.test.${key}`, params);

/**
 * Sends the welcome to the admin pressing it, from the SAVED settings, and says
 * what happened to each channel in plain words — including why one did not go.
 */
export default class TestButton extends Component {
  view() {
    return (
      <div className="Form-group GreeterTest">
        <Button className="Button" icon="fas fa-paper-plane" loading={this.loading} onclick={() => this.send()}>
          {t('button')}
        </Button>
        <p className="helpText">{t('help')}</p>
        {this.result ? (
          <ul className="GreeterTest-result">
            {['message', 'email'].map((k) => (
              <li>
                <strong>{t(k)}:</strong> {t(`status_${this.result[k]}`, { system: this.result.system || '', sender: this.result.sender || '' })}
              </li>
            ))}
          </ul>
        ) : null}
      </div>
    );
  }

  send() {
    this.loading = true;
    this.result = null;
    app
      .request({ method: 'POST', url: app.forum.attribute('apiUrl') + '/greeter/test' })
      .then((r) => (this.result = r))
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
