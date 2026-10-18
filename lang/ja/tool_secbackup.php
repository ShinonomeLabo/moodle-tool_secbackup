<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Japanese strings for tool_secbackup.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action_deregister'] = '登録を解除する';
$string['action_next'] = '次へ進む';
$string['action_register'] = '登録する';
$string['api_artifact_signature_invalid'] = 'バックアップのサービスが、このサイトの送った世代の一覧または索引の署名を確かめられませんでした。このサイトの鍵を入れ替えた場合は、登録し直してから、バックアップをやり直してください。';
$string['api_body_too_large'] = '要求が、サービスの受け付ける大きさを超えていました。';
$string['api_capacity_recheck_required'] = 'バックアップのサービスが、これ以上のデータを受け取る前に、バックアップの見込みの大きさを確かめ直すよう求めています。連携ツールが確かめ直して続けます。';
$string['api_core_manifest_not_found'] = 'バックアップのサービスに、この版の Moodle の公式のファイルの一覧がありません。';
$string['api_encryption_key_mismatch'] = '貼り付けた公開鍵が、プラットフォームにあるこのサイトの今の公開鍵と違います。プラットフォームの画面から、公開鍵をもう一度コピーしてください。';
$string['api_enrollment_code_invalid'] = '登録コードが違うか、期限 (30 分) が切れたか、すでに使われています。プラットフォームの画面で、登録コードを発行し直してください。';
$string['api_invalid_request'] = 'サービスが要求を受け付けませんでした。連携ツールを最新の版に更新してください。';
$string['api_ip_not_allowed'] = 'サービスが、このサーバの IP アドレスからの接続を受け付けていません。プラットフォームの画面の接続設定に、このサーバの IP アドレスを追加してください。';
$string['api_ip_not_allowed_address'] = 'サービスには、このサーバの接続元が {$a} と見えていますが、この IP アドレスからの接続は受け付けていません。{$a} がこのサーバから外へ出るときの IP アドレスであることを確かめてから、プラットフォームの画面の接続設定に追加してください。';
$string['api_nonce_reused'] = 'サービスに同じ要求が 2 度届きました。もう一度お試しください。';
$string['api_rate_limited'] = 'サービスへの要求が多すぎます。しばらく待ってから、もう一度お試しください。';
$string['api_response_invalid'] = 'バックアップのサービスの応答を使えませんでした。';
$string['api_run_in_progress'] = 'バックアップのサービスによると、このサイトのバックアップがまだ続いています。';
$string['api_run_limit_reached'] = '24 時間に行えるバックアップの回数の上限に達しました。しばらくしてから、やり直してください。';
$string['api_run_not_found'] = 'バックアップのサービスがこのバックアップを知りません。新しくバックアップを始めます。';
$string['api_run_state_invalid'] = 'バックアップの今の状態では、バックアップのサービスがこの操作を受け付けません。';
$string['api_signature_invalid'] = 'サービスが、このサイトの署名を確かめられませんでした。別のサーバからこのサイトを登録し直した場合は、ここでも登録し直してください。';
$string['api_signature_required'] = '要求の署名がサービスに届きませんでした。プロキシやセキュリティの機器がヘッダーを消している可能性があります。X-SecBackup で始まるヘッダーを通すよう、その管理者に頼んでください。';
$string['api_site_not_enrolled'] = 'サービスに、このサイトの登録が見つからないか、登録が無効になっています。プラットフォームの画面で登録コードを発行し、登録し直してください。';
$string['api_timestamp_out_of_range'] = 'このサーバの時刻が、サービスの時刻と大きくずれています。NTP などでこのサーバの時刻を合わせてから、もう一度お試しください。';
$string['api_timestamp_out_of_range_minutes'] = 'このサーバの時刻が、サービスの時刻と約 {$a} 分ずれています。NTP などでこのサーバの時刻を合わせてから、もう一度お試しください。';
$string['api_unavailable'] = 'サービスが混み合っているか、保守の作業中です。しばらくしてから、もう一度お試しください。';
$string['api_unexpected'] = 'サービスから想定外の応答がありました (HTTP {$a})。';
$string['api_wwwroot_mismatch'] = 'このサイトの URL ($CFG->wwwroot) が、プラットフォームに登録したサイトの URL と違います。プラットフォームの画面で、サイトの URL を確かめてください。';
$string['check_apparmor'] = 'AppArmor';
$string['check_clock'] = '時刻';
$string['check_connection'] = 'サービスへの接続';
$string['check_developer'] = '開発用の設定';
$string['check_dns'] = '名前解決';
$string['check_extensions'] = 'PHP の拡張';
$string['check_moodledata'] = 'データのフォルダ (moodledata)';
$string['check_proxy'] = 'プロキシ';
$string['check_selinux'] = 'SELinux';
$string['cli_diagnose_help'] = 'このサーバからバックアップのサービスへの接続を確かめます。

