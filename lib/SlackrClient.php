<?php
namespace slackr;

use DateTime;
use GuzzleHttp\Client;

class SlackrClient {

    private const PAGE_LIMIT_FOR_LIST = 200;
    private const PAGE_LIMIT_FOR_HISTORY = 1000;
    private const PAGE_LIMIT_FOR_USERS = 200;
    private const HTTP_STATUS_CODE_TOO_MANY_REQUESTS = 429;
    private const SAFE_REQUEST_WAIT_TIME_SECS = 30;
    private const HTTP_STATUS_CODE_OK = 200;

    /** @var Client */
    private $http;

    /** @var array */
    private $users = [];

    /** @var array */
    private $conversationTypeUrls;

    /** @var array */
    private $infoUrls;

    /** @var string */
    private $authToken;

    /** @var string */
    private $username;

    /** @var string */
    private $teamname;

    /** @var string */
    private $outdir;

    /** @var Console */
    private $console;

    /** @var array */
    private $whitelistedChannelNames = [];

    /** @var string */
    private $whitelistedChannelsFile;

    public function __construct(
        Console $console,
        Client $http,
        array  $conversationTypeUrls,
        array  $infoUrls,
        string $authToken,
        string $username,
        string $teamname,
        string $outdir,
        string $whitelistedChannelsFile
    )
    {
        $this->console = $console;
        $this->http = $http;
        $this->conversationTypeUrls = $conversationTypeUrls;
        $this->infoUrls = $infoUrls;
        $this->authToken = $authToken;
        $this->username = $username;
        $this->teamname = $teamname;
        $this->outdir = $outdir;
        $this->whitelistedChannelsFile = $whitelistedChannelsFile;
    }

    public function preloadWhitelistedChannelsFromFile()
    {
        $channelNames = file($this->whitelistedChannelsFile);
        if (empty($channelNames)) {
            return;
        }
        $this->whitelistedChannelNames = $channelNames;
    }

    public function preloadUsers()
    {
        $console = $this->console;
        $url = sprintf("%s?token=%s", $this->infoUrls["userlist"], $this->authToken);
        $nextCursor = "";
        $console::info("Preloading usernames");
        do {
            $console->debug("   " . "$url&limit=" . self::PAGE_LIMIT_FOR_USERS . "&next_cursor=$nextCursor");
            $res = $this->http->request('GET', "$url&limit=" . self::PAGE_LIMIT_FOR_USERS . "&cursor=$nextCursor", []);

            $page = json_decode($res->getBody()->getContents(), true);

            $nextCursor = $page["response_metadata"]["next_cursor"];
            $users = $page['members'];
            foreach ($users as $user) {
                $this->users[$user['id']] = $user['name'];
            }
        } while ($nextCursor !== "");
    }

    public function getPaginatedConversationList(string $conversationType): array
    {
        $url = sprintf("%s?token=%s", $this->conversationTypeUrls[$conversationType]["list"], $this->authToken);
        $nextCursor = "";
        $conversationListTmp = [];
        do {
            $this->console->debug("   " . "$url&limit=" . self::PAGE_LIMIT_FOR_LIST . "&next_cursor=$nextCursor");
            $res = $this->http->request('GET', "$url&limit=" . self::PAGE_LIMIT_FOR_LIST . "&cursor=$nextCursor", []);

            $page = json_decode($res->getBody()->getContents(), true);
            $nextCursor = $page["response_metadata"]["next_cursor"];
            $conversations = $page[$conversationType];
            if (count($this->whitelistedChannelNames) > 0) {
                $conversationListTmp []= array_filter(
                    $conversations,
                    function ($item) { return in_array($item['name'], $this->whitelistedChannelNames); }
                    );
            } else {
                $conversationListTmp []= $conversations;
            }
        } while ($nextCursor !== "");

        return array_merge(...$conversationListTmp);
    }

