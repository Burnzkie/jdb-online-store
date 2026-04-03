<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/header.php';
// At this point header.php has already set: $pdo, $userModel, $projectRoot, $currentUser

// Re-fetch to get the absolute freshest data (in case header.php's copy is stale)
$currentUser = $userModel->getUserById((int)$_SESSION['user_id']);

if (!$currentUser) {
    echo '<div class="alert alert-danger m-4">Session expired. '
       . '<a href="../../auth/login.php" class="alert-link">Please log in again.</a></div>';
    require_once '../includes/footer.php';
    exit;
}

// ── Upload config ─────────────────────────────────────────────────────────────
$allowedExts   = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$allowedMimes  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$maxSize       = 2 * 1024 * 1024; // 2 MB

// Disk path — profile.php lives at /jdb_parts/staff/public/profile.php
// ../../  goes up to /jdb_parts/ — the project root on disk
$uploadDirDisk = '../../uploads/profiles/';

// ── FIX: Build the correct web URL root ───────────────────────────────────────
// header.php computes: dirname(dirname(SCRIPT_NAME))
// e.g. SCRIPT_NAME = /jdb_parts/staff/public/profile.php
//   → dirname x1   = /jdb_parts/staff/public
//   → dirname x2   = /jdb_parts/staff       ← WRONG (one level too deep)
//
// We need THREE levels up from the script to reach the web root of the project:
//   /jdb_parts/staff/public/profile.php → /jdb_parts
$webRoot = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
// Result: /jdb_parts  (empty string '' if the project IS the server root)

$alertHtml = '';

// ── Handle upload ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_profile'])) {
    Security::requireCsrfToken();

    if (empty($_FILES['profile_picture']['name'])) {
        $alertHtml = '<div class="alert alert-warning">No file selected. Please choose an image first.</div>';

    } elseif ($_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
        $phpUploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds the server limit (php.ini: upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form size limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write the file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
        ];
        $errCode   = $_FILES['profile_picture']['error'];
        $errMsg    = $phpUploadErrors[$errCode] ?? "Unknown upload error (code $errCode).";
        $alertHtml = '<div class="alert alert-danger">' . htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8') . '</div>';

    } else {
        $file = $_FILES['profile_picture'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExts, true)) {
            $alertHtml = '<div class="alert alert-danger">Unsupported file type. Allowed: JPG, JPEG, PNG, GIF, WEBP.</div>';

        } elseif ($file['size'] > $maxSize) {
            $alertHtml = '<div class="alert alert-danger">File too large. Maximum allowed size is 2 MB.</div>';

        } elseif (!in_array(mime_content_type($file['tmp_name']), $allowedMimes, true)) {
            // Check the actual bytes — not the browser-supplied type which can be spoofed
            $alertHtml = '<div class="alert alert-danger">Invalid image file. Please upload a real JPG, PNG, GIF, or WEBP.</div>';

        } else {
            // Create directory if it doesn't exist
            if (!is_dir($uploadDirDisk) && !mkdir($uploadDirDisk, 0755, true)) {
                $alertHtml = '<div class="alert alert-danger">Upload failed: could not create directory '
                           . '<code>' . htmlspecialchars(realpath('../../uploads') ?: $uploadDirDisk, ENT_QUOTES, 'UTF-8') . '/profiles/</code>. '
                           . 'Check folder permissions.</div>';
            } else {
                $newFileName = 'profile_' . (int)$_SESSION['user_id'] . '_' . time() . '.' . $ext;
                $diskPath    = $uploadDirDisk . $newFileName;

                if (!move_uploaded_file($file['tmp_name'], $diskPath)) {
                    $alertHtml = '<div class="alert alert-danger">Upload failed: could not move file to '
                               . '<code>' . htmlspecialchars(realpath($uploadDirDisk) ?: $uploadDirDisk, ENT_QUOTES, 'UTF-8') . '</code>. '
                               . 'Check write permissions.</div>';
                } else {
                    // Path stored in DB — relative from project root, no leading slash
                    // e.g.  uploads/profiles/profile_3_1718000000.jpg
                    $dbPath = 'uploads/profiles/' . $newFileName;

                    // ── Save to database FIRST, delete old file only on success ──
                    if ($userModel->updateProfilePicture((int)$_SESSION['user_id'], $dbPath)) {

                        // DB saved — now safe to delete the old file
                        if (!empty($currentUser['profile_picture'])) {
                            $oldDisk = '../../' . ltrim($currentUser['profile_picture'], '/');
                            if (file_exists($oldDisk)) {
                                @unlink($oldDisk);
                            }
                        }

                        $alertHtml   = '<div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i>Profile picture updated successfully!</div>';
                        $currentUser = $userModel->getUserById((int)$_SESSION['user_id']);

                    } else {
                        // DB failed — rollback: delete the file we just uploaded
                        @unlink($diskPath);
                        $alertHtml = '<div class="alert alert-danger">Database update failed. The uploaded image was not saved.</div>';
                    }
                }
            }
        }
    }
}

