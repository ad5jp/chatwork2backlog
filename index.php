<?php
require(__DIR__ . '/config.php');

class Main
{
    private $webhook;

    public function __construct()
    {
        //エラーハンドリング
        $this->handleError();

        //Chatwork Webhookを処理
        $this->webhook = $this->parseRequest();

        //メンション以外は無視
        if ($this->webhook->webhook_event_type !== 'mention_to_me') {
            return;
        }

        //本文からプロジェクトキーを取得
        $project_key = $this->getProjectKeyInMessage($this->webhook->webhook_event->body);
        //なければ無視
        if ($project_key === null) {
            return;
        }

        //Backlog APIからプロジェクト情報を取得
        $project = $this->getProjectByKey($project_key);
        if ($project === null) {
            $this->log('project not found: ' . $project_key);
            $this->reply("プロジェクト {$project_key} が見つかりません (bow)");
        }

        //Backlog APIで課題を登録
        $issue_key = $this->addIssue($project, $this->webhook->webhook_event->body);
        if ($issue_key === null)  {
            $this->reply("課題の登録に失敗しました (bow)");
        }

        $issue_url = BACKLOG_SPACE_URL . '/view/' . $issue_key;
        $this->reply("Backlog登録完了 (y) \n" . $issue_url);
    }

    /**
     * リクエストを検証して、OKならペイロードを返す
     *
     * @return Object
     */
    private function parseRequest() : Object
    {
        $request_body = file_get_contents('php://input');

        if ($request_body === null) {
            $this->kill('no request body');
        }

        $headers = getallheaders();
        //ハイフン付きのキーが取れない・・・？
        //$header['X-ChatWorkWebhookSignature'];

        if (!isset($_SERVER['HTTP_X_CHATWORKWEBHOOKSIGNATURE'])) {
            $this->log("request header: ");
            $this->log($headers);
            $this->kill('no signature');
        }
        if (!$this->verifySignature($request_body, $_SERVER['HTTP_X_CHATWORKWEBHOOKSIGNATURE'])) {
            $this->log("signature: ");
            $this->log($_SERVER['HTTP_X_CHATWORKWEBHOOKSIGNATURE']);
            $this->kill('invalid signature');
        }

        $payload = json_decode($request_body);

        if ($request_body === null) {
            $this->log("request body: ");
            $this->log($request_body);
            $this->kill('invalid request body');
        }

        return $payload;
    }

    private function verifySignature(string $request_body, string $signature) : bool
    {
        //署名が正しいか検証
        //tokenをBASE64デコードする＝鍵
        $key = base64_decode(CHATWORK_WEBHOOK_TOKEN);

        //ダイジェスト値を取得ししてbase64エンコード
        $hash = base64_encode(hash_hmac('sha256', $request_body, $key, true));

        return ($signature === $hash);
    }

    private function reply($message)
    {
        $params = ['body' => $message];
        $room_id = $this->webhook->webhook_event->room_id;
        $url = "https://api.chatwork.com/v2/rooms/{$room_id}/messages";

        $curl = curl_init($url);
		curl_setopt($curl, CURLOPT_POST, TRUE);
		curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'X-ChatWorkToken:' . CHATWORK_API_TOKEN,
        ]);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);

		$response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($status >= 400) {
            $this->log("Chatwork API Error");
            $this->log($url);
            $this->log($status);
            $this->log($response);
            die;
        }
    }

    private function getProjectKeyInMessage(string $message) : ?string
    {
        $lines = explode("\n", $message);

        foreach ($lines as $line) {
            if (preg_match('/^#([A-Z0-9]+)/', $line, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function getProjectByKey(string $project_key)
    {
        $endpoint = '/api/v2/projects/' . $project_key;
        $project = $this->callApi('GET', $endpoint);

        return $project;
    }

    private function addIssue($project, $message)
    {
        $issue_types = $this->getIssueTypes($project);

        if (preg_match('/\[qt\]\[qtmeta aid=[0-9]* time=[0-9]*\](.*)\[\/qt\]/us', $message, $matches)) {
            $description = trim($matches[1]);
        } else {
            $tag = '#' . $project->projectKey;
            $description = mb_strstr($message, $tag);
            $description = mb_substr($description, strlen($tag));
            $description = trim($description);
        }

        $params = [
            'projectId' => $project->id,
            'summary' => explode("\n", $description)[0],
            'description' => $description,
            'issueTypeId' => $issue_types[0]->id,
            'priorityId' => 3, //中
        ];

        $issue = $this->callApi("POST", "/api/v2/issues", $params);
        return $issue->issueKey;
    }

    private function getIssueTypes($project)
    {
        return $this->callApi("GET", "/api/v2/projects/{$project->id}/issueTypes");
    }

    private function callApi(string $method, string $endpoint, array $params = [])
    {
		if (!in_array($method, ['GET', 'POST'])) {
			throw new Exception('invalid requeset method');
		}

        $url = BACKLOG_SPACE_URL . $endpoint . '?apiKey=' . BACKLOG_API_KEY;

		if ($method === 'GET' && $params) {
			$url .= '&' . http_build_query($params);
		}

		$curl = curl_init($url);
		if ($method == 'POST') {
			curl_setopt($curl, CURLOPT_POST, TRUE);
			if ($params) {
				curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));
			}
		} else {
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
		}
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);

		$response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($status < 400) {
            return json_decode($response);
        } else {
            $data = json_decode($response);
            $this->log("Backlog API Error");
            $this->log($endpoint);
            $this->log($status);
            $this->log(json_decode($data));
            $this->reply('Backlog からエラー応答がありました (bow) ' . ($data->errors[0]->message ?? '不明なエラー'));
            die;
        }
    }

    private function kill($str)
    {
        $this->log($str);
        die($str);
    }

    private function log($str)
    {
        if (!is_string($str)) {
            $str = json_encode($str);
        }

        $dir = __DIR__ . LOG_DIR;
        if (!file_exists($dir)) {
            mkdir($dir, 0777);
            chmod($dir, 0777);
        }

        $str = sprintf("[%s] %s \n", date('Y-m-d H:i:s'), $str);
        $file = $dir . date('Y-m-d') . '.log';
        error_log($str, 3, $file);
    }

    private function handleError()
    {
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            $debug = sprintf(
                "[Error %s] %s (in %s line %s)",
                $errno,
                $errstr,
                $errfile,
                $errline
            );
            $this->log($debug);
        });

        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error !== null) {
                $debug = sprintf(
                    "[Error %s] %s (in %s line %s)",
                    $error['type'],
                    $error['message'],
                    $error['file'],
                    $error['line'],
                );
                $this->log($debug);
            }
        });
    }
}

new Main();