    public function getPaginatedConversationHistory(string $conversationType, string $conversationInfoId): array
    {
        $url = sprintf(
            "%s?token=%s&channel=%s",
            $this->conversationTypeUrls[$conversationType]["history"],
            $this->authToken,
            $conversationInfoId
        );
        $latestTs = "";
        $conversationTmp = [];
        $hasMore = false;
        do {
            $this->console->debug("$url&limit=" . self::PAGE_LIMIT_FOR_HISTORY . "&latest=$latestTs");

            $res = $this->http->request('GET', "$url&limit=" . self::PAGE_LIMIT_FOR_HISTORY . "&latest=$latestTs", ['http_errors' => false]);
            if ($res->getStatusCode() === self::HTTP_STATUS_CODE_OK) {
                $page = json_decode($res->getBody()->getContents(), true);
                $hasMore = $page['has_more'];
                $messages = $page['messages'];
                $lastMessage = end($page['messages']); reset($page['messages']);
                $latestTs = $lastMessage['ts'];
                $conversationTmp []= $messages;
            } else if ($res->getStatusCode() === self::HTTP_STATUS_CODE_TOO_MANY_REQUESTS) {
                $retryAfter = $res->getHeader('Retry-After');

                Console::error('Max request number reached. Waiting ' . ($retryAfter[0] ?: self::SAFE_REQUEST_WAIT_TIME_SECS) . ' seconds...');
                sleep($retryAfter[0] ?: self::SAFE_REQUEST_WAIT_TIME_SECS);
                $hasMore = true;
            }
        } while ($hasMore);

        $conversation = array_merge(...$conversationTmp);

        usort(
            $conversation,
            function($a, $b) {
                if ($a["ts"] === $b["ts"]) {
                    return 0;
                }
                return ($a["ts"] < $b["ts"]) ? -1 : 1;
            }
        );

        return $conversation;
    }

    public function writeConversationToFile(string $conversationType, string $conversationName, array $conversation = [])
    {
        $outDir = sprintf(
            $this->outdir . "%s" . DIRECTORY_SEPARATOR . "%s" . DIRECTORY_SEPARATOR . "%s",
            $this->username,
            $this->teamname,
            $conversationType
        );
        if (!count($conversation)) {
            $this->console::info("Empty conversation $conversationName. Skipping file.");
            return;
        }
        $filepath = sprintf('%s' . DIRECTORY_SEPARATOR . '%s.log', $outDir, $conversationName);
        $this->console::info("Writing file $filepath");
        try {
            $this->prepareDirectoryStructure($outDir);
            $fp = fopen($filepath, 'wb+');
            foreach ($conversation as $message) {
                fputs($fp, $this->logizeMessage($message));
            }
        } catch (\Exception $ex) {
            $this->console::error("Cannot write to file $filepath. Reason: {$ex->getMessage()}");
        } finally {
            fclose($fp);
        }
    }

    public function getCachedUserNameById(string $userId): string
    {
        return array_key_exists($userId, $this->users) ? $this->users[$userId] : $userId;
    }

    private function logizeMessage(array $conversationMessage): string
    {
        $line = '[' . date(DateTime::ATOM, $conversationMessage['ts']) . ']';
        if (array_key_exists('user', $conversationMessage)) {
            $line .= '[' . $this->getCachedUserNameById($conversationMessage['user']) . '] ';
        } else if (array_key_exists('username', $conversationMessage)) {
            $line .= '[' . $conversationMessage['username'] . '] ';
        } else if (array_key_exists('name', $conversationMessage)) {
            $line .= '[' . $conversationMessage['name'] . '] ';
        } else if (array_key_exists('id', $conversationMessage)) {
            $line .= '[' . $this->getCachedUserNameById($conversationMessage['id']) . '] ';
        } else {
            $line .= '[unknown user] ';
        }
        $line .= $this->translateInlineMentions($conversationMessage['text']) . PHP_EOL;

        return $line;
    }

    private function prepareDirectoryStructure(string $outDir)
    {
        if (!is_dir($outDir)) {
            if (!mkdir($outDir, 0777, true) && !is_dir($outDir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $outDir));
            }
        }
    }

    private function translateInlineMentions(string $message): string
    {
        $this->console->debug('Translating mentions in message.');
        return str_replace(
            array_keys($this->users),
            array_values($this->users),
            $message
        );
    }
}
