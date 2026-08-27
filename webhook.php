<?php
/* ============================================================
 * webhook.php — ปลายทาง Webhook ของ LINE
 *
 * หน้าที่: เมื่อมีคนพิมพ์ข้อความในกลุ่มที่บอทอยู่ ระบบจะบันทึก Group ID ไว้
 *          เพื่อให้ครูนำไปวางในหน้า "ตั้งค่า" ได้ง่าย ๆ
 *
 * นำ URL นี้ไปวางในช่อง Webhook URL ของ LINE Developers Console
 *   ตัวอย่าง: https://school.ac.th/attendance/webhook.php
 * ============================================================ */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/line.php';

$body = file_get_contents('php://input');

/* ---- ตรวจลายเซ็นเพื่อยืนยันว่ามาจาก LINE จริง ---- */
if (defined('LINE_CHANNEL_SECRET') && LINE_CHANNEL_SECRET !== '') {
    $sig  = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';
    $calc = base64_encode(hash_hmac('sha256', $body, LINE_CHANNEL_SECRET, true));
    if (!hash_equals($calc, $sig)) {
        http_response_code(400);
        exit('invalid signature');
    }
}

$data = json_decode($body, true);
$events = $data['events'] ?? [];

function line_reply(string $token, string $text): void
{
    if (!line_enabled()) return;
    $ch = curl_init('https://api.line.me/v2/bot/message/reply');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim(LINE_CHANNEL_ACCESS_TOKEN),
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'replyToken' => $token,
            'messages'   => [['type' => 'text', 'text' => $text]],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    curl_exec($ch);
    curl_close($ch);
}

foreach ($events as $ev) {
    $src  = $ev['source'] ?? [];
    $type = $src['type'] ?? '';
    $id   = $src['groupId'] ?? ($src['roomId'] ?? null);

    if ($id) {
        setting_set('line_group_' . $id, $id);

        // ผูกให้อัตโนมัติถ้ายังไม่มีห้องไหนใช้ ID นี้ และมีห้องที่ยังว่างอยู่เพียงห้องเดียว
        $used = (int)qv('SELECT COUNT(*) FROM classrooms WHERE line_group_id = ?', [$id], 0);
        if (!$used) {
            $empty = q('SELECT id FROM classrooms WHERE (line_group_id IS NULL OR line_group_id = "") AND is_active = 1');
            if (count($empty) === 1) {
                ex('UPDATE classrooms SET line_group_id = ? WHERE id = ?', [$id, $empty[0]['id']]);
            }
        }
    }

    // ตอบกลับเมื่อมีคนพิมพ์คำว่า "id" หรือ "ไอดี" ในกลุ่ม
    $text = mb_strtolower(trim($ev['message']['text'] ?? ''));
    if (in_array($text, ['id', 'ไอดี', 'groupid', 'group id'], true) && !empty($ev['replyToken'])) {
        $msg = $id
            ? "Group ID ของกลุ่มนี้คือ\n" . $id . "\n\nนำไปวางในหน้าตั้งค่าของระบบเช็คชื่อ"
            : 'คำสั่งนี้ใช้ได้เฉพาะในกลุ่มเท่านั้น';
        line_reply($ev['replyToken'], $msg);
    }

    // ตอบทักทายเมื่อบอทถูกเชิญเข้ากลุ่ม
    if (($ev['type'] ?? '') === 'join' && !empty($ev['replyToken'])) {
        line_reply($ev['replyToken'],
            "สวัสดีค่ะ ระบบเช็คชื่อเข้าเรียนพร้อมใช้งานแล้ว\n"
            . "ทุกวันหลังครูเช็คชื่อเสร็จ ระบบจะส่งสรุปการเข้าเรียนและภาพถ่ายมาที่กลุ่มนี้\n\n"
            . "Group ID: " . ($id ?: '-'));
    }
}

http_response_code(200);
echo 'OK';
