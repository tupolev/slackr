1. Run `composer install`

3. Run `chmod ug+w slackr.sh`

4. Copy `config/Config.php.dist` into `config/Config.php` and customize it.

*Note that a user auth token from Slack is required. See https://api.slack.com/apps

5. Run `./slackr.sh [ims|groups|channels]`

(Any combination of `"ims"`, `"groups"` and `"channels"` is allowed)

* Channels backup is still an experimental feature. It can easily reach max requests rate and it's not fixed yet.



### Slack App tokens

As an alternative to legacy tokens, you can install the slackr app on your workspace, and use the token that it returns
after you authorize it. The source code of the app is available in this
repository, see below for details.

The token starts with `xoxp-`, and you must use it as your auth token in the `config/Config.php` file

This is a Slack app with full user permissions, that is used to generate a Slack user token.
Note that you need to install this app on every workspace you want to use it
for, and the workspace owners may reject it.

Click on the button below to install the app:

[![Authorize slackr](https://platform.slack-edge.com/img/add_to_slack.png)](https://slack.com/oauth/authorize?client_id=4514160482.1145417785952&scope=client)

Then copy the token from the resulting page (it starts with `xoxp-`).
