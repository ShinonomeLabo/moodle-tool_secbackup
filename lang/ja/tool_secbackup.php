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

$string['abort_capacity_refused'] = 'バックアップのサービスが 1 世代で保存できる大きさ (プランの上限、または塊の数) を超えるため、バックアップを中止しました。';
$string['abort_capacity_refused_advice'] = 'プラットフォームの画面で、プランが認める大きさを確かめ、サイトが大きくなっていればプランを変えてください。サイトから不要なファイルを消しても、バックアップは小さくなります。';
$string['abort_contract_inactive'] = 'バックアップの途中で、バックアップのサービスの契約が有効ではなくなったため、バックアップを中止しました。';
$string['abort_contract_inactive_advice'] = 'プラットフォームの画面で、バックアップのサービスの契約を確かめてください。契約が有効になれば、バックアップはまた始まります。';
$string['abort_db_slow'] = 'データベースの応答が、連携ツールの限界の値より遅くなったため、バックアップを中止しました。次の予定のバックアップで、もう一度始めます。';
$string['abort_db_slow_advice'] = 'バックアップの時間帯に、データベースサーバの負荷を確かめてください。いつもこの遅さなら、「バックアップの制限」で、データベースの応答の遅さの上限を上げてください。';
$string['abort_disk_low'] = '連携ツールの一時フォルダの空き容量が足りないため、バックアップを中止しました。';
$string['abort_disk_low_advice'] = '一時フォルダがあるディスクの空きを増やすか、「バックアップの制限」で、空きの多いディスクのフォルダを選んでください。空きがある場合は、cron を動かすユーザーと Web サーバがそのフォルダに書き込めるか、バックアップの途中でフォルダが空にされていないか (たとえばサーバの再起動で空になる /tmp の下のフォルダ) を確かめてください。';
$string['abort_expired'] = 'バックアップのサービスが、24 時間以内にバックアップの終わりを受け取れなかったため、バックアップを止めました。';
$string['abort_expired_advice'] = 'バックアップは cron が動いている間だけ進むので、Moodle の cron が 1 分ごとに動いているかを確かめてください。バックアップが時間帯の終わりで一時停止していた場合は、時間帯を長くするか、cli/backup.php で動かしてください (README を見てください)。次の予定のバックアップで、もう一度始めます。';
$string['abort_file'] = 'そのファイル: データのフォルダ (moodledata) の {$a->path} で、サイトのファイル「{$a->filename}」(コンポーネント {$a->component}、ファイルエリア {$a->filearea}、アイテム {$a->itemid}) です。';
$string['abort_file_content'] = 'そのファイル: データのフォルダ (moodledata) の {$a->path} です (サイトのどのファイルも、もうこれを使っていません)。';
$string['abort_network_check_failed'] = 'バックアップのサービスまたは保存先への接続が、送る前の検査 (アドレス、名前の問い合わせ、ネットワーク) に合格しなかったため、バックアップを中止しました。';
$string['abort_network_check_failed_advice'] = '接続の診断を開き、合格しなかった項目 (アドレス、名前の問い合わせ、ネットワーク) を直してから、もう一度バックアップを始めてください。';
$string['abort_nfs_slow'] = 'サイトのファイルを読む速さが連携ツールの限界の値より遅くなったか、サイトのファイルを何度か試しても読めませんでした。ファイルを置いている共有ストレージが遅いか、不安定かもしれません。次の回は、この回が止まったところから続けます。';
$string['abort_nfs_slow_advice'] = 'サイトのファイルを置いているデータのフォルダ (moodledata) のストレージの状態を確かめてください。遅いのが時々だけなら、「バックアップの制限」で、ファイルの読み込み速度の下限を下げてください。';
$string['abort_restarted'] = 'バックアップの途中で、このサイトがその回の記録を失ったため、その回を中止して新しい回を始めました。';
$string['abort_restarted_advice'] = '特に必要な操作はありません。何度も起きる場合は、バックアップの途中でサイトのデータベースを戻したり初期化したりしていないかを確かめてください。';
$string['abort_selinux_denied'] = 'このサーバの SELinux が、Web サーバに接続か連携ツールの一時ファイルを許さなかったため、バックアップを中止しました。';
$string['abort_selinux_denied_advice'] = '接続の診断を開き、SELinux の項目の直し方 (接続、一時フォルダ、NFS 上のデータのフォルダ (moodledata)) に従ってから、もう一度バックアップを始めてください。';
$string['abort_server_busy'] = 'サーバの負荷か空きメモリが、連携ツールの限界の値を超えたため、バックアップを中止しました。次の予定のバックアップで、もう一度始めます。';
$string['abort_server_busy_advice'] = 'バックアップの時間帯に、サーバで他に何が動いているかを確かめてください。いつもこの状態なら、「バックアップの制限」で、サーバの負荷の上限を上げるか、空きメモリの下限を下げてください。';
$string['abort_signature_invalid'] = 'バックアップのサービスから受け取ったデータの署名または中身を確かめられなかったため、バックアップを中止しました。';
$string['abort_signature_invalid_advice'] = 'このサイトを別のサーバに移した場合や登録し直した場合は、プラットフォームの画面で新しい登録コードを発行し、接続設定でもう一度登録してください。そうでない場合は、プラットフォームのサポートに問い合わせてください。';
$string['abort_tamper_detected'] = 'サイトのファイルの中身が、そのファイルを保存している名前 (ハッシュ) と合っていません。ファイルが書き換えられたか、壊れた可能性があります。バックアップを中止しました。';
$string['abort_tamper_detected_advice'] = 'サイトのデータのフォルダ (moodledata) に、書き換えられたファイルや壊れたファイルがないかを確かめてください (ディスクの故障や、ファイルを書き換えるプログラムが原因のことがあります)。壊れたファイルは以前のバックアップから戻してから、もう一度バックアップを始めてください。';
$string['abort_unknown'] = 'バックアップを中止しました ({$a})。';
$string['abort_unknown_advice'] = '接続の診断を開き、サーバのログに連携ツールの誤りがないかを確かめてから、もう一度バックアップを始めてください。続く場合は、プラットフォームのサポートに問い合わせてください。';
$string['abort_upload_failed'] = '保存先へのデータの送信が、何度やり直しても成功しなかったため、バックアップを中止しました。';
$string['abort_upload_failed_advice'] = '接続の診断を開き、このサーバからバックアップのサービスの保存先に接続できるかを確かめてください。接続に問題がなければ、次の予定のバックアップでもう一度試します。';
$string['abort_verify_artifact_mismatch'] = '保存先にある世代の一覧または索引が、このサイトが報告したものと違うことを、バックアップのサービスが見つけました。このバックアップは保存されていません。';
$string['abort_verify_artifact_mismatch_advice'] = 'もう一度バックアップを始めてください。また起きる場合は、このサーバと保存先の間のプロキシやセキュリティ機器が、データを書き換えていないかを確かめてください。';
$string['abort_verify_error'] = 'バックアップのサービスがバックアップを確かめられなかったため、このバックアップは保存されていません。';
$string['abort_verify_error_advice'] = 'しばらくしてから、もう一度バックアップを始めてください。また起きる場合は、プラットフォームのサポートに問い合わせてください。';
$string['abort_verify_index_invalid'] = 'このサイトが送った索引を、バックアップのサービスが読めなかったため、このバックアップは保存されていません。';
$string['abort_verify_index_invalid_advice'] = 'もう一度バックアップを始めてください。また起きる場合は、連携ツールを最新の版に更新し、プラットフォームのサポートに問い合わせてください。';
$string['abort_verify_missing_chunks'] = 'このサイトが送ったデータの一部が保存先に見つからなかったため、このバックアップは保存されていません。';
$string['abort_verify_missing_chunks_advice'] = 'もう一度バックアップを始めてください。また起きる場合は、接続の診断を開き、このサーバと保存先の間で、データを書き換えたり止めたりするものがないかを確かめてください。';
$string['abort_verify_size_mismatch'] = '保存先にあるデータの大きさが、このサイトが報告したものと違うため、このバックアップは保存されていません。';
$string['abort_verify_size_mismatch_advice'] = 'もう一度バックアップを始めてください。また起きる場合は、このサーバと保存先の間のプロキシやセキュリティ機器が、データを書き換えていないかを確かめてください。';
$string['abort_version_too_old'] = 'この連携ツールの版が、バックアップのサービスが受け付ける版より古いため、バックアップを中止しました。';
$string['abort_version_too_old_advice'] = '連携ツールを最新の版に更新してから、もう一度バックアップを始めてください。';
$string['abort_window_ended'] = 'バックアップが時間帯の中で終わらず、次の時間帯でも、バックアップのサービスが 1 回のバックアップに認める 24 時間のうちに終わらないため、バックアップを中止しました。次の予定のバックアップで、もう一度始めます。';
$string['abort_window_ended_advice'] = '「バックアップの制限」の値とサーバの負荷を確かめてください。サイトが大きい場合は、プラットフォームの画面で、バックアップの時間帯を長くする方法を確かめるか、時間帯の終わりで止まらない cli/backup.php をシステムの cron から動かしてください (README を見てください)。';
$string['action_deregister'] = '登録を解除する';
$string['action_next'] = '次へ進む';
$string['action_register'] = '登録する';
$string['api_artifact_signature_invalid'] = 'バックアップのサービスが、このサイトの送った世代の一覧または索引の署名を確かめられませんでした。このサイトの鍵を入れ替えた場合は、登録し直してから、バックアップをやり直してください。';
$string['api_body_too_large'] = '要求が、サービスの受け付ける大きさを超えていました。';
$string['api_capacity_recheck_required'] = 'バックアップのサービスが、これ以上のデータを受け取る前に、バックアップの見込みの大きさを確かめ直すよう求めました。';
$string['api_contract_inactive'] = 'バックアップのサービスの契約が有効ではありません。プラットフォームの画面で契約を確かめてください。';
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
$string['api_run_not_found'] = 'バックアップのサービスがこのバックアップを知りません。';
$string['api_run_state_invalid'] = 'バックアップの今の状態では、バックアップのサービスがこの操作を受け付けません。';
$string['api_signature_invalid'] = 'サービスが、このサイトの署名を確かめられませんでした。別のサーバからこのサイトを登録し直した場合は、ここでも登録し直してください。';
$string['api_signature_required'] = '要求の署名がサービスに届きませんでした。プロキシやセキュリティの機器がヘッダーを消している可能性があります。X-SecBackup で始まるヘッダーを通すよう、その管理者に頼んでください。';
$string['api_site_not_enrolled'] = 'サービスに、このサイトの登録が見つからないか、登録が無効になっています。プラットフォームの画面で登録コードを発行し、登録し直してください。';
$string['api_timestamp_out_of_range'] = 'このサーバの時刻が、サービスの時刻と大きくずれています。NTP などでこのサーバの時刻を合わせてから、もう一度お試しください。';
$string['api_timestamp_out_of_range_minutes'] = 'このサーバの時刻が、サービスの時刻と約 {$a} 分ずれています。NTP などでこのサーバの時刻を合わせてから、もう一度お試しください。';
$string['api_unavailable'] = 'サービスが混み合っているか、保守の作業中です。しばらくしてから、もう一度お試しください。';
$string['api_unexpected'] = 'サービスから想定外の応答がありました (HTTP {$a})。';
$string['api_wwwroot_mismatch'] = 'このサイトの URL ($CFG->wwwroot) が、プラットフォームに登録したサイトの URL と違います。プラットフォームの画面で、サイトの URL を確かめてください。';
$string['capacity_refused'] = 'バックアップのサービスが 1 世代で保存できる大きさ (プランの上限、または塊の数) を超えるため、バックアップを中止しました。';
$string['check_apparmor'] = 'AppArmor';
$string['check_autobackup'] = 'Moodle の自動バックアップ';
$string['check_clock'] = '時刻';
$string['check_connection'] = 'サービスへの接続';
$string['check_database'] = 'データベースの応答';
$string['check_developer'] = '開発用の設定';
$string['check_dns'] = '名前解決';
$string['check_extensions'] = 'PHP の拡張';
$string['check_load'] = 'サーバの負荷';
$string['check_memory'] = '空きメモリ';
$string['check_moodledata'] = 'データのフォルダ (moodledata)';
$string['check_proxy'] = 'プロキシ';
$string['check_selinux'] = 'SELinux';
$string['check_tempdir'] = '一時フォルダ';
$string['cli_backup_aborted'] = 'バックアップを中止しました: {$a}';
$string['cli_backup_continued'] = '動いているバックアップを続けます。';
$string['cli_backup_done'] = 'バックアップが完了しました。バックアップのサービスが確かめ、受領証も確かめました。';
$string['cli_backup_failed'] = 'バックアップの段の 1 つが失敗しました ({$a})。5 分後にもう一度試します。';
$string['cli_backup_heading'] = 'あんしんLMSバックアップのバックアップ';
$string['cli_backup_help'] = 'このサイトのバックアップを、バックアップのサービスへ、終わるまで進めます。

