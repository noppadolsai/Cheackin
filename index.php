<?php
/* ============================================================
 * index.php — หน้าจอสำหรับนักเรียน / ผู้ปกครอง
 * เข้าดูข้อมูลด้วย "รหัสนักเรียน" ไม่ต้องมีบัญชีผู้ใช้
 * ============================================================ */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/attendance.php';
require_once __DIR__ . '/includes/layout.php';

require_installed();

if (isset($_GET['exit'])) {
    unset($_SESSION['student_id']);
    redirect(url('index.php'));
}

$rooms = q('SELECT * FROM classrooms WHERE is_active = 1 ORDER BY name');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cid  = (int)post('classroom_id');
    $code = trim(post('student_code'));
    $pin  = trim(post('pin'));

    $st = q1('SELECT * FROM students WHERE classroom_id = ? AND student_code = ? AND is_active = 1', [$cid, $code]);
    if (!$st) {
        $error = 'ไม่พบรหัสนักเรียนนี้ในห้องที่เลือก กรุณาตรวจสอบอีกครั้ง';
    } elseif (STUDENT_REQUIRE_PIN && substr(preg_replace('/\D/', '', (string)$st['parent_phone']), -4) !== $pin) {
        $error = 'รหัส 4 ตัวท้ายของเบอร์ผู้ปกครองไม่ถูกต้อง';
    } else {
        $_SESSION['student_id'] = (int)$st['id'];
        redirect(url('index.php'));
    }
}

$student = null;
if (!empty($_SESSION['student_id'])) {
    $student = q1('SELECT * FROM students WHERE id = ? AND is_active = 1', [$_SESSION['student_id']]);
}

/* ============================================================
 * หน้าเข้าใช้งาน
 * ============================================================ */
if (!$student) {
    layout_head('ตรวจสอบการเข้าเรียน');
    ?>
    <main class="wrap stack" style="max-width:440px; padding-top:36px">
      <div style="text-align:center">
        <div class="eyebrow"><?= e(SCHOOL_NAME) ?></div>
        <h1 style="font-size:26px; margin-top:4px">ตรวจสอบการเข้าเรียน</h1>
        <p class="hint" style="margin-top:6px">สำหรับนักเรียนและผู้ปกครอง</p>
      </div>

      <form method="post" class="card">
        <?php if ($error): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>
        <?php if (!$rooms): ?>
          <div class="empty"><strong>ยังไม่เปิดใช้งาน</strong>ครูที่ปรึกษายังไม่ได้สร้างห้องเรียน</div>
        <?php else: ?>
          <label class="field"><span>ห้องเรียน</span>
            <select name="classroom_id" required>
              <?php foreach ($rooms as $r): ?>
                <option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option>
              <?php endforeach; ?>
            </select></label>

          <label class="field"><span>รหัสนักเรียน</span>
            <input type="text" name="student_code" required inputmode="numeric" autocomplete="off"
                   placeholder="เช่น 12345" value="<?= e(post('student_code')) ?>"></label>

          <?php if (STUDENT_REQUIRE_PIN): ?>
            <label class="field"><span>เลข 4 ตัวท้ายเบอร์ผู้ปกครอง</span>
              <input type="text" name="pin" required inputmode="numeric" maxlength="4"></label>
          <?php endif; ?>

          <button class="btn btn--primary btn--block" type="submit">ดูข้อมูลการเข้าเรียน</button>
        <?php endif; ?>
      </form>

      <a class="btn btn--block" href="<?= e(url('admin/login.php')) ?>">สำหรับครู · เข้าสู่ระบบหลังบ้าน</a>
    </main>
    <?php
    layout_foot();
    exit;
}

/* ============================================================
 * หน้าข้อมูลของนักเรียน
 * ============================================================ */
$room = q1('SELECT * FROM classrooms WHERE id = ?', [$student['classroom_id']]);
[$from, $to] = term_range($room);

$me       = student_stats((int)$student['id'], $from, $to);
$classSt  = classroom_stats((int)$room['id'], $from, $to);
$classmates = students_stats((int)$room['id'], $from, $to);
$history  = student_history((int)$student['id'], (int)$room['id'], $from, $to);

