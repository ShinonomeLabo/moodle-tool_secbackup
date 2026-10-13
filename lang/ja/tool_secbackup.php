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

$string['api_body_too_large'] = '要求が、サービスの受け付ける大きさを超えていました。';
$string['api_encryption_key_mismatch'] = '貼り付けた公開鍵が、プラットフォームにあるこのサイトの今の公開鍵と違います。プラットフォームの画面から、公開鍵をもう一度コピーしてください。';
$string['api_enrollment_code_invalid'] = '登録コードが違うか、期限 (30 分) が切れたか、すでに使われています。プラットフォームの画面で、登録コードを発行し直してください。';
$string['api_invalid_request'] = 'サービスが要求を受け付けませんでした。連携ツールを最新の版に更新してください。';
$string['api_ip_not_allowed'] = 'サービスが、このサーバの IP アドレスからの接続を受け付けていません。プラットフォームの画面の接続設定に、このサーバの IP アドレスを追加してください。';
$string['api_ip_not_allowed_address'] = 'サービスには、このサーバの接続元が {$a} と見えていますが、この IP アドレスからの接続は受け付けていません。{$a} がこのサーバから外へ出るときの IP アドレスであることを確かめてから、プラットフォームの画面の接続設定に追加してください。';
$string['api_nonce_reused'] = 'サービスに同じ要求が 2 度届きました。もう一度お試しください。';
$string['api_rate_limited'] = 'サービスへの要求が多すぎます。しばらく待ってから、もう一度お試しください。';
$string['api_signature_invalid'] = 'サービスが、このサイトの署名を確かめられませんでした。別のサーバからこのサイトを登録し直した場合は、ここでも登録し直してください。';
$string['api_signature_required'] = '要求の署名がサービスに届きませんでした。プロキシやセキュリティの機器が見出しを消している可能性があります。X-SecBackup で始まる見出しを通すよう、その管理者に頼んでください。';
$string['api_site_not_enrolled'] = 'サービスに、このサイトの登録が見つからないか、登録が無効になっています。プラットフォームの画面で登録コードを発行し、登録し直してください。';
$string['api_timestamp_out_of_range'] = 'このサーバの時刻が、サービスの時刻と大きくずれています。NTP などでこのサーバの時刻を合わせてから、もう一度お試しください。';
$string['api_timestamp_out_of_range_minutes'] = 'このサーバの時刻が、サービスの時刻と約 {$a} 分ずれています。NTP などでこのサーバの時刻を合わせてから、もう一度お試しください。';
$string['api_unavailable'] = 'サービスが混み合っているか、保守の作業中です。しばらくしてから、もう一度お試しください。';
$string['api_unexpected'] = 'サービスから想定外の応答がありました (HTTP {$a})。';
$string['api_wwwroot_mismatch'] = 'このサイトの URL ($CFG->wwwroot) が、プラットフォームに登録したサイトの URL と違います。プラットフォームの画面で、サイトの URL を確かめてください。';
$string['db_unsupported'] = 'このデータベースには対応していません。対応しているのは MySQL、MariaDB、PostgreSQL です。';
$string['developersettings_invalid'] = 'config.php の開発用の設定 (TOOL_SECBACKUP_DEVELOPER) に誤りがあります。理由は、デバッグの表示を有効にすると確かめられます。';
$string['dns_doh_failed'] = 'DNS over HTTPS のサービスが答えませんでした。';
$string['dns_doh_unavailable'] = 'DNS over HTTPS のサービス (1.1.1.1 と 8.8.8.8) のどちらも答えなかったので、接続先の IP アドレスを確かめられませんでした。1.1.1.1 か 8.8.8.8 への HTTPS の接続を許すか、Moodle にプロキシを設定してください。';
$string['dns_not_found'] = '接続先の URL のホスト名が見つかりません。接続先の URL を確かめてください。';
$string['dns_os_failed'] = 'このサーバで、接続先の URL のホスト名を引けませんでした。サーバの DNS の設定を確かめてください。';
$string['dns_os_mismatch'] = 'このサーバの DNS が、接続先のサービスのものではない IP アドレスを返しています。何も送っていません。このサーバの DNS を、ネットワークの管理者に確かめてもらってください。';
$string['dns_outside_aws'] = '接続先の URL のホスト名が、接続先のサービスを指していません。何も送っていません。接続先の URL を確かめてください。';
$string['endpoint_empty'] = 'プラットフォームの画面に出ている接続先の URL を入れてください。';
$string['endpoint_host'] = '接続先の URL のホスト名は使えません。プラットフォームの画面に出ている、IP アドレスではないホスト名の URL を使ってください。';
$string['endpoint_invalid'] = '接続先の URL の形になっていません。プラットフォームの画面から、https:// で始まる URL の全体をコピーしてください。';
$string['endpoint_path'] = '接続先の URL のパスに使えない文字が入っています。プラットフォームの画面から、もう一度コピーしてください。';
$string['endpoint_port'] = '接続先の URL に、標準の番号 (443) 以外のポートは入れられません。';
$string['endpoint_query'] = '接続先の URL に「?」や「#」は入れられません。プラットフォームの画面から、もう一度コピーしてください。';
$string['endpoint_scheme'] = '接続先の URL は https:// で始めてください。';
$string['endpoint_userinfo'] = '接続先の URL に、ユーザー名やパスワードは入れられません。';
$string['enroll_response_invalid'] = 'サービスからの登録の応答を読めませんでした。何も保存していません。';
$string['enroll_response_mismatch'] = 'サービスの署名付きの応答が、この登録の要求に対するものではありません。何も保存していません。接続先の URL を確かめ、念のため、プラットフォームの画面で登録コードを発行し直してください。';
$string['envelope_malformed'] = '接続先のサービスからの応答を読めませんでした。接続先の URL が正しいか確かめてください。';
$string['envelope_signature'] = '接続先のサービスからの応答の署名を確かめられませんでした。接続先の URL が正しいか確かめてください。';
$string['envelope_untrusted'] = 'この連携ツールが信頼していない鍵で署名された応答です。接続先の URL が正しいか確かめてください。';
$string['event_deregistered'] = 'バックアップのサービスとの登録の解除';
$string['event_enrolled'] = 'バックアップのサービスへのサイトの登録';
$string['message_deregistered_body'] = '{$a->user} が、このサイトの {$a->platform} との登録を解除しました。