// ── Handle removal ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_profile'])) {
    Security::requireCsrfToken();

    if (empty($currentUser['profile_picture'])) {
        $alertHtml = '<div class="alert alert-warning">No profile picture to remove.</div>';
    } else {
        // Clear DB first, then delete file
        if ($userModel->updateProfilePicture((int)$_SESSION['user_id'], null)) {
            $oldDisk = '../../' . ltrim($currentUser['profile_picture'], '/');
            if (file_exists($oldDisk)) {
                @unlink($oldDisk);
            }
            $alertHtml   = '<div class="alert alert-success">Profile picture removed.</div>';
            $currentUser = $userModel->getUserById((int)$_SESSION['user_id']);
        } else {
            $alertHtml = '<div class="alert alert-danger">Failed to remove picture. Please try again.</div>';
        }
    }
}

// ── Build avatar display URL ──────────────────────────────────────────────────
$fullName       = trim(($currentUser['firstname'] ?? '') . ' ' . ($currentUser['lastname'] ?? ''));
$avatarFallback = 'https://ui-avatars.com/api/?name=' . urlencode($fullName ?: 'User')
                . '&size=220&background=667eea&color=fff';

// e.g.  /jdb_parts/uploads/profiles/profile_3_xxx.jpg?v=1718000000
$avatarSrc = !empty($currentUser['profile_picture'])
    ? $webRoot . '/' . ltrim($currentUser['profile_picture'], '/') . '?v=' . time()
    : $avatarFallback;
?>

<div class="container py-4">
    <h2 class="mb-4"><i class="bi bi-person-circle me-2"></i>My Profile</h2>

    <?= $alertHtml ?>

    <div class="row">

        <!-- ── Avatar column ─────────────────────────────────────────────── -->
        <div class="col-md-4 text-center mb-4">

            <img src="<?= htmlspecialchars($avatarSrc, ENT_QUOTES, 'UTF-8') ?>"
                 id="avatarPreview"
                 class="rounded-circle img-fluid mb-3 shadow"
                 alt="Profile Picture"
                 style="width:220px;height:220px;object-fit:cover;"
                 onerror="this.onerror=null;this.src='<?= htmlspecialchars($avatarFallback, ENT_QUOTES, 'UTF-8') ?>'">

            <!-- Upload form -->
            <form method="POST" enctype="multipart/form-data" class="mb-2">
                <?= Security::csrfField() ?>
                <div class="mb-3 text-start">
                    <label for="profile_picture" class="form-label fw-semibold">Choose New Picture</label>
                    <input type="file" id="profile_picture" name="profile_picture"
                           class="form-control"
                           accept="image/jpeg,image/png,image/gif,image/webp">
                    <div class="form-text">Max 2 MB &mdash; JPG, PNG, GIF, WEBP</div>
                </div>
                <button type="submit" name="upload_profile" class="btn btn-primary w-100">
                    <i class="bi bi-upload me-1"></i> Save Picture
                </button>
            </form>

            <!-- Remove form — only shown when a picture exists -->
            <?php if (!empty($currentUser['profile_picture'])): ?>
            <form method="POST">
                <?= Security::csrfField() ?>
                <button type="submit" name="delete_profile" class="btn btn-outline-danger w-100"
                        onclick="return confirm('Remove your current profile picture?');">
                    <i class="bi bi-trash me-1"></i> Remove Picture
                </button>
            </form>
            <?php endif; ?>

        </div>

        <!-- ── Info column ───────────────────────────────────────────────── -->
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title mb-4">Personal Information</h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <strong class="text-muted d-block small">First Name</strong>
                            <?= htmlspecialchars($currentUser['firstname'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="col-md-6">
                            <strong class="text-muted d-block small">Last Name</strong>
                            <?= htmlspecialchars($currentUser['lastname'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="col-md-6">
                            <strong class="text-muted d-block small">Email</strong>
                            <?= htmlspecialchars($currentUser['email'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="col-md-6">
                            <strong class="text-muted d-block small">Phone</strong>
                            <?= htmlspecialchars($currentUser['phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="col-md-6">
                            <strong class="text-muted d-block small">Role</strong>
                            <span class="badge bg-primary">
                                <?= htmlspecialchars(ucfirst($currentUser['role'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                        <div class="col-12">
                            <strong class="text-muted d-block small">Member Since</strong>
                            <?= !empty($currentUser['created_at'])
                                ? date('F d, Y', strtotime($currentUser['created_at']))
                                : 'Unknown' ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 text-end">
                <a href="settings.php" class="btn btn-outline-primary">
                    <i class="bi bi-pencil-square me-1"></i> Edit Profile
                </a>
            </div>
        </div>

    </div>
</div>

<!-- Live preview before submitting -->
<script>
document.getElementById('profile_picture').addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('avatarPreview').src = e.target.result;
    };
    reader.readAsDataURL(file);
});
</script>

<?php require_once '../includes/footer.php'; ?>