$photos = q('SELECT p.*, d.attend_date FROM day_photos p
             JOIN attendance_days d ON d.id = p.day_id
             WHERE d.classroom_id = ? AND d.attend_date BETWEEN ? AND ?
             ORDER BY d.attend_date DESC, p.id DESC LIMIT 24', [$room['id'], $from, $to]);

$statuses = status_list();

layout_head('การเข้าเรียนของฉัน');
layout_topbar(student_full_name($student), 'ห้อง ' . $room['name'] . ' · เลขที่ ' . (int)$student['number_in_class'],
    '<a class="topbar__btn" href="' . e(url('index.php?exit=1')) . '">ออก</a>');
?>
<main class="wrap stack">

  <!-- สรุปของฉัน -->
  <section class="card">
    <div class="card__title">การเข้าเรียนของฉัน
      <small><?= e(thai_date($from, true)) ?> – <?= e(thai_date($to, true)) ?></small></div>

    <div class="tally" style="box-shadow:none; border:0; padding:0 0 12px">
      <div class="tally__head">
        <div>
          <div class="eyebrow">มาเรียนแล้ว</div>
          <div class="tally__count"><?= $me['present'] + $me['late'] ?>/<?= $me['total'] ?><small> วัน</small></div>
        </div>
        <div style="text-align:right">
          <div class="tally__count" style="color:<?= $me['rate'] >= 80 ? 'var(--present)' : 'var(--absent)' ?>">
            <?= $me['rate'] ?><small>%</small></div>
        </div>
      </div>
      <div class="tally__bar">
        <?php $t = max(1, $me['total']); foreach (['present','late','leave','absent'] as $k): ?>
          <div class="tally__seg" style="width:<?= round($me[$k] * 100 / $t, 2) ?>%;background:var(--<?= $k ?>)"></div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="stat-grid stat-grid--4">
      <div class="stat stat--present"><div class="stat__label">มาเรียน</div>
        <div class="stat__value"><?= $me['present'] ?><small> วัน</small></div></div>
      <div class="stat stat--late"><div class="stat__label">มาสาย</div>
        <div class="stat__value"><?= $me['late'] ?><small> ครั้ง</small></div></div>
      <div class="stat stat--leave"><div class="stat__label">ลา</div>
        <div class="stat__value"><?= $me['leave'] ?><small> ครั้ง</small></div></div>
      <div class="stat stat--absent"><div class="stat__label">ขาดเรียน</div>
        <div class="stat__value"><?= $me['absent'] ?><small> ครั้ง</small></div></div>
    </div>
  </section>

  <!-- ภาพรวมของห้อง -->
  <section class="card">
    <div class="card__title">ภาพรวมของห้อง <?= e($room['name']) ?> <small>ตลอดภาคเรียน</small></div>
    <div class="stat-grid stat-grid--4">
      <div class="stat stat--ink"><div class="stat__label">วันที่เช็คชื่อ</div>
        <div class="stat__value"><?= (int)$classSt['days'] ?><small> วัน</small></div></div>
      <div class="stat stat--present"><div class="stat__label">อัตรามาเรียนของห้อง</div>
        <div class="stat__value"><?= $classSt['rate'] ?><small>%</small></div></div>
      <div class="stat stat--leave"><div class="stat__label">ลารวมทั้งห้อง</div>
        <div class="stat__value"><?= (int)$classSt['leave'] ?><small> ครั้ง</small></div></div>
      <div class="stat stat--absent"><div class="stat__label">ขาดรวมทั้งห้อง</div>
        <div class="stat__value"><?= (int)$classSt['absent'] ?><small> ครั้ง</small></div></div>
    </div>

    <div class="divider"></div>
    <div class="table-scroll">
      <table class="data">
        <thead><tr><th class="tc">เลขที่</th><th>ชื่อ-นามสกุล</th><th class="tc">มา</th><th class="tc">สาย</th><th class="tc">ลา</th><th class="tc">ขาด</th></tr></thead>
        <tbody>
        <?php foreach ($classmates as $c):
          $isMe = (int)$c['id'] === (int)$student['id']; ?>
          <tr style="<?= $isMe ? 'background:var(--brass-soft) !important; font-weight:600' : '' ?>">
            <td class="tc num"><?= (int)$c['number_in_class'] ?></td>
            <td><?= e(student_full_name($c)) ?><?= $isMe ? ' (ฉัน)' : '' ?></td>
            <td class="tc num"><?= (int)$c['present'] ?></td>
            <td class="tc num"><?= (int)$c['late'] ?></td>
            <td class="tc num"><?= (int)$c['leave'] ?></td>
            <td class="tc num" style="color:var(--absent)"><?= (int)$c['absent'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- ภาพถ่ายจากครู -->
  <section class="card">
    <div class="card__title">ภาพถ่ายจากครูที่ปรึกษา <small><?= count($photos) ?> รูปล่าสุด</small></div>
    <?php if (!$photos): ?>
      <div class="empty"><strong>ยังไม่มีภาพถ่าย</strong>ครูจะอัปโหลดรูปหลังเช็คชื่อในแต่ละวัน</div>
    <?php else: ?>
      <div class="gallery">
        <?php foreach ($photos as $p): ?>
          <figure>
            <a href="<?= e(UPLOAD_URL . '/photos/' . rawurlencode($p['file_name'])) ?>" target="_blank">
              <img src="<?= e(UPLOAD_URL . '/thumbs/' . rawurlencode($p['thumb_name'] ?: $p['file_name'])) ?>"
                   alt="ภาพถ่ายวันที่ <?= e(thai_date($p['attend_date'])) ?>" loading="lazy"></a>
            <figcaption><?= e(thai_date($p['attend_date'], true)) ?><?= $p['caption'] ? ' · ' . e($p['caption']) : '' ?></figcaption>
          </figure>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- ประวัติรายวัน -->
  <section class="card">
    <div class="card__title">ประวัติรายวัน</div>
    <?php if (!$history): ?>
      <div class="empty">ยังไม่มีการเช็คชื่อในภาคเรียนนี้</div>
    <?php else: ?>
      <?php foreach ($history as $h):
        $s = $h['status']; ?>
        <div class="row-between" style="padding:9px 0; border-bottom:1px solid var(--rule)">
          <span><?= e(thai_date($h['attend_date'], false, true)) ?>
            <?php if ((int)$h['photos'] > 0): ?><small style="color:var(--muted)"> · มีรูป <?= (int)$h['photos'] ?></small><?php endif; ?>
          </span>
          <?php if ($s): ?>
            <span class="badge badge--<?= e($s) ?>"><?= e(status_label($s)) ?></span>
          <?php else: ?>
            <span class="badge badge--none">ไม่ได้บันทึก</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <p class="hint" style="text-align:center">
    หากข้อมูลไม่ถูกต้อง กรุณาแจ้งครูที่ปรึกษาเพื่อแก้ไข
  </p>
</main>
<?php layout_foot();
