<?php
require_once __DIR__ . '/compat.php';
header('Content-Type: application/json; charset=utf-8');
// CORS: validate origin against known host
$httpOrigin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$allowedOrigin = '';
if ($httpOrigin) {
    $originHost = parse_url($httpOrigin, PHP_URL_HOST);
    $serverHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '');
    if ($originHost === $serverHost || $originHost === 'localhost' || $originHost === '127.0.0.1') {
        $allowedOrigin = $httpOrigin;
    }
}
if ($allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
} else {
    header('Access-Control-Allow-Origin: http://localhost');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 启动session进行登录检查
compat_session_start();

function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

// 登录检查
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF 验证
$csrfToken = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'CSRF 令牌无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 上传目录配置
$uploadDir = __DIR__ . '/../uploads/';

// 创建上传目录（如果不存在）
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// 允许的文件类型
$allowedTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
    'application/zip' => 'zip',
    'application/x-rar-compressed' => 'rar',
    'application/x-7z-compressed' => '7z',
    'text/plain' => 'txt',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'audio/mpeg' => 'mp3',
    'audio/wav' => 'wav',
    'audio/ogg' => 'ogg',
    'video/mp4' => 'mp4',
    'video/webm' => 'webm',
    'video/x-msvideo' => 'avi',
    'application/octet-stream' => 'bin', // 其他二进制文件
    'text/x-php' => 'php',
    'application/x-php' => 'php',
    'application/x-httpd-php' => 'php',
];

// 最大文件大小 50MB
$maxFileSize = 50 * 1024 * 1024;

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 检查是否有文件上传
if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['success' => false, 'error' => '没有选择文件'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['file'];

// 检查上传错误
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => '文件大小超过服务器限制',
        UPLOAD_ERR_FORM_SIZE => '文件大小超过表单限制',
        UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
        UPLOAD_ERR_NO_TMP_DIR => '服务器找不到临时文件夹',
        UPLOAD_ERR_CANT_WRITE => '文件写入失败',
        UPLOAD_ERR_EXTENSION => '文件上传被扩展阻止',
    ];
    $errorMsg = isset($errorMessages[$file['error']]) ? $errorMessages[$file['error']] : '未知上传错误';
    echo json_encode(['success' => false, 'error' => $errorMsg], JSON_UNESCAPED_UNICODE);
    exit;
}

// 检查文件大小
if ($file['size'] > $maxFileSize) {
    echo json_encode(['success' => false, 'error' => '文件大小超过50MB限制'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取文件类型
$fileType = $file['type'];

// 对于某些浏览器可能发送不准确的MIME类型，进行额外检查
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$realType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if ($realType && $realType !== 'application/octet-stream') {
    $fileType = $realType;
}

// 检查文件类型
if (!isset($allowedTypes[$fileType]) && !in_array($fileType, $allowedTypes)) {
    // 尝试根据扩展名判断
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $reverseAllowed = array_flip($allowedTypes);
    if (!isset($reverseAllowed[$ext])) {
        echo json_encode(['success' => false, 'error' => '不支持的文件类型: ' . $fileType], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 获取扩展名
$ext = isset($allowedTypes[$fileType]) ? $allowedTypes[$fileType] : strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

// 生成安全的文件名
$originalName = pathinfo($file['name'], PATHINFO_FILENAME);
$originalName = preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]/u', '_', $originalName);
$originalName = mb_substr($originalName, 0, 50); // 限制长度

// 生成唯一文件名
$newFileName = $originalName . '_' . date('Ymd') . '_' . uniqid() . '.' . $ext;
$destPath = $uploadDir . $newFileName;

// 移动文件
if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['success' => false, 'error' => '文件保存失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 设置文件权限
chmod($destPath, 0644);

// 生成访问URL（使用配置的域名，不信任 Host 头）
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$fileUrl = $basePath . '/../uploads/' . $newFileName;

// 返回结果
echo json_encode([
    'success' => true,
    'url' => $fileUrl,
    'path' => 'uploads/' . $newFileName,
    'name' => $file['name'],
    'size' => $file['size'],
], JSON_UNESCAPED_UNICODE);