オプション:
--endpoint=URL  サイトを登録していないときに確かめる、接続先の URL。
-h, --help      この説明を出す。

cron を動かす利用者で実行してください。例:
$ sudo -u www-data /usr/bin/php admin/tool/secbackup/cli/diagnose.php
(Moodle 5.1 以降: public/admin/tool/secbackup/cli/diagnose.php)';
$string['clock_skew_warning'] = 'このサーバの時刻が、サービスの時刻と約 {$a} 分ずれています。5 分を超えてずれると、サービスがこのサイトの要求を受け付けなくなります。NTP などでこのサーバの時刻を合わせてください。';
$string['connect_again_explain'] = 'プラットフォームで登録コードを発行し直したときや、このサイトの公開鍵を作り直したときは、ここで登録し直してください。';
$string['connect_again_heading'] = '登録し直す';
$string['db_unsupported'] = 'このデータベースには対応していません。対応しているのは MySQL、MariaDB、PostgreSQL です。';
$string['deregister_done'] = 'このサイトの登録を解除しました。今後、バックアップは送られません。念のため、{$a} でも、サイトの登録の削除か、登録コードの発行し直しを行ってください。';
$string['deregister_explain'] = '登録を解除すると、このサーバにあるこのサイトの鍵と登録の情報を消します。{$a} には伝わらないので、そちらでも、サイトの登録の削除か、登録コードの発行し直しを行ってください。';
$string['deregister_heading'] = '登録を解除する';
$string['deregister_typename'] = '確認のため、プラットフォームでのサイト名を入力してください: {$a}';
$string['developer_banner'] = 'config.php に開発用の設定 (TOOL_SECBACKUP_DEVELOPER) があります。接続の安全確認の一部が無効になっています。本番のサイトでは消してください。';
$string['developersettings_invalid'] = 'config.php の開発用の設定 (TOOL_SECBACKUP_DEVELOPER) に誤りがあります。理由は、デバッグの表示を有効にすると確かめられます。';
$string['diag_apparmor_advice'] = 'AppArmor が接続を拒んでいる場合は、サーバの管理者が root で次のように調べて対処できます。拒否の記録を探すには: journalctl --no-pager | grep DENIED (ジャーナルの全体を読みます。journalctl -k では拾えないことがあります)。auditd が動いているサーバでは /var/log/audit/audit.log にも残ります。deny と書いた規則は何も記録しません。記録したいときは audit deny と書きます。PHP の接続を許すには、プロファイルの追加設定用のファイル (/etc/apparmor.d/local/ の下の、プロファイルと同じ名前のファイル。プロファイル php-fpm なら /etc/apparmor.d/local/php-fpm) に、次の 4 つの規則を 1 行に 1 つずつ足します: 「network inet stream,」「network inet dgram,」「network inet6 stream,」「network inet6 dgram,」。足したら、プロファイルのファイルを指定して読み込み直します (php-fpm なら apparmor_parser -r /etc/apparmor.d/php-fpm)。';
$string['diag_apparmor_complain'] = 'Complain モードです。プロファイル: {$a}。';
$string['diag_apparmor_enforcing'] = 'Enforcing です。プロファイル: {$a}。';
$string['diag_apparmor_other'] = 'プロファイルとモード: {$a}。';
$string['diag_apparmor_unconfined'] = '有効です。このプロセスは制限されていません。';
$string['diag_apparmor_unknown'] = '使っていないか、読み取れません。';
$string['diag_cli_advice'] = '対処: {$a}';
$string['diag_cli_endpoint'] = '接続先の URL: {$a}';
$string['diag_cli_heading'] = 'あんしんLMSバックアップの接続の診断';
$string['diag_cli_noendpoint'] = '接続先の URL: 登録していません (--endpoint で指定できます)';
$string['diag_clock_seconds'] = '時刻のずれは {$a} 秒です。';
$string['diag_clock_unknown'] = 'まだ分かりません。登録や状態の問い合わせのときに測ります。';
$string['diag_connection_ok'] = 'サービスに届きました。この確かめは署名を付けずに問い合わせるので、HTTP {$a} (認証が必要) が返れば正常です。';
$string['diag_connection_other'] = '応答はありましたが、サービスの API ではありません (HTTP {$a})。接続先の URL を確かめてください。';
$string['diag_developer_advice'] = '本番のサイトでは、config.php から TOOL_SECBACKUP_DEVELOPER の定義を消してください。';
$string['diag_developer_off'] = '使っていません。';
$string['diag_developer_on'] = '有効: {$a}';
$string['diag_dns_noaddress'] = 'アドレスなし';
$string['diag_dns_noanswer'] = '応答なし';
$string['diag_dns_source_cloudflare'] = 'Cloudflare (1.1.1.1)';
$string['diag_dns_source_google'] = 'Google (8.8.8.8)';
$string['diag_dns_source_os'] = 'このサーバ';
$string['diag_dns_viaproxy'] = '使いません。プロキシが名前を引きます';
$string['diag_extensions_advice'] = '足りない PHP の拡張を入れて、有効にしてください。';
$string['diag_extensions_missing'] = '入っていない拡張: {$a}';
$string['diag_extensions_ok'] = '連携ツールに要る拡張はすべて入っています。';
$string['diag_heading_advice'] = '対処';
$string['diag_heading_check'] = '項目';
$string['diag_heading_detail'] = '詳細';
$string['diag_heading_result'] = '結果';
$string['diag_hint'] = 'このサーバのネットワークを確かめるには、{$a}を開いてください。';
$string['diag_moodledata'] = 'ファイルシステム: {$a->type} (マウント先: {$a->mount}、元: {$a->source})';
$string['diag_moodledata_network'] = 'データのフォルダ (moodledata) がネットワークのファイルシステムにあります。バックアップは中のファイルをすべて読むので、時間がかかり、そのファイルシステムにも負荷がかかります。';
$string['diag_moodledata_unknown'] = '読み取れません。';
$string['diag_no_endpoint'] = 'サイトを登録するか、接続先の URL を入れると確かめられます。';
$string['diag_proxy_direct'] = 'プロキシを使わずに、直接つなぎます。';
$string['diag_proxy_used'] = 'プロキシ {$a} を通してつなぎます。';
$string['diag_run'] = '接続を確かめる';
$string['diag_selinux_advice'] = 'SELinux が接続を拒んでいる場合は、サーバの管理者が次のコマンドで確かめられます: ausearch -m avc -ts recent。Web サーバに、443 などの Web のポートへの接続だけを許すには: setsebool -P httpd_can_network_relay on。すべてのネットワーク接続を許すには: setsebool -P httpd_can_network_connect on';
$string['diag_selinux_enforcing'] = 'Enforcing です。プロセスの種類: {$a}。';
$string['diag_selinux_permissive'] = 'Permissive です。プロセスの種類: {$a}。';
$string['diag_selinux_unknown'] = '使っていないか、読み取れません。';
$string['diag_skipped'] = '開発用の設定があるので、確かめていません。';
$string['dns_doh_failed'] = 'DNS over HTTPS のサービスが答えませんでした。';
$string['dns_doh_unavailable'] = 'DNS over HTTPS のサービス (1.1.1.1 と 8.8.8.8) のどちらも答えなかったので、接続先の IP アドレスを確かめられませんでした。1.1.1.1 か 8.8.8.8 への HTTPS の接続を許すか、Moodle にプロキシを設定してください。';
$string['dns_not_found'] = '接続先の URL のホスト名が見つかりません。接続先の URL を確かめてください。';
$string['dns_os_failed'] = 'このサーバで、接続先の URL のホスト名を引けませんでした。サーバの DNS の設定を確かめてください。';
$string['dns_os_mismatch'] = 'このサーバの DNS が、接続先のサービスのものではない IP アドレスを返しています。何も送っていません。このサーバの DNS を、ネットワークの管理者に確かめてもらってください。';
$string['dns_outside_aws'] = '接続先の URL のホスト名が、接続先のサービスを指していません。何も送っていません。接続先の URL を確かめてください。URL が正しい場合は、連携ツールに入っている Amazon の IP アドレスの範囲の一覧が古く、サービスの新しい IP アドレスが載っていない可能性があります。連携ツールを最新の版に更新してください。';
$string['endpoint_empty'] = 'プラットフォームの画面に出ている接続先の URL を入れてください。';
$string['endpoint_host'] = '接続先の URL のホスト名は使えません。プラットフォームの画面に出ている、IP アドレスではないホスト名の URL を使ってください。';
$string['endpoint_invalid'] = '接続先の URL の形になっていません。プラットフォームの画面から、https:// で始まる URL の全体をコピーしてください。';
$string['endpoint_path'] = '接続先の URL のパスに使えない文字が入っています。プラットフォームの画面から、もう一度コピーしてください。';
$string['endpoint_port'] = '接続先の URL に、標準の番号 (443) 以外のポートは入れられません。';
$string['endpoint_query'] = '接続先の URL に「?」や「#」は入れられません。プラットフォームの画面から、もう一度コピーしてください。';
$string['endpoint_scheme'] = '接続先の URL は https:// で始めてください。';
$string['endpoint_userinfo'] = '接続先の URL に、ユーザー名やパスワードは入れられません。';
$string['enroll_code_used_advice'] = 'サービスは、この失敗の前に登録コードを受け付けています。同じ登録コードはもう使えません。原因を直してから、プラットフォームの画面で登録コードを発行し直し、もう一度登録してください。それまで、このサイトはサービスを使えません。';
$string['enroll_done'] = 'このサイトを {$a} に登録しました。';
$string['enroll_no_trusted_key'] = 'この版の連携ツールには、サービスの応答を確かめる鍵が入っていないため、このサイトを登録できません。何も送っておらず、登録コードは使われていません。サービスの鍵が入った版に、連携ツールを更新してください。';
$string['enroll_reissue_advice'] = '登録コードを誤った接続先の URL に送った可能性があります。念のため、プラットフォームの画面で登録コードを発行し直してください。';
$string['enroll_response_invalid'] = 'サービスからの登録の応答を読めませんでした。何も保存していません。';
$string['enroll_response_mismatch'] = 'サービスの署名付きの応答が、この登録の要求に対するものではありません。何も保存していません。接続先の URL を確かめ、念のため、プラットフォームの画面で登録コードを発行し直してください。';
$string['envelope_malformed'] = '接続先のサービスからの応答を読めませんでした。接続先の URL が正しいか確かめてください。';
$string['envelope_signature'] = '接続先のサービスからの応答の署名を確かめられませんでした。接続先の URL が正しいか確かめてください。';
$string['envelope_untrusted'] = 'この連携ツールが信頼していない鍵で署名された応答です。接続先の URL が正しいか確かめてください。';
$string['event_deregistered'] = 'バックアップのサービスとの登録の解除';
$string['event_enrolled'] = 'バックアップのサービスへのサイトの登録';
$string['field_code'] = '登録コード';
$string['field_code_help'] = 'プラットフォームの画面で発行した、1 回限りの登録コードです。30 分で使えなくなります。';
$string['field_code_invalid'] = '登録コードは SEC-BK-XXXX-XXXX-XXXX-XXXX-XXXX の形です。プラットフォームの画面から、もう一度コピーしてください。';
$string['field_confirmfingerprint'] = 'この値が、プラットフォーム (バックアップを申し込んだサービス) の画面のフィンガープリントと一致することを確かめました。';
$string['field_confirmfingerprint_label'] = 'フィンガープリントの確認';
$string['field_confirmfingerprint_required'] = 'プラットフォームの画面のフィンガープリントと比べて、一致したら印を付けてください。';
$string['field_confirmname'] = 'サイト名';
$string['field_confirmname_mismatch'] = '入力した名前が、プラットフォームでのサイト名と違います。';
$string['field_endpoint'] = '接続先の URL';
$string['field_endpoint_fixed'] = '登録しているあいだは、接続先の URL を変えられません。変えるときは、先に登録を解除してください。';
$string['field_endpoint_help'] = 'プラットフォーム (バックアップを申し込んだサービス) の画面に出ている、このサイトの接続先の URL をコピーしてください。https:// で始まります。';
$string['field_fingerprint'] = '公開鍵のフィンガープリント';
$string['field_publickey'] = 'このサイトの公開鍵';
$string['field_publickey_help'] = 'プラットフォームの画面から、secbk1: で始まるこのサイトの公開鍵をコピーしてください。次の画面にフィンガープリントを出すので、正しい鍵をコピーしたかを確かめられます。';
$string['instruction_invalid'] = 'バックアップのサービスから届いた指示を確かめられなかったので、使いませんでした。';
$string['intro'] = 'この連携ツールは、このサイト (Moodle™) をバックアップのサービスにつなぎ、暗号化したバックアップを預けられるようにします。';
$string['message_deregistered_body'] = '{$a->user} が、このサイトの {$a->platform} との登録を解除しました。

