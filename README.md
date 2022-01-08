# これは何？

ChatWork から BO T宛にメッセージを送ることにより、
Backlog に課題を登録するための Webhook エンドポイントです。

# 必要なもの

SSL 化された Webサーバ。

BOT にするための ChatWork アカウント (無料プランで可)
-> APIトークン と Webhookトークン を発行しておく

Backlog アカウント (APIが使えるプラン)
-> APIトークン を発行しておく

# 設置手順
config.example.php を参考に、config.php を作成。

index.php と config.php をサーバに設置。
※ index.php は公開領域に。
※ config.php は非公開領域に置くか、HTTPアクセスを制限すること。

※ 設置したURL (HTTPS) を、ChatWork の Webhook URL として登録する。

# 使い方

BOT宛に TO をつけてメッセージを送る。

対象の Backlog のプロジェクトキーが ABC の場合、
メッセージに #ABC という行を含める。

メッセージ内に引用がある場合は引用内のテキストが、
ない場合は、上記 #ABC 以降の全文が、課題内容として登録されます。

課題内容の先頭行が件名になります。