動いているバックアップがあれば続けます。無ければ、--now を付けたときだけ始めます。失敗した段は 5 分後にもう一度試します。始めてから 24 時間たっても受領証の無いバックアップは中止します。

オプション:
--now        バックアップが動いていなければ、時間帯の内でも外でも、すぐに始める。
--quiet      結果だけを出す。
-h, --help   この説明を出す。

終了の値: 0 はバックアップが完了した、1 はバックアップを中止したか、バックアップのサービスが始めなかった、2 はバックアップを進めなかった。

cron を動かす利用者で実行してください。例:
$ sudo -u www-data /usr/bin/php admin/tool/secbackup/cli/backup.php --now
(Moodle 5.1 以降: public/admin/tool/secbackup/cli/backup.php)';
$string['cli_backup_lockwait'] = 'ほかの処理がバックアップの段を進めています。それが終わってから続けます。';
$string['cli_backup_none'] = '動いているバックアップはありません。始めるには --now を付けてください。';
$string['cli_backup_notstarted'] = 'バックアップを始めませんでした: {$a}';
$string['cli_backup_otherserver'] = '動いているバックアップの一時ファイルは、サーバ {$a} にあります。そのサーバでこのコマンドを実行してください。';
$string['cli_backup_refused'] = 'バックアップのサービスがバックアップを始めませんでした: {$a}';
$string['cli_backup_stage'] = '段階: {$a}';
$string['cli_backup_started'] = 'バックアップを始めました。';
$string['cli_backup_waiting'] = '{$a} まで待ちます。';
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
$string['contract_inactive'] = 'バックアップの途中で、バックアップのサービスの契約が有効ではなくなったため、バックアップを中止しました。';
$string['core_manifest_invalid'] = 'バックアップのサービスから受け取った Moodle の公式ファイルの一覧、またはこのサイトのバージョンのファイルを、想定どおりに読めませんでした。代わりに Moodle のプログラムのファイルをすべて送るため、時間と保存容量が多くかかります。';
$string['core_manifest_mismatch'] = 'バックアップのサービスから受け取った Moodle の公式ファイルの一覧は、このサイトの Moodle とは別の版のものです。代わりに Moodle のプログラムのファイルをすべて送るため、時間と保存容量が多くかかります。';
$string['crypto_chunk_corrupt'] = 'バックアップの塊が壊れているか、書き換えられているか、このサイトのものでないため、復号できませんでした。';
$string['crypto_chunk_sourceshort'] = 'バックアップの塊に入れるデータが、想定の長さより前で終わりました。読んでいる間に変わった可能性があります。';
$string['crypto_chunk_truncated'] = 'バックアップの塊が最後の区切りより前で終わっているため、途中で切れています。';
$string['db_slow'] = 'データベースの応答が、連携ツールの限界の値より遅くなったため、バックアップを中止しました。次の予定のバックアップで、もう一度始めます。';
$string['db_unsupported'] = 'このデータベースには対応していません。対応しているのは MySQL、MariaDB、PostgreSQL です。';
$string['dedupkey_corrupt'] = '保存した、バックアップの塊の名前を作るための鍵を読めません。登録を解除してから、もう一度登録してください。';
$string['dedupkey_savefailed'] = 'バックアップの塊の名前を作るための鍵を暗号化できなかったため、保存しませんでした。データのフォルダ (moodledata) にある暗号鍵のファイルを確かめてから、もう一度試してください。';
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
$string['diag_autobackup_advice'] = 'Moodle の自動バックアップが動いている間は、連携ツールはバックアップを始めません。自動バックアップの時刻は、連携ツールのバックアップの時間帯と重ならないようにしてください。';
$string['diag_autobackup_noround'] = '記録がありません';
$string['diag_autobackup_off'] = '無効です。Moodle のコースの自動バックアップは使われていません。';
$string['diag_autobackup_on'] = '有効: {$a->days} の {$a->time} ({$a->zone})。前回の所要時間: {$a->duration}。';
$string['diag_cli_advice'] = '対処: {$a}';
$string['diag_cli_endpoint'] = '接続先の URL: {$a}';
$string['diag_cli_heading'] = 'あんしんLMSバックアップの接続の診断';
$string['diag_cli_noendpoint'] = '接続先の URL: 登録していません (--endpoint で指定できます)';
$string['diag_clock_seconds'] = '時刻のずれは {$a} 秒です。';
$string['diag_clock_unknown'] = 'まだ分かりません。登録や状態の問い合わせのときに測ります。';
$string['diag_connection_ok'] = 'サービスに届きました。この確かめは署名を付けずに問い合わせるので、HTTP {$a} (認証が必要) が返れば正常です。';
$string['diag_connection_other'] = '応答はありましたが、サービスの API ではありません (HTTP {$a})。接続先の URL を確かめてください。';
$string['diag_database'] = '簡単な読み込み 1 回に {$a->ms} ミリ秒かかりました。{$a->limit} ミリ秒を超えると、バックアップは待ちます。';
$string['diag_database_advice'] = 'データベースが限界の値より遅いです。データベースサーバの負荷を確かめるか、「バックアップの制限」で、データベースの応答の遅さの上限を上げてください。';
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
$string['diag_load'] = 'CPU コア 1 つあたりの負荷: {$a->load}。{$a->limit} を超えると、バックアップは待ちます。';
$string['diag_load_advice'] = 'サーバが限界の値より忙しいです。負荷が下がるまで、バックアップは待ちます。いつもこの状態なら、「バックアップの制限」で、負荷の上限を上げてください。';
$string['diag_memory'] = '空きメモリ: {$a->memory}。{$a->limit} を下回ると、バックアップは待ちます。';
$string['diag_memory_advice'] = 'サーバの空きメモリが限界の値より少ないです。空きが増えるまで、バックアップは待ちます。いつもこの状態なら、「バックアップの制限」で、空きメモリの下限を下げてください。';
$string['diag_moodledata'] = 'ファイルシステム: {$a->type} (マウント先: {$a->mount}、元: {$a->source})';
$string['diag_moodledata_network'] = 'データのフォルダ (moodledata) がネットワークのファイルシステムにあります。バックアップは中のファイルをすべて読むので、時間がかかり、そのファイルシステムにも負荷がかかります。';
$string['diag_moodledata_unknown'] = '読み取れません。';
$string['diag_no_endpoint'] = 'サイトを登録するか、接続先の URL を入れると確かめられます。';
$string['diag_proxy_direct'] = 'プロキシを使わずに、直接つなぎます。';
$string['diag_proxy_used'] = 'プロキシ {$a} を通してつなぎます。';
$string['diag_run'] = '接続を確かめる';
$string['diag_selinux_advice'] = 'SELinux が Web サーバに許さなかった場合は、サーバの管理者が次のコマンドで確かめられます: ausearch -m avc -ts recent。バックアップのサービスとその保存先への接続のため、Web サーバに、443 などの Web のポートへの接続だけを許すには: setsebool -P httpd_can_network_relay on。すべてのネットワーク接続を許すには: setsebool -P httpd_can_network_connect on。ほかのディスクにある連携ツールの一時フォルダ ({$a}) には: semanage fcontext -a -t httpd_sys_rw_content_t "{$a}(/.*)?" のあとで restorecon -Rv {$a}。NFS にある Moodle のデータのフォルダ (moodledata) には: setsebool -P httpd_use_nfs on';
$string['diag_selinux_enforcing'] = 'Enforcing です。プロセスの種類: {$a}。';
$string['diag_selinux_permissive'] = 'Permissive です。プロセスの種類: {$a}。';
$string['diag_selinux_unknown'] = '使っていないか、読み取れません。';
$string['diag_skipped'] = '開発用の設定があるので、確かめていません。';
$string['diag_tempdir'] = '空き容量: {$a->free}。バックアップの一時ファイルの予算: {$a->budget}。';
$string['diag_tempdir_advice'] = '空き容量が、バックアップに必要な量 (ディスクの 10% と 5 GB の大きいほう) より少ないです。空きを増やすか、「バックアップの制限」で、空きの多いディスクのフォルダを選んでください。';
$string['diag_unreadable'] = '読み取れません。';
$string['disk_low'] = '連携ツールの一時フォルダの空き容量が足りないため、バックアップを中止しました。';
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
$string['message_backupfailure_body'] = 'バックアップのサービスへのこのサイトのバックアップが、直近 {$a->count} 回続けて、保存される前に中止されました。

