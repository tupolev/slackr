<?php
namespace slackr;

use Config;
use GuzzleHttp\Client;

include_once "vendor/autoload.php";
include_once "config/Config.php";
include_once "lib/Console.php";
include_once "lib/SlackrClient.php";


try {
    $console = new Console(Config::_DEBUG);

    $processArgs = static function() use ($argv): array
    {
        $processableItems = [];
        $argc = count($argv);
        for ($i=1; $i < $argc; $i++) {
            $processableItems[]=$argv[$i];
            if (!array_key_exists($argv[$i], Config::$conversationTypeUrls)) {
                Console::error(
                    "ERROR: invalid conversation type {$argv[$i]}. Valid types are "
                    .  implode(', ', array_keys(Config::$conversationTypeUrls))
                );
                exit(1);
            }
        }

        return $processableItems;
    };

    $itemsToProcess = $processArgs();
    $httpClient = new Client();
    $slackr = new SlackrClient(
        $console,
        $httpClient,
        Config::$conversationTypeUrls,
        Config::$infoUrls,
        Config::AUTH_TOKEN,
        Config::USER_NAME,
        Config::TEAM_NAME,
        Config::OUT_DIR,
        Config::WHITELISTED_CHANNELS_FILE
    );
    $slackr->preloadUsers();
    $slackr->preloadWhitelistedChannelsFromFile();
    foreach ($itemsToProcess as $itemToProcess) {
        $conversationType = $itemToProcess;
        Console::info("Fetching $conversationType");
        $conversationList = $slackr->getPaginatedConversationList($conversationType);
        foreach ($conversationList as $conversationInfo) {
            $console->debug("#" . $conversationInfo["id"]);
            $conversation = $slackr->getPaginatedConversationHistory($conversationType, $conversationInfo["id"]);
            if (in_array($conversationType, ["channels", "groups"]) ) {
                $conversationName = $conversationInfo["name"];
            } else {
                $conversationName = $slackr->getCachedUserNameById($conversationInfo["user"]);
            }
            $slackr->writeConversationToFile($conversationType, $conversationName, $conversation);
        }
    }
    Console::info("Done");
    Console::info("Ouput generated at" . sprintf(
        Config::OUT_DIR . "%s" . DIRECTORY_SEPARATOR . "%s" . DIRECTORY_SEPARATOR,
        Config::USER_NAME,
        Config::TEAM_NAME)
    );
} catch (\Exception $ex) {
    Console::error("{$ex->getMessage()}. Aborting process.");
}
