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
$string['envelope_malformed'] = '接続先のサービスからの応答を読めませんでした。接続先の URL が正しいか確かめてください。';
$string['envelope_signature'] = '接続先のサービスからの応答の署名を確かめられませんでした。接続先の URL が正しいか確かめてください。';
$string['envelope_untrusted'] = 'この連携ツールが信頼していない鍵で署名された応答です。接続先の URL が正しいか確かめてください。';
$string['pluginname'] = 'あんしんLMSバックアップ';
$string['privacy:metadata:service'] = 'この連携ツールは、このサイトを暗号化したバックアップを、あんしんLMSバックアップのサービスへ送ります。';
$string['privacy:metadata:service:sitedata'] = 'サイト全体 (全利用者の個人データを含む、バックアップの対象のすべて)。';
$string['publickey_base64'] = '公開鍵に使えない文字が入っています。プラットフォームの画面から、もう一度コピーしてください。';
$string['publickey_length'] = '公開鍵が途中で切れています。プラットフォームの画面から、鍵の全体をもう一度コピーしてください。';
$string['publickey_prefix'] = '公開鍵は「secbk1:」で始まります。プラットフォームの画面から、もう一度コピーしてください。';
$string['secbackup:manage'] = 'あんしんLMSバックアップとの接続を管理する';
$string['signingkey_corrupt'] = '保存した署名用の鍵を読めません。登録を解除してから、もう一度登録してください。';
$string['signingkey_savefailed'] = '署名用の鍵を暗号化できなかったため、保存しませんでした。データディレクトリにある暗号鍵のファイルを確かめてから、もう一度試してください。';