接続先: {$a->endpoint}
プラットフォームでのサイト名: {$a->site}

今後、バックアップは送られません。念のため、プラットフォームの画面でも、サイトの登録の削除か、登録コードの発行し直しを行ってください。

{$a->url}';
$string['message_deregistered_subject'] = '{$a->platform} との登録を解除しました';
$string['message_enrolled_body'] = '{$a->user} が、このサイトを {$a->platform} に登録しました。

接続先: {$a->endpoint}
プラットフォームでのサイト名: {$a->site}

心当たりが無い場合は、連携ツールの接続設定を確かめてください。

{$a->url}';
$string['message_enrolled_subject'] = 'このサイトを {$a->platform} に登録しました';
$string['message_reenrolled_body'] = '{$a->user} が、新しい登録コードで、このサイトを {$a->platform} に登録し直しました。このサイトの前の鍵は使えなくなりました。

接続先: {$a->endpoint}
プラットフォームでのサイト名: {$a->site}

心当たりが無い場合は、連携ツールの接続設定を確かめてください。

{$a->url}';
$string['message_reenrolled_subject'] = 'このサイトを {$a->platform} に登録し直しました';
$string['messageprovider:connection'] = 'バックアップのサービスとの接続の変更';
$string['notifier_system'] = 'システム';
$string['pluginname'] = 'あんしんLMSバックアップ';
$string['privacy:metadata:core_message'] = '連携ツールは、バックアップのサービスとの接続が変わったことを、メッセージの仕組みでサイトの管理者に知らせます。メッセージには、変更を行った管理者の名前が入ります。';
$string['privacy:metadata:service'] = 'この連携ツールは、このサイトを暗号化したバックアップを、あんしんLMSバックアップのサービスへ送ります。';
$string['privacy:metadata:service:sitedata'] = 'サイト全体 (全利用者の個人データを含む、バックアップの対象のすべて)。';
$string['publickey_base64'] = '公開鍵に使えない文字が入っています。プラットフォームの画面から、もう一度コピーしてください。';
$string['publickey_length'] = '公開鍵が途中で切れています。プラットフォームの画面から、鍵の全体をもう一度コピーしてください。';
$string['publickey_prefix'] = '公開鍵は「secbk1:」で始まります。プラットフォームの画面から、もう一度コピーしてください。';
$string['secbackup:manage'] = 'あんしんLMSバックアップとの接続を管理する';
$string['signingkey_corrupt'] = '保存した署名用の鍵を読めません。登録を解除してから、もう一度登録してください。';
$string['signingkey_savefailed'] = '署名用の鍵を暗号化できなかったため、保存しませんでした。データディレクトリにある暗号鍵のファイルを確かめてから、もう一度試してください。';
$string['status_response_invalid'] = 'サービスからの状態の応答を読めませんでした。';
$string['transport_blocked'] = 'Moodle の HTTP のセキュリティ設定が、接続先への通信を止めています。接続先の URL のホスト名と 443 番のポートを許すよう、サイトの管理者に設定してもらってください。';
$string['transport_connect'] = '接続先のサービスにつながらないか、時間内に応答がありませんでした。このサーバのネットワークとプロキシの設定を確かめてください。';
$string['transport_failed'] = '接続先のサービスとの通信に失敗しました。';
$string['transport_redirect'] = '接続先のサービスが別の場所へ案内しましたが、この連携ツールはたどりません。接続先の URL を確かめてください。';
$string['transport_tls'] = '接続先のサービスの証明書を確かめられませんでした。ネットワークに HTTPS の通信を途中で復号する機器 (セキュリティの装置やプロキシ) がある場合は、接続先の URL のホスト名を復号の対象から外すよう、その管理者に頼んでください。';
$string['transport_too_large'] = '接続先のサービスからの応答が想定より大きかったので、捨てました。';
