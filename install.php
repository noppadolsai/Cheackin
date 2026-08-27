<?php
/* ============================================================
 * install.php — ติดตั้งระบบครั้งแรก
 * ** เมื่อติดตั้งเสร็จแล้ว ให้ลบไฟล์นี้ทิ้งทันที **
 * ============================================================ */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$errors = [];
$done   = false;

/* ---- ตรวจว่าติดตั้งไปแล้วหรือยัง ---- */
$installed = false;
try {
    $installed = (int)qv("SELECT COUNT(*) FROM users", [], 0) > 0;
} catch (Throwable $e) {
    $installed = false;
}

/* ---- สร้างตาราง ---- */
function run_schema(): void
{
    $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        db()->exec($stmt);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(post('username'));
    $password = (string)post('password');
    $fullName = trim(post('full_name'));
    $room     = trim(post('classroom'));
    $year     = trim(post('academic_year', '2568'));
    $term     = trim(post('term', '1'));
    $tStart   = post('term_start') ?: null;
    $tEnd     = post('term_end') ?: null;

    if ($username === '' || mb_strlen($username) < 4) $errors[] = 'ชื่อผู้ใช้ต้องยาวอย่างน้อย 4 ตัวอักษร';
    if (mb_strlen($password) < 6)                     $errors[] = 'รหัสผ่านต้องยาวอย่างน้อย 6 ตัวอักษร';
    if ($fullName === '')                             $errors[] = 'กรุณากรอกชื่อ-นามสกุลของครู';
    if ($room === '')                                 $errors[] = 'กรุณากรอกชื่อห้องเรียน';

    if (!$errors) {
        try {
            run_schema();
            db()->beginTransaction();
            ex('INSERT INTO classrooms (name, academic_year, term, term_start, term_end) VALUES (?,?,?,?,?)',
               [$room, $year, $term, $tStart, $tEnd]);
            $cid = (int)db()->lastInsertId();
            ex('INSERT INTO users (username, password_hash, full_name, role, classroom_id) VALUES (?,?,?,?,?)',
               [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, 'admin', $cid]);
            db()->commit();
            $done = true;
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $errors[] = 'ติดตั้งไม่สำเร็จ: ' . $e->getMessage();
        }
    }
}

/* ---- ตรวจความพร้อมของเซิร์ฟเวอร์ ---- */
$checks = [
    'PHP 8.0 ขึ้นไป'          => version_compare(PHP_VERSION, '8.0.0', '>='),
    'ส่วนขยาย PDO MySQL'      => extension_loaded('pdo_mysql'),
    'ส่วนขยาย mbstring'       => extension_loaded('mbstring'),
    'ส่วนขยาย cURL (ส่ง LINE)' => extension_loaded('curl'),
    'ส่วนขยาย GD (ย่อรูป)'     => extension_loaded('gd'),
    'ส่วนขยาย ZipArchive (Excel)' => class_exists('ZipArchive'),
    'เขียนโฟลเดอร์ uploads ได้' => is_writable(dirname(__FILE__) . '/uploads') || @mkdir(dirname(__FILE__) . '/uploads', 0755, true),
];

layout_head('ติดตั้งระบบ');
layout_topbar('ติดตั้งระบบ', APP_NAME);
?>
<main class="wrap stack">

<?php if ($done): ?>
  <div class="alert alert--success">ติดตั้งเรียบร้อยแล้ว</div>
  <div class="card">
    <div class="card__title">ขั้นตอนถัดไป</div>
    <ol style="padding-left:20px; margin:0">
      <li><strong>ลบไฟล์ <code>install.php</code> ออกจากเซิร์ฟเวอร์ทันที</strong> เพื่อความปลอดภัย</li>
      <li>เข้าสู่ระบบด้วยชื่อผู้ใช้และรหัสผ่านที่เพิ่งตั้งไว้</li>
      <li>นำเข้ารายชื่อนักเรียนจากไฟล์ CSV</li>
      <li>ตั้งค่า LINE Group ID ที่หน้า "ตั้งค่า" เพื่อส่งสรุปเข้ากลุ่มผู้ปกครอง</li>
    </ol>
    <div class="divider"></div>
    <a class="btn btn--primary btn--block" href="<?= e(url('admin/login.php')) ?>">ไปหน้าเข้าสู่ระบบ</a>
  </div>

<?php elseif ($installed): ?>
  <div class="alert alert--warn">ระบบนี้ติดตั้งไปแล้ว หากต้องการติดตั้งใหม่ ให้ลบข้อมูลในตาราง <code>users</code> ก่อน</div>
  <a class="btn btn--primary btn--block" href="<?= e(url('admin/login.php')) ?>">ไปหน้าเข้าสู่ระบบ</a>

<?php else: ?>
  <div class="card">
    <div class="card__title">ตรวจความพร้อมของเซิร์ฟเวอร์</div>
    <?php foreach ($checks as $label => $ok): ?>
      <div class="row-between" style="padding:6px 0; border-bottom:1px solid var(--rule)">
        <span><?= e($label) ?></span>
        <span class="badge badge--<?= $ok ? 'present' : 'absent' ?>"><?= $ok ? 'ผ่าน' : 'ไม่พบ' ?></span>
      </div>
    <?php endforeach; ?>
    <p class="hint">ถ้า GD หรือ ZipArchive ไม่ผ่าน ระบบยังใช้งานได้ แต่จะย่อรูป/สร้างไฟล์ Excel ไม่ได้</p>
  </div>

  <form method="post" class="card">
    <div class="card__title">สร้างบัญชีครูที่ปรึกษา (ผู้ดูแลระบบ)</div>
    <?php foreach ($errors as $er): ?><div class="alert alert--error"><?= e($er) ?></div><?php endforeach; ?>

    <label class="field"><span>ชื่อ-นามสกุล ครูที่ปรึกษา</span>
      <input type="text" name="full_name" value="<?= e(post('full_name')) ?>" required placeholder="เช่น นางสาวสมศรี ใจดี"></label>
    <label class="field"><span>ชื่อผู้ใช้ (สำหรับเข้าระบบ)</span>
      <input type="text" name="username" value="<?= e(post('username')) ?>" required autocomplete="username" placeholder="เช่น teacher01"></label>
    <label class="field"><span>รหัสผ่าน (อย่างน้อย 6 ตัวอักษร)</span>
      <input type="password" name="password" required autocomplete="new-password"></label>

    <div class="divider"></div>
    <div class="card__title">ห้องเรียนแรก</div>
    <label class="field"><span>ชื่อห้องเรียน</span>
      <input type="text" name="classroom" value="<?= e(post('classroom', 'ม.4/1')) ?>" required></label>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px">
      <label class="field"><span>ปีการศึกษา</span>
        <input type="text" name="academic_year" value="<?= e(post('academic_year', '2568')) ?>"></label>
      <label class="field"><span>ภาคเรียน</span>
        <input type="text" name="term" value="<?= e(post('term', '1')) ?>"></label>
      <label class="field"><span>วันเปิดเทอม</span>
        <input type="date" name="term_start" value="<?= e(post('term_start')) ?>"></label>
      <label class="field"><span>วันปิดเทอม</span>
        <input type="date" name="term_end" value="<?= e(post('term_end')) ?>"></label>
    </div>

    <button class="btn btn--brass btn--block" type="submit">ติดตั้งระบบ</button>
  </form>
<?php endif; ?>

</main>
<?php layout_foot();