接続先の URL: {$a->endpoint}
プラットフォームでのサイト名: {$a->site}

今後、バックアップは送られません。念のため、プラットフォームの画面でも、サイトの登録の削除か、登録コードの発行し直しを行ってください。

{$a->url}';
$string['message_deregistered_subject'] = '{$a->platform} との登録を解除しました';
$string['message_enrolled_body'] = '{$a->user} が、このサイトを {$a->platform} に登録しました。

接続先の URL: {$a->endpoint}
プラットフォームでのサイト名: {$a->site}

心当たりが無い場合は、連携ツールの接続設定を確かめてください。

{$a->url}';
$string['message_enrolled_subject'] = 'このサイトを {$a->platform} に登録しました';
$string['message_reenrolled_body'] = '{$a->user} が、新しい登録コードで、このサイトを {$a->platform} に登録し直しました。このサイトの前の鍵は使えなくなりました。

接続先の URL: {$a->endpoint}
プラットフォームでのサイト名: {$a->site}

心当たりが無い場合は、連携ツールの接続設定を確かめてください。

{$a->url}';
$string['message_reenrolled_subject'] = 'このサイトを {$a->platform} に登録し直しました';
$string['messageprovider:connection'] = 'バックアップのサービスとの接続の変更';
$string['notifier_system'] = 'システム';
$string['object_incomplete'] = '保存先へ送ったデータ、または保存先から読んだデータの長さが想定と違ったため、破棄しました。';
$string['object_rejected'] = '保存先がリクエストを受け付けませんでした。転送用のアドレスの有効期限が切れた可能性があります。';
$string['object_url_invalid'] = 'バックアップのサービスが示した保存先のアドレスは、この連携ツールが受け付けない形でした。何も送っていません。';
$string['page_connect'] = '接続設定';
$string['page_diagnostics'] = '接続の診断';
$string['page_status'] = '状態';
$string['pluginname'] = 'あんしんLMSバックアップ';
$string['privacy:metadata:core_message'] = '連携ツールは、バックアップのサービスとの接続が変わったことを、メッセージの仕組みでサイトの管理者に知らせます。メッセージには、変更を行った管理者の名前が入ります。';
$string['privacy:metadata:service'] = 'この連携ツールは、このサイトを暗号化したバックアップを、あんしんLMSバックアップのサービスへ送ります。';
$string['privacy:metadata:service:sitedata'] = 'サイト全体 (全利用者の個人データを含む、バックアップの対象のすべて)。';
$string['publickey_base64'] = '公開鍵に使えない文字が入っています。プラットフォームの画面から、もう一度コピーしてください。';
$string['publickey_length'] = '公開鍵が途中で切れています。プラットフォームの画面から、鍵の全体をもう一度コピーしてください。';
$string['publickey_prefix'] = '公開鍵は「secbk1:」で始まります。プラットフォームの画面から、もう一度コピーしてください。';
$string['receipt_mismatch'] = 'バックアップのサービスの受領証が、このサイトの送ったバックアップと合いません。保存できたものとは数えません。';
$string['result_fail'] = '問題あり';
$string['result_info'] = '情報';
$string['result_pass'] = '問題なし';
$string['result_unknown'] = '不明';
$string['result_warn'] = '注意';
$string['secbackup:manage'] = 'あんしんLMSバックアップとの接続を管理する';
$string['service_status_active'] = '有効';
$string['service_status_awaiting_enrollment'] = '登録を待っています';
$string['service_status_other'] = 'その他 ({$a})';
$string['signingkey_corrupt'] = '保存した署名用の鍵を読めません。登録を解除してから、もう一度登録してください。';
$string['signingkey_missing'] = 'このサイトの署名用の鍵が見つかりません。登録し直してください。';
$string['signingkey_savefailed'] = '署名用の鍵を暗号化できなかったため、保存しませんでした。データのフォルダ (moodledata) にある暗号鍵のファイルを確かめてから、もう一度試してください。';
$string['status_enrolled_at'] = '登録した日時';
$string['status_last_error'] = '最後の状態の問い合わせに失敗しました。';
$string['status_last_error_code'] = '最後の状態の問い合わせに失敗しました ({$a})。';
$string['status_last_poll'] = '最後の状態の問い合わせ';
$string['status_never'] = 'まだありません';
$string['status_not_registered'] = 'このサイトは、まだバックアップのサービスに登録されていません。接続設定を開き、プラットフォーム (バックアップを申し込んだサービス) の画面で発行した登録コードで登録してください。';
$string['status_platform'] = 'プラットフォーム';
$string['status_response_invalid'] = 'サービスからの状態の応答を読めませんでした。';
$string['status_service_status'] = 'サービスでの状態';
$string['status_site'] = 'プラットフォームでのサイト名';
$string['status_siteid'] = 'サイト ID';
$string['status_unknown'] = 'まだ分かりません';
$string['status_unreadable'] = '読み取れません。';
$string['task_poll_failed'] = '状態の問い合わせに失敗しました。連携ツールの状態の画面を確かめてください。';
$string['task_poll_notregistered'] = 'サイトが登録されていないので、問い合わせませんでした。';
$string['task_poll_ok'] = '状態を受け取りました。';
$string['task_poll_status'] = 'バックアップのサービスに、このサイトの状態を問い合わせる';
$string['trademark'] = 'Moodle という語と関連する Moodle のロゴは、Moodle Pty Ltd またはその関連会社の商標または登録商標です。The word Moodle and associated Moodle logos are trademarks or registered trademarks of Moodle Pty Ltd or its related affiliates. あんしんLMSバックアップは、Moodle Pty Ltd とその関連会社のいずれとも関係がなく、その承認や推奨を受けたものではありません。';
$string['transport_blocked'] = 'Moodle の HTTP のセキュリティ設定が、接続先への通信を止めています。「サイト管理 > 一般 > セキュリティ > HTTPセキュリティ」で、「cURLブロック済みホスト一覧」に接続先の URL のホスト名とその IP アドレスが入っていないことと、「cURL許可済みポート一覧」が空か、443 を含むことを確かめてください。';
$string['transport_connect'] = '接続先のサービスにつながらないか、時間内に応答がありませんでした。このサーバのネットワークとプロキシの設定を確かめてください。';
$string['transport_failed'] = '接続先のサービスとの通信に失敗しました。';
$string['transport_redirect'] = '接続先のサービスが別の場所へ案内しましたが、この連携ツールはたどりません。接続先の URL を確かめてください。';
$string['transport_tls'] = '接続先のサービスの証明書を確かめられませんでした。ネットワークに HTTPS の通信を途中で復号する機器 (セキュリティの装置やプロキシ) がある場合は、接続先の URL のホスト名を復号の対象から外すよう、その管理者に頼んでください。';
$string['transport_too_large'] = '接続先のサービスからの応答が想定より大きかったので、捨てました。';