最後の理由: {$a->reason}

直し方: {$a->advice}

連携ツールの状態の画面で、詳しく確かめられます: {$a->url}';
$string['message_backupfailure_subject'] = 'このサイトのバックアップが {$a->count} 回続けて中止されました';
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
$string['messageprovider:backupfailure'] = '続けて中止されたバックアップ';
$string['messageprovider:connection'] = 'バックアップのサービスとの接続の変更';
$string['modal_core'] = 'バックアップ対象LMS (Moodle™) は、LMS本体のソースコードに変更が加えられている可能性があります。バックアップ自体は可能ですが、一時サイトへのリストアに失敗する可能性があります。バックアップ後に必ず「プレビュー」機能を利用し、リストア出来ることを確認して下さい。';
$string['modal_core_title'] = '本体のコードが改造されている可能性があります';
$string['modal_external'] = 'AWS S3等外部クラウドサービスでmoodledata内のデータを管理しているプラグインを検知しました。バックアップ実行時、クラウドサービス側で課金が発生する可能性があります。({$a}以外での課金発生については、弊社ではサポートしておりません)';
$string['modal_external_title'] = '外部のクラウドの保存先を検知しました';
$string['network_check_failed'] = 'バックアップのサービスまたは保存先への接続が、送る前の検査 (アドレス、名前の問い合わせ、ネットワーク) に合格しなかったため、バックアップを中止しました。連携ツールの接続の診断を開いてください。';
$string['nfs_slow'] = 'サイトのファイルを何度か試しても読めませんでした。ファイルを置いている共有ストレージが遅いか、不安定かもしれません。次の回は、この回が止まったところから続けます。';
$string['notifier_system'] = 'システム';
$string['object_incomplete'] = '保存先へ送ったデータ、または保存先から読んだデータの長さが想定と違ったため、破棄しました。';
$string['object_rejected'] = '保存先がリクエストを受け付けませんでした。転送用のアドレスの有効期限が切れた可能性があります。';
$string['object_url_invalid'] = 'バックアップのサービスが示した保存先のアドレスは、この連携ツールが受け付けない形でした。何も送っていません。';
$string['page_connect'] = '接続設定';
$string['page_diagnostics'] = '接続の診断';
$string['page_settings'] = 'バックアップの制限';
$string['page_status'] = '状態';
$string['pluginname'] = 'あんしんLMSバックアップ';
$string['privacy:metadata:core_message'] = '連携ツールは、バックアップのサービスとの接続が変わったことを、メッセージの仕組みでサイトの管理者に知らせます。メッセージには、変更を行った管理者の名前が入ります。';
$string['privacy:metadata:service'] = 'この連携ツールは、このサイトを暗号化したバックアップを、あんしんLMSバックアップのサービスへ送ります。';
$string['privacy:metadata:service:sitedata'] = 'サイト全体 (全利用者の個人データを含む、バックアップの対象のすべて)。';
$string['publickey_base64'] = '公開鍵に使えない文字が入っています。プラットフォームの画面から、もう一度コピーしてください。';
$string['publickey_invalid'] = 'このサイトの公開鍵は暗号化に使えません。プラットフォームの画面から、もう一度コピーして登録し直してください。';
$string['publickey_length'] = '公開鍵が途中で切れています。プラットフォームの画面から、鍵の全体をもう一度コピーしてください。';
$string['publickey_prefix'] = '公開鍵は「secbk1:」で始まります。プラットフォームの画面から、もう一度コピーしてください。';
$string['receipt_mismatch'] = 'バックアップのサービスの受領証が、このサイトの送ったバックアップと合いません。保存できたものとは数えません。';
$string['refused_contract_inactive'] = '{$a} でのバックアップのサービスの契約が有効ではないため、バックアップのサービスはバックアップを始めませんでした。';
$string['refused_contract_inactive_advice'] = '{$a} で契約を確かめてください。契約が有効になれば、次の予定のバックアップから始まります。';
$string['refused_run_limit_reached'] = 'プランが 24 時間に認めるバックアップの回数に達したため、バックアップのサービスは新しい回を始めませんでした。';
$string['refused_run_limit_reached_advice'] = '特に必要な操作はありません。次の予定のバックアップでもう一度試し、プランが認めるようになれば始まります。';
$string['refused_run_limit_reached_at'] = 'プランが 24 時間に認めるバックアップの回数に達したため、バックアップのサービスは新しい回を始めませんでした。{$a} より後なら、また始められます。';
$string['refused_unknown'] = 'バックアップのサービスはバックアップを始めませんでした ({$a})。';
$string['refused_unknown_advice'] = '接続の診断を開き、しばらくしてからもう一度試してください。続く場合は、プラットフォームのサポートに問い合わせてください。';
$string['restarted'] = 'バックアップの途中で、このサイトがその回の記録を失ったため、その回を中止して新しい回を始めました。';
$string['result_fail'] = '問題あり';
$string['result_info'] = '情報';
$string['result_pass'] = '問題なし';
$string['result_unknown'] = '不明';
$string['result_warn'] = '注意';
$string['secbackup:manage'] = 'あんしんLMSバックアップとの接続を管理する';
$string['selinux_denial_network'] = 'SELinux が、Web サーバに、バックアップのサービスとその保存先が使うポート 443 への接続を許しませんでした。サーバの管理者は、次のコマンドで拒否を確かめられます: ausearch -m avc -ts recent。Web サーバに、443 などの Web のポートへの接続だけを許すには: setsebool -P httpd_can_network_relay on (すべてのネットワーク接続を許すには: setsebool -P httpd_can_network_connect on)。そのあとで、もう一度バックアップを始めてください。';
$string['selinux_denial_tempfolder'] = 'SELinux が、Web サーバに、一時フォルダ {$a} への書き込みを許しませんでした。サーバの管理者は、次のコマンドで拒否を確かめられます: ausearch -m avc -ts recent。Web サーバがそこに書き込めるようにするには: semanage fcontext -a -t httpd_sys_rw_content_t "{$a}(/.*)?" のあとで restorecon -Rv {$a}。一時フォルダを既定で置く Moodle のデータのフォルダ (moodledata) が NFS にある場合は: setsebool -P httpd_use_nfs on。そのあとで、もう一度バックアップを始めてください。';
$string['selinux_denied'] = 'このサーバの SELinux が、連携ツールの接続か一時ファイルを拒んだ可能性が高いため、バックアップを中止しました。変える設定は、連携ツールの接続の診断で確かめてください。';
$string['server_busy'] = 'サーバの負荷か空きメモリが、連携ツールの限界の値を超えたため、バックアップを中止しました。次の予定のバックアップで、もう一度始めます。';
$string['service_status_active'] = '有効';
$string['service_status_awaiting_enrollment'] = '登録を待っています';
$string['service_status_other'] = 'その他 ({$a})';
$string['setting_bandwidthmbps'] = '送信速度の上限 (Mbps)';
$string['setting_bandwidthmbps_desc'] = '連携ツールが 1 秒に送る量の上限です。単位はメガビットです。0 は上限なしです。';
$string['setting_error_decimal'] = '{$a} 以上の数を、小数は 6 桁までで入力してください。';
$string['setting_error_directory'] = '".." と制御文字を含まない絶対パスを入力してください。空にもできます。';
$string['setting_error_dirroot'] = 'Moodle のコードのフォルダの中は指定できません。ウェブサーバが公開するおそれがあります。';
$string['setting_error_whole'] = '{$a} 以上の整数を入力してください。';
$string['setting_loadpercore'] = 'CPU コア 1 つあたりの負荷の上限';
$string['setting_loadpercore_desc'] = '1 分間の負荷平均を CPU コアの数で割った値がこの値を超えている間、バックアップは待ちます。既定は 2 です。';
$string['setting_maxdbms'] = 'データベースの応答の遅さの上限 (ミリ秒)';
$string['setting_maxdbms_desc'] = '簡単な読み取り 1 回がこの時間より長くかかる間、バックアップは待ちます。既定は 500 です。';
$string['setting_minmemorymb'] = '使えるメモリの下限 (MB)';
$string['setting_minmemorymb_desc'] = 'サーバがあと割り当てられるメモリがこの値を下回る間、バックアップは待ちます。既定は 256 です。';
$string['setting_minnfsmbps'] = 'ファイル保管場所の読み取り速度の下限 (MB/秒)';
$string['setting_minnfsmbps_desc'] = 'サイトのファイルの読み取りがこの速さより遅い間、バックアップは待ちます。既定は 5 です。';
$string['setting_tempbudgetmb'] = '一時ファイルの予算 (MB)';
$string['setting_tempbudgetmb_desc'] = 'バックアップが一時フォルダに置いておく量の上限です。0 は、空き容量の 10% と 2,048 MB の小さいほうです。';
$string['setting_tempdir'] = '一時フォルダ';
$string['setting_tempdir_desc'] = '絶対パスで指定します。空のときは、Moodle の一時フォルダの中の tool_secbackup を使います。';
$string['signature_invalid'] = 'バックアップのサービスから受け取ったデータの署名または中身を確かめられなかったため、バックアップを中止しました。このサイトを別のサーバに移した場合や登録し直した場合は、もう一度登録してください。';
$string['signingkey_corrupt'] = '保存した署名用の鍵を読めません。登録を解除してから、もう一度登録してください。';
$string['signingkey_missing'] = 'このサイトの署名用の鍵が見つかりません。登録し直してください。';
$string['signingkey_savefailed'] = '署名用の鍵を暗号化できなかったため、保存しませんでした。データのフォルダ (moodledata) にある暗号鍵のファイルを確かめてから、もう一度試してください。';
$string['stage_capacity'] = 'バックアップの大きさを確かめています';
$string['stage_code'] = 'Moodle 本体と追加機能のプログラムを送っています';
$string['stage_complete'] = 'バックアップのサービスで仕上げています';
$string['stage_dataroot'] = 'データのフォルダ (moodledata) のそのほかのファイルを送っています';
$string['stage_db'] = 'データベースを送っています';
$string['stage_done'] = '完了しました';
$string['stage_files'] = 'サイトのファイルを送っています';
$string['stage_index'] = 'バックアップの索引を作っています';
$string['stage_manifest'] = 'バックアップの一覧を作っています';
$string['stage_prepare'] = '準備しています';
$string['stage_present'] = 'バックアップのサービスにすでにあるものを確かめています';
$string['stage_receipt'] = 'バックアップのサービスの受領証を待っています';
$string['stage_start'] = 'バックアップのサービスで始めています';
$string['start_autobackup'] = 'Moodle のコースの自動バックアップが動いているので、バックアップは始めませんでした。終わってから、もう一度試してください。';
$string['start_busy'] = 'サーバが、連携ツールの限界の値に対して忙しすぎる (負荷、メモリ、ディスク、データベース) ため、バックアップは始めませんでした。しばらくしてからもう一度試すか、「バックアップの制限」で値を変えてください。';
$string['start_cause_db_slow'] = 'データベースの応答が、連携ツールの限界の値より遅くなっていました。その時刻のデータベースのサーバの負荷を確かめるか、「バックアップの制限」の「データベースの応答の遅さの上限」を上げてください。';
$string['start_cause_disk_low'] = '連携ツールの一時フォルダがあるディスクの空きが、ディスクの 10% と 5 GB の大きいほうより少なくなっています。空きを増やすか、「バックアップの制限」でほかのディスクのフォルダを選んでください。';
$string['start_cause_load'] = 'サーバの CPU コアあたりの負荷が、連携ツールの限界の値を超えていました。その時刻にほかに何が動いているかを確かめるか、「バックアップの制限」の「CPU コア 1 つあたりの負荷の上限」を上げてください。';
$string['start_cause_memory'] = 'サーバの使えるメモリが、連携ツールの限界の値より少なくなっていました。その時刻にほかに何が動いているかを確かめるか、「バックアップの制限」の「使えるメモリの下限」を下げてください。';
$string['start_cause_nfs_slow'] = 'サイトのファイルの保管場所を読む速さが、連携ツールの限界の値より遅くなっていました。サイトのファイルを置いている保管場所を確かめるか、「バックアップの制限」の「ファイル保管場所の読み取り速度の下限」を下げてください。';
$string['start_cause_server_busy'] = 'サーバの負荷か使えるメモリが、連携ツールの限界の値を超えていました。';
$string['start_clock'] = 'このサーバの時計が、バックアップのサービスの時計と 5 分以上ずれているので、バックアップは始めませんでした。このサーバの時刻を (たとえば NTP で) 合わせてから、もう一度試してください。';
$string['start_memorylimit'] = 'PHP のメモリの上限と Moodle の追加のメモリの上限が、どちらも 256 MB より小さいので、バックアップは始めませんでした。どちらかを 256 MB 以上にしてから、もう一度試してください。';
$string['start_notreceived'] = 'このサイトのバックアップの設定を、バックアップのサービスがまだ送ってきていないので、バックアップは始めませんでした。次の状態の問い合わせのあとで、もう一度試してください。';
$string['start_notregistered'] = 'このサイトは、まだバックアップのサービスに登録されていないので、バックアップは始めませんでした。';
$string['start_reason_autobackup'] = 'Moodle のコースの自動バックアップが、まだ動いています。終わってからバックアップを始めます。';
$string['start_reason_busy'] = 'サーバが混み合っている (負荷、メモリ、ディスクの空き、データベース、ファイルの保存先) ため、今はバックアップを始めません。';
$string['start_reason_clock'] = 'このサーバの時計が、バックアップのサービスの時計と 5 分以上ずれています。サーバの時刻を直してください。';
$string['start_reason_memorylimit'] = 'PHP が使えるメモリが 256 MB より少ない可能性があります。memory_limit か、Moodle の追加のメモリの上限を上げてください。';
$string['start_reason_notreceived'] = 'バックアップのサービスの設定を、まだ受け取っていません。次の状態の問い合わせを待ってください。';
$string['start_reason_notregistered'] = 'このサイトは、バックアップのサービスに登録されていません。';
$string['start_reason_outsidewindow'] = '予定のバックアップの時間帯ではありません。';
$string['start_reason_running'] = 'バックアップが、すでに動いています。';
$string['start_reason_started'] = 'バックアップを始めました。';
$string['start_running'] = 'バックアップはすでに進んでいます。';
$string['start_started'] = 'バックアップを待ち行列に入れました。次に Moodle の cron が動くときに始まります。';
$string['status_aborted'] = '{$a} に始めた直近のバックアップは中止されました。';
$string['status_advice'] = '直し方: {$a}';
$string['status_backup_now'] = '予定を待たずにバックアップする';
$string['status_backup_running'] = 'バックアップが進んでいるので、今は新しく始められません。';
$string['status_backups_heading'] = 'バックアップ';
$string['status_enrolled_at'] = '登録した日時';
$string['status_failed_value'] = '段階: {$a->stage}。開始: {$a->started}。ここまでに送った量: {$a->size} ({$a->chunks} 個の塊)。直前の段が失敗しました ({$a->error})。次に試すのは {$a->next} です。始めてから 24 時間たっても失敗が続くと、バックアップは止まります。';
$string['status_last_completed'] = '最後に完了したバックアップ';
$string['status_last_duration'] = 'かかった時間';
$string['status_last_error'] = '最後の状態の問い合わせに失敗しました。';
$string['status_last_error_code'] = '最後の状態の問い合わせに失敗しました ({$a})。';
$string['status_last_poll'] = '最後の状態の問い合わせ';
$string['status_last_schedule'] = '最後の予定の開始';
$string['status_last_schedule_value'] = '{$a->time}: {$a->text}';
$string['status_last_size'] = 'そのバックアップで送ったデータ';
$string['status_last_stored'] = 'バックアップの大きさ';
$string['status_never'] = 'まだありません';
$string['status_none_completed'] = 'まだ完了したバックアップはありません。';
$string['status_not_registered'] = 'このサイトは、まだバックアップのサービスに登録されていません。接続設定を開き、プラットフォーム (バックアップを申し込んだサービス) の画面で発行した登録コードで登録してください。';
$string['status_paused_value'] = '段階: {$a->stage}。開始: {$a->started}。ここまでに送った量: {$a->size} ({$a->chunks} 個の塊)。待っています。次に試すのは {$a->next} です。';
$string['status_platform'] = 'プラットフォーム';
$string['status_refused'] = '{$a} に求めた直近のバックアップを、バックアップのサービスは始めませんでした。';
$string['status_response_invalid'] = 'サービスからの状態の応答を読めませんでした。';
$string['status_running'] = '進んでいるバックアップ';
$string['status_running_value'] = '段階: {$a->stage}。開始: {$a->started}。ここまでに送った量: {$a->size} ({$a->chunks} 個の塊)。';
$string['status_service_status'] = 'サービスでの状態';
$string['status_site'] = 'プラットフォームでのサイト名';
$string['status_siteid'] = 'サイト ID';
$string['status_size_value'] = '{$a->size} ({$a->chunks} 個の塊)';
$string['status_unknown'] = 'まだ分かりません';
$string['status_unreadable'] = '読み取れません。';
$string['tamper_detected'] = 'サイトのファイルの中身が、そのファイルを保存している名前 (ハッシュ) と合っていません。ファイルが書き換えられたか、壊れた可能性があります。バックアップを中止しました。';
$string['task_poll_failed'] = '状態の問い合わせに失敗しました。連携ツールの状態の画面を確かめてください。';
$string['task_poll_notregistered'] = 'サイトが登録されていないので、問い合わせませんでした。';
$string['task_poll_ok'] = '状態を受け取りました。';
$string['task_poll_start'] = '予定のバックアップ: {$a}';
$string['task_poll_status'] = 'バックアップのサービスに、このサイトの状態を問い合わせる';
$string['task_run_backup'] = 'このサイトのバックアップを進める';
$string['task_run_backup_aborted'] = 'バックアップを中止しました。連携ツールの状態の画面を確かめてください。';
$string['task_run_backup_continue'] = 'バックアップは次の段で続けます。';
$string['task_run_backup_done'] = 'バックアップが完了しました。';
$string['task_run_backup_locked'] = 'ほかの処理がバックアップの段を進めています。';
$string['task_run_backup_none'] = '動いているバックアップはありません。';
$string['task_run_backup_paused'] = 'バックアップは、次の時間帯か、一時ファイルを持つサーバを待っています。';
$string['temp_write_failed'] = '連携ツールの一時フォルダにファイルを書けませんでした。空き容量とフォルダの権限を確かめてください。';
$string['trademark'] = 'Moodle という語と関連する Moodle のロゴは、Moodle Pty Ltd またはその関連会社の商標または登録商標です。The word Moodle and associated Moodle logos are trademarks or registered trademarks of Moodle Pty Ltd or its related affiliates. あんしんLMSバックアップは、Moodle Pty Ltd とその関連会社のいずれとも関係がなく、その承認や推奨を受けたものではありません。';
$string['transport_blocked'] = 'Moodle の HTTP のセキュリティ設定が、接続先への通信を止めています。「サイト管理 > 一般 > セキュリティ > HTTPセキュリティ」で、「cURLブロック済みホスト一覧」に接続先の URL のホスト名とその IP アドレスが入っていないことと、「cURL許可済みポート一覧」が空か、443 を含むことを確かめてください。';
$string['transport_connect'] = '接続先のサービスにつながらないか、時間内に応答がありませんでした。このサーバのネットワークとプロキシの設定を確かめてください。';
$string['transport_failed'] = '接続先のサービスとの通信に失敗しました。';
$string['transport_redirect'] = '接続先のサービスが別の場所へ案内しましたが、この連携ツールはたどりません。接続先の URL を確かめてください。';
$string['transport_tls'] = '接続先のサービスの証明書を確かめられませんでした。ネットワークに HTTPS の通信を途中で復号する機器 (セキュリティの装置やプロキシ) がある場合は、接続先の URL のホスト名を復号の対象から外すよう、その管理者に頼んでください。';
$string['transport_too_large'] = '接続先のサービスからの応答が想定より大きかったので、捨てました。';
$string['upload_failed'] = '保存先へのデータの送信が、何度やり直しても成功しなかったため、バックアップを中止しました。次の予定のバックアップで、もう一度始めます。';
$string['version_too_old'] = 'この連携ツールの版が、バックアップのサービスが受け付ける版より古いため、バックアップを中止しました。連携ツールを新しくしてください。';
$string['window_ended'] = 'バックアップが 2 回続けて時間帯の中で終わらなかったため、バックアップを中止しました。次の予定のバックアップで、もう一度始めます。';
