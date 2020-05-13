1. Run `composer install`

3. Run `chmod ug+w slackr.sh`

4. Copy `config/Config.php.dist` into `config/Config.php` and customize it.

*Note that a user auth token from Slack is required. See https://api.slack.com/apps

5. Run `./slackr.sh [ims|groups|channels]`

(Any combination of `"ims"`, `"groups"` and `"channels"` is allowed)

