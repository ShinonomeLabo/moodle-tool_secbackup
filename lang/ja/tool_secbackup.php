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
