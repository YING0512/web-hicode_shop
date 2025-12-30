<?php
// 引入資料庫連線設定
require 'db.php';
// 設定回應內容為 JSON 格式
header('Content-Type: application/json');

// 檢查管理員身份
// 從 URL 參數取得 admin_id
$admin_id = $_GET['admin_id'] ?? null;

// 簡易檢查邏輯 (注意：在實際生產環境中應使用 session 或 JWT token 進行驗證)
if (!$admin_id) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// 查詢該使用者是否存在且角色為 admin
$stmtCheck = $pdo->prepare("SELECT role FROM User WHERE user_id = ?");
$stmtCheck->execute([$admin_id]);
$callingUser = $stmtCheck->fetch();

if (!$callingUser || $callingUser['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// 取得 HTTP 請求方法
$method = $_SERVER['REQUEST_METHOD'];

// ----------------------------------------------------------------
// 1. 處理讀取請求 (GET) - 列出所有使用者
// ----------------------------------------------------------------
if ($method === 'GET') {
    // 查詢所有使用者資料
    // 僅取得基本資訊 + 權限 + 餘額 (隱藏密碼雜湊)
    $stmt = $pdo->query("SELECT user_id, username, email, role, wallet_balance, registration_date FROM User ORDER BY registration_date DESC");
    echo json_encode($stmt->fetchAll());

// ----------------------------------------------------------------
// 2. 處理更新請求 (PUT) - 修改使用者權限
// ----------------------------------------------------------------
} elseif ($method === 'PUT') {
    // 切換權限 (升級/降級)
    $data = json_decode(file_get_contents('php://input'), true);
    $target_user_id = $data['user_id'] ?? null;
    $new_role = $data['role'] ?? null; // 允許的值: 'user', 'seller', 'admin'

    // 驗證角色合法性
    $allowed_roles = ['user', 'seller', 'admin'];
    if (!in_array($new_role, $allowed_roles)) {
        http_response_code(400); 
        echo json_encode(['error' => 'Invalid role']); 
        exit;
    }

    // 防止移除自己的管理員權限 (若是重要操作)
    // 這裡保留彈性：若管理員真的想把自己的權限拿掉，是允許的。
    if ($target_user_id == $admin_id && $new_role !== 'admin') {
         // 可選擇阻止自我降級，但目前允許標準更新操作。
    }

    // 更新資料庫中的角色欄位
    $stmt = $pdo->prepare("UPDATE User SET role = ? WHERE user_id = ?");
    $stmt->execute([$new_role, $target_user_id]);
    
    echo json_encode(['message' => 'User role updated']);
}
?>
